#!/usr/bin/env python3
"""
rest_scrub_loms_records.py

Paginate through the JO DB REST records endpoint, fetch the LOMS text export for
all record IDs, and bundle the returned text files into a ZIP archive.

Workflow:
    1) GET /records/?page=N&per_page=50
    2) For each item, read jo_record_id
    3) GET /records/{id}/data?type=loms
    4) Save each returned text payload as a .txt file inside the ZIP + manifest.json

Usage:
  python rest_scrub_loms_records.py \
    --base-url https://www.loms.cz/jo-db/api/rest/ \
    --out loms_records.zip
    
Optional flags:    
    --start-page 1
    --end-page 3
    --per-page 50
    --sleep 0.05
    --skip-empty
    --no-verify-tls
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
import zipfile
from typing import Any, Dict, Iterable, Optional

import requests


class JOClient:
    def __init__(self, base_url: str, timeout: float = 30.0, verify_tls: bool = True):
        self.base_url = base_url.rstrip("/") + "/"
        self.timeout = timeout
        self.session = requests.Session()
        self.session.verify = verify_tls
        self.session.headers.update({
            "User-Agent": "jo-db-loms-scrubber/1.0",
            "Accept": "application/json, text/plain;q=0.9, */*;q=0.8",
        })

    def _url(self, endpoint: str) -> str:
        return self.base_url + endpoint.lstrip("/")

    def browse_records_page(self, page: int, per_page: int = 50) -> Dict[str, Any]:
        url = self._url("records/")
        params = {
            "page": page,
            "per_page": min(50, max(1, per_page)),
        }
        r = self.session.get(url, params=params, timeout=self.timeout)
        r.raise_for_status()
        return r.json()

    def get_loms_text(self, record_id: int) -> str:
        url = self._url(f"records/{record_id}/data")
        r = self.session.get(url, params={"type": "loms"}, timeout=self.timeout)
        r.raise_for_status()
        return r.text


def _sanitize_part(s: Any, fallback: str = "") -> str:
    s = "" if s is None else str(s)
    s = s.strip()
    s = re.sub(r"\s+", "_", s)
    s = re.sub(r"[^A-Za-z0-9._+-]", "-", s)
    s = re.sub(r"-+", "-", s).strip("-._")
    return s or fallback


def _record_filename(record: Dict[str, Any]) -> str:
    record_id = record.get("jo_record_id")
    year = record.get("pub_year") or record.get("year") or ""
    doi = record.get("doi") or ""

    parts = [f"record_{record_id}"]
    if year:
        parts.append(_sanitize_part(year))
    if doi:
        parts.append(_sanitize_part(doi))

    return "__".join(parts) + ".txt"


def iter_all_records(client: JOClient, per_page: int, start_page: int = 1, end_page: Optional[int] = None) -> Iterable[Dict[str, Any]]:
    page = max(1, start_page)
    total_pages: Optional[int] = None

    while True:
        payload = client.browse_records_page(page=page, per_page=per_page)
        items = payload.get("items") or []
        total_pages = payload.get("total_pages") or total_pages

        for item in items:
            if isinstance(item, dict):
                yield item

        if end_page is not None and page >= end_page:
            break
        if total_pages is not None and page >= int(total_pages):
            break
        if not items:
            break

        page += 1


def main() -> int:
    ap = argparse.ArgumentParser(description="Download all LOMS record text exports and bundle them into a ZIP.")
    ap.add_argument("--base-url", default="https://www.loms.cz/jo-db/api/rest", help="JO DB API base URL")
    ap.add_argument("--out", default="all_loms_records.zip", help="Output ZIP file")
    ap.add_argument("--per-page", type=int, default=50, help="Records per page (API max appears to be 50)")
    ap.add_argument("--start-page", type=int, default=1, help="Start page")
    ap.add_argument("--end-page", type=int, default=None, help="Optional end page")
    ap.add_argument("--timeout", type=float, default=30.0, help="HTTP timeout in seconds")
    ap.add_argument("--sleep", type=float, default=0.0, help="Sleep between LOMS record fetches")
    ap.add_argument("--no-verify-tls", action="store_true", help="Disable TLS certificate verification")
    ap.add_argument("--skip-empty", action="store_true", help="Skip records whose LOMS export is empty/blank")
    args = ap.parse_args()

    client = JOClient(
        base_url=args.base_url,
        timeout=args.timeout,
        verify_tls=not args.no_verify_tls,
    )

    manifest: Dict[str, Any] = {
        "base_url": client.base_url,
        "start_page": args.start_page,
        "end_page": args.end_page,
        "per_page": min(50, max(1, args.per_page)),
        "downloaded": [],
        "failed": [],
        "skipped_empty": [],
    }

    seen_ids = set()
    count = 0

    with zipfile.ZipFile(args.out, mode="w", compression=zipfile.ZIP_DEFLATED) as zf:
        for record in iter_all_records(
            client,
            per_page=args.per_page,
            start_page=args.start_page,
            end_page=args.end_page,
        ):
            record_id = record.get("jo_record_id")
            if record_id is None:
                manifest["failed"].append({
                    "record": record,
                    "error": "Missing jo_record_id in record payload",
                })
                continue

            try:
                record_id = int(record_id)
            except Exception:
                manifest["failed"].append({
                    "record_id": record_id,
                    "error": "jo_record_id is not an integer",
                })
                continue

            if record_id in seen_ids:
                continue
            seen_ids.add(record_id)

            try:
                text = client.get_loms_text(record_id)
                if args.skip_empty and not text.strip():
                    manifest["skipped_empty"].append({"record_id": record_id})
                    continue

                filename = _record_filename(record)
                zf.writestr(filename, text)
                manifest["downloaded"].append({
                    "record_id": record_id,
                    "filename": filename,
                    "bytes": len(text.encode("utf-8")),
                })
                count += 1

                if count % 10 == 0:
                    print(f"Downloaded {count} records...", file=sys.stderr)

            except Exception as e:
                manifest["failed"].append({
                    "record_id": record_id,
                    "error": f"{type(e).__name__}: {e}",
                })

            if args.sleep > 0:
                time.sleep(args.sleep)

        manifest["summary"] = {
            "unique_record_ids_seen": len(seen_ids),
            "downloaded_count": len(manifest["downloaded"]),
            "failed_count": len(manifest["failed"]),
            "skipped_empty_count": len(manifest["skipped_empty"]),
        }
        zf.writestr("manifest.json", json.dumps(manifest, ensure_ascii=False, indent=2))

    print(f"Wrote ZIP: {args.out}")
    print(json.dumps(manifest["summary"], indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
