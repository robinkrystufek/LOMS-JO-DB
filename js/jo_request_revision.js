(() => {
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
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
        width:min(760px, calc(100vw - 24px));
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
      title.textContent = titleText || "Request revision";
      const body = document.createElement("div");
      body.style.cssText = "padding:12px;";
      top.append(title);
      panel.append(top, body);
      overlay.append(panel);
      function close() {
        document.removeEventListener("keydown", onKey);
        overlay.remove();
      }
      function onKey(e) {
        if (e.key === "Escape") close();
      }
      overlay.addEventListener("click", (e) => { if (e.target === overlay) close(); });
      document.addEventListener("keydown", onKey);
      return { overlay, body, close };
    }
    function openAddTab() {
      const tab = $("tab-add-btn");
      if (!tab) return false;
      if (tab.classList.contains("locked")) return false;
      tab.click();
      return true;
    }
    function setSelectByText(selectEl, desiredText) {
      if (!selectEl || !desiredText) return;
      const want = String(desiredText).trim();
      const opt = [...selectEl.options].find(o => (o.textContent || "").trim() === want)
               || [...selectEl.options].find(o => (o.textContent || "").trim().toLowerCase() === want.toLowerCase());
      if (opt) selectEl.value = opt.value;
    }
    function setRadioByName(name, value) {
      if (!name) return;
      const el = document.querySelector(`input[type="radio"][name="${CSS.escape(name)}"][value="${CSS.escape(value)}"]`);
      if (el) el.checked = true;
    }
    function setInput(id, v) {
      const el = $(id);
      if (!el) return;
      el.value = (v ?? "");
    }
    function populateCompositionGrid(comps) {
      const tbody = $("comp-grid-body");
      if (!tbody) return;
      const rows = [...tbody.querySelectorAll("tr")];
      rows.slice(1).forEach(r => r.remove());
      const first = tbody.querySelector("tr");
      const fillRow = (tr, c) => {
        tr.querySelector('input[name="comp_component[]"]')?.setAttribute("value", "");
        const inComp = tr.querySelector('input[name="comp_component[]"]');
        const inVal  = tr.querySelector('input[name="comp_value[]"]');
        const selU   = tr.querySelector('select[name="comp_unit[]"]');
        if (inComp) inComp.value = c?.component ?? "";
        if (inVal)  inVal.value  = (c?.value ?? "");
        if (selU && c?.unit) selU.value = c.unit;
      };
      const list = Array.isArray(comps) ? comps : [];
      if (!list.length) return;
      fillRow(first, list[0]);
      for (let i = 1; i < list.length; i++) {
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td><input class="jo-db-datagrid-input" name="comp_component[]" value="" /></td>
          <td><input class="jo-db-datagrid-input" name="comp_value[]" value="" /></td>
          <td>
            <select class="jo-db-datagrid-input" name="comp_unit[]">
              <option value="mol%">mol%</option>
              <option value="wt%">wt%</option>
              <option value="at%">at%</option>
            </select>
          </td>
          <td class="jo-db-datagrid-actions">
            <button type="button" class="btn btn-secondary btn-sm jo-row-remove" title="Remove row">
              <i class="fa fa-trash"></i>
            </button>
          </td>
        `;
        tbody.appendChild(tr);
        fillRow(tr, list[i]);
      }
    }
    function populateAddFormFromRecord(rec) {
      const d = rec?.details || {};
      setInput("pub-doi", rec?.doi || "");
      setInput("pub-title", rec?.pub_title || "");
      setInput("pub-authors", rec?.pub_authors || "");
      setInput("pub-journal", rec?.pub_journal || "");
      setInput("pub-year", rec?.pub_year || "");
      setInput("pub-url", rec?.pub_url || (rec?.doi ? ("https://doi.org/" + rec.doi) : ""));
      setSelectByText($("host-type"), d?.host || rec?.host || "");
      setInput("sample-label", rec?.sample_label || "");
      setInput("comp-text", d?.composition || rec?.composition || "");
      populateCompositionGrid(d?.composition_components || []);
      setInput("re-ion", rec?.re_ion || "");

      const hasRange = $("has-range-re");
      if (hasRange) hasRange.checked = (rec?.concentration_upper != "");
      hasRange?.dispatchEvent(new Event("change"));
      setInput("re-conc", rec?.concentration_lower || "");
      setInput("re-conc-upper", rec?.concentration_upper || "");
      setSelectByText($("re-conc-unit"), rec?.concentration_unit || "");
  
      setInput("omega2", rec?.omega2 ?? "");
      setInput("omega4", rec?.omega4 ?? "");
      setInput("omega6", rec?.omega6 ?? "");
      const hasErr = $("has-omega-error");
      if (hasErr) hasErr.checked = (rec?.omega2_error != null || rec?.omega4_error != null || rec?.omega6_error != null);
      $("omega2-error") && ( $("omega2-error").value = (rec?.omega2_error ?? "") );
      $("omega4-error") && ( $("omega4-error").value = (rec?.omega4_error ?? "") );
      $("omega6-error") && ( $("omega6-error").value = (rec?.omega6_error ?? "") );
      hasErr?.dispatchEvent(new Event("change"));
  
      const bs = Array.isArray(rec?.badges_states) ? rec.badges_states : [];
      if (Number.isFinite(bs[0])) {
        const mapRI = { 0:"no", 1:"unknown", 2:"single-value", 3:"dispersion-relation" };
        setRadioByName("refractive_index_option", mapRI[bs[0]] || "unknown");
      }
      if (Number.isFinite(bs[1])) {
        const map = { 0:"no", 1:"unknown", 2:"yes" };
        setRadioByName("combinatorial_jo_option", map[bs[1]] || "unknown");
      }
      if (Number.isFinite(bs[2])) {
        const map = { 0:"no", 1:"unknown", 2:"yes" };
        setRadioByName("sigma_f_s_option", map[bs[2]] || "unknown");
      }
      if (Number.isFinite(bs[3])) {
        const map = { 0:"no", 1:"unknown", 2:"yes" };
        setRadioByName("mag_dipole_option", map[bs[3]] || "unknown");
      }
      if (Number.isFinite(bs[4])) {
        const map = { 0:"no", 1:"unknown", 2:"yes" };
        setRadioByName("reduced_element_option", map[bs[4]] || "unknown");
      }
      if (Number.isFinite(bs[5])) {
        const mapJO = { 0:"original", 1:"unknown", 2:"recalc" };
        setRadioByName("jo_source", mapJO[bs[5]] || "unknown");
      }
      if (rec?.has_density === 2) setRadioByName("has_density", "yes");
      else if (rec?.has_density === 1) setRadioByName("has_density", "unknown");
      else if (rec?.has_density === 0) setRadioByName("has_density", "no");

      const bn = Array.isArray(rec?.badges_notes) ? rec.badges_notes : [];
      setInput("refractive-index-note", bn[0] || "");
      setInput("combinatorial-jo-note", bn[1] || "");
      setInput("sigma-f-s-note", bn[2] || "");
      setInput("mag-dipole-note", bn[3] || "");
      setInput("reduced-element-note", bn[4] || "");
      setInput("jo-source-note", bn[5] || "");
      setInput("is-revision-of-id", rec?.jo_record_id || null);
      setInput("density-gcm3", rec?.density ?? "");
      setInput("notes", rec?.notes + "[Revision of record #" + rec?.jo_record_id + "]" || "");
    }

    document.addEventListener("click", async (e) => {
      const btn = e.target.closest(".jo-request-revision");
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      if ($("tab-add-btn")?.classList.contains("locked")) {
        alert("You need to be registered to use this feature.");
        return;
      }
      const id = Number(btn.getAttribute("data-id"));
      const rec = window.__JO_RECORD_CACHE__?.[id];
      if (!rec) {
        alert("Record data not found in cache (try reloading results).");
        return;
      }
      const confirmUI = buildOverlayShell(`Request revision for record #${esc(id)}?`);
      confirmUI.body.innerHTML = `
        <div style="color: #555; font-size: 13px; margin-bottom:2em;">
            You will be taken to the "Add new entry" form with the data from this record pre-filled. You can then make necessary changes and submit it as a revision to this record.
        </div>
        <div class="jo-db-btn-group">
          <button class="btn btn-primary btn-sm" id="jo-rr-yes">
            <i class="fa fa-check"></i>&nbsp;&nbsp;Yes
          </button>
          <button class="btn btn-secondary btn-sm" id="jo-rr-no">
            <i class="fa fa-times"></i>&nbsp;&nbsp;No
          </button>
        </div>
      `;
      document.body.append(confirmUI.overlay);
      confirmUI.body.querySelector("#jo-rr-no")?.addEventListener("click", () => confirmUI.close());
      confirmUI.body.querySelector("#jo-rr-yes")?.addEventListener("click", async () => {
        confirmUI.close();
        const spinUI = buildOverlayShell("Preparing revision…");
        spinUI.body.innerHTML = `
            <div style="display:flex; align-items:center; gap:10px;">
            <i class="fa fa-repeat fa-spin" style="font-size:18px;"></i>
            <div style="font-weight:800;">Copying record data into “Add new entry”…</div>
            </div>
        `;
        document.body.append(spinUI.overlay);
        try {
            populateAddFormFromRecord(rec);
            const ok = openAddTab();
            if (!ok) throw new Error("Could not open Add new entry tab.");
        } 
        catch (err) {
            alert("Could not prepare revision: " + (err?.message || err));
        } 
        finally {
            spinUI.close();
        }
      });
    });
})();