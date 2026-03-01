window.__RI_MAIN_BOOK_CACHE__ = window.__RI_MAIN_BOOK_CACHE__ || new Map();
window.__RI_MAIN_BOOK_INFLIGHT__ = window.__RI_MAIN_BOOK_INFLIGHT__ || new Map();
function setRiSpinner(slotEl) {
  slotEl.innerHTML = `<i class="fa fa-spinner fa-spin" aria-label="Checking refractiveindex.info"></i>`;
}
function setRiHit(slotEl, url) {
  slotEl.innerHTML =
    `<a href="${esc(url)}" target="_blank" rel="noopener" title="Open refractiveindex.info record">
      <img src="dist/refractiveindex.info.logo.png" alt="RI" class="ri-icon" />
    </a>`;
}
function setRiEmpty(slotEl) {
  slotEl.innerHTML = '';
}
async function lookupRiMainBook(component) {
  const key = String(component || '').trim();
  if (!key) return null;
  if (window.__RI_MAIN_BOOK_CACHE__.has(key)) {
    return window.__RI_MAIN_BOOK_CACHE__.get(key);
  }
  if (window.__RI_MAIN_BOOK_INFLIGHT__.has(key)) {
    return window.__RI_MAIN_BOOK_INFLIGHT__.get(key);
  }
  const p = (async () => {
    try {
      const url = `api/lookup_refractive_index_info.php?component=${encodeURIComponent(key)}`;
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      const data = await res.json().catch(() => null);
      const hitUrl = (data && data.ok && data.found && data.url) ? String(data.url) : null;
      window.__RI_MAIN_BOOK_CACHE__.set(key, hitUrl);
      return hitUrl;
    } catch {
      window.__RI_MAIN_BOOK_CACHE__.set(key, null);
      return null;
    } finally {
      window.__RI_MAIN_BOOK_INFLIGHT__.delete(key);
    }
  })();
  window.__RI_MAIN_BOOK_INFLIGHT__.set(key, p);
  return p;
}
function hydrateRiIcons(containerEl) {
  if (!containerEl) return;
  const slots = Array.from(containerEl.querySelectorAll('.ri-lookup[data-component]'));
  if (!slots.length) return;
  slots.forEach(async (slot) => {
    if (slot.dataset.riDone === '1') return;
    const comp = slot.getAttribute('data-component') || '';
    setRiSpinner(slot);
    const hitUrl = await lookupRiMainBook(comp);
    if (hitUrl) setRiHit(slot, hitUrl);
    else setRiEmpty(slot);
    slot.dataset.riDone = '1';
  });
}
let sortBy = 'id';
let sortDir = 'desc';
function updateSortIndicators() {
  document.querySelectorAll('.jo-db-table thead th[data-sort]').forEach(th => {
    const key = th.getAttribute('data-sort');
    const slot = th.querySelector('.jo-sort');
    th.classList.toggle('is-sorted', key === sortBy);
    if (slot) slot.innerHTML = '';
    if (slot && key === sortBy) {
      slot.innerHTML = sortDir === 'asc'
        ? `<i class="fa fa-caret-up jo-sort-icon" aria-hidden="true"></i>`
        : `<i class="fa fa-caret-down jo-sort-icon" aria-hidden="true"></i>`;
    }
  });
}
let sortBound = false;
function bindSortHeaders() {
  if (sortBound) return;
  sortBound = true;
  const thead = document.querySelector('.jo-db-table thead');
  if (!thead) return;
  thead.addEventListener('click', (e) => {
    const th = e.target.closest('th[data-sort]');
    if (!th) return;
    const key = th.getAttribute('data-sort') || 'id';
    if (key === sortBy) {
      sortDir = (sortDir === 'asc') ? 'desc' : 'asc';
    } 
    else {
      sortBy = key;
      sortDir = 'asc';
    }
    updateSortIndicators();
    load(1);
  });
  updateSortIndicators();
}
const tbody = document.getElementById('jo-tbody-results');
const pager = document.getElementById('jo-pager');
const countEl = document.getElementById('jo-count');
let currentPage = 1;
const perPage = 50;
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}
function fmtNum(x) {
  if (x === null || x === undefined || x === '') return '';
  const s = String(x);
  if (!s.includes('.')) return s;
  return s.replace(/\.?0+$/, '');
}
function fmtFixed(x, decimals) {
  return Number.isFinite(x) ? Number(x).toFixed(decimals) : '';
}
function compositionShorthand(components) {
  if (!Array.isArray(components) || !components.length) return '';
  if(components.length === 1) return components[0].component;
  const valid = components.filter(c => isFinite(c.value) && c.component);
  if (!valid.length) return '';
  const EPS = 1e-9;
  const allIntegers = valid.every(c => Math.abs(Number(c.value) - Math.round(Number(c.value))) < EPS);
  let precision;
  if (allIntegers) {
    precision = 0;
  } 
  else {
    const decs = valid.map(c => estimateDecimals(c.value));
    precision = Math.min(...decs);
    if (precision === 0 && decs.some(d => d > 0)) precision = 1;
    precision = Math.min(precision, 3);
  }
  const parts = valid.map(c => {
    const value = Number(c.value).toFixed(precision);
    return `${value} ${c.component}`;
  });
  const unit = valid.find(c => c.unit)?.unit || '';
  const body = parts.join('–');
  return unit ? `${body} (${unit})` : body;
}
function estimateDecimals(x) {
  const s = Number(x).toPrecision(12);
  const m = s.match(/\.(\d+?)0*$/);
  return m ? m[1].length : 0;
}
function columnPrecision(rows, getNum, maxDecimals = 3) {
  const EPS = 1e-9;
  const nums = (rows || [])
    .map(r => getNum(r))
    .filter(v => Number.isFinite(v));
  if (!nums.length) return 0;
  const allIntegers = nums.every(v => Math.abs(v - Math.round(v)) < EPS);
  if (allIntegers) return 0;
  const decs = nums.map(estimateDecimals);
  let p = Math.min(...decs);
  if (p === 0 && decs.some(d => d > 0)) p = 1;
  return Math.min(p, maxDecimals);
}
function render(items) {
  tbody.innerHTML = '';
  items.forEach((it, idx) => {
    window.__JO_RECORD_CACHE__ = window.__JO_RECORD_CACHE__ || {};
    window.__JO_RECORD_CACHE__[it.jo_record_id] = it;
    const detailsId = `details-${it.jo_record_id}`;
    const foregroundColors = ["#A33A3A", "#8A6D1F", "#2F7A4A", "#2F7A4A"];
    const backgroundColors = ["#FDECEC", "#FFF8E1", "#EAF7EF", "#EAF7EF"];
    const badgesHtml = (it.badges || [])
    .map((val, id) => `
      <span class="jo-db-badge"
            style="color: ${esc(foregroundColors[it.badges_states[id]] || "#888")}; background-color: ${esc(backgroundColors[it.badges_states[id]] || "#888")}; border: ${esc(it.badges_notes[id] || "")==""?  '1px solid ' + esc(backgroundColors[it.badges_states[id]] || "#888") : '1px dashed ' + esc(foregroundColors[it.badges_states[id]] || "#888")};"
            title="${id == 6 ? esc(fmtNum(it.density)) + ' g/cm³' || "" : esc(it.badges_notes[id] || "")}">
        ${esc(it.badges[id] || id)}
      </span>
    `)
    .join(' ');
    const row = document.createElement('tr');
    row.innerHTML = `
    <td>${esc(it.jo_record_id)}</td>
      <td>${esc(it.re_ion)}</td>
      <td${esc(it.concentration_note) != "" && !it.concentration ? ' style="overflow: visible"' : ''}>${esc(it.concentration)}${esc(it.concentration_note) != "" && !it.concentration ? '<i class=\'fa fa-question-circle tooltip-icon\' data-tooltip=\''+esc(it.concentration_note)+'\'></i>' : ''}</td>
      <td>${esc(compositionShorthand(it.details.composition_components) || it.details.composition || it.composition)}</td>
      <td>${esc(it.details.host || it.host)}</td>
      <td>${esc(fmtNum(it.omega2))}${it.omega2_error != null ? ` ± ${esc(fmtNum(it.omega2_error))}` : ''}</td>
      <td>${esc(fmtNum(it.omega4))}${it.omega4_error != null ? ` ± ${esc(fmtNum(it.omega4_error))}` : ''}</td>
      <td>${esc(fmtNum(it.omega6))}${it.omega6_error != null ? ` ± ${esc(fmtNum(it.omega6_error))}` : ''}</td>
      <td>${badgesHtml}</td>
      <td style="text-align: right;">
        <button class="btn btn-secondary btn-sm jo-db-view-btn" type="button" data-target="${esc(detailsId)}" aria-label="Entry details" title="Entry details"><i class="fa fa-search-plus"></i></button>
      </td>
    `;
    row.style.cursor = 'pointer';
    tbody.appendChild(row);
    const d = it.details || {};
    const det = document.createElement('tr');
    det.id = detailsId;
    det.className = 'jo-db-details-row jo-db-hidden';
    const comps = (d.composition_components || []);
    const pValue = columnPrecision(comps, c => Number(c.value), 3);
    const compRows = comps.map(c => {
      const value    = fmtFixed(Number(c.value), pValue);
      const cMol     = fmtFixed(Number(c.c_mol), Math.max (pValue, 1));
      const cWt      = fmtFixed(Number(c.c_wt),  Math.max (pValue, 1));
      const cAt      = fmtFixed(Number(c.c_at),  Math.max (pValue, 1));
      return `
        <tr>
          <td style="overflow: inherit;">
            <button class="comp-filter-btn" data-component="${esc(c.component)}" title="Search for entries containing ${esc(c.component)}. Shift+click to add multiple components">
              ${esc(c.component)}
              <i class="fa fa-filter"></i>
            </button>
          </td>
          <td style="text-align:right; overflow: inherit;">
            <button class="comp-filter-btn" ${c.unit != 'mol%' ? 'style="color:var(--text-muted);" ' : ''}data-component="${esc(c.component)}" data-concentration="${c.unit == "mol%" ? value : cMol}" data-unit="mol%" title="Search for entries containing ${esc(c.component)} at ${c.unit == "mol%" ? value : cMol} mol%">
              ${c.unit == "mol%" ? value : cMol} mol%
              <i class="fa fa-filter"></i>
            </button>
          </td>
          <td style="text-align:right; overflow: inherit;">
            <button class="comp-filter-btn" ${c.unit != 'wt%' ? 'style="color:var(--text-muted);" ' : ''}data-component="${esc(c.component)}" data-concentration="${c.unit == "wt%" ? value : cWt}" data-unit="wt%" title="Search for entries containing ${esc(c.component)} at ${c.unit == "wt%" ? value : cWt} wt%">
              ${c.unit == "wt%" ? value : cWt} wt%
              <i class="fa fa-filter"></i>
            </button>
          </td>
          <td style="text-align:right; overflow: inherit;">
            <button class="comp-filter-btn" ${c.unit != 'at%' ? 'style="color:var(--text-muted);" ' : ''}data-component="${esc(c.component)}" data-concentration="${c.unit == "at%" ? value : cAt}" data-unit="at%" title="Search for entries containing ${esc(c.component)} at ${c.unit == "at%" ? value : cAt} at%">
              ${c.unit == "at%" ? value : cAt} at%
              <i class="fa fa-filter"></i>
            </button>
          </td>
          <td>
            <span class="ri-lookup" data-component="${normalizeSubscripts(esc(c.component))}" aria-hidden="true"></span>
          </td>
        </tr>
      `;
    }).join('');
    
    const compTable = `
      <table class="composition-detail-table">
        <tbody>
          ${compRows}
        </tbody>
      </table>
    `;      
    let lomsHtml = '';
    if (d.loms_file_url) {
    lomsHtml = ` <a href="${esc(d.loms_file_url)}" target="_blank" rel="noopener" download><i class="fa fa-download" aria-hidden="true"></i> Download</a>`;
    }
    const stateMapRI = {
      0: "<i class='fa fa-times'></i> ",
      1: "<i class='fa fa-question'></i> ",
      2: "<i class='fa fa-check'></i> (Single value) ",
      3: "<i class='fa fa-check'></i> (Dispersion relation) "
    };
    const startsWithNumber = /^[0-9]+(\.[0-9]+)?\b/.test(it.badges_notes[0]);
    let rIndexDesc = "";
    if (startsWithNumber && it.badges_states[0] == 2) {
      rIndexDesc = esc(it.badges_notes[0]);
    } 
    else {
      rIndexDesc += stateMapRI[it.badges_states[0]] ?? "";
      if (it.badges_notes[0] !== "") {
        rIndexDesc += `<i class='fa fa-question-circle tooltip-icon' data-tooltip='${esc(it.badges_notes[0])}'></i>`;
      }
    }
    det.innerHTML = `
      <td colspan="10" style="overflow: visible;">
        <div class="jo-db-details">
          <h3>Record details – ${esc(it.re_ion)} in ${esc(d.host)}</h3>
          <dl>
            <dt>Sample label</dt>
            <dd>
              ${esc(it.sample_label) || '<i class=\'fa fa-times\'></i>'}
              ${esc(it.notes) != "" ? ' <i class=\'fa fa-question-circle tooltip-icon\' data-tooltip=\''+esc(it.notes)+'\'></i>' : ''}
            </dd>
            <dt>Density</dt>
            <dd>
              ${it.has_density == 2 ? esc(fmtNum(it.density)) + ' g/cm³' : it.has_density == 1 ? '<i class=\'fa fa-question\'></i>' : '<i class=\'fa fa-times\'></i>'}
            </dd>
            <dt>Host</dt>
            <dd>
              ${esc(d.host || it.host)}
            </dd>
            <dt>Refractive index</dt>
            <dd>
              ${rIndexDesc}
            </dd>
            <dt>JO parameters</dt>
            <dd>
              Ω₂ = ${esc(fmtNum(it.omega2))}${it.omega2_error != null ? ` ± ${esc(fmtNum(it.omega2_error))}` : ''},
              Ω₄ = ${esc(fmtNum(it.omega4))}${it.omega4_error != null ? ` ± ${esc(fmtNum(it.omega4_error))}` : ''},
              Ω₆ = ${esc(fmtNum(it.omega6))}${it.omega6_error != null ? ` ± ${esc(fmtNum(it.omega6_error))}` : ''}
              (${esc(d.jo_parameters?.units || '10⁻²⁰ cm²')})
            </dd>
            <dt>Combinatorial JO analysis</dt>
            <dd>
              ${({0:'<i class=\'fa fa-times\'></i> ',1:'<i class=\'fa fa-question\'></i> ',2:'<i class=\'fa fa-check\'></i> '}[it.badges_states[1]] ?? '')}
              ${esc(it.badges_notes[1]) != "" ? '<i class=\'fa fa-question-circle tooltip-icon\' data-tooltip=\''+esc(it.badges_notes[1])+'\'></i>' : ''}
            </dd>
            <dt>JO recalculated by LOMS</dt>
            <dd>
              ${({0:'<i class=\'fa fa-times\'></i> ',1:'<i class=\'fa fa-question\'></i> ',2:'<i class=\'fa fa-check\'></i> '}[it.badges_states[5]] ?? '')}
              ${(lomsHtml || '')}
              ${esc(it.badges_notes[5]) != "" ? ' <i class=\'fa fa-question-circle tooltip-icon\' data-tooltip=\''+esc(it.badges_notes[5])+'\'></i>' : ''}
            </dd>
            <dt>σ,F,S included</dt>
            <dd>
              ${({0:'<i class=\'fa fa-times\'></i> ',1:'<i class=\'fa fa-question\'></i> ',2:'<i class=\'fa fa-check\'></i> '}[it.badges_states[2]] ?? '')}
              ${esc(it.badges_notes[2]) != "" ? '<i class=\'fa fa-question-circle tooltip-icon\' data-tooltip=\''+esc(it.badges_notes[2])+'\'></i>' : ''}
            </dd>
            <dt>Concentration (${esc(it.re_ion)})</dt>
            <dd>
              ${esc(it.concentration)} ${esc(it.concentration_note) != "" ? '<i class=\'fa fa-question-circle tooltip-icon\' data-tooltip=\''+esc(it.concentration_note)+'\'></i>' : ''}
            </dd>

            <dt>Magnetic dipole correction</dt>
            <dd>
              ${({0:'<i class=\'fa fa-times\'></i> ',1:'<i class=\'fa fa-question\'></i> ',2:'<i class=\'fa fa-check\'></i> '}[it.badges_states[3]] ?? '')}
              ${esc(it.badges_notes[3]) != "" ? '<i class=\'fa fa-question-circle tooltip-icon\' data-tooltip=\''+esc(it.badges_notes[3])+'\'></i>' : ''}
            </dd>
            <dt>Composition (as reported)</dt>
            <dd>
              ${esc(d.composition || it.composition)}
            </dd>
            <dt>Reduced elements included</dt>
            <dd>
              ${({0:'<i class=\'fa fa-times\'></i> ',1:'<i class=\'fa fa-question\'></i> ',2:'<i class=\'fa fa-check\'></i> '}[it.badges_states[4]] ?? '')}
              ${esc(it.badges_notes[4]) != "" ? '<i class=\'fa fa-question-circle tooltip-icon\' data-tooltip=\''+esc(it.badges_notes[4])+'\'></i>' : ''}
            </dd>                    
            <dt>Normalized composition</dt>
            <dd>
              ${compTable || ''}
            </dd>
          </dl>
          <h3 style="margin-top: 0.5em;">Parent publication</h3>
          <dl>
            <dt>Title</dt>
            <dd>${(it.pub_title || '')}</dd>
            <dt>Authors</dt>
            <dd>${(it.pub_authors || '')}</dd>
            <dt>Journal</dt>
            <dd>${(it.pub_journal || '')}</dd>
            <dt>Year</dt>
            <dd>${(it.pub_year || '')}</dd>
            <dt>DOI</dt>
            <dd><a href="${(it.pub_url || '')}" target="_blank">${(it.doi || '')}</a></dd>
          </dl>
          <div class="jo-db-btn-group">
          <button class="btn btn-primary btn-sm jo-parentpub-zoom" type="button" data-doi="${esc(it.doi || '')}" data-pub-id="${esc(it.publication_id || '')}"><i class="fa fa-search"></i>&nbsp;&nbsp;Publication details</button>
            <button class="btn btn-primary btn-sm jo-find-parent-doi" data-doi="${esc(it.doi || '')}" type="button"><i class="fa fa-arrow-right"></i>&nbsp;&nbsp;Show entries from this publication</button>
              <div class="btn-split">
                <button class="btn btn-secondary btn-sm" type="button" onclick="exportCitation(${esc(it.jo_record_id)}, 'bibtex')">
                  <i class="fa fa-download"></i>&nbsp;&nbsp;Export citation
                </button>
                <button class="btn btn-secondary btn-sm btn-split-toggle" type="button" aria-label="Select format">
                  <i class="fa fa-caret-down"></i>
                </button>
                <div class="btn-split-menu">
                  <button onclick="exportCitation(${esc(it.jo_record_id)}, 'bibtex')">BibTeX</button>
                  <button onclick="exportCitation(${esc(it.jo_record_id)}, 'ris')">RIS</button>
                  <button onclick="exportCitation(${esc(it.jo_record_id)}, 'apa')">APA</button>
                </div>
              </div>
              <div class="btn-split">
                <button class="btn btn-secondary btn-sm" type="button" onclick="window.location.href = 'api/export_entry.php?type=csv&id=${esc(it.jo_record_id)}';">
                  <i class="fa fa-download"></i>&nbsp;&nbsp;Export data
                </button>
                <button class="btn btn-secondary btn-sm btn-split-toggle" type="button" aria-label="Select format">
                  <i class="fa fa-caret-down"></i>
                </button>
                <div class="btn-split-menu">
                  <button onclick="window.location.href = 'api/export_entry.php?type=csv&id=${esc(it.jo_record_id)}';">CSV</button>
                  <button onclick="window.location.href = 'api/export_entry.php?type=loms&&id=${esc(it.jo_record_id)}';">Submission file</button>
                </div>
              </div>
            <button class="btn btn-secondary btn-sm jo-audit-trail" type="button" data-id="${esc(it.jo_record_id)}">
              <i class="fa fa-history"></i>&nbsp;&nbsp;Audit trail
            </button>
            <button type="button" data-id="${esc(it.jo_record_id)}" class="btn btn-secondary btn-sm jo-request-revision"><i class="fa fa-edit"></i>&nbsp;&nbsp;Request revision</button>
          </div>
        </div>
      </td>
    `;
    tbody.appendChild(det);
  });
  tbody.querySelectorAll('.jo-db-view-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleDetailsFor(btn);
    });
  });
  tbody.querySelectorAll('tr').forEach(tr => {
    if (tr.classList.contains('jo-db-details-row')) return;
    tr.addEventListener('click', (e) => {
      if (e.target.closest('a, button, input, textarea, select, label')) return;
      const btn = tr.querySelector('.jo-db-view-btn');
      if (btn) toggleDetailsFor(btn);
    });
  });
  tbody?.addEventListener('click', (e) => {
    const btn = e.target.closest('.jo-find-parent-doi');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    const icon = btn.querySelector('i');
    if (!icon) return;
    icon.classList.add('fa-flip-swap', 'flip');
    btn.disabled = true;
    findEntriesByParentDOI(btn.getAttribute('data-doi') || '');
    setTimeout(() => {
      icon.className = 'fa fa-repeat fa-spin fa-flip-swap';
      icon.classList.remove('flip');
    }, 250);
  });
}
function normalizeSubscripts(str) {
  if (!str) return str;
  const map = {
    '₀': '0',
    '₁': '1',
    '₂': '2',
    '₃': '3',
    '₄': '4',
    '₅': '5',
    '₆': '6',
    '₇': '7',
    '₈': '8',
    '₉': '9'
  };
  return str.replace(/[₀-₉]/g, ch => map[ch] || ch);
}
function toggleDetailsFor(btn) {
  const targetId  = btn.getAttribute('data-target');
  const targetRow = document.getElementById(targetId);
  if (!targetRow) return;
  document.querySelectorAll('.jo-db-details-row').forEach(r => {
    if (r.id !== targetId) r.classList.add('jo-db-hidden');
  });
  tbody.querySelectorAll('.jo-db-view-btn').forEach(b => {
    if (b !== btn) {
      const i = b.querySelector('i.fa');
      if (i) { i.classList.add('fa-search-plus'); i.classList.remove('fa-search-minus'); }
    }
  });
  targetRow.classList.toggle('jo-db-hidden');
  const i = btn.querySelector('i.fa');
  if (i) {
    const open = !targetRow.classList.contains('jo-db-hidden');
    i.classList.toggle('fa-search-plus', !open);
    i.classList.toggle('fa-search-minus', open);
    if (open) hydrateRiIcons(targetRow);
  }
}
function renderPager(page, totalPages, total) {
  countEl.textContent = `${total} entries`;
  setResultsLoading(false);
  const pages = [];
  const start = Math.max(1, page - 2);
  const end = Math.min(totalPages, page + 2);
  for (let p = start; p <= end; p++) pages.push(p);
  pager.innerHTML = `
    <button class="btn btn-secondary btn-sm" ${page <= 1 ? 'disabled' : ''} data-go="${page-1}"><i class="fa fa-caret-left"></i></button>
    ${start > 1 ? `<button class="btn btn-secondary btn-sm" data-go="1">1</button>` : ''}
    ${start > 2 ? `<span class="pager-ellipsis">…</span>` : ''}
    ${pages.map(p => `
      <button class="btn ${p === page ? 'btn-primary' : 'btn-secondary'} btn-sm" data-go="${p}">
        ${p}
      </button>
    `).join('')}
    ${totalPages > (end+1)? `<span class="pager-ellipsis">…</span>` : ''}
    ${totalPages > end? `<button class="btn btn-secondary btn-sm" data-go="${totalPages}">${totalPages}</button>` : ''}
    <button class="btn btn-secondary btn-sm" ${page >= totalPages ? 'disabled' : ''} data-go="${page+1}"><i class="fa fa-caret-right"></i></button>
  `;
  pager.querySelectorAll('button[data-go]').forEach(b => {
    b.addEventListener('click', () => {
      const go = parseInt(b.getAttribute('data-go'), 10);
      if (!isNaN(go)) load(go);
    });
  });
}
function getBadgeFilters() {
  const container = document.getElementById("badge-selector");
  if (!container) return {};
  const out = {};
  container.querySelectorAll(".jo-db-badge[data-badge]").forEach(el => {
    const key = el.dataset.badge;                 
    const state = Number(el.dataset.state ?? -1);
    out[`badge_${key}`] = String(state);
  });
  return out;
}
function getFilters() {
  const re_ion = document.getElementById('filter-re-ion')?.value || '';
  const host_type = document.getElementById('filter-host-type')?.value || '';
  const composition_q = document.getElementById('filter-composition-text')?.value || '';
  const element_q = document.getElementById('filter-composition-element')?.value || '';
  const pub_doi_q = document.getElementById('filter-pub-doi')?.value || '';
  const pub_authors_q = document.getElementById('filter-pub-author')?.value || '';
  const pub_title_q = document.getElementById('filter-pub-title')?.value || '';
  const has_jo = document.getElementById('filter-has-jo')?.checked ? 1 : 0;
  const has_density = document.getElementById('filter-has-density')?.checked ? 1 : 0;
  const jo_original = document.getElementById('filter-jo-original')?.checked ? 1 : 0;
  const jo_recalc = document.getElementById('filter-jo-recalc')?.checked ? 1 : 0;
  return {
    re_ion, host_type, composition_q, pub_doi_q, pub_title_q, pub_authors_q, element_q,
    has_jo, has_density, 
    jo_original, jo_recalc,
    ...getBadgeFilters()
  };
}
let loadSeq = 0;
async function load(page) {
  const seq = ++loadSeq;
  setResultsLoading(true);
  currentPage = page;
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
    sort_by: String(sortBy),
    sort_dir: String(sortDir),
    ...getFilters()
  });
  const adv = window.__JO_ADV_RULES__?.getRules ? window.__JO_ADV_RULES__.getRules() : [];
  adv.forEach(r => {
    params.append('comp_component[]', r.component);
    params.append('comp_op[]', r.op);
    params.append('comp_value[]', r.value);
    params.append('comp_unit[]', r.unit);
  });
  const url = `api/browse_records.php?${params.toString()}`;
  const res = await fetch(url, { headers: { 'Accept': 'application/json' }});
  const data = await res.json();
  if (seq !== loadSeq) return;
  if (!data.ok) throw new Error(data.error || 'Fetch failed');
  render(data.items || []);
  renderPager(data.page, data.total_pages, data.total);
  bindSortHeaders();
}
function resetSearchInput(reload = true) {
  window.resetPeriodicTable();
  const c = document.getElementById("badge-selector");
  c?.querySelectorAll(".jo-db-badge").forEach(b => applyBadgeState(b, -1));
  document.getElementById('filter-pub-doi').value = '';
  document.getElementById('filter-pub-title').value = '';
  document.getElementById('filter-pub-author').value = '';
  document.getElementById('filter-re-ion').value = '';
  document.getElementById('filter-host-type').value = '';
  document.getElementById('adv-component').value = '';
  document.getElementById('adv-value').value = '';
  document.getElementById('adv-unit').value = 'mol%';
  document.getElementById('adv-op').value = '>=';
  document.getElementById('advanced-panel').classList.remove('active');
  document.getElementById('advanced-panel').classList.remove('open');
  document.getElementById('advanced-panel').style.display="none";
  document.getElementById('filter-composition-text').value = '';
  document.getElementById('filter-composition-element').value = '';
  sortBy = 'id';
  sortDir = 'desc'; 
  if(reload){
    window.__JO_ADV_RULES__?.clearBackground?.();
    updateSortIndicators();
    load(1);
  }
  else {
    window.__JO_ADV_RULES__?.clearBackground?.();
  }
}
let findParentDoiThrottleTimer = null;
let lastParentDoi = null;
function findEntriesByParentDOI(doi) {
  lastParentDoi = doi;
  if (findParentDoiThrottleTimer) return; 
  findParentDoiThrottleTimer = setTimeout(() => {
    findParentDoiThrottleTimer = null;
    resetSearchInput(false);
    const input = document.getElementById('filter-pub-doi');
    if (input) input.value = lastParentDoi || '';
    load(1);
  }, 500);
}
let findCompThrottleTimer = null;
let lastComp = null;
function findEntriesByComponent(component) {
  lastComp = component;
  if (findCompThrottleTimer) return; 
  findCompThrottleTimer = setTimeout(() => {
    findCompThrottleTimer = null;
    resetSearchInput(false);
    const input = document.getElementById('filter-composition-text');
    if (input) input.value = lastComp || '';
    load(1);
  }, 500);
}
function addComponentToMultiFilter(component, reload = false, concentration = '', unit = '') {
  if(concentration && unit) {
    window.__JO_ADV_RULES__?.clearBackground();
    window.__JO_ADV_RULES__?.addRule(component, unit, concentration, '=', true);
  }
  else {
    if(reload) window.__JO_ADV_RULES__?.clearBackground();
    window.__JO_ADV_RULES__?.addRule(component, 'any%', '0', '>', reload);
  }
}

function exportCitation(id, format) {
  window.location.href = `api/export_entry.php?type=citation&id=${encodeURIComponent(id)}&format=${encodeURIComponent(format)}`;
}
document.addEventListener('click', e => {
  const btn = e.target.closest('.comp-filter-btn');
  if (!btn) return;
  const comp = btn.dataset.component;
  if(btn.dataset.concentration && btn.dataset.unit) {
    resetSearchInput(false);
    addComponentToMultiFilter(comp, true, btn.dataset.concentration, btn.dataset.unit);
  }
  else {
    if (e.shiftKey) {
      addComponentToMultiFilter(comp, false);
    } else {
      resetSearchInput(false);
      addComponentToMultiFilter(comp, true);
    }
  }
});
document.addEventListener("click", (e) => {
  const toggle = e.target.closest(".btn-split-toggle");
  const clickedSplit = toggle?.closest(".btn-split") || null;
  const wasOpen = !!clickedSplit && clickedSplit.classList.contains("open");
  document.querySelectorAll(".btn-split.open").forEach(el => el.classList.remove("open"));
  if (clickedSplit && !wasOpen) {
    clickedSplit.classList.add("open");
    e.stopPropagation();
  }
});
document.getElementById('btn-search')?.addEventListener('click', () => load(1));
document.getElementById('btn-reset')?.addEventListener('click', () => {
  resetSearchInput();
});
load(1).catch(err => {
  console.error(err);
  countEl.textContent = 'Error loading entries';
});
