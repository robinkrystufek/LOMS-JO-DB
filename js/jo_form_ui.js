(() => {
  function getQueryParams(name = false) {
    const params = new URLSearchParams(window.location.search);
    if (name) {
      return params.get(name);
    }
    const out = {};
    for (const [key, value] of params.entries()) {
      out[key] = value;
    }
    return out;
  }
  function clearUrlQuery({ keep = [] } = {}) {
    const url = new URL(window.location.href);
    if (!url.search) return false;
  
    if (!keep.length) {
      url.search = '';
    } else {
      const next = new URLSearchParams();
      const cur = url.searchParams;
      keep.forEach(k => {
        cur.getAll(k).forEach(v => next.append(k, v));
      });
      url.search = next.toString() ? `?${next.toString()}` : '';
    }
  
    history.replaceState({}, '', url.toString());
    return true;
  }
  
  function hasUrlQuery() {
    return new URL(window.location.href).searchParams.toString().length > 0;
  }
  window.hasUrlQuery = hasUrlQuery;
  window.clearUrlQuery = clearUrlQuery;
  window.getQueryParams = getQueryParams;
  function expandRowFromUrl() {
    const rowParam = getQueryParams('row-highlight');
    if (!rowParam) return;
    const index = parseInt(rowParam, 10);
    if (isNaN(index) || index < 1) return;
    const tbody = document.getElementById('jo-tbody-results');
    if (!tbody) return;
    const rows = tbody.querySelectorAll('tr.jo-db-data-row');
    const targetRow = rows[index - 1];
    console.log('Expanding row from URL:', { index, targetRow });
    if (targetRow) {
      targetRow.click();
    }
    const pubDetailsParam = getQueryParams('pub-details');
    if (pubDetailsParam) {
      targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
      const buttons = document.querySelectorAll('.jo-parentpub-zoom');
      buttons[index - 1]?.click();
    }
    else {
      setTimeout(() => {
        targetRow.scrollIntoView({
          behavior: 'smooth',
          block: 'center'
        });
      }, 80);
    }
  }
  function waitForRowsThenExpand() {
    const tbody = document.getElementById('jo-tbody-results');
    if (!tbody) return;
    const observer = new MutationObserver(() => {
      if (tbody.querySelectorAll('tr').length > 5) {
        observer.disconnect();
        expandRowFromUrl();
      }
    });
    observer.observe(tbody, { childList: true });
  }
  const rowParam = getQueryParams('row-highlight');
  if (rowParam !== null) {
    document.addEventListener('DOMContentLoaded', () => {
      waitForRowsThenExpand();
    });
  }
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
  function initUserInfo() {
    const user = firebase.auth().currentUser;
    updateAccessPermissions();
    const setVal = (id, v) => {
      const el = document.getElementById(id);
      if (el) el.value = v;
      return el;
    };
    if (!user) {
      setVal('contributor-info-ui', '');
      setVal('contributor-info', '');
      setVal('contributor-info-ui2', '');
      setVal('contributor-info2', '');
      return;
    }
    const name  = (user.displayName || '').trim();
    const email = (user.email || '').trim();
    let affiliation = '';
    let orcid = '';
    if (typeof user.photoURL === 'string' && user.photoURL.includes(';')) {
      const parts = user.photoURL.split(';');
      affiliation = (parts[0] || '').trim();
      orcid       = (parts[1] || '').trim();
    }
    const contributorCombined = name ? `${name} <${email}>` : `<${email}>`;
    for (const s of ['', '2']) {
      setVal(`contributor-info-ui${s}`, contributorCombined);
      setVal(`contributor-info${s}`, user.uid);
      setVal(`contributor-info-name${s}`, name);
      setVal(`contributor-info-email${s}`, email);
      setVal(`contributor-info-affiliation${s}`, affiliation);
      setVal(`contributor-info-orcid${s}`, orcid);
    }
    const uiInput = document.getElementById('contributor-info-ui');
    if (uiInput) uiInput.disabled = true;
  }
  async function updateAccessPermissions() {
    const buttons = document.querySelectorAll('.jo-request-revision');
    const addTab = document.getElementById('tab-add-btn');
    const fileUploadOption = document.querySelector('input[name="input_format"][value="upload"]');
    if (!buttons.length || !addTab) return;
    if(userRole === null && userLoggedIn) {
      try {
        const user = firebase.auth().currentUser;
        const token = await user?.getIdToken(true);
        const resp = await fetch('api/auth_permissions.php', {
          method: 'POST',
          headers: {
          "Authorization": `Bearer ${token}`
          }
        });
        const text = await resp.text();
        let data = JSON.parse(text);
        if (resp.ok && data && data.ok && data.role && data.uid == user.uid) {
          userRole = data.role;
        }
        else {
          userRole = null;
        }
      } 
      catch (err) {
        console.error("Error fetching user permissions:", err);
        userRole = null;
      }
    }
    document.getElementById('loggedin-area-role').textContent =
    typeof userRole === 'string' && userRole.length
      ? userRole[0].toUpperCase() + userRole.slice(1)
      : 'User';
    buttons.forEach(btn => {
      if (!userLoggedIn) {
        btn.disabled = true;
        btn.classList.add('locked', 'tooltip-icon');
        btn.dataset.tooltip = "You need to be registered to use this feature";
      } 
      else {
        btn.disabled = false;
        btn.classList.remove('locked', 'tooltip-icon');
        btn.removeAttribute('data-tooltip');
      }
    });
    if (!userLoggedIn) {
      addTab.classList.add('locked');
      addTab.dataset.tooltip = "You need to be registered to use this feature";
      if (!addTab.querySelector('i')) {
        const icon = document.createElement('i');
        icon.className = 'bx bx-lock-alt';
        addTab.appendChild(icon);
      }
    } 
    else {
      addTab.classList.remove('locked');
      addTab.removeAttribute('data-tooltip');
      const icon = addTab.querySelector('i');
      if (icon) icon.remove();
    }  
    if(!userLoggedIn || !['depositor', 'reviewer', 'admin'].includes(userRole)) {
      fileUploadOption.disabled = true;
      fileUploadOption.classList.add('locked', 'tooltip-icon');
      fileUploadOption.dataset.tooltip = "You need to be assigned the depositor role to use this feature";
    }
    else {
      fileUploadOption.disabled = false;
      fileUploadOption.classList.remove('locked', 'tooltip-icon');
      fileUploadOption.removeAttribute('data-tooltip');
    }
  }
  window.cInitUserInfo = initUserInfo;
  window.cUpdateAccessPermissions = updateAccessPermissions;
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
    if (window.getQueryParams) {
      const urlParams = window.getQueryParams();
      Object.entries(urlParams).forEach(([key, value]) => {
        if (value === null || value === '') return;
        if (key.endsWith('[]')) {
          params.delete(key);
          if (Array.isArray(value)) {
            value.forEach(v => params.append(key, v));
          } 
          else {
            params.append(key, value);
          }
        } else {
          params.set(key, value);
        }
      });
    }
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