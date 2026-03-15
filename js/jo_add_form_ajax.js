(() => {
  const form = document.getElementById('jo-add-form');
  if (!form) return;
  const statusEl = document.getElementById('jo-add-status');
  const btn = document.getElementById('form-submit-btn');
  function setStatus(msg, isOk, allowHtml = false) {
    if (!statusEl) {
      alert(msg || '');
      return;
    }
    if (allowHtml) statusEl.innerHTML = msg || '';
    else statusEl.textContent = msg || '';
    statusEl.style.fontWeight = '600';
    statusEl.style.color = isOk ? 'green' : 'crimson';
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, ch => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[ch]));
  }
  form.addEventListener('submit', async function (e) {
    const hasErrors = form.querySelector('.doi-status.is-error') !== null;
    if (hasErrors) {
      const proceed = confirm(
        "One or more components could not be interpreted.\nSubmit anyway?"
      );
      if (!proceed) {
        e.preventDefault();
        return;
      }
    }
    e.preventDefault();
    if (btn) btn.disabled = true;
    setStatus('Submitting…', true);
    try {
      const fd = new FormData(form);
      const auth = firebase.auth();
      const user = auth.currentUser;
      const token = await user?.getIdToken(true);
      const resp = await fetch(form.action, {
        method: 'POST',
        body: fd,
        headers: {
          "Authorization": `Bearer ${token}`
        }
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
        if (resp.status === 401) {
          setStatus('Unauthorized: Please refresh the page and log in again.', false);
          return;
        }
        setStatus('Error: ' + JSON.stringify(msg), false);
        return;
      }
      let html = '';
      if (data.jo_record_id) html = `Record #${escapeHtml(data.jo_record_id)} submitted for approval!`;
      else html = 'Record file generated! Send it to the <a href="mailto:info@loms.cz?subject=JO%20DB%20submission">administrator</a> for approval.';
      if (data.loms_db_file) {
        const blob = new Blob([data.loms_db_file], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const filename = `jo_record_${data.jo_record_id || 'draft'}.txt`;
        html += ` <a href="${url}" download="${escapeHtml(filename)}" style="margin-left:10px;"><i class="fa fa-download"></i> Download submission file</a>`;
        if (statusEl) {
          const oldCleanup = statusEl.dataset.downloadUrl;
          if (oldCleanup) URL.revokeObjectURL(oldCleanup);
          statusEl.dataset.downloadUrl = url;
        }
      }
      setStatus(html, true, true);
    } catch (err) {
      setStatus('Error: ' + JSON.stringify(err?.message || err), false);
    }
    finally {
      if (btn) btn.disabled = false;
    }
  });
})();
