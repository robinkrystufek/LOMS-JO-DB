/**
 * Apps Script example for Google Sheet use
 * Returns "no records" if DOI has no hits in LOMS, otherwise "record found".
 *
 * @param {string} doi
 * @return {string}
 * @customfunction
 */
function LOMS_DOI_TEST(doi) {
  if (!doi) return '';
  const cleanDoi = String(doi).trim();
  const url = 'https://www.loms.cz/jo-db/api/rest/records?pub_doi_q=' + encodeURIComponent(cleanDoi);
  try {
    const text = UrlFetchApp.fetch(url, { muteHttpExceptions: true }).getContentText().trim();
    if (text === '{"ok":true,"page":1,"per_page":50,"total":0,"total_pages":1,"items":[]}') {
      return 'no records';
    }
    return 'record found';
  } 
  catch (e) {
    return 'error';
  }
}