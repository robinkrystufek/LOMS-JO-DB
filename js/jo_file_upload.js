(() => {
  const form = document.getElementById('jo-add-form-file');
  if (!form) return;
  const btn = document.getElementById('file-submit-btn');
  const statusEl = document.getElementById('file-submit-status');
  function setStatus(msg, isOk) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.style.fontWeight = '600';
    statusEl.style.color = isOk ? 'green' : 'crimson';
  }
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    setStatus('', true);
    const entryFile = form.querySelector('input[type="file"][name="jo_db_file_file_format"]');
    if (entryFile && (!entryFile.files || entryFile.files.length === 0)) {
      setStatus('Error: please select an entry file to upload.', false);
      return;
    }
    if (btn) btn.disabled = true;
    setStatus('Saving...', true);
    try {
      const fd = new FormData(form);
      const resp = await fetch(form.action, {
        method: 'POST',
        body: fd,
        headers: { 'Accept': 'application/json' }
      });
      const text = await resp.text();
      let data;
      try {
        data = JSON.parse(text);
      } 
      catch (err) {
        setStatus('Error: server did not return JSON. Check PHP errors/logs.', false);
        console.error("Non-JSON response:", text, "status:", resp.status);
        return;
      }
      if (!resp.ok || !data || data.ok !== true) {
        const msg = (data && data.error) ? data.error : ('HTTP ' + resp.status);
        setStatus('Error: ' + msg, false);
        return;
      }
      const pub = data.publication_id ? `pub_id=${data.publication_id}` : '';
      const rec = data.jo_record_id ? `record_id=${data.jo_record_id}` : '';
      const extra = [rec, pub].filter(Boolean).join(', ');
      setStatus('Saved' + (extra ? ` (${extra})` : '') + '.', true);
    } 
    catch (err) {
      console.error(err);
      setStatus('Error: network or server failure.', false);
    } 
    finally {
      if (btn) btn.disabled = false;
    }
  });
})();
