(() => {
  const rules = []; 
  const elToggle = document.getElementById('advanced-toggle');
  const elPanel  = document.getElementById('advanced-panel');
  const elList   = document.getElementById('adv-rules-list');
  const inComp = document.getElementById('adv-component');
  const inOp   = document.getElementById('adv-op');
  const inVal  = document.getElementById('adv-value');
  const inUnit = document.getElementById('adv-unit');
  const btnAdd = document.getElementById('adv-add-rule');
  const btnClr = document.getElementById('adv-clear-rules');
  if (!elToggle || !elPanel || !elList || !inComp || !inOp || !inVal || !inUnit || !btnAdd || !btnClr) {
    return;
  }
  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }
  function renderRules() {
    if (!rules.length){
      elList.innerHTML = `<div style="font-size:12px;color:#6c757d;">No advanced rules.</div>`;
      if (typeof window.load === 'function') window.load(1);
      return;
    }
    elList.innerHTML = rules.map((r, idx) => `
      <div class="jo-db-adv-rule" data-idx="${idx}">
        <code>${esc(r.component)}</code>
        <code>${esc(r.op)}</code>
        <code>${esc(r.value)}</code>
        <code>${esc(r.unit)}</code>
        <button type="button" class="btn btn-secondary btn-sm jo-db-adv-remove" data-remove="${idx}">✕</button>
      </div>
    `).join('');
    elList.querySelectorAll('[data-remove]').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = parseInt(btn.getAttribute('data-remove'), 10);
        if (!Number.isFinite(idx)) return;
        rules.splice(idx, 1);
        renderRules();
      });
    });
    if (typeof window.load === 'function') window.load(1);
  }
  function normalizeComponent(s){
    return s.trim();
  }
  function addRule() {
    const component = normalizeComponent(inComp.value);
    const op = inOp.value;
    const value = inVal.value;
    const unit = inUnit.value;
    if (!component || value === '') return;
    const key = `${component}||${op}||${value}||${unit}`;
    const exists = rules.some(r => `${r.component}||${r.op}||${r.value}||${r.unit}` === key);
    if (!exists) rules.push({ component, op, value, unit });
    inVal.value = '';
    inComp.focus();
    renderRules();
  }
  function clearRules() {
    rules.length = 0;
    renderRules();
  }
  elToggle.addEventListener('click', () => {
    elPanel.classList.toggle('open');
    elPanel.style.display = elPanel.classList.contains('open') ? 'block' : 'none';
  });
  btnAdd.addEventListener('click', addRule);
  btnClr.addEventListener('click', clearRules);
  inVal.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); addRule(); }
  });
  renderRules();
  window.__JO_ADV_RULES__ = {
    getRules: () => rules.slice(),
    clear: clearRules
  };
})();
