(() => {
    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }
    function tryJsonParse(s) {
        if (!s || typeof s !== "string") return null;
        try { return JSON.parse(s); } catch { return null; }
    }
    function doiToUrl(doi) {
        if (!doi) return "";
        return "https://doi.org/" + encodeURIComponent(String(doi).replace(/^https?:\/\/(dx\.)?doi\.org\//i, ""));
    }
    function openalexIdToUrl(id) {
        if (!id) return "";
        return String(id).startsWith("http") ? String(id) : ("https://openalex.org/" + String(id));
    }
    function fmtPct(x) {
        const n = Number(x);
        if (!Number.isFinite(n)) return "";
        return (n * 100).toFixed(1) + "%";
    } 
    function el(tag, attrs = {}, children = []) {
        const n = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs || {})) {
            if (k === "style") n.style.cssText = String(v);
            else if (k === "class") n.className = String(v);
            else if (k.startsWith("on") && typeof v === "function") n.addEventListener(k.slice(2), v);
            else if (v !== null && v !== undefined) n.setAttribute(k, String(v));
        }
        for (const ch of ([]).concat(children || [])) {
            if (ch === null || ch === undefined) continue;
            n.append(typeof ch === "string" ? document.createTextNode(ch) : ch);
        }
        return n;
    }
    function normalizeDoiKey(d) {
        if (!d) return "";
        return String(d)
          .trim()
          .replace(/^https?:\/\/(dx\.)?doi\.org\//i, "")
          .replace(/^doi:\s*/i, "")
          .replace(/\s+/g, "")
          .toLowerCase();
    }
    async function resolveDois(dois) {
        const keys = Array.from(new Set((dois || []).map(normalizeDoiKey).filter(Boolean)));
        if (!keys.length) return {};
      
        const resp = await fetch("api/get_record_doi.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            body: JSON.stringify({ dois: keys })
        });
      
        const data = await resp.json().catch(() => null);
        if (!resp.ok || !data || data.ok !== true) return {};
        return data.by_doi || {};
    }
    function buildOverlayShell(titleText) {
        const overlay = el("div", { style: `
            position:fixed; inset:0; z-index:2147483647;
            background:rgba(0,0,0,.45);
            display:flex; align-items:center; justify-content:center;
            font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
        `});
        const panel = el("div", { style: `
            width:min(1100px, calc(100vw - 24px));
            height:min(760px, calc(100vh - 24px));
            background:#fff;
            border-radius:6px;
            box-shadow:0 20px 60px rgba(0,0,0,.35);
            display:flex;
            flex-direction:column;
            overflow:hidden;
        `});
        const top = el("div", { style: `
            padding:10px 12px;
            border-bottom:1px solid #eee;
            display:flex; align-items:center; gap:10px;
        `}, [
            el("div", { style: "font-weight:800; font-size:14px;" }, titleText || "Parent publication data"),
            el("div", { style: "margin-left:auto; display:flex; gap:8px; align-items:center;" }, [
            el("button", {
                style: `
                padding:8px 10px;
                border:1px solid #ddd;
                border-radius:3px;
                background:#fafafa;
                cursor:pointer;
                `,
                id: "jo-alex-close-btn"
            }, "Close (Esc)")
            ])
        ]);
        const body = el("div", { style: "padding:12px; overflow:auto;" });
        panel.append(top, body);
        overlay.append(panel);
        return { overlay, body, closeBtn: top.querySelector("#jo-alex-close-btn") };
    }
    function renderSummary(row, oaWork, crMsg, oaCitations, meta = {}) {
        const title = oaWork?.title || row.title || "(no title)";
        const year = oaWork?.publication_year || row.year || "";
        const journal = oaWork?.primary_location?.source?.display_name || row.journal || "";
        const doi = (oaWork?.doi || row.doi || "").replace(/^https?:\/\/(dx\.)?doi\.org\//i, "");
        const doiUrl = doi ? doiToUrl(doi) : (row.url || "");
        const type = oaWork?.type ? oaWork.type.replace(/-/g, " ") : "";
        const lang = oaWork?.language || "";
        const oa = oaWork?.open_access?.oa_status || (oaWork?.open_access?.is_oa ? "OA" : "closed access");
        const citedBy = oaWork?.cited_by_count;
        const refCountOA = oaWork?.referenced_works_count ?? (Array.isArray(oaWork?.referenced_works) ? oaWork.referenced_works.length : null);
        const refCountCR = crMsg?.["reference-count"] ?? (Array.isArray(crMsg?.reference) ? crMsg.reference.length : null);
        const citingCount = oaCitations?.total ?? (Array.isArray(oaCitations?.items) ? oaCitations.items.length : null);
        const joCount = Number.isFinite(Number(meta.jo_records_count_for_doi))
        ? Number(meta.jo_records_count_for_doi)
        : null;

        const authors = (() => {
            const a = oaWork?.authorships;
            if (Array.isArray(a) && a.length) {
                return a.map(x => x?.author?.display_name).filter(Boolean).join(", ");
            }
            return row.authors || "";
        })();
      
        return `
        <div style="border:1px solid #eee; border-radius:8px; background:#fafafa; padding:12px;">
            <div style="font-weight:900; font-size:15px; line-height:1.25;">${esc(title)}</div>
            <div style="margin-top:6px; color:#555; font-size:13px;">
                ${authors ? `<div><b>Authors:</b> ${esc(authors)}</div>` : ""}
                ${(journal || year) ? `<div><b>Venue:</b> ${esc(journal)}${year ? ` (${esc(year)})` : ""}</div>` : ""}
                ${doi ? `<div><b>DOI:</b> <a href="${esc(doiUrl)}" target="_blank" rel="noopener">${esc(doi)}</a></div>` : ""}
                </div>
                <div style="margin-top:10px; display:flex; flex-wrap:wrap; gap:10px; font-size:12px;">
                ${Number.isFinite(joCount) ? `
                <span role="button" title="Show entries from this publication" data-jo-doi="${esc(doi)}"= onclick="findEntriesByParentDOI('${esc(doi)}');" id="find-jo-records-btn"
                style="border:1px solid #2F6FDB; background:#2F6FDB; color:#fff; padding:3px 10px; border-radius:999px; cursor:pointer; font-weight:600; transition:all .15s ease; user-select:none;"
                onmouseover="this.style.background='#255ac0'"
                onmouseout="this.style.background='#2F6FDB'"
                onmousedown="this.style.transform='scale(0.96)'"
                onmouseup="this.style.transform='scale(1)'"><b>JO records</b>: ${esc(joCount)}</span>` : ""}
                ${type ? `<span style="border:1px solid #ddd; background:#fff; padding:3px 8px; border-radius:999px;"><b>Type</b>: ${esc(type)}</span>` : ""}
                ${lang ? `<span style="border:1px solid #ddd; background:#fff; padding:3px 8px; border-radius:999px;"><b>Lang</b>: ${esc(lang)}</span>` : ""}
                ${oa ? `<span style="border:1px solid #ddd; background:#fff; padding:3px 8px; border-radius:999px;"><b>OA</b>: ${esc(oa)}</span>` : ""}
                ${Number.isFinite(refCountCR) ? `<span style="border:1px solid #ddd; background:#fff; padding:3px 8px; border-radius:999px;"><b>References (Crossref)</b>: ${esc(refCountCR)}</span>` : ""}
                ${Number.isFinite(refCountOA) ? `<span style="border:1px solid #ddd; background:#fff; padding:3px 8px; border-radius:999px;"><b>References (OA)</b>: ${esc(refCountOA)}</span>` : ""}
                ${Number.isFinite(citedBy) ? `<span style="border:1px solid #ddd; background:#fff; padding:3px 8px; border-radius:999px;"><b>Cited by</b>: ${esc(citedBy)}</span>` : ""}
                ${Number.isFinite(citingCount) ? `<span style="border:1px solid #ddd; background:#fff; padding:3px 8px; border-radius:999px;"><b>Citations list</b>: ${esc(citingCount)}</span>` : ""}
            </div>
        </div>
        `;
    }
    function renderTopics(oaWork) {
        const topics = Array.isArray(oaWork?.topics) ? oaWork.topics : [];
        if (!topics.length) return `<div style="color:#666;">No topics.</div>`;
        const rows = topics
            .slice(0, 20)
            .map(t => {
            const name = t.display_name || "";
            const score = fmtPct(t.score);
            const field = t.field?.display_name || "";
            const subfield = t.subfield?.display_name || "";
            const domain = t.domain?.display_name || "";
            return `
                <div style="padding:8px 10px; border:1px solid #eee; border-radius:8px; background:#fafafa;">
                <div style="font-weight:800;">${esc(name)}${score ? ` <span style="color:#666; font-weight:600;">(${esc(score)})</span>` : ""}</div>
                <div style="margin-top:3px; font-size:12px; color:#555;">
                    ${domain ? `<span><b>Domain:</b> ${esc(domain)}</span> &nbsp;` : ""}
                    ${field ? `<span><b>Field:</b> ${esc(field)}</span> &nbsp;` : ""}
                    ${subfield ? `<span><b>Subfield:</b> ${esc(subfield)}</span>` : ""}
                </div>
                </div>
            `;
            }).join("");
        return `<div style="display:grid; gap:10px;">${rows}</div>`;
    }
    function firstSurnameFromAuthors(authorsStr) {
        if (!authorsStr) return "";
        const s = String(authorsStr).trim();
        if (!s) return "";
        const first = s.split(/;| and /i)[0].trim();
        if (!first) return "";
        const etal = first.match(/^([A-Za-zÀ-ž'’-]+)\s+et\s+al\.?$/i);
        if (etal) return etal[1];
        const comma = first.match(/^([A-Za-zÀ-ž'’-]+)\s*,/);
        if (comma) return comma[1];
        const tokens = first.split(/\s+/).filter(Boolean);
        if (
            tokens.length >= 2 &&
            /^al\.?$/i.test(tokens[tokens.length - 1]) &&
            /^et$/i.test(tokens[tokens.length - 2])
        ) {
            tokens.splice(-2, 2); // remove "et al"
        }
        if (!tokens.length) return "";
        return tokens[tokens.length - 1].replace(/[.,;:]+$/g, "");
    }
    function renderPubCard({ headline, subline, doi, extraLink, badgeInsert }) {
        const doiClean = (doi || "").trim();
        const doiUrl = doiClean ? doiToUrl(doiClean) : "";
        const linkBits = [];
        if (doiClean && doiUrl) {
            linkBits.push(`DOI: <a href="${esc(doiUrl)}" target="_blank" rel="noopener">${esc(doiClean)}</a>`);
        }
        if (extraLink?.url && extraLink?.label) {
            linkBits.push(`<a href="${esc(extraLink.url)}" target="_blank" rel="noopener">${esc(extraLink.label)}</a>`);
        }
        return `
            <div style="padding:10px; border:1px solid #eee; border-radius:8px; background:#fafafa;">
            <div style="font-weight:800; font-size:13px; line-height:1.25;">${esc(headline || "(no title)")}</div>
            ${subline ? `<div style="margin-top:4px; font-size:12px; color:#555;">${esc(subline)}</div>` : ""}
            ${linkBits.length ? `<div style="margin-top:6px; font-size:12px; color:#555;">${badgeInsert || ""}${linkBits.join(" &nbsp;•&nbsp; ")}</div>` : ""}
            </div>
        `;
    }                                                     
    function renderCrossrefReferences(crMsg, byDoi = {}) {
        const refs0 = Array.isArray(crMsg?.reference) ? crMsg.reference : [];
        if (!refs0.length) return `<div style="color:#666;">No Crossref reference list.</div>`;
        const refs = refs0.map((r, i) => {
            const doi = r?.DOI || "";
            const hit = byDoi[normalizeDoiKey(doi)] || null;
            const jo = hit ? Number(hit.jo_count || 0) : 0;
            return { r, i, jo };
        });
        refs.sort((a, b) => (b.jo - a.jo) || (a.i - b.i));
        const items = refs.map(({ r }, idx) => {
            const author = r.author || "";
            const year = r.year || "";
            const jt = r["journal-title"] || r["series-title"] || "";
            const title = r["article-title"] || "";
            const vol = r.volume || "";
            const fp = r["first-page"] || "";
            const doi = r.DOI || "";
            const headline =
            [author, year, (title || jt)].filter(Boolean).join(" · ") || `Reference #${idx + 1}`;
            const subline = [jt, vol ? `vol. ${vol}` : "", fp ? `p. ${fp}` : ""]
            .filter(Boolean)
            .join(", ");
            const doiKey = normalizeDoiKey(doi);
            const hit = doiKey ? byDoi[doiKey] : null;
            if (hit && Number(hit.jo_count || 0) > 0) {
            return renderPubCard({
                headline,
                subline,
                doi,
                badgeInsert: `<span style="border: 1px solid rgb(47, 111, 219); background: rgb(47, 111, 219); color: rgb(255, 255, 255); padding: 1px 7px; margin-right: 6px;" title="Show entries from this publication" class="jo-doi-hit jo-db-badge" data-jo-doi="${esc(hit.doi)}"onmouseover="this.style.background='#255ac0'" onmouseout="this.style.background='#2F6FDB'" onmousedown="this.style.transform='scale(0.96)'" onmouseup="this.style.transform='scale(1)'"><b>JO records</b>: ${esc(hit.jo_count)}</span>`
                });
            }
            return renderPubCard({ headline, subline, doi });
        }).join("");
        return `<div style="display:grid; gap:10px;">${items}</div>`;
    } 
    function renderCitingWorks(oaCitations, byDoi = {}) {
        const items0 = Array.isArray(oaCitations?.items) ? oaCitations.items : [];
        if (!items0.length) return `<div style="color:#666;">No citing works list.</div>`;
        const items = items0.map((it, i) => {
          const doi = it?.doi || "";
          const hit = byDoi[normalizeDoiKey(doi)] || null;
          const jo = hit ? Number(hit.jo_count || 0) : 0;
          return { it, i, jo };
        });
        items.sort((a, b) => (b.jo - a.jo) || (a.i - b.i));
        const rows = items.map(({ it }) => {
        const journal = it.journal || "";
        const title = it.title || "";
        const year = it.year || "";
        const authors = it.authors || "";
        const firstSurname = firstSurnameFromAuthors(authors);
        const doi = it.doi || "";
        const headline = [firstSurname, year, (title || journal)].filter(Boolean).join(" · ") || "(no title)";
        const subline = journal + ", " + it.biblio || "";
        const oaUrl = it.alex_id ? openalexIdToUrl(it.alex_id) : "";
        const doiKey = normalizeDoiKey(doi);
        const hit = doiKey ? byDoi[doiKey] : null;
        if (hit && Number(hit.jo_count || 0) > 0) {
            return renderPubCard({
                headline,
                subline,
                doi,
                extraLink: oaUrl ? { label: "OpenAlex", url: oaUrl } : null,
                badgeInsert: `<span style="border: 1px solid rgb(47, 111, 219); background: rgb(47, 111, 219); color: rgb(255, 255, 255); padding: 1px 7px; margin-right: 6px;" title="Show entries from this publication" class="jo-doi-hit jo-db-badge" data-jo-doi="${esc(hit.doi)}"onmouseover="this.style.background='#255ac0'" onmouseout="this.style.background='#2F6FDB'" onmousedown="this.style.transform='scale(0.96)'" onmouseup="this.style.transform='scale(1)'"><b>JO records</b>: ${esc(hit.jo_count)}</span>`
            });
        }
        return renderPubCard({
            headline,
            subline,
            doi,
            extraLink: oaUrl ? { label: "OpenAlex", url: oaUrl } : null
        });
        }).join("");
        return `<div style="display:grid; gap:10px;">${rows}</div>`;
    }
    async function openAlexRefsOverlay({ doi, pubId }) {
        const cleanDoi = normalizeDoiKey(doi);
        const { overlay, body, closeBtn } = buildOverlayShell(
            cleanDoi ? `Parent publication details (DOI: ${cleanDoi})` : "Parent publication details"
        );
        function close() {
            document.removeEventListener("keydown", onKey);
            overlay.remove();
        }
        function onKey(ev) {
            if (ev.key === "Escape") close();
        }

        closeBtn.addEventListener("click", close);
        overlay.addEventListener("click", (e) => {
            if (e.target === overlay) close(); // click outside panel closes
        });
        document.addEventListener("keydown", onKey);
        document.body.append(overlay);
        if (!cleanDoi && !pubId) {
            body.innerHTML = `<div style="color:crimson; font-weight:700;">No DOI available for this entry.</div>`;
            return;
        }
        body.innerHTML = `<div style="display:flex; align-items:center; gap:8px;">
            <i class="bx bx-loader-alt"></i>
            <div style="font-weight:700;">Loading…</div>
            </div>`;
        const qs = new URLSearchParams();
        if (cleanDoi) qs.set("doi", cleanDoi);
        if (pubId) qs.set("publication_id", String(pubId));
        const resp = await fetch(`api/get_pub_metadata.php?${qs.toString()}`, {
            method: "GET",
            headers: { "Accept": "application/json" }
        });
        const data = await resp.json().catch(() => null);
        if (!resp.ok || !data || data.ok !== true) {
            const msg = (data && data.error) ? data.error : `HTTP ${resp.status}`;
            body.innerHTML = `<div style="color:crimson; font-weight:700;">Error:</div>
                                <div style="margin-top:6px;">${esc(msg)}</div>`;
            return;
        }
        const row = data.raw_row || {};
        const oaWork = tryJsonParse(row.alex_refs);
        const oaCitations = tryJsonParse(row.alex_citations);
        const cr = tryJsonParse(row.metadata); 
        const crMsg = cr?.data?.message || null;
        const crossrefDois = Array.isArray(crMsg?.reference)
        ? crMsg.reference.map(r => r?.DOI).filter(Boolean)
        : [];
        const citedByDois = Array.isArray(oaCitations?.items)
        ? oaCitations.items.map(it => it?.doi).filter(Boolean)
        : [];
        const byDoi = await resolveDois([...crossrefDois, ...citedByDois]);
        const refs = Array.isArray(data.refs) ? data.refs : null;
        const metaLines = [];
        if (data.meta && typeof data.meta === "object") {
            if (data.meta.source) metaLines.push(`<div><b>Source</b>: ${esc(data.meta.source)}</div>`);
            if (data.meta.updated_at) metaLines.push(`<div><b>Updated</b>: ${esc(data.meta.updated_at)}</div>`);
            if (Number.isFinite(data.meta.count)) metaLines.push(`<div><b>Count</b>: ${esc(data.meta.count)}</div>`);
        }
        const summaryHtml = renderSummary(row, oaWork, crMsg, oaCitations, data.meta || {});
        const topicsHtml = oaWork ? renderTopics(oaWork) : `<div style="color:#666;">No OpenAlex payload.</div>`;
        const crossrefRefsHtml = renderCrossrefReferences(crMsg, byDoi);
        const citingHtml = renderCitingWorks(oaCitations, byDoi);
        body.innerHTML = `
            ${summaryHtml}
            <div style="margin-top:12px; display:grid; gap:10px;">
            <details open style="border:1px solid #eee; border-radius:8px; background:#fff; padding:10px;">
                <summary style="cursor:pointer; font-weight:900;">Topics & categories (OpenAlex)</summary>
                <div style="margin-top:10px;">${topicsHtml}</div>
            </details>
            <details style="border:1px solid #eee; border-radius:8px; background:#fff; padding:10px;">
                <summary style="cursor:pointer; font-weight:900;">References</summary>
                <div style="margin-top:10px;">${crossrefRefsHtml}</div>
            </details>
            <details style="border:1px solid #eee; border-radius:8px; background:#fff; padding:10px;">
                <summary style="cursor:pointer; font-weight:900;">Cited by</summary>
                <div style="margin-top:10px;">${citingHtml}</div>
            </details>
            </div>`;
        body.addEventListener("click", (e) => {
            const hit = e.target.closest(".jo-doi-hit");
            if (!hit) return;
            const doi = hit.getAttribute("data-jo-doi") || "";
            if (!doi) return;
            close();
            if (typeof findEntriesByParentDOI === "function") {
            findEntriesByParentDOI(doi);
            }
        });
        const joBtn = body.querySelector("#find-jo-records-btn");
        if (joBtn) {
            joBtn.addEventListener("click", () => {
            close();
            });
        }
        const btnRaw = body.querySelector("#jo-alex-toggle-raw");
        const rawBox = body.querySelector("#jo-alex-raw");
        btnRaw?.addEventListener("click", () => {
            if (!rawBox) return;
            rawBox.style.display = (rawBox.style.display === "none") ? "block" : "none";
        });
    }

    document.addEventListener("click", (e) => {
        const btn = e.target.closest(".jo-parentpub-zoom");
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        const doi = btn.getAttribute("data-doi") || "";
        const pubId = btn.getAttribute("data-pub-id") || "";
        openAlexRefsOverlay({ doi, pubId }).catch(err => {
            console.error(err);
            alert("Could not load publication details data.");
        });
    });
})();