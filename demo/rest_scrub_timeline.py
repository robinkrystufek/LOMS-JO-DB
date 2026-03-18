#!/usr/bin/env python3
"""
rest_scrub_timeline.py

Build an interactive publication timeline from the JO DB REST endpoints:

- rest/records/      (collects JO records + publication DOIs + years)
- rest/publications  (collects per-publication reference/cited-by metadata)

Output:
- jo_pub_timeline.html        (interactive Plotly timeline with citation/reference edges)
- nodes.csv, edges.csv (for debugging / downstream use)

Usage:
  python rest_scrub_timeline.py --base-url https://loms.cz/jo-db/api --out timeline.html

Graphing details:
- Lines are drawn only when both DOIs are already present in the JO dataset
- Blob size is determined by number of JO records for that publication.
"""

from __future__ import annotations

import argparse
import csv
import json
import math
import random
import re
import sys
from collections import Counter, defaultdict
from dataclasses import dataclass
from typing import Any, Dict, Iterable, List, Optional, Set, Tuple

import requests

try:
    import plotly.graph_objects as go
except ImportError:
    print("ERROR: plotly is required. Install with: pip install plotly", file=sys.stderr)
    raise

DOI_RE = re.compile(r"\b10\.\d{4,9}/[^\s\"<>]+", re.IGNORECASE)


def normalize_doi(s: str) -> str:
    """
    Normalize DOIs for matching.

    Key detail: Crossref reference DOIs and scraped strings often end in punctuation
    ('.', ')', ';', etc.). This is a critical detail for matching, and we must strip these trailing chars
    """
    s = (s or "").strip()
    s = re.sub(r"^https?://(dx\.)?doi\.org/", "", s, flags=re.I)
    s = re.sub(r"^doi:\s*", "", s, flags=re.I)
    s = re.sub(r"\s+", "", s)
    s = s.rstrip(").,;:]}>\"'")  # <-- critical
    return s.lower()


def looks_like_doi(s: str) -> bool:
    if not s:
        return False
    return bool(DOI_RE.search(normalize_doi(s)))


def extract_dois_from_text(s: str) -> Set[str]:
    if not s:
        return set()
    m = set(normalize_doi(x) for x in DOI_RE.findall(s))
    return {x for x in m if x}


@dataclass
class PubNode:
    doi: str
    year: Optional[int]
    title: str
    journal: str
    authors: str
    jo_count: int


@dataclass(frozen=True)
class Edge:
    src: str
    dst: str
    kind: str  # "references" or "cited_by"


class JOClient:
    """
    HTTP client for the JO DB API.

    Accepts base-url with or without /api/ and normalizes to .../api/.
    """

    def __init__(self, base_url: str, timeout: float = 30.0, verify_tls: bool = True):
        b = base_url.rstrip("/") + "/"
        if not b.endswith("/api/"):
            if b.endswith("/api"):
                b += "/"
            else:
                b += "api/"
        self.base_url = b
        self.timeout = timeout
        self.session = requests.Session()
        self.session.verify = verify_tls

    def _url(self, endpoint: str) -> str:
        return self.base_url + endpoint.lstrip("/")

    def browse_records_page(self, page: int, per_page: int = 50) -> Dict[str, Any]:
        url = self._url("rest/records/")
        params = {
            "page": page,
            "per_page": min(50, max(1, per_page)),
            "sort_by": "pub_year",
            "sort_dir": "asc",
        }
        r = self.session.get(url, params=params, timeout=self.timeout)
        r.raise_for_status()
        return r.json()

    def get_pub_metadata(self, doi: str) -> Dict[str, Any]:
        url = self._url("rest/publications/")
        r = self.session.get(url, params={"doi": doi}, timeout=self.timeout)
        if r.status_code == 404:
            return {"ok": False, "doi": doi}
        r.raise_for_status()
        return r.json()


def _as_int_year(v: Any) -> Optional[int]:
    try:
        if v is None:
            return None
        if isinstance(v, int):
            return v if 1000 <= v <= 3000 else None
        s = str(v).strip()
        if not s:
            return None
        y = int(float(s))
        return y if 1000 <= y <= 3000 else None
    except Exception:
        return None


def _safe_json_loads(s: Any) -> Any:
    if not isinstance(s, str) or not s.strip():
        return None
    try:
        return json.loads(s)
    except Exception:
        return None


def extract_edges_from_pub_metadata_live(resp: Dict[str, Any], known_dois: Set[str]) -> List[Edge]:
    """
    Internal edge extraction from the live rest/publications schema:

    - references (outgoing):
        raw_row.metadata  -> JSON string -> data.message.reference[].DOI

    - cited_by (incoming):
        raw_row.alex_citations -> JSON string -> items[].doi

    Returns only edges where both endpoints are in known_dois.
    """
    if not resp.get("ok"):
        return []

    src = normalize_doi(str(resp.get("doi") or ""))
    if not src or src not in known_dois:
        return []

    raw_row = resp.get("raw_row") or {}
    edges: List[Edge] = []

    # --- references: src -> ref
    meta = _safe_json_loads(raw_row.get("metadata"))
    msg = (((meta or {}).get("data") or {}).get("message") or {})
    refs = msg.get("reference") or []
    if isinstance(refs, list):
        for r in refs:
            if not isinstance(r, dict):
                continue
            d = normalize_doi(str(r.get("DOI") or ""))
            if d and d in known_dois and d != src:
                edges.append(Edge(src=src, dst=d, kind="references"))

    # --- cited_by: citer -> src
    ac = _safe_json_loads(raw_row.get("alex_citations"))
    items = (ac or {}).get("items") if isinstance(ac, dict) else None
    if isinstance(items, list):
        for it in items:
            if not isinstance(it, dict):
                continue
            citing = normalize_doi(str(it.get("doi") or ""))
            if citing and citing in known_dois and citing != src:
                edges.append(Edge(src=citing, dst=src, kind="cited_by"))

    # de-dupe
    return list({(e.src, e.dst, e.kind): e for e in edges}.values())


# --------------------------
# Timeline layout + plotting
# --------------------------

def stable_jitter(doi: str, amp: float = 0.35) -> float:
    h = 0
    for ch in doi:
        h = (h * 131 + ord(ch)) & 0xFFFFFFFF
    r = (h % 10_000) / 10_000.0
    return (r - 0.5) * 2.0 * amp


def build_positions(nodes: Dict[str, PubNode]) -> Dict[str, Tuple[float, float]]:
    """
    x = year
    y = stacked index within year bucket + stable jitter
    """
    by_year: Dict[int, List[str]] = defaultdict(list)
    for doi, n in nodes.items():
        y = n.year if n.year is not None else 0
        by_year[y].append(doi)

    years_sorted = sorted([y for y in by_year.keys() if y != 0]) + ([0] if 0 in by_year else [])

    pos: Dict[str, Tuple[float, float]] = {}
    for y in years_sorted:
        bucket = sorted(by_year[y])
        for i, doi in enumerate(bucket):
            x = float(y) if y != 0 else (max(years_sorted[:-1]) + 1 if years_sorted[:-1] else 0.0)
            base = i - (len(bucket) - 1) / 2.0
            pos[doi] = (x, base + stable_jitter(doi))
    return pos


def scale_marker_sizes(counts: Dict[str, int], min_size: float = 10.0, max_size: float = 55.0) -> Dict[str, float]:
    vals = [max(1, int(v)) for v in counts.values()] or [1]
    svals = [math.sqrt(v) for v in vals]
    lo, hi = min(svals), max(svals)
    if hi == lo:
        return {k: (min_size + max_size) / 2.0 for k in counts.keys()}

    out: Dict[str, float] = {}
    for k, v in counts.items():
        sv = math.sqrt(max(1, int(v)))
        t = (sv - lo) / (hi - lo)
        out[k] = min_size + t * (max_size - min_size)
    return out


def write_csv_nodes(nodes: Dict[str, PubNode], path: str) -> None:
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["doi", "year", "jo_count", "title", "journal", "authors"])
        for doi in sorted(nodes.keys()):
            n = nodes[doi]
            w.writerow([n.doi, n.year or "", n.jo_count, n.title, n.journal, n.authors])


def write_csv_edges(edges: List[Edge], path: str) -> None:
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["src", "dst", "kind"])
        for e in sorted(edges, key=lambda x: (x.kind, x.src, x.dst)):
            w.writerow([e.src, e.dst, e.kind])


def make_plot(nodes: Dict[str, PubNode], edges: List[Edge], out_html: str,
              title: str = "JO publications timeline") -> None:

    pos = build_positions(nodes)
    sizes = scale_marker_sizes({k: v.jo_count for k, v in nodes.items()})

    # --- Build edge trace
    xs: List[float] = []
    ys: List[float] = []
    for e in edges:
        if e.src in pos and e.dst in pos:
            x0, y0 = pos[e.src]
            x1, y1 = pos[e.dst]
            xs += [x0, x1, None]
            ys += [y0, y1, None]

    edges_trace = go.Scatter(
        x=xs,
        y=ys,
        mode="lines",
        hoverinfo="skip",
        line=dict(
            color="rgba(0,0,0,0.18)",
            width=1
        ),
        showlegend=False,
    )

    # --- Nodes
    node_x = []
    node_y = []
    node_size = []
    hover = []

    for doi, n in nodes.items():
        x, y = pos[doi]
        node_x.append(x)
        node_y.append(y)
        node_size.append(sizes[doi])
        yr = n.year if n.year is not None else "?"
        hover.append(
            f"<b>{n.title or doi}</b><br>"
            f"DOI: {doi}<br>"
            f"Year: {yr}<br>"
            f"JO records: {n.jo_count}"
        )

    nodes_trace = go.Scatter(
        x=node_x,
        y=node_y,
        mode="markers",
        hoverinfo="text",
        text=hover,
        marker=dict(
            size=node_size,
            color="#007bff",
            line=dict(color="white", width=0.5),
            opacity=1,
        ),
        showlegend=False,
    )

    fig = go.Figure(data=[edges_trace, nodes_trace])

    # --- Year bounds
    years = sorted({n.year for n in nodes.values() if n.year is not None})
    xmin, xmax = (min(years), max(years)) if years else (0, 1)

    fig.update_layout(
        title=dict(text=title, x=0.5),
        paper_bgcolor="white",
        plot_bgcolor="white",
        font=dict(
            family="Arial",
            size=14,
            color="black"
        ),
        xaxis=dict(
            title="Publication year",
            range=[xmin - 1, xmax + 1],
            showgrid=True,
            gridcolor="rgba(0,0,0,0.08)",
            zeroline=False,
            linecolor="black",
            mirror=True,
        ),
        yaxis=dict(
            showticklabels=False,
            showgrid=False,
            zeroline=False,
            linecolor="black",
            mirror=True,
        ),
        margin=dict(l=60, r=40, t=80, b=60),
        hovermode="closest",
    )

    fig.write_html(out_html, include_plotlyjs="cdn",post_script=f"document.title = {title!r};")
    print(f"Wrote {out_html}")


# --------------------------
# Main crawl
# --------------------------

def crawl_publications_from_records(client: JOClient, per_page: int = 50) -> Dict[str, PubNode]:
    p1 = client.browse_records_page(page=1, per_page=per_page)
    if not p1.get("ok"):
        raise RuntimeError(f"rest/records returned ok=false: {p1}")

    total_pages = int(p1.get("total_pages") or 1)

    counts: Counter[str] = Counter()
    meta: Dict[str, Dict[str, Any]] = {}

    def ingest(items: List[Dict[str, Any]]) -> None:
        for it in items or []:
            doi = normalize_doi(str(it.get("doi") or ""))
            if not doi:
                continue
            counts[doi] += 1
            if doi not in meta:
                meta[doi] = {
                    "year": _as_int_year(it.get("pub_year")),
                    "title": str(it.get("pub_title") or ""),
                    "journal": str(it.get("pub_journal") or ""),
                    "authors": str(it.get("pub_authors") or ""),
                }
            else:
                if meta[doi].get("year") is None:
                    meta[doi]["year"] = _as_int_year(it.get("pub_year"))
                if not meta[doi].get("title"):
                    meta[doi]["title"] = str(it.get("pub_title") or "")
                if not meta[doi].get("journal"):
                    meta[doi]["journal"] = str(it.get("pub_journal") or "")
                if not meta[doi].get("authors"):
                    meta[doi]["authors"] = str(it.get("pub_authors") or "")

    ingest(p1.get("items") or [])

    for page in range(2, total_pages + 1):
        pj = client.browse_records_page(page=page, per_page=per_page)
        if not pj.get("ok"):
            raise RuntimeError(f"browse_records page={page} returned ok=false: {pj}")
        ingest(pj.get("items") or [])

    nodes: Dict[str, PubNode] = {}
    for doi, c in counts.items():
        m = meta.get(doi, {})
        nodes[doi] = PubNode(
            doi=doi,
            year=m.get("year"),
            title=m.get("title", ""),
            journal=m.get("journal", ""),
            authors=m.get("authors", ""),
            jo_count=int(c),
        )
    return nodes


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base-url", required=True, help="Base URL where the PHP endpoints live (with or without /api/)")
    ap.add_argument("--out", default="jo_pub_timeline.html", help="Output HTML file")
    ap.add_argument("--per-page", type=int, default=50, help="browse_records per_page (max 50)")
    ap.add_argument("--max-pubs", type=int, default=0, help="If >0, limit to top-N publications by JO record count")
    ap.add_argument("--timeout", type=float, default=30.0)
    ap.add_argument("--no-verify-tls", action="store_true")
    ap.add_argument("--seed", type=int, default=1)
    args = ap.parse_args()

    random.seed(args.seed)

    client = JOClient(base_url=args.base_url, timeout=args.timeout, verify_tls=(not args.no_verify_tls))

    print("Crawling JO records -> publications.")
    nodes = crawl_publications_from_records(client, per_page=args.per_page)
    print(f"Found {len(nodes)} publications with DOIs in JO records.")

    if args.max_pubs and args.max_pubs > 0 and len(nodes) > args.max_pubs:
        items = list(nodes.items())
        items.sort(key=lambda kv: (-kv[1].jo_count, kv[0]))
        nodes = dict(items[: args.max_pubs])
        print(f"Limited to top {len(nodes)} publications by JO record count.")

    known = set(nodes.keys())

    print("Fetching publication metadata + extracting edges...")
    edges: List[Edge] = []
    missing_meta = 0

    for i, doi in enumerate(sorted(nodes.keys()), 1):
        resp = client.get_pub_metadata(doi)
        if not resp.get("ok"):
            missing_meta += 1
            continue

        edges.extend(extract_edges_from_pub_metadata_live(resp, known_dois=known))

        if i % 25 == 0:
            print(f"  processed {i}/{len(nodes)}")

    edges = list({(e.src, e.dst, e.kind): e for e in edges}.values())
    print(f"Extracted {len(edges)} internal edges.")
    if missing_meta:
        print(f"Note: {missing_meta} publications had no stored metadata (404/ok=false).")

    write_csv_nodes(nodes, "nodes.csv")
    write_csv_edges(edges, "edges.csv")
    print("Wrote nodes.csv and edges.csv")

    make_plot(
        nodes=nodes,
        edges=edges,
        out_html=args.out,
        title="JO DB publications timeline",
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())