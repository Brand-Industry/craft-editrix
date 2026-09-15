import { ref, computed, reactive } from 'vue';
import { useApi } from './useApi';
import { useConfig } from './useConfig';

export function useSearch(actionMode) {
  const { post } = useApi();
  const { apiUrl, replaceUrl, previewUrl, hasFeature } = useConfig();

  // Search state
  const loading = ref(false);
  const error = ref(null);
  const results = ref({});
  const totalResults = ref(0);
  const selectedResults = ref([]);
  const hasSearched = ref(false);

  // Search params
  const searchParams = reactive({
    query: '',
    replaceWith: '',
    siteId: null,
    allSites: false,
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
    return actionMode?.value === 'replace' ? all.filter(r => !r.readOnly) : all;
  });
  const allSelected = computed(() => {
    if (selectableResults.value.length === 0) return false;
    return selectedCount.value === selectableResults.value.length;
  });

  // Perform search
  const search = async () => {
    loading.value = true;
    error.value = null;
    hasSearched.value = true;
    selectedResults.value = [];

    try {
      const data = await post(apiUrl.value, {
        query: searchParams.query,
        siteId: searchParams.siteId,
        allSites: searchParams.allSites,
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
      });

      if (data.success) {
        results.value = data.groupedResults || {};
        totalResults.value = data.totalResults || 0;
      } else {
        error.value = data.error || 'Search failed';
        results.value = {};
        totalResults.value = 0;
      }

      return data;
    } catch (err) {
      error.value = err.message;
      results.value = {};
      totalResults.value = 0;
      throw err;
    } finally {
      loading.value = false;
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
    if (actionMode?.value === 'replace' && result.readOnly) return;

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
        selectedResults: toReplace,
        siteId: searchParams.siteId,
        useRegex: searchParams.useRegex,
        caseInsensitive: searchParams.caseInsensitive,
        wholeWords: searchParams.wholeWords,
        confirmationCode: confirmationCode ?? '',
      });

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
        result: result,
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
    selectedResults.value = [];
    hasSearched.value = false;
    error.value = null;
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
    selectedResults,
    hasSearched,
    searchParams,

    // Computed
    hasResults,
    selectedCount,
    hasSelected,
    allSelected,

    // Methods
    search,
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
