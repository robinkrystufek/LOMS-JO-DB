(() => {
  const form = document.getElementById('jo-add-form');
  if (!form) return;
  const statusEl = document.getElementById('jo-add-status');
  const btn = document.getElementById('form-submit-btn');
  function setStatus(msg, isOk) {
    if (!statusEl) {
      alert(msg || '');
      return;
    }
    statusEl.textContent = msg || '';
    statusEl.style.fontWeight = '600';
    statusEl.style.color = isOk ? 'green' : 'crimson';
  }
  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (btn) btn.disabled = true;
    setStatus('Submitting…', true);
    try {
      const fd = new FormData(form);
      const resp = await fetch(form.action, {
        method: 'POST',
        body: fd
      });
      const text = await resp.text();
      let data;
      try { 
        data = JSON.parse(text); 
      }
      catch { 
        setStatus('Error: Non-JSON response', false);
        console.error("Server did not return JSON:", text, "status:", resp.status);
        return;
      }
      if (!resp.ok || !data || data.ok !== true) {
        const msg = (data && data.error) ? data.error : ('HTTP ' + resp.status);
        setStatus('Error: ' + msg, false);
        return;
      }
      setStatus(`Record #${data.jo_record_id} submitted for approval!`, true);
    } catch (err) {
      setStatus('Error: ' + (err?.message || err), false);
    }
    finally {
      if (btn) btn.disabled = false;
    }
  });
})();
