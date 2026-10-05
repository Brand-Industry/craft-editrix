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
