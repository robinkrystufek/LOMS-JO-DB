(() => {
    function esc(s) {
      return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
      }[c]));
    }
  
    // same "pub detail overlay" vibe (copied + slightly retitled)
    function buildOverlayShell(titleText) {
      const overlay = document.createElement("div");
      overlay.style.cssText = `
        position:fixed; inset:0; z-index:2147483647;
        background:rgba(0,0,0,.45);
        display:flex; align-items:center; justify-content:center;
        font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      `;
  
      const panel = document.createElement("div");
      panel.style.cssText = `
        width:min(900px, calc(100vw - 24px));
        background:#fff;
        border-radius:6px;
        box-shadow:0 20px 60px rgba(0,0,0,.35);
        display:flex;
        flex-direction:column;
        overflow:hidden;
      `;
  
      const top = document.createElement("div");
      top.style.cssText = `
        padding:10px 12px;
        border-bottom:1px solid #eee;
        display:flex; align-items:center; gap:10px;
      `;
  
      const title = document.createElement("div");
      title.style.cssText = "font-weight:800; font-size:14px;";
      title.textContent = titleText || "Audit trail";
  
      const right = document.createElement("div");
      right.style.cssText = "margin-left:auto; display:flex; gap:8px; align-items:center;";
  
      const closeBtn = document.createElement("button");
      closeBtn.id = "jo-audit-close-btn";
      closeBtn.style.cssText = `
        padding:8px 10px;
        border:1px solid #ddd;
        border-radius:3px;
        background:#fafafa;
        cursor:pointer;
      `;
      closeBtn.textContent = "Close (Esc)";
  
      right.appendChild(closeBtn);
      top.append(title, right);
  
      const body = document.createElement("div");
      body.style.cssText = "padding:12px; overflow:auto;";
  
      panel.append(top, body);
      overlay.append(panel);
  
      return { overlay, body, closeBtn };
    }
  
    function renderTable(chain) {
      const rows = (chain || []).map((r, idx) => {
        const versionNumber = chain.length - idx;
        const badge = idx === 0
          ? `<span class="jo-db-badge">Current (v${versionNumber})</span>`
          : `<span class="jo-db-badge">v${versionNumber}</span><span style="font-weight: 1000;">⤴</span>`;
  
        return `
          <tr>
            <td style="font-weight:800; padding-left: 0.5em;">${esc(r.record_id)}</td>
            <td>${esc(r.submitted_by_name || '')}</td>
            <td>${esc(r.record_date_submitted || '')}</td>
            <td>${esc(r.approved_by_name) || ''}</td>
            <td>${esc(r.record_date_approved) || ''}</td>
            <td>${badge}</td>
          </tr>
        `;
      }).join("");
  
      return `
        <div style="border:1px solid #eee; border-radius:8px; overflow:hidden;">
          <table style="width:100%; table-layout: auto;" class="jo-db-table">
            <thead>
              <tr>
                <th style="padding-left: 0.5em; width: auto;">Record</th>
                <th style="width: auto;" colspan="2">Submitted by</th>
                <th style="width: auto;" colspan="2">Approved by</th>
                <th style="width: auto;"></th>
              </tr>
            </thead>
            <tbody>
              ${rows || `<tr><td colspan="4" style="padding-left: 0.5em; color:#777;">No audit info.</td></tr>`}
            </tbody>
          </table>
        </div>
      `;
    }
  
    async function openAuditTrail(recordId) {
      const rid = Number(recordId);
      const { overlay, body, closeBtn } = buildOverlayShell(`Audit trail (record #${rid})`);
  
      function close() {
        document.removeEventListener("keydown", onKey);
        overlay.remove();
      }
      function onKey(ev) {
        if (ev.key === "Escape") close();
      }
  
      closeBtn.addEventListener("click", close);
      overlay.addEventListener("click", (e) => {
        if (e.target === overlay) close();
      });
      document.addEventListener("keydown", onKey);
      document.body.append(overlay);
  
      if (!Number.isFinite(rid) || rid <= 0) {
        body.innerHTML = `<div style="color:crimson; font-weight:700;">Missing/invalid record id.</div>`;
        return;
      }
  
      body.innerHTML = `
        <div style="display:flex; align-items:center; gap:8px;">
          <i class="bx bx-loader-alt"></i>
          <div style="font-weight:700;">Loading…</div>
        </div>
      `;
  
      const resp = await fetch(`api/get_audit_trail.php?id=${encodeURIComponent(String(rid))}`, {
        headers: { "Accept": "application/json" }
      });
  
      const data = await resp.json().catch(() => null);
      if (!resp.ok || !data || data.ok !== true) {
        const msg = (data && data.error) ? data.error : `HTTP ${resp.status}`;
        body.innerHTML = `<div style="color:crimson; font-weight:800;">Error</div><div style="margin-top:6px;">${esc(msg)}</div>`;
        return;
      }
  
      body.innerHTML = renderTable(data.chain || []);
    }
  
    // event delegation (works with dynamic rows)
    document.addEventListener("click", (e) => {
      const btn = e.target.closest(".jo-audit-trail");
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      openAuditTrail(btn.getAttribute("data-id")).catch(err => {
        console.error(err);
        alert("Could not load audit trail.");
      });
    });
  })();