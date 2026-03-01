(() => {
  const subMap = { '0':'₀','1':'₁','2':'₂','3':'₃','4':'₄','5':'₅','6':'₆','7':'₇','8':'₈','9':'₉' };
  const revSubMap = { '₀':'0','₁':'1','₂':'2','₃':'3','₄':'4','₅':'5','₆':'6','₇':'7','₈':'8','₉':'9' };
  const lookupCache = new Map();
  const inFlightByInput = new WeakMap();
  const debounceTimers = new WeakMap();
  const lastQByInput = new WeakMap();

  function toSubscripts(str) { 
    return str.replace(/[0-9]/g, d => subMap[d] || d); 
  }
  function fromSubscripts(str) { 
    return str.replace(/[₀-₉]/g, s => revSubMap[s] || s); 
  }
  function toggleSubscripts(str){
    return (/[₀-₉]/.test(str)) ? fromSubscripts(str) : toSubscripts(str);
  }
  function applyToggleToInputSelection(input) {
    const start = input.selectionStart ?? 0;
    const end   = input.selectionEnd ?? 0;
    if (end > start){
      const before = input.value.slice(0, start);
      const sel = input.value.slice(start, end);
      const after = input.value.slice(end);
      const out = toggleSubscripts(sel);
      input.value = before + out + after;
      input.selectionStart = start;
      input.selectionEnd = start + out.length;
      input.focus();
      return;
    }
    input.value = toggleSubscripts(input.value);
    input.focus();
    input.selectionStart = input.selectionEnd = input.value.length;
  }
  function addElementPT(input) {
    window.pickElement()
    .then(e => {
      if (e) {
        const end = input.selectionEnd ?? 0;
        const before = input.value.slice(0, end);
        const after = input.value.slice(end);
        input.value = before + e.symbol + after;
        const pos = end + e.symbol.length;
        input.selectionStart = input.selectionEnd = pos;
        input.focus();
      }
    })
    .catch(() => {});
  }
  function normalizeQ(q) {
    return String(q ?? '').trim();
  }
  function setStatus(el, state, title, cid = null) {
    if (!el) return;
    el.className = "doi-status is-visible";
    if (title) el.title = title;
    else el.removeAttribute("title");
    el.onclick = null;
    if (state === "idle") {
      el.className = "doi-status";
      el.innerHTML = "";
      el.style.setProperty("pointer-events", "none");
      el.style.setProperty("cursor", "default");
      return;
    }
    if (state === "loading") {
      el.classList.add("is-loading");
      el.innerHTML = "<i class='bx bx-loader-alt'></i>";
      el.style.setProperty("pointer-events", "all");
      el.style.setProperty("cursor", "default");
      return;
    }
    if (state === "parseable") {
      el.classList.add("is-parseable");
      el.innerHTML = "<i class='bx bx-check'></i>";
      el.style.setProperty("pointer-events", "all");
      el.style.setProperty("cursor", "default");
      return;
    }
    if (state === "success") {
      el.classList.add("is-success");
      el.innerHTML = "<i class='bx bx-check'></i>";
      if (cid) el.onclick = () => window.open('https://pubchem.ncbi.nlm.nih.gov/compound/' + cid, '_blank');
      el.style.setProperty("pointer-events", "all");
      el.style.setProperty("cursor", "pointer");
      return;
    }
    if (state === "error") {
      el.classList.add("is-error");
      el.innerHTML = "<i class='bx bx-x'></i>";
      el.style.setProperty("pointer-events", "all");
      el.style.setProperty("cursor", "default");
      return;
    }
  }
  async function doPubchemCheck(input, iconEl) {
    const q = normalizeQ(input.value);
    lastQByInput.set(input, q);
    if (!q) { setStatus(iconEl, "idle"); return; }
    const cached = lookupCache.get(q);
    if (cached) {
      if (cached.ok) {
        const parts = [q];
        if (cached.mw != null && cached.mw !== '') parts.push('MW ' + cached.mw);
        if (cached.cid != null && cached.cid !== '') parts.push('CID ' + cached.cid);
        if (cached.cid) {
          setStatus(iconEl, "success", parts.join(" · "), cached.cid);
        }
        else {
          setStatus(iconEl, "parseable", parts.join(" · "));
        }
      } else {
        setStatus(iconEl, "error", "Could not interpret component");
      }
      return;
    }
    const prev = inFlightByInput.get(input);
    if (prev) { try { prev.abort(); } catch {} }
    const ac = new AbortController();
    inFlightByInput.set(input, ac);
    setStatus(iconEl, "loading", "Looking up…");
    try {
      const url = 'https://www.loms.cz/jo-db/api/lookup_pubchem.php?q=' + encodeURIComponent(q) + '&check=1';
      const resp = await fetch(url, {
        method: 'GET',
        headers: { 'Accept': 'application/json' },
        signal: ac.signal
      });
      const data = await resp.json().catch(() => null);
      const ok = !!(data && data.calculated_properties && data.calculated_properties.ok === true);
      const mw = data?.calculated_properties?.molecular_weight;
      const cid = data?.selected?.CID;
      lookupCache.set(q, { ok, mw, cid });
      if (lastQByInput.get(input) !== q) return;
      if (ok) {
        const parts = [q];
        if (mw != null && mw !== '') parts.push('MW ' + Number(mw).toFixed(2));
        if (cid != null && cid !== '') parts.push('CID ' + cid);
        if (cid) {
          setStatus(iconEl, "success", parts.join(" · "), cid);
        }
        else {
          setStatus(iconEl, "parseable", parts.join(" · "));
        }
      } else {
        setStatus(iconEl, "error", "Could not interpret component");
      }
    } 
    catch (e) {
      if (e && e.name === 'AbortError') return;
      lookupCache.set(q, { ok: false, mw: null, cid: null });
      if (lastQByInput.get(input) !== q) return;
      setStatus(iconEl, "error", "Could not interpret component");
    } 
    finally {
      if (inFlightByInput.get(input) === ac) inFlightByInput.delete(input);
    }
  }
  function schedulePubchemCheck(input, iconEl, delayMs = 4050) {
    const t = debounceTimers.get(input);
    if (t) clearTimeout(t);
    debounceTimers.set(input, setTimeout(() => doPubchemCheck(input, iconEl), delayMs));
  }
  function enhanceComponentInputs(root=document) {
    root.querySelectorAll('input[name="comp_component[]"]:not([data-jo-enhanced]), #adv-component').forEach(input => {
      input.setAttribute('data-jo-enhanced', '1');
      const wrap = document.createElement('div');
      wrap.className = 'jo-cell-wrap';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'jo-cell-btn';
      btn.title = 'Toggle subscripts';
      btn.textContent = '₂';
      wrap.appendChild(btn);
      btn.addEventListener('click', () => applyToggleToInputSelection(input));
      const btnPT = document.createElement('button');
      btnPT.type = 'button';
      btnPT.className = 'jo-cell-btn-PT';
      btnPT.title = 'Periodic table';
      btnPT.textContent = 'PT';
      wrap.appendChild(btnPT);
      btnPT.addEventListener("click", () => {
        addElementPT(input)
      });

      const inGrid = input.closest('#comp-grid-body');
      if (inGrid) {
        const icn = document.createElement("span");
        icn.className = "doi-status";
        icn.style.cssText = "transform: translateY(-50%) translateX(-230%);";
        icn.style.setProperty("pointer-events", "all");
        wrap.appendChild(icn);
        input.addEventListener('input', () => {
          setStatus(icn, "idle");
          schedulePubchemCheck(input, icn);
        });
        input.addEventListener('blur', () => doPubchemCheck(input, icn));
        input.addEventListener('keydown', (e) => {
          if (e.ctrlKey && e.key === '.') {
            e.preventDefault();
            applyToggleToInputSelection(input);
          }
          if (e.key === 'Enter') {
            schedulePubchemCheck(input, icn, 0);
          }
        });
      }
    });
  }

  enhanceComponentInputs();
  document.getElementById('comp-subscript-btn').addEventListener('click', () => applyToggleToInputSelection(document.getElementById('comp-text')));
  const gridBody = document.getElementById('comp-grid-body');
  if (gridBody) {
    const obs = new MutationObserver(() => enhanceComponentInputs(gridBody));
    obs.observe(gridBody, { childList: true, subtree: true });
  }
})();