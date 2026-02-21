(() => {
  const subMap = { '0':'₀','1':'₁','2':'₂','3':'₃','4':'₄','5':'₅','6':'₆','7':'₇','8':'₈','9':'₉' };
  const revSubMap = { '₀':'0','₁':'1','₂':'2','₃':'3','₄':'4','₅':'5','₆':'6','₇':'7','₈':'8','₉':'9' };
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
  function enhanceComponentInputs(root=document) {
    root.querySelectorAll('input[name="comp_component[]"]:not([data-jo-enhanced]), #adv-component')
      .forEach(input => {
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
        input.addEventListener('keydown', (e) => {
          if (e.ctrlKey && e.key === '.') {
            e.preventDefault();
            applyToggleToInputSelection(input);
          }
        });
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
