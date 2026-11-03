// Marks every match of a row in the field's full value. Offsets are in
// characters, like String.slice. Overlapping or out-of-range ones are
// dropped instead of producing broken markup.
export function highlightMatches(value, matches, escapeHtml, className = 'match') {
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
    html += `<span class="${className}">${escapeHtml(value.slice(m.start, m.end))}</span>`;
    cursor = m.end;
  }

  return html + escapeHtml(value.slice(cursor));
}

const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

// The server sends a match's context as raw field text with [[MATCH]] around
// the hit. That text can hold markup, so it is escaped first and only then
// are the markers turned into the highlight span.
// Where a literal piece of text stands in a value, as match ranges for
// highlightMatches. Does not overlap: "aa" in "aaa" is found once.
export function findLiteral(value, needle, ignoreCase = false) {
  if (!needle) return [];

  const haystack = ignoreCase ? value.toLowerCase() : value;
  const target = ignoreCase ? needle.toLowerCase() : needle;
  // Lowercasing can change a text's length for a few scripts; then the
  // offsets would not line up with the value, so nothing is marked.
  if (haystack.length !== value.length) return [];

  const ranges = [];
  let from = 0;

  for (;;) {
    const at = haystack.indexOf(target, from);
    if (at === -1) return ranges;
    ranges.push({ start: at, end: at + target.length });
    from = at + target.length;
  }
}

export function escapeText(text) {
  return String(text ?? '').replace(/[&<>"']/g, ch => HTML_ESCAPES[ch]);
}

export function contextToHtml(context) {
  return escapeText(context)
    .replace(/\[\[MATCH\]\]/g, '<span class="match">')
    .replace(/\[\[\/MATCH\]\]/g, '</span>');
}
