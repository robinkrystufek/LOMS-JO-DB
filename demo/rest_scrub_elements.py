#!/usr/bin/env python3
import requests
import matplotlib.pyplot as plt
import squarify
from matplotlib.patches import Rectangle
from matplotlib import colors as mcolors

URL = "https://www.loms.cz/jo-db/api/rest/elements/?match_records=1"

GROUP_MAP = {
    "H": "1", "He": "18",
    "Li": "1", "Be": "2", "B": "13", "C": "14", "N": "15", "O": "16", "F": "17", "Ne": "18",
    "Na": "1", "Mg": "2", "Al": "13", "Si": "14", "P": "15", "S": "16", "Cl": "17", "Ar": "18",
    "K": "1", "Ca": "2", "Sc": "3", "Ti": "4", "V": "5", "Cr": "6", "Mn": "7", "Fe": "8", "Co": "9", "Ni": "10", "Cu": "11", "Zn": "12",
    "Ga": "13", "Ge": "14", "As": "15", "Se": "16", "Br": "17", "Kr": "18",
    "Rb": "1", "Sr": "2", "Y": "3", "Zr": "4", "Nb": "5", "Mo": "6", "Tc": "7", "Ru": "8", "Rh": "9", "Pd": "10", "Ag": "11", "Cd": "12",
    "In": "13", "Sn": "14", "Sb": "15", "Te": "16", "I": "17", "Xe": "18",
    "Cs": "1", "Ba": "2",
    "La": "Ln", "Ce": "Ln", "Pr": "Ln", "Nd": "Ln", "Pm": "Ln", "Sm": "Ln", "Eu": "Ln", "Gd": "Ln", "Tb": "Ln", "Dy": "Ln", "Ho": "Ln", "Er": "Ln", "Tm": "Ln", "Yb": "Ln", "Lu": "Ln",
    "Hf": "4", "Ta": "5", "W": "6", "Re": "7", "Os": "8", "Ir": "9", "Pt": "10", "Au": "11", "Hg": "12",
    "Tl": "13", "Pb": "14", "Bi": "15", "Po": "16", "At": "17", "Rn": "18",
    "Fr": "1", "Ra": "2",
    "Ac": "An", "Th": "An", "Pa": "An", "U": "An", "Np": "An", "Pu": "An", "Am": "An", "Cm": "An", "Bk": "An", "Cf": "An", "Es": "An", "Fm": "An", "Md": "An", "No": "An", "Lr": "An",
    "Rf": "4", "Db": "5", "Sg": "6", "Bh": "7", "Hs": "8", "Mt": "9", "Ds": "10", "Rg": "11", "Cn": "12",
    "Nh": "13", "Fl": "14", "Mc": "15", "Lv": "16", "Ts": "17", "Og": "18",
}

GROUP_ORDER = [str(i) for i in range(1, 19)] + ["Ln", "An"]

GROUP_COLORS = {
    "1": "#e76f51",  "2": "#f4a261",  "3": "#e9c46a",  "4": "#90be6d",  "5": "#43aa8b",
    "6": "#2a9d8f",  "7": "#4d908e",  "8": "#577590",  "9": "#277da1",  "10": "#4d6cfa",
    "11": "#7b6dce", "12": "#8e7dbe", "13": "#b56576", "14": "#6d597a", "15": "#9c89b8",
    "16": "#84a59d", "17": "#f9c74f", "18": "#a8dadc", "Ln": "#2a9d8f", "An": "#264653",
}

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
    return "#111111" if lum > 0.42 else "white"

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

def draw_text(ax, x, y, dx, dy, text, text_color, box_color, size):
    ax.text(
        x + dx / 2, y + dy / 2, text,
        ha="center", va="center",
        fontsize=size, color=text_color, fontweight="medium",
        linespacing=1.05,
        bbox=dict(
            boxstyle="round,pad=0.16",
            facecolor=box_color,
            edgecolor="none"
        )
    )

def plot_grouped_treemap(rows):
    total = sum(r["count"] for r in rows)

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

    group_rows.sort(key=lambda x: x["count"], reverse=True)

    W, H = 100, 56
    group_sizes = [g["count"] for g in group_rows]
    group_rects = squarify.squarify(
        squarify.normalize_sizes(group_sizes, W, H), 0, 0, W, H
    )

    fig, ax = plt.subplots(figsize=(16, 9))
    fig.patch.set_facecolor("#f4f4f4")
    ax.set_facecolor("#f4f4f4")

    for g, grect in zip(group_rows, group_rects):
        gx, gy, gdx, gdy = grect["x"], grect["y"], grect["dx"], grect["dy"]
        base = GROUP_COLORS.get(g["group"], "#9aa0a6")

        ax.add_patch(Rectangle(
            (gx, gy), gdx, gdy,
            facecolor="none",
            edgecolor="white",
            linewidth=3
        ))

        pad = 0.20
        ix, iy = gx + pad, gy + pad
        idx, idy = gdx - 2 * pad, gdy - 2 * pad
        if idx <= 0 or idy <= 0:
            continue

        inner_sizes = [item["count"] for item in g["items"]]
        inner_rects = squarify.squarify(
            squarify.normalize_sizes(inner_sizes, idx, idy), ix, iy, idx, idy
        )

        n = len(g["items"])
        for j, (item, rect) in enumerate(zip(g["items"], inner_rects)):
            x, y, dx, dy = rect["x"], rect["y"], rect["dx"], rect["dy"]
            area = dx * dy

            frac = 0 if n <= 1 else j / max(1, n - 1)
            tile = blend_with_white(base, 0.18 * frac)
            tcolor = text_color_for_bg(tile)

            ax.add_patch(Rectangle(
                (x, y), dx, dy,
                facecolor=tile,
                edgecolor="white",
                linewidth=2
            ))

            if area >= 40:
                fs = max(8, min(16, 0.18 * min(dx, dy) + 5))
                draw_text(ax, x, y, dx, dy, f"{item['element']}\n{pct_label(item['count'], total)}", tcolor, tile, fs)
            elif area >= 15:
                fs = max(7, min(12, 0.16 * min(dx, dy) + 4))
                draw_text(ax, x, y, dx, dy, item["element"], tcolor, tile, fs)
            elif area >= 8:
                ax.text(
                    x + dx / 2, y + dy / 2, item["element"],
                    ha="center", va="center",
                    fontsize=6.5, color=tcolor, fontweight="medium",
                    bbox=dict(
                        boxstyle="round,pad=0.10",
                        facecolor=tile,
                        edgecolor="none"
                    )
                )

    ax.set_xlim(0, W)
    ax.set_ylim(0, H)
    ax.invert_yaxis()
    ax.axis("off")
    ax.set_title(f"JO DB Elemental breakdown", fontsize=28, pad=18)

    plt.tight_layout()
    plt.show()

if __name__ == "__main__":
    rows = load_data()
    plot_grouped_treemap(rows)