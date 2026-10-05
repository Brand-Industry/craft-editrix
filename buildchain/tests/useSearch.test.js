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
    previewUrl: ref('/preview'),
    hasFeature: () => true,
  }),
}));

const { useSearch, QUEUE_STALL_MS, POLL_INTERVAL_MS, JOB_IDLE_SECONDS, RESUME_MAX_IDLE_SECONDS } = await import(
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

  it('re-attaches to a search that is still running when the page opens', async () => {
    serve({
      list: recent(saved()),
      poll: status({ status: 'running', scannedCount: 500, totalCount: 1000 }),
    });

    const s = useSearch();
    const resumed = await s.resumeActiveSearch();
    await vi.advanceTimersByTimeAsync(0);

    expect(resumed).toBe(true);
    expect(s.loading.value).toBe(true);
    expect(s.hasSearched.value).toBe(true);
    expect(s.progress.value).toBe(0.5);
    expect(get).toHaveBeenCalledWith('/search/status', { id: 'old' });
  });

  it('does nothing when no search is in progress', async () => {
    serve({ list: recent(saved({ status: 'completed' })), poll: status() });

    const s = useSearch();
    const resumed = await s.resumeActiveSearch();

    expect(resumed).toBe(false);
    expect(s.loading.value).toBe(false);
    expect(s.recentSearches.value).toHaveLength(1);
  });

  it('does not re-attach to a search whose worker went quiet long ago', async () => {
    serve({
      list: recent(saved({ idleSeconds: RESUME_MAX_IDLE_SECONDS })),
      poll: status(),
    });

    const s = useSearch();
    expect(await s.resumeActiveSearch()).toBe(false);
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
    await s.resumeActiveSearch();
    await vi.advanceTimersByTimeAsync(0);
    await s.cancelSearch();

    expect(post).toHaveBeenCalledWith('/search/cancel', { id: 'old' });
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
