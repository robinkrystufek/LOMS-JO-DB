(() => {
  document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('jo-add-form');
    if (!form) return;
    form.addEventListener('reset', () => {
      const tbody = form.querySelector('#comp-grid-body');
      if (tbody) {
        const rows = [...tbody.querySelectorAll("tr")];
        rows.slice(1).forEach(r => r.remove());        
      }
      form.querySelector('#jo-add-status').textContent = '';
      form.querySelector('#is-revision-of-id').value = null;
      const disabled = Array.from(
        form.querySelectorAll('input:disabled, textarea:disabled, select:disabled')
      ).map(el => ({ el, value: el.value }));
      setTimeout(() => {
        disabled.forEach(({ el, value }) => { el.value = value; });
      }, 0);
    });
  });
  window.setResultsLoading = function setResultsLoading(isLoading) {
    const el = document.getElementById('jo-results-loading');
    if (!el) return;
    el.classList.toggle('active', !!isLoading);
  };
  window.setResultsLoading(true);
  document.querySelectorAll('.jo-db-tab').forEach(function (tab) {
    tab.addEventListener('click', function (e) {
      const target = tab.getAttribute('data-tab');
      if (tab.classList.contains('locked')) {
        e?.preventDefault?.();
        return;
      }
      document.querySelectorAll('.jo-db-tab').forEach(function (t) {
        t.classList.remove('active');
      });
      document.querySelectorAll('.jo-db-panel').forEach(function (p) {
        p.classList.remove('active');
      });
      tab.classList.add('active');
      const panel = document.getElementById('tab-' + target);
      if (panel) panel.classList.add('active');
    });
  });
  const advToggle = document.getElementById('advanced-toggle');
  const advPanel = document.getElementById('advanced-panel');
  if (advToggle && advPanel) {
    advToggle.addEventListener('click', function () {
      advPanel.classList.toggle('active');
    });
  }
  const joSourceRadios = document.querySelectorAll('input[name="jo_source"]');
  const inputFormatRadios = document.querySelectorAll('input[name="input_format"]');
  const joUploadBlock = document.getElementById('jo-recalc-upload');
  const joUploadBlockFileFormat = document.getElementById('jo-recalc-upload-file-format');
  const dbUploadBlockFileFormat = document.getElementById('jo-db-upload-file-format');
  const uiSectionForm = document.getElementById('ui-section');
  const fileSubmitButton = document.getElementById('file-submit');
  function updateJoUploadVisibility() {
    const joSrc = document.querySelector('input[name="jo_source"]:checked')?.value;
    if (joUploadBlock) {
      if (joSrc === 'recalc') joUploadBlock.classList.remove('jo-db-hidden');
      else joUploadBlock.classList.add('jo-db-hidden');
    }
    const fmt = document.querySelector('input[name="input_format"]:checked')?.value;
    if (fmt === 'upload') {
      joUploadBlockFileFormat?.classList.remove('jo-db-hidden');
      dbUploadBlockFileFormat?.classList.remove('jo-db-hidden');
      fileSubmitButton?.classList.remove('jo-db-hidden');
      uiSectionForm?.classList.add('jo-db-hidden');
    } else {
      joUploadBlockFileFormat?.classList.add('jo-db-hidden');
      dbUploadBlockFileFormat?.classList.add('jo-db-hidden');
      fileSubmitButton?.classList.add('jo-db-hidden');
      uiSectionForm?.classList.remove('jo-db-hidden');
    }
  }
  inputFormatRadios.forEach(function (r) {
    r.addEventListener('change', updateJoUploadVisibility);
  });
  joSourceRadios.forEach(function (r) {
    r.addEventListener('change', updateJoUploadVisibility);
  });
  document.querySelectorAll('.jo-db-view-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const targetId = btn.getAttribute('data-target');
      const targetRow = document.getElementById(targetId);
      document.querySelectorAll('.jo-db-details-row').forEach(function (row) {
        if (row.id !== targetId) row.classList.add('jo-db-hidden');
      });
      if (targetRow) targetRow.classList.toggle('jo-db-hidden');
    });
  });
  function updateExtraOpticalDataUI() {
    document.querySelectorAll('.jo-db-extra-toggle').forEach(function (cb) {
      const section = cb.getAttribute('data-section');
      if (!section) return;
      const groups = document.querySelectorAll('[data-extra="' + section + '"]');
      groups.forEach(function (group) {
        const enabled = cb.checked;
        group.classList.toggle('jo-db-disabled-group', !enabled);
        group.querySelectorAll('input, select, textarea').forEach(function (el) {
          if (el.type === 'hidden' || el.type === 'submit') return;
          el.disabled = !enabled;
        });
      });
    });
  }
  let tip;  
  function showTip(el) {
    const text = el.getAttribute('data-tooltip');
    if (!text) return;
    tip = document.createElement('div');
    tip.className = 'jo-tooltip';
    tip.textContent = text;
    document.body.appendChild(tip);
    const r = el.getBoundingClientRect();
    tip.style.left = (r.left + r.width / 2) + 'px';
    tip.style.top  = (r.bottom + 8) + 'px';
    tip.style.transform = 'translateX(-50%)';
  }
  function hideTip() {
    tip?.remove();
    tip = null;
  }
  function bindCheckboxToggle(checkboxId, targetIds) {
    const cb = document.getElementById(checkboxId);
    if (!cb) return;
    const targets = targetIds
      .map(id => document.getElementById(id))
      .filter(Boolean);
    function update() {
      targets.forEach(el => {
        el.disabled = !cb.checked;
        el.classList.toggle('jo-db-disabled', !cb.checked);
      });
    }
    cb.addEventListener('change', update);
    update();
  }
  bindCheckboxToggle('has-omega-error', [
    'omega2-error',
    'omega4-error',
    'omega6-error'
  ]);
  bindCheckboxToggle('has-range-re', [
    're-conc-upper'
  ]);
  const btn = document.getElementById('btn-export-csv');
  if (!btn) return;
  btn.addEventListener('click', () => {
    const params = new URLSearchParams();
      const f = getFilters();
      Object.entries(f).forEach(([k,v]) => {
        if (v === null || v === undefined) return;
        // skip empty strings and 0 (unless you want explicit 0)
        if (typeof v === 'string' && v.trim() === '') return;
        if (typeof v === 'number' && v === 0) return;
        params.set(k, String(v));
      });
    const adv = window.__JO_ADV_RULES__?.getRules ? window.__JO_ADV_RULES__.getRules() : [];
    adv.forEach(r => {
      params.append('comp_component[]', r.component);
      params.append('comp_op[]', r.op);
      params.append('comp_value[]', r.value);
      params.append('comp_unit[]', r.unit);
    });
    const doi = document.getElementById('filter-pub-doi')?.value || '';
    if (doi) params.set('pub_doi_q', doi);
    const title = document.getElementById('filter-pub-title')?.value || '';
    if (doi) params.set('pub_title_q', doi);
    const url = `api/export_csv.php?${params.toString()}`;
    window.location.href = url;
  });
  const filters = document.getElementById('filters-panel');
  filters.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    if (!document.getElementById('tab-browse').classList.contains('active')) return;
    if (e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return;
    e.preventDefault();
    document.getElementById('btn-search').click();
  });
  document.addEventListener('mouseover', (e) => {
    const el = e.target.closest('.jo-db-tab.locked');
    if (!el) return;
    showTip(el);
  });
  document.addEventListener('mouseout', (e) => {
    if (e.target.closest('.jo-db-tab.locked')) hideTip();
  });
  window.addEventListener('scroll', hideTip, true);
  document.querySelectorAll('.jo-db-extra-toggle').forEach(function (cb) {
    cb.addEventListener('change', updateExtraOpticalDataUI);
  });
  const panel = document.getElementById('filters-panel');
  const header = panel.querySelector('.jo-panel-header');
  header.addEventListener('click', function () {
    panel.classList.toggle('jo-panel-collapsed');
  });
  updateExtraOpticalDataUI();
  updateJoUploadVisibility();
})();
