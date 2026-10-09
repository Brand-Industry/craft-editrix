// Word-level diff for the before/after preview of one field. The server
// supplies both texts; this only shows where they differ, marking each
// changed word on its own instead of everything between the first and the
// last change.

const TOKEN = /\s+|<[^>]*>|[\p{L}\p{N}_]+|[\s\S]/gu;

// Past this many edits the exact diff costs too much memory, so the whole
// span between the first and the last change is marked instead.
const MAX_EDITS = 600;

export function tokenize(text) {
  return text.match(TOKEN) || [];
}

// Myers' O(ND) shortest edit script over two token lists. Returns
// [{ type: 'same' | 'del' | 'add', text }] or null when it needs more than
// MAX_EDITS edits.
function myers(a, b) {
  const n = a.length;
  const m = b.length;
  const max = Math.min(n + m, MAX_EDITS);
  const offset = max + 1;
  const v = new Int32Array(2 * max + 3);
  const trace = [];
  let found = -1;

  for (let d = 0; d <= max && found < 0; d++) {
    trace.push(v.slice());

    for (let k = -d; k <= d; k += 2) {
      let x =
        k === -d || (k !== d && v[offset + k - 1] < v[offset + k + 1])
          ? v[offset + k + 1]
          : v[offset + k - 1] + 1;
      let y = x - k;

      while (x < n && y < m && a[x] === b[y]) {
        x++;
        y++;
      }

      v[offset + k] = x;

      if (x >= n && y >= m) {
        found = d;
        break;
      }
    }
  }

  if (found < 0) return null;

  const edits = [];
  let x = n;
  let y = m;

  for (let d = found; d > 0; d--) {
    const prev = trace[d];
    const k = x - y;
    const prevK =
      k === -d || (k !== d && prev[offset + k - 1] < prev[offset + k + 1]) ? k + 1 : k - 1;
    const prevX = prev[offset + prevK];
    const prevY = prevX - prevK;

    while (x > prevX && y > prevY) {
      edits.push({ type: 'same', text: a[x - 1] });
      x--;
      y--;
    }

    edits.push(x === prevX ? { type: 'add', text: b[prevY] } : { type: 'del', text: a[prevX] });

    x = prevX;
    y = prevY;
  }

  while (x > 0 && y > 0) {
    edits.push({ type: 'same', text: a[x - 1] });
    x--;
    y--;
  }

  return edits.reverse();
}

function merge(parts) {
  const out = [];

  for (const part of parts) {
    if (part.text === '') continue;

    const last = out[out.length - 1];
    if (last && last.type === part.type) {
      last.text += part.text;
    } else {
      out.push({ ...part });
    }
  }

  return out;
}

// [{ type, text }] where 'same' text is in both strings, 'del' only in the
// original and 'add' only in the proposed one.
export function diffText(original, proposed) {
  if (original === proposed) return merge([{ type: 'same', text: original }]);

  const max = Math.min(original.length, proposed.length);
  let prefix = 0;
  while (prefix < max && original[prefix] === proposed[prefix]) prefix++;

  let suffix = 0;
  while (
    suffix < max - prefix &&
    original[original.length - 1 - suffix] === proposed[proposed.length - 1 - suffix]
  ) {
    suffix++;
  }

  const oldMiddle = original.slice(prefix, original.length - suffix);
  const newMiddle = proposed.slice(prefix, proposed.length - suffix);

  // Cutting at the first difference can land inside a word ("resort" to
  // "resort2" shares "resort"), so the common part is only kept up to the
  // last whole token boundary: start over from the tokens of the middle
  // with the neighbouring word attached.
  const lead = original.slice(0, prefix).match(/[\p{L}\p{N}_]*$/u)[0];
  const tail = original.slice(original.length - suffix).match(/^[\p{L}\p{N}_]*/u)[0];

  const a = tokenize(lead + oldMiddle + tail);
  const b = tokenize(lead + newMiddle + tail);

  const edits = myers(a, b) || [
    { type: 'del', text: lead + oldMiddle + tail },
    { type: 'add', text: lead + newMiddle + tail },
  ];

  return merge([
    { type: 'same', text: original.slice(0, prefix - lead.length) },
    ...edits,
    { type: 'same', text: original.slice(original.length - suffix + tail.length) },
  ]);
}
