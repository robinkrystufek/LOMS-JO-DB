#!/usr/bin/env python3
"""
jo_elements_treemap.py

Build an interactive elemental treemap from the JO DB REST endpoint:

- rest/elements/?match_records=1
  (collects elements and their matched JO records)

Output:
- jo_elements_treemap.html
  (interactive standalone HTML treemap with hover tooltips and clickable element boxes)

Usage:
  python jo_elements_treemap.py

Visualization details:
- The treemap is grouped by periodic-table group.
- Each group is rendered as a larger parent box containing individual element boxes.
- Hover shows element symbol, periodic-table group, and number of JO records.
- Clicking an element opens the JO DB search page for that element in a new tab.
- Element box area is proportional to the number of matched JO records.
"""

import json
import requests
import squarify
from matplotlib import colors as mcolors

URL = "https://www.loms.cz/jo-db/api/rest/elements/?match_records=1"
OUT_HTML = "jo_elements_treemap.html"

GROUP_MAP = {
    "H": "1", "He": "18",
    "Li": "1", "Be": "2", "B": "13", "C": "14", "N": "15", "O": "16", "F": "17", "Ne": "18",
    "Na": "1", "Mg": "2", "Al": "13", "Si": "14", "P": "15", "S": "16", "Cl": "17", "Ar": "18",

    "K": "1", "Ca": "2",
    "Sc": "TM", "Ti": "TM", "V": "TM", "Cr": "TM", "Mn": "TM", "Fe": "TM", "Co": "TM", "Ni": "TM", "Cu": "TM", "Zn": "TM",
    "Ga": "13", "Ge": "14", "As": "15", "Se": "16", "Br": "17", "Kr": "18",

    "Rb": "1", "Sr": "2",
    "Y": "TM", "Zr": "TM", "Nb": "TM", "Mo": "TM", "Tc": "TM", "Ru": "TM", "Rh": "TM", "Pd": "TM", "Ag": "TM", "Cd": "TM",
    "In": "13", "Sn": "14", "Sb": "15", "Te": "16", "I": "17", "Xe": "18",

    "Cs": "1", "Ba": "2",
    "La": "Ln", "Ce": "Ln", "Pr": "Ln", "Nd": "Ln", "Pm": "Ln", "Sm": "Ln", "Eu": "Ln", "Gd": "Ln", "Tb": "Ln", "Dy": "Ln", "Ho": "Ln", "Er": "Ln", "Tm": "Ln", "Yb": "Ln", "Lu": "Ln",
    "Hf": "TM", "Ta": "TM", "W": "TM", "Re": "TM", "Os": "TM", "Ir": "TM", "Pt": "TM", "Au": "TM", "Hg": "TM",

    "Tl": "13", "Pb": "14", "Bi": "15", "Po": "16", "At": "17", "Rn": "18",

    "Fr": "1", "Ra": "2",
    "Ac": "An", "Th": "An", "Pa": "An", "U": "An", "Np": "An", "Pu": "An", "Am": "An", "Cm": "An", "Bk": "An", "Cf": "An", "Es": "An", "Fm": "An", "Md": "An", "No": "An", "Lr": "An",
    "Rf": "TM", "Db": "TM", "Sg": "TM", "Bh": "TM", "Hs": "TM", "Mt": "TM", "Ds": "TM", "Rg": "TM", "Cn": "TM",

    "Nh": "13", "Fl": "14", "Mc": "15", "Lv": "16", "Ts": "17", "Og": "18",
}

GROUP_ORDER = [str(i) for i in [1, 2, 13, 14, 15, 16, 17, 18]] + ["TM", "Ln", "An"]

GROUP_COLORS = {
    "1": "#e76f51",
    "2": "#f4a261",
    "13": "#b56576",
    "14": "#6d597a",
    "15": "#9c89b8",
    "16": "#84a59d",
    "17": "#f9c74f",
    "18": "#a8dadc",
    "TM": "#5b6c7d",
    "Ln": "#2a9d8f",
    "An": "#264653",
}


W, H = 1600, 650


def group_of(el):
    return GROUP_MAP.get(el, "?")


def hex_to_rgb01(h):
    h = h.lstrip("#")
    return tuple(int(h[i:i+2], 16) / 255.0 for i in (0, 2, 4))


def rgb01_to_hex(rgb):
    return mcolors.to_hex(rgb)


def blend_with_white(hex_color, amount=0.12):
    r, g, b = hex_to_rgb01(hex_color)
    r = r + (1 - r) * amount
    g = g + (1 - g) * amount
    b = b + (1 - b) * amount
    return rgb01_to_hex((r, g, b))


def relative_luminance(rgb):
    def ch(c):
        return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4
    r, g, b = [ch(c) for c in rgb]
    return 0.2126 * r + 0.7152 * g + 0.0722 * b


def text_color_for_bg(hex_color):
    lum = relative_luminance(hex_to_rgb01(hex_color))
    return "#111111" if lum > 0.42 else "#ffffff"


def pct_label(count, total):
    pct = 100 * count / total if total else 0
    if pct >= 10:
        return f"{pct:.0f}%"
    elif pct >= 1:
        return f"{pct:.1f}%"
    else:
        return f"{pct:.2f}%"


def load_data():
    r = requests.get(URL, timeout=30)
    r.raise_for_status()
    data = r.json()

    rows = []
    for item in data:
        symbol = item.get("element", "")
        jo_records = item.get("jo_records", []) or []
        count = len(jo_records)
        if count > 0:
            rows.append({
                "element": symbol,
                "count": count,
                "group": group_of(symbol),
            })
    return rows


def compute_layout(rows):
    grouped = {}
    for r in rows:
        grouped.setdefault(r["group"], []).append(r)

    group_rows = []
    for grp in GROUP_ORDER:
        items = grouped.get(grp, [])
        s = sum(x["count"] for x in items)
        if s > 0:
            group_rows.append({
                "group": grp,
                "count": s,
                "items": sorted(items, key=lambda z: z["count"], reverse=True)
            })
    
    total = sum(r["count"] for r in group_rows)        

    group_rows.sort(key=lambda x: x["count"], reverse=True)

    group_sizes = [g["count"] for g in group_rows]
    group_rects = squarify.squarify(
        squarify.normalize_sizes(group_sizes, W, H), 0, 0, W, H
    )

    layout = []
    for g, grect in zip(group_rows, group_rects):
        gx, gy, gdx, gdy = grect["x"], grect["y"], grect["dx"], grect["dy"]
        base = GROUP_COLORS.get(g["group"], "#9aa0a6")

        pad = 4
        ix, iy = gx + pad, gy + pad
        idx, idy = gdx - 2 * pad, gdy - 2 * pad
        if idx <= 0 or idy <= 0:
            continue

        inner_sizes = [item["count"] for item in g["items"]]
        inner_rects = squarify.squarify(
            squarify.normalize_sizes(inner_sizes, idx, idy), ix, iy, idx, idy
        )

        elements = []
        n = len(g["items"])
        for j, (item, rect) in enumerate(zip(g["items"], inner_rects)):
            x, y, dx, dy = rect["x"], rect["y"], rect["dx"], rect["dy"]
            area = dx * dy

            frac = 0 if n <= 1 else j / max(1, n - 1)
            tile = blend_with_white(base, 0.18 * frac)
            tcolor = text_color_for_bg(tile)

            label_mode = "none"
            if area >= 14000:
                label_mode = "full"
            elif area >= 4000:
                label_mode = "symbol"
            elif area >= 1600:
                label_mode = "tiny"

            elements.append({
                "element": item["element"],
                "count": item["count"],
                "pct": pct_label(item["count"], total),
                "group": g["group"],
                "x": round(x, 2),
                "y": round(y, 2),
                "w": round(dx, 2),
                "h": round(dy, 2),
                "fill": tile,
                "text": tcolor,
                "label_mode": label_mode,
                "url": f"https://www.loms.cz/jo-db/?element_q={item['element']}",
            })

        layout.append({
            "group": g["group"],
            "x": round(gx, 2),
            "y": round(gy, 2),
            "w": round(gdx, 2),
            "h": round(gdy, 2),
            "border": "#ffffff",
            "elements": elements,
        })

    return layout


def make_html(layout):
    payload = json.dumps(layout, ensure_ascii=False)

    return f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>JO DB elemental breakdown</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  html, body {{
    margin: 0;
    height: 100%;
    background: #fff;
    font-family: Arial, Helvetica, sans-serif;
  }}
  #chart {{
    border: 1px solid #000;
    
  }}
  .wrap {{
    transform: translateY(88px);
    width: 95%;
    box-sizing: border-box;
    margin: auto;
  }}
  svg {{
    display: block;
    background: #fff;
  }}
  .group-border {{
    fill: none;
    stroke: #fff;
    stroke-width: 3;
    pointer-events: none;
  }}
  .tile {{
    stroke: #fff;
    stroke-width: 2;
    cursor: pointer;
    transition: filter 120ms ease, opacity 120ms ease;
  }}
  .tile:hover {{
    filter: brightness(0.96);
  }}
  .label {{
    pointer-events: none;
    text-anchor: middle;
    dominant-baseline: middle;
    font-weight: 500;
  }}
  .tooltip {{
    position: fixed;
    z-index: 99;
    pointer-events: none;
    background: rgb(0, 123, 255);
    color: white;
    padding: 8px 10px;
    border-radius: 8px;
    font-size: 13px;
    line-height: 1.35;
    box-shadow: 0 4px 20px rgba(0,0,0,0.25);
    display: none;
    white-space: nowrap;
  }}
  .tooltip.tip-right {{
    transform: translate(12px, 12px);
  }}
  .tooltip.tip-left {{
    transform: translate(calc(-100% - 12px), 12px);
  }}
  .title {{
    position: fixed;
    left: 50%;
    transform: translateX(-50%);
    top: 30px;
    z-index: 10;
    opacity: 1;
    font-family: Arial;
    font-size: 20px;
    fill: rgb(0, 0, 0);
    fill-opacity: 1;
    font-weight: normal;
    font-style: normal;
    font-variant: normal;
    white-space: pre;
    pointer-events: none;
    text-align: center;
  }}
</style>
</head>
<body>
<div class="title">JO DB elemental breakdown</div>
<div class="wrap">
  <svg id="chart" viewBox="0 0 {W} {H}" preserveAspectRatio="xMidYMid meet"></svg>
</div>
<div id="tooltip" class="tooltip"></div>

<script>
const data = {payload};
const svg = document.getElementById('chart');
const tip = document.getElementById('tooltip');
const NS = "http://www.w3.org/2000/svg";

function el(name, attrs = {{}}, text=null) {{
  const n = document.createElementNS(NS, name);
  for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v);
  if (text !== null) n.textContent = text;
  return n;
}}

function showTip(evt, d) {{
  tip.innerHTML = `<b>${{d.element}}</b><br>Group ${{d.group}}<br>${{d.count}} records`;
  tip.style.display = 'block';
  moveTip(evt);
}}

function moveTip(evt) {{
  const margin = 12;

  tip.style.left = evt.clientX + 'px';
  tip.style.top = evt.clientY + 'px';

  const rect = tip.getBoundingClientRect();
  const wouldOverflowRight = evt.clientX + 12 + rect.width > window.innerWidth - margin;

  tip.classList.remove('tip-left', 'tip-right');
  tip.classList.add(wouldOverflowRight ? 'tip-left' : 'tip-right');
}}

function hideTip() {{
  tip.style.display = 'none';
}}

function fontSizeForBox(w, h, mode) {{
  const m = Math.min(w, h);
  if (mode === 'full') return Math.max(14, Math.min(28, m * 0.18));
  if (mode === 'symbol') return Math.max(11, Math.min(22, m * 0.22));
  return Math.max(9, Math.min(16, m * 0.24));
}}

for (const g of data) {{
  svg.appendChild(el('rect', {{
    x: g.x, y: g.y, width: g.w, height: g.h, class: 'group-border'
  }}));

  for (const d of g.elements) {{
    const rect = el('rect', {{
      x: d.x, y: d.y, width: d.w, height: d.h,
      fill: d.fill, class: 'tile',
      'data-url': d.url
    }});

    rect.addEventListener('mousemove', (evt) => {{
      showTip(evt, d);
    }});
    rect.addEventListener('mouseleave', hideTip);
    rect.addEventListener('click', () => {{
      window.open(d.url, '_blank', 'noopener');
    }});

    svg.appendChild(rect);

    if (d.label_mode !== 'none') {{
      const fs = fontSizeForBox(d.w, d.h, d.label_mode);
      const cx = d.x + d.w / 2;
      const cy = d.y + d.h / 2;

      const text = el('text', {{
        x: cx,
        y: cy,
        fill: d.text,
        class: 'label',
        'font-size': fs
      }});

      if (d.label_mode === 'full') {{
        const t1 = el('tspan', {{ x: cx, dy: '-0.55em' }}, d.element);
        const t2 = el('tspan', {{ x: cx, dy: '1.15em' }}, d.pct);
        text.appendChild(t1);
        text.appendChild(t2);
      }} else {{
        text.textContent = d.element;
      }}

      svg.appendChild(text);
    }}
  }}
}}
</script>
</body>
</html>
"""


def main():
    rows = load_data()
    layout = compute_layout(rows)
    html_text = make_html(layout)
    with open(OUT_HTML, "w", encoding="utf-8") as f:
        f.write(html_text)
    print(f"Wrote {OUT_HTML}")


if __name__ == "__main__":
    main()