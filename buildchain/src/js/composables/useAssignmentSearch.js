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

  const search = async (type) => {
    const url =
      type === 'category'
        ? assignmentUrls.value.categories
        : assignmentUrls.value.tags;

    if (!url || !params.query) {
      return null;
    }

    const data = await post(url, {
      query: params.query,
      siteId: params.siteId,
      sections: params.sections,
    });

    hasSearched.value = true;
    results.value = data?.success ? data.results || [] : [];

    return data;
  };

  const reset = () => {
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
