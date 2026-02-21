(() => {
  const $ = (id) => document.getElementById(id);
  const doiEl     = $("pub-doi");
  const statusEl  = $("pub-doi-status");
  const yearEl    = $("pub-year");
  const titleEl   = $("pub-title");
  const authorsEl = $("pub-authors");
  const journalEl = $("pub-journal");
  const urlEl     = $("pub-url");
  const rawEl     = $("article-metadata");
  let lastLookedUpDoi = "";
  let dirty = false;
  let inFlight = null;
  function looksLikeDoi(doi) {
    return /^10\.\d{4,9}\/\S+$/i.test(doi);
  }
  function normalizeDoi(input) {
    if (!input) return "";
    let s = input.trim();
    s = s.replace(/^https?:\/\/(dx\.)?doi\.org\//i, "");
    s = s.replace(/^doi:\s*/i, "");
    s = s.replace(/\s+/g, "");
    return s;
  }
  function setRaw(raw) {
    try { rawEl.value = JSON.stringify(raw || [], null, 0); }
    catch { rawEl.value = "[]"; }
  }
  function setStatus(state) {
    statusEl.className = "doi-status is-visible";
    if (state === "idle") {
      statusEl.className = "doi-status";
      statusEl.innerHTML = "";
      return;
    }
    if (state === "loading") {
      statusEl.classList.add("is-loading");
      statusEl.innerHTML = "<i class='bx bx-loader-alt'></i>";
      return;
    }
    if (state === "success") {
      statusEl.classList.add("is-success");
      statusEl.innerHTML = "<i class='bx bx-check'></i>";
      return;
    }
    if (state === "error") {
      statusEl.classList.add("is-error");
      statusEl.innerHTML = "<i class='bx bx-x'></i>";
      return;
    }
  }
  async function lookupIfNeeded() {
    const doi = normalizeDoi(doiEl.value);
    doiEl.value = doi;
    if (!doi) { setStatus("idle"); setRaw([]); dirty = false; return; }
    if (!looksLikeDoi(doi)) {
      setStatus("error");
      setRaw([]);
      dirty = false;
      return;
    }
    if (!dirty && doi === lastLookedUpDoi) return;
    if (inFlight) {
      try { inFlight.abort(); } catch {}
    }
    inFlight = new AbortController();
    setStatus("loading");
    try {
      const resp = await fetch("api/doi_lookup.php?doi=" + encodeURIComponent(doi), {
        method: "GET",
        headers: { "Accept": "application/json" },
        signal: inFlight.signal
      });
      const data = await resp.json().catch(() => null);
      lastLookedUpDoi = doi;
      dirty = false;
      if (!resp.ok || !data || data.error) {
        setStatus("error");
        setRaw([]);
        return;
      }
      titleEl.value   = data.title   || "";
      authorsEl.value = data.authors || "";
      yearEl.value    = data.year    || "";
      journalEl.value = data.journal || "";
      urlEl.value     = data.url     || ("https://doi.org/" + doi);
      setRaw(data.raw || []);
      setStatus("success");
    } 
    catch (e) {
      if (e && (e.name === "AbortError")) return;
      lastLookedUpDoi = normalizeDoi(doiEl.value);
      dirty = false;
      setStatus("error");
      setRaw([]);
    } 
    finally {
      inFlight = null;
    }
  }
  doiEl.addEventListener("input", () => {
    dirty = true;
    setStatus("idle");
  });
  doiEl.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      lookupIfNeeded();
    }
  });
  doiEl.addEventListener("blur", () => {
    lookupIfNeeded();
  });
})();
