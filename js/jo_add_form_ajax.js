(() => {
  const form = document.getElementById('jo-add-form');
  if (!form) return;
  const statusEl = document.getElementById('jo-add-status');
  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (statusEl) statusEl.textContent = 'Submitting…';
    try {
      const fd = new FormData(form);
      const res = await fetch(form.action, {
        method: 'POST',
        body: fd
      });
      const text = await res.text();
      let data;
      try { data = JSON.parse(text); }
      catch { throw new Error('Server did not return JSON:\n' + text); }
      if (data.ok) {
        if (statusEl) {
          statusEl.textContent =
            `Saved! publication_id=${data.publication_id}, jo_record_id=${data.jo_record_id}` +
            (data.loms_file_path ? `, file=${data.loms_file_path}` : '');
        } else {
          alert(`Saved!\npublication_id=${data.publication_id}\njo_record_id=${data.jo_record_id}`);
        }
      } else {
        const msg = data.error || 'Unknown error';
        if (statusEl) statusEl.textContent = 'Error: ' + msg;
        else alert('Error: ' + msg);
      }
    } catch (err) {
      if (statusEl) statusEl.textContent = 'Error: ' + (err?.message || err);
      else alert('Error: ' + (err?.message || err));
    }
  });
})();
