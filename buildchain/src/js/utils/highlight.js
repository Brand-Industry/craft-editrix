// Marks every match of a row in the field's full value. Offsets are in
// characters, like String.slice. Overlapping or out-of-range ones are
// dropped instead of producing broken markup.
export function highlightMatches(value, matches, escapeHtml) {
  const ranges = (matches || [])
    .filter(
      m =>
        Number.isInteger(m.start) &&
        Number.isInteger(m.end) &&
        m.end > m.start &&
        m.end <= value.length
    )
    .sort((a, b) => a.start - b.start);

  let html = '';
  let cursor = 0;

  for (const m of ranges) {
    if (m.start < cursor) continue;
    html += escapeHtml(value.slice(cursor, m.start));
    html += `<span class="match">${escapeHtml(value.slice(m.start, m.end))}</span>`;
    cursor = m.end;
  }

  return html + escapeHtml(value.slice(cursor));
}

const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

// The server sends a match's context as raw field text with [[MATCH]] around
// the hit. That text can hold markup, so it is escaped first and only then
// are the markers turned into the highlight span.
export function contextToHtml(context) {
  return String(context || '')
    .replace(/[&<>"']/g, ch => HTML_ESCAPES[ch])
    .replace(/\[\[MATCH\]\]/g, '<span class="match">')
    .replace(/\[\[\/MATCH\]\]/g, '</span>');
}
