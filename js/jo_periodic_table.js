(() => {
  if (window.pickElement && window.renderPeriodicTable && window.__periodicPickerInjected) return;
  window.__periodicPickerInjected = true;
  const ELEMENTS = [
    {z:1,s:"H",n:"Hydrogen",g:1,p:1,c:"nonmetal"},{z:2,s:"He",n:"Helium",g:18,p:1,c:"noble"},
    {z:3,s:"Li",n:"Lithium",g:1,p:2,c:"alkali"},{z:4,s:"Be",n:"Beryllium",g:2,p:2,c:"alkaline"},
    {z:5,s:"B",n:"Boron",g:13,p:2,c:"metalloid"},{z:6,s:"C",n:"Carbon",g:14,p:2,c:"nonmetal"},
    {z:7,s:"N",n:"Nitrogen",g:15,p:2,c:"nonmetal"},{z:8,s:"O",n:"Oxygen",g:16,p:2,c:"nonmetal"},
    {z:9,s:"F",n:"Fluorine",g:17,p:2,c:"halogen"},{z:10,s:"Ne",n:"Neon",g:18,p:2,c:"noble"},
    {z:11,s:"Na",n:"Sodium",g:1,p:3,c:"alkali"},{z:12,s:"Mg",n:"Magnesium",g:2,p:3,c:"alkaline"},
    {z:13,s:"Al",n:"Aluminum",g:13,p:3,c:"post"},{z:14,s:"Si",n:"Silicon",g:14,p:3,c:"metalloid"},
    {z:15,s:"P",n:"Phosphorus",g:15,p:3,c:"nonmetal"},{z:16,s:"S",n:"Sulfur",g:16,p:3,c:"nonmetal"},
    {z:17,s:"Cl",n:"Chlorine",g:17,p:3,c:"halogen"},{z:18,s:"Ar",n:"Argon",g:18,p:3,c:"noble"},
    {z:19,s:"K",n:"Potassium",g:1,p:4,c:"alkali"},{z:20,s:"Ca",n:"Calcium",g:2,p:4,c:"alkaline"},
    {z:21,s:"Sc",n:"Scandium",g:3,p:4,c:"transition"},{z:22,s:"Ti",n:"Titanium",g:4,p:4,c:"transition"},
    {z:23,s:"V",n:"Vanadium",g:5,p:4,c:"transition"},{z:24,s:"Cr",n:"Chromium",g:6,p:4,c:"transition"},
    {z:25,s:"Mn",n:"Manganese",g:7,p:4,c:"transition"},{z:26,s:"Fe",n:"Iron",g:8,p:4,c:"transition"},
    {z:27,s:"Co",n:"Cobalt",g:9,p:4,c:"transition"},{z:28,s:"Ni",n:"Nickel",g:10,p:4,c:"transition"},
    {z:29,s:"Cu",n:"Copper",g:11,p:4,c:"transition"},{z:30,s:"Zn",n:"Zinc",g:12,p:4,c:"transition"},
    {z:31,s:"Ga",n:"Gallium",g:13,p:4,c:"post"},{z:32,s:"Ge",n:"Germanium",g:14,p:4,c:"metalloid"},
    {z:33,s:"As",n:"Arsenic",g:15,p:4,c:"metalloid"},{z:34,s:"Se",n:"Selenium",g:16,p:4,c:"nonmetal"},
    {z:35,s:"Br",n:"Bromine",g:17,p:4,c:"halogen"},{z:36,s:"Kr",n:"Krypton",g:18,p:4,c:"noble"},
    {z:37,s:"Rb",n:"Rubidium",g:1,p:5,c:"alkali"},{z:38,s:"Sr",n:"Strontium",g:2,p:5,c:"alkaline"},
    {z:39,s:"Y",n:"Yttrium",g:3,p:5,c:"transition"},{z:40,s:"Zr",n:"Zirconium",g:4,p:5,c:"transition"},
    {z:41,s:"Nb",n:"Niobium",g:5,p:5,c:"transition"},{z:42,s:"Mo",n:"Molybdenum",g:6,p:5,c:"transition"},
    {z:43,s:"Tc",n:"Technetium",g:7,p:5,c:"transition"},{z:44,s:"Ru",n:"Ruthenium",g:8,p:5,c:"transition"},
    {z:45,s:"Rh",n:"Rhodium",g:9,p:5,c:"transition"},{z:46,s:"Pd",n:"Palladium",g:10,p:5,c:"transition"},
    {z:47,s:"Ag",n:"Silver",g:11,p:5,c:"transition"},{z:48,s:"Cd",n:"Cadmium",g:12,p:5,c:"transition"},
    {z:49,s:"In",n:"Indium",g:13,p:5,c:"post"},{z:50,s:"Sn",n:"Tin",g:14,p:5,c:"post"},
    {z:51,s:"Sb",n:"Antimony",g:15,p:5,c:"metalloid"},{z:52,s:"Te",n:"Tellurium",g:16,p:5,c:"metalloid"},
    {z:53,s:"I",n:"Iodine",g:17,p:5,c:"halogen"},{z:54,s:"Xe",n:"Xenon",g:18,p:5,c:"noble"},
    {z:55,s:"Cs",n:"Cesium",g:1,p:6,c:"alkali"},{z:56,s:"Ba",n:"Barium",g:2,p:6,c:"alkaline"},
    {z:57,s:"La",n:"Lanthanum",g:3,p:6,c:"lanth"},
    {z:72,s:"Hf",n:"Hafnium",g:4,p:6,c:"transition"},{z:73,s:"Ta",n:"Tantalum",g:5,p:6,c:"transition"},
    {z:74,s:"W",n:"Tungsten",g:6,p:6,c:"transition"},{z:75,s:"Re",n:"Rhenium",g:7,p:6,c:"transition"},
    {z:76,s:"Os",n:"Osmium",g:8,p:6,c:"transition"},{z:77,s:"Ir",n:"Iridium",g:9,p:6,c:"transition"},
    {z:78,s:"Pt",n:"Platinum",g:10,p:6,c:"transition"},{z:79,s:"Au",n:"Gold",g:11,p:6,c:"transition"},
    {z:80,s:"Hg",n:"Mercury",g:12,p:6,c:"transition"},{z:81,s:"Tl",n:"Thallium",g:13,p:6,c:"post"},
    {z:82,s:"Pb",n:"Lead",g:14,p:6,c:"post"},{z:83,s:"Bi",n:"Bismuth",g:15,p:6,c:"post"},
    {z:84,s:"Po",n:"Polonium",g:16,p:6,c:"post"},{z:85,s:"At",n:"Astatine",g:17,p:6,c:"halogen"},
    {z:86,s:"Rn",n:"Radon",g:18,p:6,c:"noble"},
    {z:87,s:"Fr",n:"Francium",g:1,p:7,c:"alkali"},{z:88,s:"Ra",n:"Radium",g:2,p:7,c:"alkaline"},
    {z:89,s:"Ac",n:"Actinium",g:3,p:7,c:"act"},
    {z:104,s:"Rf",n:"Rutherfordium",g:4,p:7,c:"transition"},{z:105,s:"Db",n:"Dubnium",g:5,p:7,c:"transition"},
    {z:106,s:"Sg",n:"Seaborgium",g:6,p:7,c:"transition"},{z:107,s:"Bh",n:"Bohrium",g:7,p:7,c:"transition"},
    {z:108,s:"Hs",n:"Hassium",g:8,p:7,c:"transition"},{z:109,s:"Mt",n:"Meitnerium",g:9,p:7,c:"transition"},
    {z:110,s:"Ds",n:"Darmstadtium",g:10,p:7,c:"transition"},{z:111,s:"Rg",n:"Roentgenium",g:11,p:7,c:"transition"},
    {z:112,s:"Cn",n:"Copernicium",g:12,p:7,c:"transition"},{z:113,s:"Nh",n:"Nihonium",g:13,p:7,c:"post"},
    {z:114,s:"Fl",n:"Flerovium",g:14,p:7,c:"post"},{z:115,s:"Mc",n:"Moscovium",g:15,p:7,c:"post"},
    {z:116,s:"Lv",n:"Livermorium",g:16,p:7,c:"post"},{z:117,s:"Ts",n:"Tennessine",g:17,p:7,c:"halogen"},
    {z:118,s:"Og",n:"Oganesson",g:18,p:7,c:"noble"},
  ];
  const LANTH = [
    {z:58,s:"Ce",n:"Cerium"},{z:59,s:"Pr",n:"Praseodymium"},{z:60,s:"Nd",n:"Neodymium"},
    {z:61,s:"Pm",n:"Promethium"},{z:62,s:"Sm",n:"Samarium"},{z:63,s:"Eu",n:"Europium"},
    {z:64,s:"Gd",n:"Gadolinium"},{z:65,s:"Tb",n:"Terbium"},{z:66,s:"Dy",n:"Dysprosium"},
    {z:67,s:"Ho",n:"Holmium"},{z:68,s:"Er",n:"Erbium"},{z:69,s:"Tm",n:"Thulium"},
    {z:70,s:"Yb",n:"Ytterbium"},{z:71,s:"Lu",n:"Lutetium"},
  ].map(e => ({...e, c:"lanth"}));
  const ACT = [
    {z:90,s:"Th",n:"Thorium"},{z:91,s:"Pa",n:"Protactinium"},{z:92,s:"U",n:"Uranium"},
    {z:93,s:"Np",n:"Neptunium"},{z:94,s:"Pu",n:"Plutonium"},{z:95,s:"Am",n:"Americium"},
    {z:96,s:"Cm",n:"Curium"},{z:97,s:"Bk",n:"Berkelium"},{z:98,s:"Cf",n:"Californium"},
    {z:99,s:"Es",n:"Einsteinium"},{z:100,s:"Fm",n:"Fermium"},{z:101,s:"Md",n:"Mendelevium"},
    {z:102,s:"No",n:"Nobelium"},{z:103,s:"Lr",n:"Lawrencium"},
  ].map(e => ({...e, c:"act"}));
  const CATEGORY_STYLE = {
    alkali:      "background:#ffefef;border-color:#ff9a9a;",
    alkaline:    "background:#fff6e7;border-color:#ffc27a;",
    transition:  "background:#eef7ff;border-color:#8bc6ff;",
    post:        "background:#f2f0ff;border-color:#b4a7ff;",
    metalloid:   "background:#f0fff4;border-color:#86d39b;",
    nonmetal:    "background:#f7fff0;border-color:#b9e07b;",
    halogen:     "background:#f0fffd;border-color:#69d7ce;",
    noble:       "background:#f5f5f5;border-color:#bfbfbf;",
    lanth:       "background:#fff0fb;border-color:#ff9fe6;",
    act:         "background:#fff0f0;border-color:#ffb0b0;",
    unknown:     "background:#ffffff;border-color:#d0d0d0;",
  };
  function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === "style") node.style.cssText = v;
      else if (k.startsWith("on") && typeof v === "function") node.addEventListener(k.slice(2), v);
      else if (v !== undefined && v !== null) node.setAttribute(k, String(v));
    }
    for (const ch of (Array.isArray(children) ? children : [children])) {
      node.append(ch?.nodeType ? ch : document.createTextNode(String(ch)));
    }
    return node;
  }
  function categoryFor(e) {
    return e.c || "unknown";
  }
  let lastSymbol = "";
  function normalizePicked(e) {
    if(e.s === lastSymbol) {
      lastSymbol = "";
      return { atomicNumber: 0, symbol: "", name: "" };
    }
    else {
      lastSymbol = e.s;
      return { atomicNumber: e.z, symbol: e.s, name: e.n };
    }
  }
  function createTableUI({
    onPick,
    storeToWindow = true,
    compact = false,
    tileMode = "full" // "full" | "symbol"
  } = {}) {
    const cell = compact ? 36 : 44;
    const root = el("div", { style: `
      --pt-cell: ${cell}px;
      --pt-gap: 6px;
      display:flex; flex-direction:column; gap:12px;
      font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
    `});
    const header = el("div", { style: `
      display:flex; align-items:center; gap:10px; flex-wrap:wrap;
    `});
    const title = el("div", { style: "font-weight:800; font-size:14px;" }, "Periodic table");
    const search = el("input", { type:"search", placeholder:"Search (symbol or name)…", style: `
      padding:8px 10px;
      border:1px solid #ddd; border-radius:3px; outline:none;
      width:${compact ? "min(320px, 100%)" : "min(420px, 100%)"};
    `});
    const status = el("div", { style: `
      font-size:12px; color:#333;
      padding:8px 10px;
      border:1px solid #eee; border-radius:3px;
      background:#fafafa;
      flex:1;
      min-width:260px;
    `}, "Click an element to select it.");
    header.append(title, search, status);
    const grid = el("div", { style: `
      display:grid;
      grid-template-columns: repeat(18, minmax(var(--pt-cell), 1fr));
      grid-template-rows: repeat(7, ${compact ? 31 : 54}px);
      gap: var(--pt-gap);
      align-items:stretch;
    `});
    let highlightedSymbol = null;
    function setHighlightedSymbol(symbol) {
      highlightedSymbol = symbol ? symbol.trim().toLowerCase() : null;
      const buttons = root.querySelectorAll('button[data-s]');
      buttons.forEach(btn => {
        const s = btn.getAttribute("data-s").toLowerCase();
        if (highlightedSymbol && s === highlightedSymbol) {
          btn.classList.add("pt-highlight");
        } 
        else {
          btn.classList.remove("pt-highlight");
        }
      });
    }
    function elementButton(e) {
      const cat = categoryFor(e);
      const btn = el("button", {
        type: "button",
        "data-z": e.z,
        "data-s": e.s,
        "data-n": e.n,
        style: `
          position:relative;
          display:flex; flex-direction:column;
          align-items:flex-start; justify-content:space-between;
          padding:${compact ? "5px 6px" : "6px 7px"};
          border:2px solid #ddd;
          border-radius:3px;
          cursor:pointer;
          min-height:${tileMode === "symbol" ? (compact ? 31 : 38) : (compact ? 46 : 54)}px;
          user-select:none;
          ${CATEGORY_STYLE[cat] || CATEGORY_STYLE.unknown}
        `,
        onclick: () => {
          const picked = normalizePicked(e);
          if (storeToWindow) window.__pickedElement = picked;
          if(picked.symbol == "")  status.textContent = "Click an element to select it.";
          else status.textContent = `Selected: ${picked.name} (${picked.symbol}), Z=${picked.atomicNumber}`;
          setHighlightedSymbol(picked.symbol)
          if (typeof onPick === "function") onPick(picked);
        }
      });
      btn.addEventListener("mouseenter", () => { 
        if(e.s == "") status.textContent = "Click an element to select it.";
        else status.textContent = `${e.n} (${e.s}), Z=${e.z}`; 
      });
      btn.addEventListener("mouseleave", () => { status.textContent = "Click an element to select it."; });
      if (tileMode === "symbol") {
        const H = compact ? 31 : 38;
        btn.style.height = H + "px";
        btn.style.minHeight = H + "px";
        btn.style.maxHeight = H + "px";
        btn.style.padding = "0";
        btn.style.display = "block";
        btn.append(el("div", { style: `
          position:absolute;
          top:3px; left:5px;
          font-size:${compact ? 7 : 10}px;
          opacity:.65;
          line-height:1;
          pointer-events:none;
        `}, String(e.z)));
        btn.append(el("div", { style: `
          position:absolute;
          inset:0;
          display:flex;
          align-items:center;
          justify-content:center;
          font-weight:900;
          font-size:${compact ? 12 : 14}px;
          line-height:1;
          text-align:center;
          pointer-events:none;
        `}, e.s));
        return btn;
      }
      btn.append(
        el("div", { style: `font-size:${compact ? 10 : 9}px; opacity:.75;` }, String(e.z)),
        el("div", { style: `font-weight:800; font-size:${compact ? 13 : 14}px; line-height:1;` }, e.s),
        el("div", { style: `
          font-size:${compact ? 9 : 9}px; opacity:.8;
          white-space:nowrap; overflow:hidden; text-overflow:ellipsis; width:100%; text-align:left;
        `}, e.n)
      );
      return btn;
    }
    function placeMainTable() {
      grid.innerHTML = "";
      for (const e of ELEMENTS) {
        const btn = elementButton(e);
        btn.style.gridColumn = String(e.g);
        btn.style.gridRow = String(e.p);
        grid.append(btn);
      }
    }
    const fblockWrap = el("div", { style: "display:flex; flex-direction:column; gap:8px;" });
    function placeFBlockRow(titleText, items) {
      const row = el("div", { style: `
        display:grid;
        grid-template-columns: repeat(18, minmax(var(--pt-cell), 1fr));
        gap: var(--pt-gap);
        align-items:stretch;
      `});
      row.append(el("div", { style: `
        grid-column: span 3;
        font-size:12px; color:#333;
        padding:0px 10px;
        border:1px solid #eee;
        border-radius:3px;
        background:#f8f9fa;
        display:flex; align-items:center;
      `}, titleText));    
      for (const e of items) row.append(elementButton(e));
      fblockWrap.append(row);
    }
    function setFilter(q) {
      const query = (q || "").trim().toLowerCase();
      const buttons = root.querySelectorAll('button[data-z]');
      if (!query) {
        buttons.forEach(b => { b.style.outline=""; b.style.opacity="1"; b.disabled=false; });
        return;
      }
      buttons.forEach(b => {
        const s = (b.getAttribute("data-s") || "").toLowerCase();
        const n = (b.getAttribute("data-n") || "").toLowerCase();
        const hit = (s === query) || n.includes(query);
        b.style.opacity = hit ? "1" : "0.25";
        b.disabled = !hit;
        b.style.outline = hit ? "2px solid rgba(0,0,0,.25)" : "";
      });
    }
    search.addEventListener("input", () => setFilter(search.value));
    search.addEventListener("keydown", (ev) => {
      if (ev.key === "Enter") {
        const enabled = [...root.querySelectorAll('button[data-z]')].filter(b => !b.disabled);
        if (enabled.length === 1) enabled[0].click();
      }
    });
    placeMainTable();
    placeFBlockRow("Lanthanides (57–71)", LANTH);
    placeFBlockRow("Actinides (89–103)", ACT);
    root.append(header, grid, fblockWrap);
    if (compact) {
      const controls = el("div", { style: `
        grid-row: 1 / span 1;
        grid-column: 2 / span 11;
        display:flex;
        align-items:flex-start;
      `});
      search.style.width = "100%";
      search.style.height = "100%";
      const statusBox = el("div", { style: `
        grid-row: 1 / span 1;
        grid-column: 13 / span 5;
        display:flex;
        align-items:flex-start;
      `});
      status.style.width = "100%";
      status.style.minWidth = "100%";
      status.style.height = "100%";
      status.style.alignItems = "center";
      status.style.display = "flex";
      controls.append(search);
      statusBox.append(status);
      header.remove();
      grid.append(controls, statusBox);
    }
    return {
      root,
      setFilter,
      setHighlightedSymbol,
      destroy: () => root.remove(),
      focusSearch: () => search.focus(),
      reset: () => {
        lastSymbol = "";
        if (storeToWindow) {
          window.__pickedElement = { atomicNumber: 0, symbol: "", name: "" };
        }
        setHighlightedSymbol("");
        status.textContent = "Click an element to select it.";
      }
    };
  }
  function buildOverlay(resolve, reject) {
    const overlay = el("div", { style: `
      position:fixed; inset:0; z-index:2147483647;
      background:rgba(0,0,0,.45);
      display:flex; align-items:center; justify-content:center;
      font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
    `});
    const panel = el("div", { style: `
      width:min(1200px, calc(100vw - 24px));
      height:min(720px, calc(100vh - 24px));
      background:#fff;
      border-radius:6px;
      box-shadow:0 20px 60px rgba(0,0,0,.35);
      display:flex;
      flex-direction:column;
      overflow:hidden;
    `});
    const top = el("div", { style: `
      padding:10px 12px;
      border-bottom:1px solid #eee;
      display:flex; align-items:center; gap:10px;
    `}, [
      el("div", { style: "font-weight:800; font-size:14px;" }, "Pick an element"),
      el("div", { style: "margin-left:auto;" }, [
        el("button", {
          style: `
            padding:8px 10px;
            border:1px solid #ddd;
            border-radius:3px;
            background:#fafafa;
            cursor:pointer;
          `,
          onclick: () => close(false)
        }, "Close (Esc)")
      ])
    ]);
    const content = el("div", { style: "padding:12px; overflow:auto;" });
    const ui = createTableUI({
      onPick: (picked) => close(true, picked),
      storeToWindow: true,
      compact: false
    });
    content.append(ui.root);
    panel.append(top, content);
    overlay.append(panel);
    document.body.append(overlay);
    function onKey(ev) {
      if (ev.key === "Escape") close(false);
    }
    document.addEventListener("keydown", onKey);
    ui.focusSearch();
    function close(success, value) {
      document.removeEventListener("keydown", onKey);
      overlay.remove();
      if (success) resolve(value);
      else reject(new Error("Element picker closed"));
    }
  }
  window.pickElement = function pickElement() {
    return new Promise((resolve, reject) => buildOverlay(resolve, reject));
  };
  window.renderPeriodicTable = function renderPeriodicTable(container, options = {}) {
    const target = (typeof container === "string")
      ? document.querySelector(container)
      : container;
    if (!target) throw new Error("renderPeriodicTable: container not found");
    const {
      replace = false,
      onPick,
      storeToWindow = true,
      compact = false,
      tileMode = "full"
    } = options;
    if (replace) {
      target.querySelectorAll(":scope > [data-periodic-table-root]").forEach(n => n.remove());
    }
    const ui = createTableUI({ onPick, storeToWindow, compact, tileMode });
    ui.root.setAttribute("data-periodic-table-root", "1");
    target.append(ui.root);
    return { ...ui, container: target, destroy: () => ui.root.remove() };
  };
  window.destroyPeriodicTable = function destroyPeriodicTable(arg) {
    if (arg && arg.root && arg.root.nodeType === 1) {
      arg.root.remove();
      return true;
    }
    const target = (typeof arg === "string") ? document.querySelector(arg) : arg;
    if (!target) return false;
    const nodes = target.querySelectorAll(":scope > [data-periodic-table-root]");
    nodes.forEach(n => n.remove());
    return nodes.length > 0;
  };
  window.resetPeriodicTable = () => table.reset();
})();
const table = renderPeriodicTable("#filters-render-slot", {
  compact: true,
  tileMode: "symbol",
  onPick: el => { document.getElementById('filter-composition-element').value = el.symbol; document.getElementById('btn-search').click(); console.log('Picked element:', el); }
});
document.getElementById("filter-composition-text").addEventListener("input", e => {
  table.setHighlightedSymbol(e.target.value);
});
