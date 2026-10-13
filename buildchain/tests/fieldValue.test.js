import { describe, it, expect } from 'vitest';
import { fieldValueParams, slimRow } from '../src/js/utils/fieldValue';

const row = {
  uniqueKey: 'entry_1_body_1',
  elementType: 'entry',
  elementId: 1,
  fieldHandle: 'body',
  siteId: 1,
  valueHash: 'abc',
  matchCount: 3,
  matches: [
    { start: 1, end: 2, context: 'a', readOnly: false },
    { start: 5, end: 6, context: 'b', readOnly: true },
    { start: 9, end: 10, context: 'c', readOnly: true },
  ],
};

describe('fieldValueParams', () => {
  it('sends what the server needs to find the field and compare it', () => {
    expect(fieldValueParams(row)).toEqual({
      elementType: 'entry',
      elementId: 1,
      fieldHandle: 'body',
      siteId: 1,
      valueHash: 'abc',
    });
  });

  it('sends an empty hash for a row without one', () => {
    expect(fieldValueParams({ ...row, valueHash: undefined }).valueHash).toBe('');
  });
});

describe('slimRow', () => {
  it('drops the list of contexts but keeps the count of matches left alone', () => {
    const slim = slimRow(row);

    expect(slim.matches).toBeUndefined();
    expect(slim.readOnlyMatchCount).toBe(2);
    expect(slim.matchCount).toBe(3);
    expect(slim.valueHash).toBe('abc');
  });

  it('copes with a row that has no matches list', () => {
    expect(slimRow({ elementId: 1 }).readOnlyMatchCount).toBe(0);
  });

  it('does not change the row it was given', () => {
    slimRow(row);
    expect(row.matches).toHaveLength(3);
  });
});
