import { reactive, ref } from 'vue';
import { useApi } from './useApi';
import { useConfig } from './useConfig';

// Category/tag assignment search - "which entries have this category/tag?"
// Much simpler than useSearch(): no selection, no replace, just a query and
// a scope.
export function useAssignmentSearch() {
  const { post, loading, error } = useApi();
  const { assignmentUrls } = useConfig();

  const results = ref([]);
  const hasSearched = ref(false);

  const params = reactive({
    query: '',
    siteId: null,
    sections: [],
  });

  // Guards against a slower, older request resolving after a newer search
  // (or a reset(), e.g. from switching Category <-> Tag) and overwriting
  // results/hasSearched with stale data.
  let requestId = 0;

  const search = async (type) => {
    const url =
      type === 'category'
        ? assignmentUrls.value.categories
        : assignmentUrls.value.tags;

    if (!url || !params.query) {
      return null;
    }

    const thisRequestId = ++requestId;

    const data = await post(url, {
      query: params.query,
      siteId: params.siteId,
      sections: params.sections,
    });

    if (thisRequestId === requestId) {
      hasSearched.value = true;
      results.value = data?.success ? data.results || [] : [];
    }

    return data;
  };

  const reset = () => {
    requestId++;
    results.value = [];
    hasSearched.value = false;
  };

  return {
    loading,
    error,
    results,
    hasSearched,
    params,
    search,
    reset,
  };
}
