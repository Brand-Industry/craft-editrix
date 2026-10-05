import { ref, computed, reactive } from 'vue';
import { useApi } from './useApi';
import { useConfig } from './useConfig';
import { slimRow } from '../utils/fieldValue';

// How long a search may sit in "queued" before we tell the user the queue
// probably isn't running.
export const QUEUE_STALL_MS = 15000;
export const POLL_INTERVAL_MS = 1500;
// A running job reports progress every few hundred entries. This long
// without a report means the worker most likely died.
export const JOB_IDLE_SECONDS = 120;
// A queued or running search silent for this long is a leftover from a dead
// worker. It stops counting as in progress (the server uses the same limit).
export const ACTIVE_MAX_IDLE_SECONDS = 600;

// Identifies one field of one element on one site. The server builds the
// same key (ReplaceService::keyFor), so replaced marks line up with rows.
export const resultKey = r =>
  `${r.elementType}_${r.elementId}_${r.fieldHandle}_${r.siteId}`;

export const isActiveSearch = s =>
  (s.status === 'queued' || s.status === 'running') &&
  (s.idleSeconds || 0) < ACTIVE_MAX_IDLE_SECONDS;

// Craft starts its web queue runner from the control panel's JavaScript,
// and only when a panel page loads. A job pushed by an AJAX request, like
// ours, would otherwise wait until the user opens another page. This asks
// Craft's own runner to start it now; it does nothing if Craft isn't there
// or the queue runs from a worker.
export const kickQueue = () => {
  try {
    window.Craft?.cp?.runQueue?.();
  } catch (err) {
    // The queue still starts on the next panel page load.
  }
};

export function useSearch(actionMode) {
  const { post, get } = useApi();
  const {
    searchStartUrl,
    searchStatusUrl,
    searchRecentUrl,
    searchCancelUrl,
    replaceUrl,
    previewUrl,
    hasFeature,
  } = useConfig();

  // Search state
  const loading = ref(false);
  const error = ref(null);
  const results = ref({});
  const totalResults = ref(0);
  const truncated = ref(false);
  const stopReason = ref(null);
  const selectedResults = ref([]);
  const hasSearched = ref(false);

  // Background-search progress - a search runs as a queued job (see
  // SearchJob/actionSearchStart) instead of inside one HTTP request, so a
  // large site doesn't risk timing out or starving other requests of
  // PHP-FPM workers. These track that job while search() polls for it.
  const scannedCount = ref(0);
  const totalCount = ref(0);
  // Matches found so far, reported by the job while it runs.
  const matchCount = ref(0);
  const progress = computed(() =>
    totalCount.value > 0 ? scannedCount.value / totalCount.value : 0
  );
  const currentSearchId = ref(null);
  const recentSearches = ref([]);
  // The saved search whose results are on screen, so a replace can be
  // recorded against it.
  const loadedSearchId = ref(null);
  // For the waiting screen: the job's state, when the search started
  // (server clock, so a reopened search shows real elapsed time) and the
  // first scan reading, which the time estimate measures pace against.
  const jobStatus = ref(null);
  const startedAtMs = ref(null);
  const scanSample = ref(null);
  // Matches already replaced, and ones skipped because their content
  // changed after the search. Both stay visible but can't be selected.
  const replacedKeys = ref(new Set());
  const staleKeys = ref(new Set());
  const rowState = r => {
    const key = resultKey(r);
    if (replacedKeys.value.has(key)) return 'replaced';
    if (staleKeys.value.has(key)) return 'stale';
    return null;
  };
  const queueStalled = ref(false);
  const jobStalled = ref(false);

  // Search params
  const searchParams = reactive({
    query: '',
    replaceWith: '',
    siteId: null,
    allSites: false,
    siteIds: [],
    useRegex: false,
    caseInsensitive: false,
    wholeWords: false,
    dryRun: false,
    searchEntries: true,
    searchGlobals: true,
    searchMatrix: true,
    searchCategories: false,
    sections: [],
    fields: [],
    entryTypes: [],
  });

  // Computed
  const hasResults = computed(() => totalResults.value > 0);
  const selectedCount = computed(() => selectedResults.value.length);
  const hasSelected = computed(() => selectedCount.value > 0);
  // Selection means different things per mode: in Search it drives CSV
  // export (read-only matches like Tags are fine to include), in Replace
  // it drives what gets written back (read-only matches must be excluded).
  const selectableResults = computed(() => {
    const all = getAllResults();
    if (actionMode?.value !== 'replace') return all;
    return all.filter(r => !r.readOnly && !rowState(r));
  });
  const allSelected = computed(() => {
    if (selectableResults.value.length === 0) return false;
    return selectedCount.value === selectableResults.value.length;
  });

  // Perform search - starts a background job and polls for progress/
  // results rather than waiting on a single (potentially very long)
  // request, so a large site can't time out or crash mid-search.
  const search = async () => {
    loading.value = true;
    error.value = null;
    hasSearched.value = true;
    selectedResults.value = [];
    scannedCount.value = 0;
    totalCount.value = 0;
    matchCount.value = 0;
    jobStatus.value = null;
    scanSample.value = null;
    startedAtMs.value = Date.now();
    replacedKeys.value = new Set();
    staleKeys.value = new Set();
    truncated.value = false;
    stopReason.value = null;
    queueStalled.value = false;
    jobStalled.value = false;

    const payload = {
      query: searchParams.query,
      siteId: searchParams.siteId,
      allSites: searchParams.allSites,
      siteIds: searchParams.siteIds,
      useRegex: searchParams.useRegex,
      caseInsensitive: searchParams.caseInsensitive,
      wholeWords: searchParams.wholeWords,
      dryRun: searchParams.dryRun,
      searchEntries: searchParams.searchEntries,
      searchGlobals: searchParams.searchGlobals,
      searchMatrix: searchParams.searchMatrix,
      searchCategories: searchParams.searchCategories,
      sections: searchParams.sections,
      fields: searchParams.fields,
      entryTypes: searchParams.entryTypes,
    };

    try {
      const startData = await post(searchStartUrl.value, payload);

      if (!startData.success) {
        error.value = startData.error || 'Search failed';
        results.value = {};
        totalResults.value = 0;
        loading.value = false;
        return startData;
      }

      currentSearchId.value = startData.searchId;
      loadedSearchId.value = startData.searchId;
      kickQueue();
      const finished = await pollSearch(startData.searchId);
      loadRecentSearches();
      return finished;
    } catch (err) {
      error.value = err.message;
      results.value = {};
      totalResults.value = 0;
      loading.value = false;
      throw err;
    }
  };

  const pollSearch = (searchId, onParams = null) => {
    const startedAt = Date.now();
    let paramsApplied = false;

    return new Promise((resolve, reject) => {
      const tick = async () => {
        // A cancel (or a newer search started since) may have moved on
        // from this poll loop - don't let a stale response land.
        if (currentSearchId.value !== searchId) {
          resolve(null);
          return;
        }

        try {
          const data = await get(searchStatusUrl.value, { id: searchId });

          if (currentSearchId.value !== searchId) {
            resolve(null);
            return;
          }

          if (!data.success) {
            error.value = data.error || 'Search failed';
            results.value = {};
            totalResults.value = 0;
            loading.value = false;
            currentSearchId.value = null;
            resolve(data);
            return;
          }

          // Opening a saved search puts its form back as it was.
          if (onParams && !paramsApplied && data.params) {
            paramsApplied = true;
            Object.assign(searchParams, data.params);
            onParams(data.params);
          }

          scannedCount.value = data.scannedCount || 0;
          totalCount.value = data.totalCount || 0;
          matchCount.value = data.resultCount || 0;
          jobStatus.value = data.status;
          if (data.elapsedSeconds != null) {
            startedAtMs.value = Date.now() - data.elapsedSeconds * 1000;
          }
          if (
            !scanSample.value &&
            data.status === 'running' &&
            (data.scannedCount || 0) > 0
          ) {
            scanSample.value = { atMs: Date.now(), scanned: data.scannedCount };
          }
          queueStalled.value =
            data.status === 'queued' &&
            Date.now() - startedAt >= QUEUE_STALL_MS;
          jobStalled.value =
            data.status === 'running' &&
            (data.idleSeconds || 0) >= JOB_IDLE_SECONDS;

          if (data.status === 'completed' || data.status === 'cancelled') {
            results.value = data.groupedResults || {};
            totalResults.value = data.totalResults || 0;
            truncated.value = data.truncated || false;
            stopReason.value = data.stopReason || null;
            replacedKeys.value = new Set(data.replaced || []);
            queueStalled.value = false;
            jobStalled.value = false;
            loading.value = false;
            currentSearchId.value = null;
            resolve(data);
            return;
          }

          if (data.status === 'error') {
            error.value = data.error || 'Search failed';
            results.value = {};
            totalResults.value = 0;
            loading.value = false;
            currentSearchId.value = null;
            resolve(data);
            return;
          }

          setTimeout(tick, POLL_INTERVAL_MS);
        } catch (err) {
          error.value = err.message;
          loading.value = false;
          currentSearchId.value = null;
          reject(err);
        }
      };

      tick();
    });
  };

  const loadRecentSearches = async () => {
    try {
      const data = await get(searchRecentUrl.value);
      recentSearches.value = data.success ? data.searches || [] : [];
    } catch (err) {
      // The list is a convenience; the search itself doesn't depend on it.
    }
    return recentSearches.value;
  };

  // Follows a search that already exists on the server - running, queued
  // or finished - instead of starting a new one. This is what makes a
  // search survive leaving the page or a reload.
  const openSearch = async (searchId, onParams = null) => {
    loading.value = true;
    error.value = null;
    hasSearched.value = true;
    selectedResults.value = [];
    scannedCount.value = 0;
    totalCount.value = 0;
    matchCount.value = 0;
    jobStatus.value = null;
    scanSample.value = null;
    startedAtMs.value = Date.now();
    replacedKeys.value = new Set();
    staleKeys.value = new Set();
    truncated.value = false;
    stopReason.value = null;
    queueStalled.value = false;
    jobStalled.value = false;
    currentSearchId.value = searchId;
    loadedSearchId.value = searchId;

    try {
      const finished = await pollSearch(searchId, onParams);
      loadRecentSearches();
      return finished;
    } catch (err) {
      error.value = err.message;
      loading.value = false;
      throw err;
    }
  };

  const activeSearches = computed(() =>
    recentSearches.value.filter(isActiveSearch)
  );

  // Cancels any of the user's searches, not just the one on screen.
  const cancelSearchById = async searchId => {
    try {
      await post(searchCancelUrl.value, { id: searchId });
    } catch (err) {
      // The next refresh of the list shows what actually happened.
    }
    await loadRecentSearches();
  };

  // Best-effort - the running job checks this flag on its own cadence
  // (every ~250 entries), so cancellation isn't instant, but the search
  // stops consuming resources shortly after rather than running to
  // completion regardless. The poll loop above picks up the resulting
  // "cancelled" status on its own once the job notices.
  const cancelSearch = async () => {
    if (!currentSearchId.value) return;
    try {
      await post(searchCancelUrl.value, { id: currentSearchId.value });
    } catch (err) {
      // Ignored - polling continues regardless.
    }
  };

  // Get all results as flat array
  const getAllResults = () => {
    const all = [];
    Object.values(results.value).forEach(siteData => {
      all.push(...siteData.results);
    });
    return all;
  };

  // Selection methods
  const toggleResult = (result) => {
    if (actionMode?.value === 'replace' && (result.readOnly || rowState(result))) return;

    const index = selectedResults.value.findIndex(
      r => r.uniqueKey === result.uniqueKey
    );

    if (index === -1) {
      selectedResults.value.push(result);
    } else {
      selectedResults.value.splice(index, 1);
    }
  };

  const isSelected = (result) => {
    return selectedResults.value.some(r => r.uniqueKey === result.uniqueKey);
  };

  const selectAll = () => {
    selectedResults.value = [...selectableResults.value];
  };

  const deselectAll = () => {
    selectedResults.value = [];
  };

  const toggleSelectAll = () => {
    if (allSelected.value) {
      deselectAll();
    } else {
      selectAll();
    }
  };

  // Replace methods. Pass `results`/`replaceWith` to replace a single match
  // (e.g. from the detail modal) instead of the current bulk selection.
  const replace = async ({
    results: targets,
    replaceWith,
    confirmationCode,
  } = {}) => {
    const toReplace = (targets ?? selectedResults.value).filter(
      r => !r.readOnly
    );
    if (toReplace.length === 0) return null;

    loading.value = true;
    error.value = null;

    try {
      const data = await post(replaceUrl.value, {
        searchQuery: searchParams.query,
        replaceWith: replaceWith ?? searchParams.replaceWith,
        selectedResults: toReplace.map(slimRow),
        siteId: searchParams.siteId,
        useRegex: searchParams.useRegex,
        caseInsensitive: searchParams.caseInsensitive,
        wholeWords: searchParams.wholeWords,
        confirmationCode: confirmationCode ?? '',
        searchId: loadedSearchId.value ?? '',
      });

      // Mark what was done on screen; no new search is needed.
      if (data?.success) {
        replacedKeys.value = new Set([
          ...replacedKeys.value,
          ...(data.replacedKeys || []),
        ]);
        staleKeys.value = new Set([
          ...staleKeys.value,
          ...(data.skipped || []).map(s => s.key),
        ]);
        selectedResults.value = selectedResults.value.filter(r => !rowState(r));
      }

      return data;
    } catch (err) {
      error.value = err.message;
      throw err;
    } finally {
      loading.value = false;
    }
  };

  // Preview single result
  const getPreview = async (result) => {
    try {
      const data = await post(previewUrl.value, {
        result: slimRow(result),
        searchQuery: searchParams.query,
        replaceWith: searchParams.replaceWith,
        useRegex: searchParams.useRegex,
        caseInsensitive: searchParams.caseInsensitive,
        wholeWords: searchParams.wholeWords,
      });
      return data;
    } catch (err) {
      console.error('Preview error:', err);
      return null;
    }
  };

  // Reset
  const reset = () => {
    results.value = {};
    totalResults.value = 0;
    truncated.value = false;
    stopReason.value = null;
    selectedResults.value = [];
    hasSearched.value = false;
    error.value = null;
    scannedCount.value = 0;
    totalCount.value = 0;
    matchCount.value = 0;
    jobStatus.value = null;
    scanSample.value = null;
    startedAtMs.value = null;
    replacedKeys.value = new Set();
    staleKeys.value = new Set();
    queueStalled.value = false;
    jobStalled.value = false;
    // Lets any in-flight poll loop notice it's stale and stop on its next
    // tick instead of overwriting this reset with a late response. The job
    // itself keeps running on the server; coming back re-attaches to it.
    currentSearchId.value = null;
    loadedSearchId.value = null;
    loading.value = false;
  };

  // Reset form
  const resetForm = () => {
    searchParams.query = '';
    searchParams.replaceWith = '';
    searchParams.useRegex = false;
    searchParams.caseInsensitive = false;
    searchParams.wholeWords = false;
    searchParams.dryRun = false;
    searchParams.sections = [];
    searchParams.fields = [];
    searchParams.entryTypes = [];
    reset();
  };

  return {
    // State
    loading,
    error,
    results,
    totalResults,
    truncated,
    stopReason,
    recentSearches,
    rowState,
    loadRecentSearches,
    openSearch,
    activeSearches,
    cancelSearchById,
    selectedResults,
    hasSearched,
    searchParams,
    scannedCount,
    matchCount,
    jobStatus,
    startedAtMs,
    scanSample,
    currentSearchId,
    totalCount,
    queueStalled,
    jobStalled,

    // Computed
    hasResults,
    selectedCount,
    hasSelected,
    allSelected,
    progress,

    // Methods
    search,
    cancelSearch,
    getAllResults,
    toggleResult,
    isSelected,
    selectAll,
    deselectAll,
    toggleSelectAll,
    replace,
    getPreview,
    reset,
    resetForm,
  };
}
