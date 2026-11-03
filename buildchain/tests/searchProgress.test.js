import { describe, it, expect } from 'vitest';
import {
  searchPhase,
  formatElapsed,
  formatRemaining,
  estimateRemainingSeconds,
  waitingTips,
  MIN_SAMPLE_SECONDS,
  MIN_SAMPLE_ENTRIES,
  estimateRevertSeconds,
} from '../src/js/utils/searchProgress';

describe('searchPhase', () => {
  it('is sending until the server has given the search an id', () => {
    expect(searchPhase({ hasId: false, status: null, scanned: 0, total: 0 })).toBe('sending');
  });

  it('is sending while an opened search has not answered yet', () => {
    expect(searchPhase({ hasId: true, status: null, scanned: 0, total: 0 })).toBe('sending');
  });

  it('waits in the queue', () => {
    expect(searchPhase({ hasId: true, status: 'queued', scanned: 0, total: 0 })).toBe('queued');
  });

  it('counts entries before it knows the total', () => {
    expect(searchPhase({ hasId: true, status: 'running', scanned: 0, total: 0 })).toBe('counting');
  });

  it('scans while there is a total and entries left', () => {
    expect(searchPhase({ hasId: true, status: 'running', scanned: 500, total: 1000 })).toBe('scanning');
  });

  it('prepares results once every entry is scanned', () => {
    expect(searchPhase({ hasId: true, status: 'running', scanned: 1000, total: 1000 })).toBe('finishing');
  });
});

describe('formatElapsed', () => {
  it('formats minutes and seconds', () => {
    expect(formatElapsed(0)).toBe('0:00');
    expect(formatElapsed(42)).toBe('0:42');
    expect(formatElapsed(605)).toBe('10:05');
  });

  it('adds hours past an hour', () => {
    expect(formatElapsed(3725)).toBe('1:02:05');
  });

  it('never goes negative', () => {
    expect(formatElapsed(-4)).toBe('0:00');
  });
});

describe('formatRemaining', () => {
  it('says nothing under a minute', () => {
    expect(formatRemaining(40)).toBe(null);
  });

  it('rounds up to whole minutes', () => {
    expect(formatRemaining(61)).toBe('2 min');
    expect(formatRemaining(720)).toBe('12 min');
  });

  it('switches to hours for long searches', () => {
    expect(formatRemaining(90 * 60)).toBe('1 h 30 min');
    expect(formatRemaining(120 * 60)).toBe('2 h');
  });
});

describe('estimateRemainingSeconds', () => {
  const sample = { atMs: 0, scanned: 1000 };

  it('has no estimate without a sample or before enough time has passed', () => {
    expect(estimateRemainingSeconds({ sample: null, scanned: 5000, total: 10000, nowMs: 60000 })).toBe(null);
    expect(
      estimateRemainingSeconds({ sample, scanned: 5000, total: 10000, nowMs: (MIN_SAMPLE_SECONDS - 1) * 1000 })
    ).toBe(null);
  });

  it('has no estimate before enough entries have been scanned', () => {
    expect(
      estimateRemainingSeconds({
        sample,
        scanned: sample.scanned + MIN_SAMPLE_ENTRIES - 1,
        total: 10000,
        nowMs: 60000,
      })
    ).toBe(null);
  });

  it('projects the pace measured since the sample', () => {
    // 4000 entries in 20 s is 200 per second; 6000 are left.
    expect(estimateRemainingSeconds({ sample, scanned: 5000, total: 11000, nowMs: 20000 })).toBe(30);
  });

  it('has no estimate once everything is scanned', () => {
    expect(estimateRemainingSeconds({ sample, scanned: 10000, total: 10000, nowMs: 60000 })).toBe(null);
  });
});

describe('waitingTips', () => {
  it('leads with the tip that the search keeps running without the page', () => {
    expect(waitingTips({ hasScopeFilters: true })[0]).toMatch(/leave this page/);
  });

  it('leaves the limit to be filled in, so the tip never states a number that is wrong for the edition', () => {
    const tip = waitingTips({ hasScopeFilters: false }).find(t => /stops at/.test(t));

    expect(tip).toContain('{limit}');
    expect(tip).not.toMatch(/\d,\d{3}/);
  });

  it('only suggests narrowing by section where scope filters exist', () => {
    const withFilters = waitingTips({ hasScopeFilters: true }).join(' ');
    const without = waitingTips({ hasScopeFilters: false }).join(' ');

    expect(withFilters).toMatch(/Segmented Search/);
    expect(without).not.toMatch(/Segmented Search/);
  });
});

describe('estimateRevertSeconds', () => {
  const first = { reverted: 0, atMs: 0 };

  it('gives the time left at the pace so far', () => {
    // 120 values in 20 s is 6 a second; 1,757 are left.
    expect(estimateRevertSeconds({ first, reverted: 120, total: 1877, nowMs: 20000 })).toBeCloseTo(292.83, 1);
  });

  it('counts only what this revert restored, not what was already back', () => {
    const resumed = { reverted: 1000, atMs: 0 };
    expect(estimateRevertSeconds({ first: resumed, reverted: 1120, total: 1877, nowMs: 20000 })).toBeCloseTo((1877 - 1120) / 6, 1);
  });

  it('says nothing until there is enough to go on', () => {
    expect(estimateRevertSeconds({ first: null, reverted: 50, total: 100, nowMs: 30000 })).toBe(null);
    expect(estimateRevertSeconds({ first, reverted: 120, total: 1877, nowMs: 5000 })).toBe(null);
    expect(estimateRevertSeconds({ first, reverted: 5, total: 1877, nowMs: 30000 })).toBe(null);
  });

  it('is zero when everything is back', () => {
    expect(estimateRevertSeconds({ first, reverted: 1877, total: 1877, nowMs: 60000 })).toBe(0);
  });
});
