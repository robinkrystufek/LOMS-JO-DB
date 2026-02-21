(() => {
  const tbody = document.getElementById('comp-grid-body');
  const addBtn = document.getElementById('comp-add-row');
  if (!tbody || !addBtn) return;
  function rowCount() {
    return tbody.querySelectorAll('tr').length;
  }
  function escapeHtml(str) {
    return String(str)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }
  function makeRow({ component = '', value = '', unit = 'mol%' } = {}) {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>
        <input class="jo-db-datagrid-input" name="comp_component[]" value="${escapeHtml(component)}" />
      </td>
      <td>
        <input class="jo-db-datagrid-input" name="comp_value[]" value="${escapeHtml(String(value))}" />
      </td>
      <td>
        <select class="jo-db-datagrid-input" name="comp_unit[]">
          <option value="mol%" ${unit === 'mol%' ? 'selected' : ''}>mol%</option>
          <option value="wt%" ${unit === 'wt%' ? 'selected' : ''}>wt%</option>
          <option value="at%" ${unit === 'at%' ? 'selected' : ''}>at%</option>
        </select>
      </td>
      <td class="jo-db-datagrid-actions">
        <button type="button" class="btn btn-secondary btn-sm jo-row-remove" title="Remove row">
          <i class="fa fa-trash"></i>
        </button>
      </td>
    `;
    return tr;
  }
  addBtn.addEventListener('click', () => {
    const tr = makeRow();
    tbody.appendChild(tr);
    tr.querySelector('input[name="comp_component[]"]')?.focus();
  });
  tbody.addEventListener('click', (e) => {
    const btn = e.target.closest('.jo-row-remove');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (!tr) return;
    if (rowCount() <= 1) {
      alert('At least one composition row is required.');
      return;
    }
    tr.remove();
  });
})();
