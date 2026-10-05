import { describe, it, expect } from 'vitest';
import { highlightMatches } from '../src/js/utils/highlight';

const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const mark = s => `<span class="match">${s}</span>`;

describe('highlightMatches', () => {
  const value = 'a hotel and a hotel again';

  it('highlights every match in the field, not just the first', () => {
    const html = highlightMatches(
      value,
      [{ start: 2, end: 7 }, { start: 14, end: 19 }],
      esc
    );

    expect(html).toBe(`a ${mark('hotel')} and a ${mark('hotel')} again`);
  });

  it('does not depend on the order the matches arrive in', () => {
    const html = highlightMatches(
      value,
      [{ start: 14, end: 19 }, { start: 2, end: 7 }],
      esc
    );

    expect(html).toBe(`a ${mark('hotel')} and a ${mark('hotel')} again`);
  });

  it('escapes the text around and inside matches', () => {
    expect(highlightMatches('<b>x</b>', [{ start: 3, end: 4 }], esc)).toBe(
      `&lt;b&gt;${mark('x')}&lt;/b&gt;`
    );
  });

  it('drops overlapping and out-of-range matches instead of breaking the markup', () => {
    const html = highlightMatches(
      value,
      [{ start: 2, end: 7 }, { start: 4, end: 9 }, { start: 20, end: 99 }, { start: 5, end: 5 }],
      esc
    );

    expect(html).toBe(`a ${mark('hotel')} and a hotel again`);
  });

  it('returns the escaped value when there is nothing to highlight', () => {
    expect(highlightMatches('a < b', [], esc)).toBe('a &lt; b');
    expect(highlightMatches('a < b', undefined, esc)).toBe('a &lt; b');
  });
});
