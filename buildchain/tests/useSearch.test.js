import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref } from 'vue';

const post = vi.fn();
const get = vi.fn();

vi.mock('../src/js/composables/useApi', () => ({
  useApi: () => ({ post, get }),
}));

vi.mock('../src/js/composables/useConfig', () => ({
  useConfig: () => ({
    searchStartUrl: ref('/search/start'),
    searchStatusUrl: ref('/search/status'),
    searchRecentUrl: ref('/search/recent'),
    searchCancelUrl: ref('/search/cancel'),
    replaceUrl: ref('/replace'),
    replaceStatusUrl: ref('/replace/status'),
    replaceCancelUrl: ref('/replace/cancel'),
    replaceActiveUrl: ref('/replace/active'),
    previewUrl: ref('/preview'),
    hasFeature: () => true,
  }),
}));

const { useSearch, resultKey, QUEUE_STALL_MS, POLL_INTERVAL_MS, JOB_IDLE_SECONDS, ACTIVE_MAX_IDLE_SECONDS } = await import(
  '../src/js/composables/useSearch'
);

const status = (overrides = {}) => ({
  success: true,
  status: 'running',
  scannedCount: 0,
  totalCount: 0,
  ...overrides,
});

// Resolves the pending poll loop by advancing fake time one poll at a time.
const tickPoll = () => vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);

describe('useSearch background polling', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    post.mockReset();
    get.mockReset();
    post.mockResolvedValue({ success: true, searchId: 'abc' });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('polls until the job completes and exposes results and the truncated flag', async () => {
    get
      .mockResolvedValueOnce(status({ scannedCount: 250, totalCount: 1000 }))
      .mockResolvedValueOnce(
        status({
          status: 'completed',
          scannedCount: 1000,
          totalCount: 1000,
          totalResults: 5000,
          groupedResults: { default: { results: [{ uniqueKey: 'a' }] } },
          truncated: true,
        })
      );

    const s = useSearch();
    const done = s.search();
    await vi.advanceTimersByTimeAsync(0);
    expect(s.loading.value).toBe(true);
    expect(s.progress.value).toBe(0.25);

    await tickPoll();
    await done;

    expect(s.loading.value).toBe(false);
    expect(s.totalResults.value).toBe(5000);
    expect(s.truncated.value).toBe(true);
    expect(s.getAllResults()).toEqual([{ uniqueKey: 'a' }]);
  });

  it('exposes why a search stopped early', async () => {
    get.mockResolvedValueOnce(
      status({ status: 'completed', totalResults: 12, truncated: true, stopReason: 'memory', groupedResults: {} })
    );
    const s = useSearch();
    const done = s.search();
    await tickPoll();
    await done;
    expect(s.stopReason.value).toBe('memory');

    get.mockResolvedValueOnce(status({ status: 'completed', totalResults: 1, groupedResults: {} }));
    const next = s.search();
    await tickPoll();
    await next;
    expect(s.stopReason.value).toBe(null);
  });

  it('clears the truncated flag when a new search starts', async () => {
    get.mockResolvedValueOnce(
      status({ status: 'completed', totalResults: 1, truncated: true, groupedResults: {} })
    );
    const s = useSearch();
    const first = s.search();
    await tickPoll();
    await first;
    expect(s.truncated.value).toBe(true);

    get.mockResolvedValueOnce(
      status({ status: 'completed', totalResults: 1, groupedResults: {} })
    );
    const second = s.search();
    expect(s.truncated.value).toBe(false);
    await tickPoll();
    await second;
    expect(s.truncated.value).toBe(false);
  });

  it('surfaces a start failure as an error without polling', async () => {
    post.mockResolvedValueOnce({ success: false, error: 'Permission denied' });
    const s = useSearch();
    await s.search();

    expect(s.error.value).toBe('Permission denied');
    expect(s.loading.value).toBe(false);
    expect(get).not.toHaveBeenCalled();
  });

  it('surfaces a job error from the status endpoint', async () => {
    get.mockResolvedValueOnce(status({ status: 'error', error: 'boom' }));
    const s = useSearch();
    const done = s.search();
    await tickPoll();
    await done;

    expect(s.error.value).toBe('boom');
    expect(s.loading.value).toBe(false);
  });
});

describe('useSearch queue-stalled warning', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    post.mockReset();
    get.mockReset();
    post.mockResolvedValue({ success: true, searchId: 'abc' });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('stays quiet while queued for less than the stall threshold', async () => {
    get.mockResolvedValue(status({ status: 'queued' }));
    const s = useSearch();
    s.search();
    await tickPoll();

    expect(s.queueStalled.value).toBe(false);
  });

  it('flags a stalled queue once a search has been queued past the threshold', async () => {
    get.mockResolvedValue(status({ status: 'queued' }));
    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(QUEUE_STALL_MS + POLL_INTERVAL_MS);

    expect(s.queueStalled.value).toBe(true);
  });

  it('drops the warning as soon as the job starts running', async () => {
    get.mockResolvedValue(status({ status: 'queued' }));
    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(QUEUE_STALL_MS + POLL_INTERVAL_MS);
    expect(s.queueStalled.value).toBe(true);

    get.mockResolvedValue(status({ status: 'running', totalCount: 100 }));
    await tickPoll();
    expect(s.queueStalled.value).toBe(false);
  });

  it('never flags a search that is running, however long it takes', async () => {
    get.mockResolvedValue(status({ status: 'running', totalCount: 100 }));
    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(QUEUE_STALL_MS * 3);

    expect(s.queueStalled.value).toBe(false);
  });

  it('resets the warning on reset()', async () => {
    get.mockResolvedValue(status({ status: 'queued' }));
    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(QUEUE_STALL_MS + POLL_INTERVAL_MS);
    expect(s.queueStalled.value).toBe(true);

    s.reset();
    expect(s.queueStalled.value).toBe(false);
  });
});

describe('useSearch cancel and stale polls', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    post.mockReset();
    get.mockReset();
    post.mockResolvedValue({ success: true, searchId: 'abc' });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('posts the current search id to the cancel endpoint', async () => {
    get.mockResolvedValue(status({ status: 'running', totalCount: 10 }));
    const s = useSearch();
    s.search();
    await tickPoll();

    await s.cancelSearch();

    expect(post).toHaveBeenCalledWith('/search/cancel', { id: 'abc' });
  });

  it('shows the partial results of a cancelled search', async () => {
    get.mockResolvedValueOnce(
      status({
        status: 'cancelled',
        totalResults: 2,
        groupedResults: { default: { results: [{ uniqueKey: 'a' }, { uniqueKey: 'b' }] } },
      })
    );
    const s = useSearch();
    const done = s.search();
    await tickPoll();
    await done;

    expect(s.loading.value).toBe(false);
    expect(s.totalResults.value).toBe(2);
  });

  it('ignores a late response after reset()', async () => {
    get.mockResolvedValue(status({ status: 'running', totalCount: 10 }));
    const s = useSearch();
    s.search();
    await tickPoll();

    s.reset();
    get.mockResolvedValue(
      status({ status: 'completed', totalResults: 9, groupedResults: {} })
    );
    await tickPoll();

    expect(s.totalResults.value).toBe(0);
  });
});

describe('useSearch stalled job warning', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    post.mockReset();
    get.mockReset();
    post.mockResolvedValue({ success: true, searchId: 'abc' });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('flags a running job that has stopped reporting progress', async () => {
    get.mockResolvedValue(
      status({ status: 'running', totalCount: 100, idleSeconds: JOB_IDLE_SECONDS })
    );
    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(0);

    expect(s.jobStalled.value).toBe(true);
  });

  it('stays quiet while progress keeps arriving', async () => {
    get.mockResolvedValue(
      status({ status: 'running', totalCount: 100, idleSeconds: JOB_IDLE_SECONDS - 1 })
    );
    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(0);

    expect(s.jobStalled.value).toBe(false);
  });

  it('does not treat a queued search as a stalled job', async () => {
    get.mockResolvedValue(status({ status: 'queued', idleSeconds: 9999 }));
    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(0);

    expect(s.jobStalled.value).toBe(false);
  });

  it('clears when the job picks up again', async () => {
    get.mockResolvedValue(
      status({ status: 'running', totalCount: 100, idleSeconds: JOB_IDLE_SECONDS })
    );
    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(0);
    expect(s.jobStalled.value).toBe(true);

    get.mockResolvedValue(status({ status: 'running', totalCount: 100, idleSeconds: 1 }));
    await tickPoll();
    expect(s.jobStalled.value).toBe(false);
  });
});

describe('useSearch leaving and coming back', () => {
  const recent = (...searches) => ({ success: true, searches });
  const saved = (overrides = {}) => ({
    searchId: 'old',
    status: 'running',
    query: 'hotel',
    idleSeconds: 3,
    ...overrides,
  });

  // The recent list and the status poll share one mocked GET.
  const serve = ({ list, poll }) =>
    get.mockImplementation(async url => (url === '/search/recent' ? list : poll));

  beforeEach(() => {
    vi.useFakeTimers();
    post.mockReset();
    get.mockReset();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('lists the searches still in progress, with their progress', async () => {
    serve({
      list: recent(
        saved({ scannedCount: 500, totalCount: 1000 }),
        saved({ searchId: 'done', status: 'completed' })
      ),
      poll: status(),
    });

    const s = useSearch();
    await s.loadRecentSearches();

    expect(s.recentSearches.value).toHaveLength(2);
    expect(s.activeSearches.value.map(x => x.searchId)).toEqual(['old']);
  });

  it('does not count a search whose worker went quiet long ago', async () => {
    serve({
      list: recent(saved({ idleSeconds: ACTIVE_MAX_IDLE_SECONDS })),
      poll: status(),
    });

    const s = useSearch();
    await s.loadRecentSearches();

    expect(s.activeSearches.value).toEqual([]);
  });

  it('does not open anything by itself when the page loads', async () => {
    serve({ list: recent(saved()), poll: status() });

    const s = useSearch();
    await s.loadRecentSearches();

    expect(s.loading.value).toBe(false);
    expect(s.hasSearched.value).toBe(false);
  });

  it('opens a search that is still running and follows its progress', async () => {
    serve({
      list: recent(saved()),
      poll: status({ status: 'running', scannedCount: 500, totalCount: 1000 }),
    });

    const s = useSearch();
    s.openSearch('old');
    await vi.advanceTimersByTimeAsync(0);

    expect(s.loading.value).toBe(true);
    expect(s.progress.value).toBe(0.5);
  });

  it('opens a finished search and puts its form back', async () => {
    serve({
      list: recent(),
      poll: status({
        status: 'completed',
        totalResults: 7,
        groupedResults: { default: { results: [{ uniqueKey: 'a' }] } },
        params: { query: 'hotel', sections: ['articles'], useRegex: true },
      }),
    });

    const onParams = vi.fn();
    const s = useSearch();
    await s.openSearch('old', onParams);

    expect(s.searchParams.query).toBe('hotel');
    expect(s.searchParams.sections).toEqual(['articles']);
    expect(s.searchParams.useRegex).toBe(true);
    expect(onParams).toHaveBeenCalledTimes(1);
    expect(s.totalResults.value).toBe(7);
    expect(s.loading.value).toBe(false);
    expect(post).not.toHaveBeenCalled();
  });

  it('can cancel a search it re-attached to', async () => {
    serve({
      list: recent(saved()),
      poll: status({ status: 'running', totalCount: 10 }),
    });
    post.mockResolvedValue({ success: true });

    const s = useSearch();
    s.openSearch('old');
    await vi.advanceTimersByTimeAsync(0);
    await s.cancelSearch();

    expect(post).toHaveBeenCalledWith('/search/cancel', { id: 'old' });
  });

  it('cancels a search from the list and refreshes it', async () => {
    serve({ list: recent(saved({ status: 'cancelled' })), poll: status() });
    post.mockResolvedValue({ success: true });

    const s = useSearch();
    await s.cancelSearchById('old');

    expect(post).toHaveBeenCalledWith('/search/cancel', { id: 'old' });
    expect(s.activeSearches.value).toEqual([]);
  });

  it('shows the server\'s refusal when a search is already running', async () => {
    post.mockResolvedValue({
      success: false,
      code: 'searchInProgress',
      error: 'You already have a search running.',
    });

    const s = useSearch();
    await s.search();

    expect(s.error.value).toBe('You already have a search running.');
    expect(s.loading.value).toBe(false);
  });
});

describe('useSearch starting the queue', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    post.mockReset();
    get.mockReset();
    post.mockResolvedValue({ success: true, searchId: 'abc' });
    get.mockResolvedValue(status({ status: 'queued' }));
  });

  afterEach(() => {
    vi.useRealTimers();
    delete globalThis.window;
  });

  it('asks Craft to run the queue as soon as the search is queued', async () => {
    const runQueue = vi.fn();
    globalThis.window = { Craft: { cp: { runQueue } } };

    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(0);

    expect(runQueue).toHaveBeenCalledTimes(1);
  });

  it('does not ask for the queue when the search could not be started', async () => {
    const runQueue = vi.fn();
    globalThis.window = { Craft: { cp: { runQueue } } };
    post.mockResolvedValue({ success: false, error: 'nope' });

    const s = useSearch();
    await s.search();

    expect(runQueue).not.toHaveBeenCalled();
  });

  it('still searches when Craft\'s panel script is not there', async () => {
    globalThis.window = {};

    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(0);

    expect(s.loading.value).toBe(true);
  });
});

describe('useSearch live progress and leaving mid-search', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    post.mockReset();
    get.mockReset();
    post.mockResolvedValue({ success: true, searchId: 'abc' });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('exposes the matches found so far while the search runs', async () => {
    get
      .mockResolvedValueOnce(
        status({ scannedCount: 250, totalCount: 1000, resultCount: 12 })
      )
      .mockResolvedValue(
        status({ scannedCount: 500, totalCount: 1000, resultCount: 31 })
      );

    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(0);
    expect(s.matchCount.value).toBe(12);

    await tickPoll();
    expect(s.matchCount.value).toBe(31);
  });

  it('starts the next search from zero matches', async () => {
    get.mockResolvedValueOnce(
      status({ status: 'completed', totalResults: 4, resultCount: 4, groupedResults: {} })
    );
    const s = useSearch();
    const first = s.search();
    await tickPoll();
    await first;

    get.mockResolvedValue(status({ scannedCount: 1, totalCount: 10 }));
    s.search();
    await vi.advanceTimersByTimeAsync(0);
    expect(s.matchCount.value).toBe(0);
  });

  it('stops showing a search as loading when the user leaves the view, and the job is untouched', async () => {
    get.mockResolvedValue(status({ scannedCount: 100, totalCount: 1000 }));
    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(0);
    expect(s.loading.value).toBe(true);

    s.reset();

    expect(s.loading.value).toBe(false);
    expect(s.matchCount.value).toBe(0);
    expect(post).not.toHaveBeenCalledWith('/search/cancel', expect.anything());
  });

  it('follows the same search again after leaving and coming back', async () => {
    get.mockResolvedValue(
      status({ scannedCount: 400, totalCount: 1000, resultCount: 9, params: { query: 'hotel' } })
    );
    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(0);
    s.reset();

    s.openSearch('abc');
    await vi.advanceTimersByTimeAsync(0);

    expect(s.loading.value).toBe(true);
    expect(s.progress.value).toBe(0.4);
    expect(s.matchCount.value).toBe(9);
  });
});

describe('useSearch after a replace', () => {
  const row = (id, field = 'body') => ({
    uniqueKey: `u${id}${field}`,
    elementType: 'entry',
    elementId: id,
    fieldHandle: field,
    siteId: 1,
  });

  const finished = rows => ({
    success: true,
    status: 'completed',
    totalResults: rows.length,
    groupedResults: { default: { results: rows } },
    replaced: [],
  });

  let s;

  beforeEach(async () => {
    vi.useFakeTimers();
    post.mockReset();
    get.mockReset();
    post.mockResolvedValue({ success: true, searchId: 'abc' });
    get.mockResolvedValue(finished([row(1), row(2), row(3)]));

    s = useSearch(ref('replace'));
    const done = s.search();
    await tickPoll();
    await done;
    post.mockReset();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('marks replaced rows in place without starting another search', async () => {
    post.mockResolvedValue({ success: true, replacedCount: 1, replacedKeys: [resultKey(row(1))], skipped: [] });

    await s.replace({ results: [row(1)], replaceWith: 'x' });

    expect(s.rowState(row(1))).toBe('replaced');
    expect(s.rowState(row(2))).toBe(null);
    expect(post).toHaveBeenCalledTimes(1);
    expect(post.mock.calls[0][0]).toBe('/replace');
  });

  it('marks a row changed since the search as stale, and a refused one as not saved', async () => {
    post.mockResolvedValue({
      success: true,
      replacedKeys: [],
      skipped: [
        { key: resultKey(row(1)), reason: 'changed' },
        { key: resultKey(row(2)), reason: 'permission' },
        { key: resultKey(row(3)), reason: 'protected' },
      ],
    });

    await s.replace({ results: [row(1), row(2), row(3)], replaceWith: 'x' });

    expect(s.rowState(row(1))).toBe('stale');
    expect(s.rowState(row(2))).toBe('failed');
    expect(s.rowState(row(3))).toBe('failed');
  });

  it('sends rows without their lists of contexts', async () => {
    post.mockResolvedValue({ success: true, replacedKeys: [], skipped: [] });

    await s.replace({
      results: [{ ...row(1), matches: [{ context: 'x', readOnly: true }, { context: 'y' }] }],
      replaceWith: 'x',
    });

    const sent = post.mock.calls[0][1].selectedResults[0];
    expect(sent.matches).toBeUndefined();
    expect(sent.readOnlyMatchCount).toBe(1);
  });

  it('tells the server which saved search the replace came from', async () => {
    post.mockResolvedValue({ success: true, replacedKeys: [], skipped: [] });

    await s.replace({ results: [row(1)], replaceWith: 'x' });

    expect(post.mock.calls[0][1].searchId).toBe('abc');
  });

  it('marks rows the server skipped as changed since the search', async () => {
    post.mockResolvedValue({
      success: true,
      replacedKeys: [],
      skipped: [{ key: resultKey(row(2)), reason: 'changed' }],
    });

    await s.replace({ results: [row(2)], replaceWith: 'x' });

    expect(s.rowState(row(2))).toBe('stale');
  });

  it('leaves replaced and stale rows out of select all and out of the selection', async () => {
    s.selectAll();
    expect(s.selectedCount.value).toBe(3);

    post.mockResolvedValue({
      success: true,
      replacedKeys: [resultKey(row(1))],
      skipped: [{ key: resultKey(row(2)) }],
    });
    await s.replace({ replaceWith: 'x' });

    expect(s.selectedResults.value.map(r => r.elementId)).toEqual([3]);
    s.deselectAll();
    s.selectAll();
    expect(s.selectedResults.value.map(r => r.elementId)).toEqual([3]);

    s.toggleResult(row(1));
    s.toggleResult(row(2));
    expect(s.selectedResults.value.map(r => r.elementId)).toEqual([3]);
  });

  it('marks rows the server could not save, and keeps them out of the selection', async () => {
    s.selectAll();
    post.mockResolvedValue({
      success: true,
      replacedKeys: [],
      skipped: [],
      failed: [{ key: resultKey(row(3)), reason: 'it would leave a required field empty' }],
    });

    await s.replace({ replaceWith: 'x' });

    expect(s.rowState(row(3))).toBe('failed');
    expect(s.selectedResults.value.map(r => r.elementId)).toEqual([1, 2]);
  });

  it('does not mark anything when the replace fails', async () => {
    post.mockResolvedValue({ success: false, error: 'nope' });

    await s.replace({ results: [row(1)], replaceWith: 'x' });

    expect(s.rowState(row(1))).toBe(null);
  });

  it('shows rows already replaced when a saved search is reopened', async () => {
    get.mockResolvedValue({ ...finished([row(1), row(2)]), replaced: [resultKey(row(2))] });

    s.openSearch('old');
    await vi.advanceTimersByTimeAsync(0);

    expect(s.rowState(row(2))).toBe('replaced');
    expect(s.rowState(row(1))).toBe(null);
  });

  it('clears the marks when a new search starts', async () => {
    post.mockResolvedValue({ success: true, replacedKeys: [resultKey(row(1))], skipped: [] });
    await s.replace({ results: [row(1)], replaceWith: 'x' });
    expect(s.rowState(row(1))).toBe('replaced');

    post.mockResolvedValue({ success: true, searchId: 'next' });
    s.search();
    await vi.advanceTimersByTimeAsync(0);

    expect(s.rowState(row(1))).toBe(null);
  });
});

describe('useSearch waiting-screen data', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    post.mockReset();
    get.mockReset();
    post.mockResolvedValue({ success: true, searchId: 'abc' });
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('reports the job state so the screen can tell queued from counting', async () => {
    get.mockResolvedValueOnce(status({ status: 'queued' })).mockResolvedValue(status({ status: 'running' }));

    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(0);
    expect(s.jobStatus.value).toBe('queued');

    await tickPoll();
    expect(s.jobStatus.value).toBe('running');
  });

  it('takes the start time from the server so a reopened search shows real elapsed time', async () => {
    vi.setSystemTime(1_000_000);
    get.mockResolvedValue(status({ status: 'running', totalCount: 100, elapsedSeconds: 300 }));

    const s = useSearch();
    s.openSearch('old');
    await vi.advanceTimersByTimeAsync(0);

    expect(s.startedAtMs.value).toBe(1_000_000 - 300_000);
  });

  it('records the first scan reading once, for the time estimate', async () => {
    vi.setSystemTime(5_000);
    get
      .mockResolvedValueOnce(status({ status: 'running', scannedCount: 0, totalCount: 1000 }))
      .mockResolvedValueOnce(status({ status: 'running', scannedCount: 250, totalCount: 1000 }))
      .mockResolvedValue(status({ status: 'running', scannedCount: 500, totalCount: 1000 }));

    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(0);
    expect(s.scanSample.value).toBe(null);

    await tickPoll();
    expect(s.scanSample.value.scanned).toBe(250);

    await tickPoll();
    expect(s.scanSample.value.scanned).toBe(250);
  });

  it('forgets the previous search\'s timing when a new one starts', async () => {
    get.mockResolvedValue(status({ status: 'running', scannedCount: 250, totalCount: 1000 }));
    const s = useSearch();
    s.search();
    await vi.advanceTimersByTimeAsync(0);
    expect(s.scanSample.value).not.toBe(null);

    s.reset();
    expect(s.scanSample.value).toBe(null);
    expect(s.startedAtMs.value).toBe(null);
  });
});

describe('useSearch replace in the queue', () => {
  const row = id => ({
    uniqueKey: `u${id}`,
    elementType: 'entry',
    elementId: id,
    fieldHandle: 'body',
    siteId: 1,
  });

  let s;

  beforeEach(async () => {
    vi.useFakeTimers();
    post.mockReset();
    get.mockReset();
    post.mockResolvedValue({ success: true, searchId: 'abc' });
    get.mockResolvedValue({
      success: true,
      status: 'completed',
      totalResults: 3,
      groupedResults: { default: { results: [row(1), row(2), row(3)] } },
      replaced: [],
    });

    s = useSearch(ref('replace'));
    const done = s.search();
    await tickPoll();
    await done;
    post.mockReset();
    get.mockReset();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('follows a replace the server queued until it stops running, then marks the rows', async () => {
    post.mockResolvedValueOnce({ success: true, queued: true, logId: 7, total: 3 });
    get
      .mockResolvedValueOnce({ success: true, status: 'running', total: 3, done: 1, replaced: 1 })
      .mockResolvedValueOnce({
        success: true,
        status: 'success',
        total: 3,
        done: 3,
        replaced: 2,
        replacedKeys: ['entry_1_body_1', 'entry_2_body_1'],
        skipped: [{ key: 'entry_3_body_1', reason: 'changed' }],
        failed: [],
      });

    const result = s.replace({ results: [row(1), row(2), row(3)], replaceWith: 'x' });
    await vi.advanceTimersByTimeAsync(0);

    expect(s.replaceProgress.value).toMatchObject({ logId: 7, total: 3, done: 1, replaced: 1 });

    await tickPoll();
    const data = await result;

    expect(data.success).toBe(true);
    expect(s.replaceProgress.value).toBe(null);
    expect(s.rowState(row(1))).toBe('replaced');
    expect(s.rowState(row(2))).toBe('replaced');
    expect(s.rowState(row(3))).toBe('stale');
    expect(get).toHaveBeenCalledWith('/replace/status', { logId: 7 });
  });

  it('does not wait on an inline replace', async () => {
    post.mockResolvedValueOnce({ success: true, replacedKeys: ['entry_1_body_1'], skipped: [], failed: [] });

    await s.replace({ results: [row(1)], replaceWith: 'x' });

    expect(get).not.toHaveBeenCalled();
    expect(s.replaceProgress.value).toBe(null);
  });

  it('can cancel a queued replace', async () => {
    post.mockResolvedValueOnce({ success: true, queued: true, logId: 9, total: 3 });
    get.mockResolvedValue({ success: true, status: 'running', total: 3, done: 1, replaced: 1 });

    s.replace({ results: [row(1)], replaceWith: 'x' });
    await vi.advanceTimersByTimeAsync(0);

    post.mockResolvedValue({ success: true });
    await s.cancelReplace();

    expect(post).toHaveBeenLastCalledWith('/replace/cancel', { logId: 9 });
  });

  it('stops following when the server cannot find the replace', async () => {
    post.mockResolvedValueOnce({ success: true, queued: true, logId: 5, total: 3 });
    get.mockResolvedValue({ success: false, error: 'Replace not found' });

    const data = await s.replace({ results: [row(1)], replaceWith: 'x' });

    expect(data.success).toBe(false);
    expect(s.replaceProgress.value).toBe(null);
  });

  it('picks a replace back up after a reload and ends with its summary', async () => {
    get
      .mockResolvedValueOnce({ success: true, logId: 12 })
      .mockResolvedValueOnce({ success: true, status: 'running', total: 40, done: 10, replaced: 9 })
      .mockResolvedValueOnce({
        success: true,
        status: 'success',
        total: 40,
        done: 40,
        replaced: 38,
        replacedKeys: ['entry_1_body_1'],
        skipped: [],
        failed: [],
      });

    const resumed = s.resumeReplace();
    await vi.advanceTimersByTimeAsync(0);

    expect(get).toHaveBeenNthCalledWith(1, '/replace/active');
    expect(get).toHaveBeenNthCalledWith(2, '/replace/status', { logId: 12 });
    expect(s.replaceProgress.value).toMatchObject({ logId: 12, total: 40, done: 10 });

    await tickPoll();
    const data = await resumed;

    expect(data.replaced).toBe(38);
    expect(s.replaceProgress.value).toBe(null);
    expect(s.rowState(row(1))).toBe('replaced');
  });

  it('does nothing on load when no replace is running', async () => {
    get.mockResolvedValueOnce({ success: true, logId: null });

    expect(await s.resumeReplace()).toBe(null);
    expect(get).toHaveBeenCalledTimes(1);
    expect(s.replaceProgress.value).toBe(null);
  });

  it('does not follow the same replace twice', async () => {
    post.mockResolvedValueOnce({ success: true, queued: true, logId: 3, total: 3 });
    get.mockResolvedValue({ success: true, status: 'running', total: 3, done: 1, replaced: 1 });

    s.replace({ results: [row(1)], replaceWith: 'x' });
    await vi.advanceTimersByTimeAsync(0);
    get.mockClear();

    expect(await s.resumeReplace()).toBe(null);
    expect(get).not.toHaveBeenCalled();
  });

  it('can cancel a replace it picked back up', async () => {
    get
      .mockResolvedValueOnce({ success: true, logId: 21 })
      .mockResolvedValue({ success: true, status: 'running', total: 5, done: 1, replaced: 1 });

    s.resumeReplace();
    await vi.advanceTimersByTimeAsync(0);

    post.mockResolvedValue({ success: true });
    await s.cancelReplace();

    expect(post).toHaveBeenLastCalledWith('/replace/cancel', { logId: 21 });
  });
});
