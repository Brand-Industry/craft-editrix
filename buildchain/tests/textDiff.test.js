import { describe, it, expect } from 'vitest';
import { diffText, tokenize } from '../src/js/utils/textDiff';

const side = (parts, keep) =>
  parts
    .filter(p => p.type === 'same' || p.type === keep)
    .map(p => p.text)
    .join('');
const marked = (parts, type) => parts.filter(p => p.type === type).map(p => p.text);

describe('tokenize', () => {
  it('splits into words, spaces, tags and symbols and loses nothing', () => {
    const text = 'a <strong>Hôtel</strong> &amp; x < y';
    expect(tokenize(text).join('')).toBe(text);
  });
});

describe('diffText', () => {
  it('marks the whole word when a replacement only adds to it', () => {
    const parts = diffText('on resort dining', 'on resort2 dining');
    expect(marked(parts, 'del')).toEqual(['resort']);
    expect(marked(parts, 'add')).toEqual(['resort2']);
  });

  it('marks each match on its own and leaves the text between them alone', () => {
    const original = 'a resort here. ' + 'x '.repeat(200) + 'and a resort there.';
    const proposed = original.replace(/resort/g, 'hotel');
    const parts = diffText(original, proposed);

    expect(marked(parts, 'del')).toEqual(['resort', 'resort']);
    expect(marked(parts, 'add')).toEqual(['hotel', 'hotel']);
  });

  it('rebuilds both texts from the parts', () => {
    const original = '<p>Our <b>resort</b> and the resorts nearby</p>';
    const proposed = '<p>Our <b>hotel</b> and the resorts nearby</p>';
    const parts = diffText(original, proposed);

    expect(side(parts, 'del')).toBe(original);
    expect(side(parts, 'add')).toBe(proposed);
  });

  it('handles a deletion and an insertion', () => {
    const del = diffText('keep this resort now', 'keep this now');
    expect(side(del, 'del')).toBe('keep this resort now');
    expect(side(del, 'add')).toBe('keep this now');
    expect(marked(del, 'add')).toEqual([]);

    const ins = diffText('a b', 'a x b');
    expect(side(ins, 'add')).toBe('a x b');
    expect(marked(ins, 'del')).toEqual([]);
  });

  it('returns a single unchanged part when nothing changes', () => {
    expect(diffText('same', 'same')).toEqual([{ type: 'same', text: 'same' }]);
  });

  it('falls back to marking the span when there are too many edits', () => {
    const original = Array.from({ length: 1500 }, (_, i) => `w${i}`).join(' ');
    const proposed = Array.from({ length: 1500 }, (_, i) => `z${i}`).join(' ');
    const parts = diffText(original, proposed);

    expect(side(parts, 'del')).toBe(original);
    expect(side(parts, 'add')).toBe(proposed);
  });
});
