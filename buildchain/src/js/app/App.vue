<template>
  <div class="editrix">
    <div class="editrix-layout">
      <aside v-if="hasFeature('scopeFilters') && searchMode === 'segmented'" class="editrix-layout__sidebar">
        <ScopeFilters
          v-model:sections="searchParams.sections"
          v-model:sites="selectedSites"
          v-model:fields="searchParams.fields"
          v-model:entry-types="searchParams.entryTypes"
          :action-mode="actionMode"
        />

        <SelectionSummary
          :total-entries="totalEntries"
          :selected-fields="searchParams.fields.length"
        />

      </aside>

      <main class="editrix-layout__main">
        <header class="editrix-search__header">
          <h1 class="editrix-search__title">
            {{ t('Find & Replace') }}
            <EnvironmentBadge v-if="hasFeature('envIndicator')" />
          </h1>

          <div class="editrix-search__actions">
            <button
              v-if="hasFeature('presets')"
              class="editrix-btn editrix-btn--secondary"
              @click="showPresets = true"
            >
            {{ t('Presets') }}
            </button>
          </div>
        </header>

        <div class="editrix-mode-toggle">
          <button
            type="button"
            class="editrix-mode-toggle__option"
            :class="{ 'is-active': actionMode === 'search' }"
            @click="setActionMode('search')"
          >
            {{ t('Search') }}
          </button>
          <button
            type="button"
            class="editrix-mode-toggle__option"
            :class="{ 'is-active': actionMode === 'replace' }"
            @click="setActionMode('replace')"
          >
            {{ t('Search & Replace') }}
          </button>
        </div>
        <p class="editrix-mode-toggle__hint">
          {{ actionMode === 'search'
            ? t('Find where content lives and export the results - nothing gets changed.')
            : t('Find content and replace it. Only fields safe to overwrite are offered.') }}
        </p>

        <div v-if="hasFeature('scopeFilters')" class="editrix-mode-toggle">
          <button
            type="button"
            class="editrix-mode-toggle__option"
            :class="{ 'is-active': searchMode === 'general' }"
            @click="setSearchMode('general')"
          >
            {{ t('General Search') }}
          </button>
          <button
            type="button"
            class="editrix-mode-toggle__option"
            :class="{ 'is-active': searchMode === 'segmented' }"
            @click="setSearchMode('segmented')"
          >
            {{ t('Segmented Search') }}
          </button>
        </div>
        <p v-if="hasFeature('scopeFilters')" class="editrix-mode-toggle__hint">
          {{ searchMode === 'general'
            ? t('Searches every entry and field across the selected site(s).')
            : t('Narrow the search down to a section, entry type, and fields.') }}
        </p>

        <SearchForm
          v-model:query="searchParams.query"
          v-model:replace-with="searchParams.replaceWith"
          v-model:site-id="searchParams.siteId"
          v-model:all-sites="searchParams.allSites"
          v-model:use-regex="searchParams.useRegex"
          v-model:case-insensitive="searchParams.caseInsensitive"
          v-model:whole-words="searchParams.wholeWords"
          v-model:dry-run="searchParams.dryRun"
          v-model:search-entries="searchParams.searchEntries"
          v-model:search-globals="searchParams.searchGlobals"
          v-model:search-matrix="searchParams.searchMatrix"
          v-model:search-categories="searchParams.searchCategories"
          :loading="loading"
          :show-scope-inline="!hasFeature('scopeFilters')"
          :action-mode="actionMode"
          @search="handleSearch"
        />

        <LoadingSpinner v-if="loading" :text="t('Searching...')" />

        <div v-else-if="error" class="editrix-form__warning" style="margin-top: 16px;">
          ⚠️ {{ error }}
        </div>

        <ResultsTable
          v-else-if="hasSearched"
          :results="visibleResults"
          :total-results="visibleTotalResults"
          :selected-count="selectedCount"
          :all-selected="allSelected"
          :is-selected="isSelected"
          :action-mode="actionMode"
          :search-query="searchParams.query"
          @toggle="toggleResult"
          @toggle-all="toggleSelectAll"
          @view="openView"
          @replace="showReplaceConfirm = true"
        />

        <!-- <EmptyState v-else /> -->
      </main>
    </div>

    <DiffPreview
      :show="!!previewResult"
      :result="previewResult"
      :search-query="searchParams.query"
      :replace-with="searchParams.replaceWith"
      @close="previewResult = null"
      @apply="applyToSingle"
      @skip="skipToNext"
    />

    <ResultDetailModal
      :show="!!detailResult"
      :result="detailResult"
      @close="detailResult = null"
      @request-replace="handleRequestSingleReplace"
    />

    <ConfirmModal
      :show="showReplaceConfirm"
      :title="t('Confirm Replacement')"
      :count="confirmCount"
      :loading="replacing"
      @confirm="handleReplace"
      @cancel="cancelReplaceConfirm"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, provide } from 'vue';
import { useConfig } from '../composables/useConfig';
import { useSearch } from '../composables/useSearch';

import SearchForm from '../components/search/SearchForm.vue';
import ResultsTable from '../components/results/ResultsTable.vue';
import DiffPreview from '../components/results/DiffPreview.vue';
import ResultDetailModal from '../components/results/ResultDetailModal.vue';
import ScopeFilters from '../components/search/ScopeFilters.vue';
import SelectionSummary from '../components/search/SelectionSummary.vue';
import ConfirmModal from '../components/common/ConfirmModal.vue';
import LoadingSpinner from '../components/common/LoadingSpinner.vue';
import EmptyState from '../components/common/EmptyState.vue';
import EnvironmentBadge from '../components/common/EnvironmentBadge.vue';

const { t, hasFeature, currentSiteId } = useConfig();

provide('t', t);
provide('hasFeature', hasFeature);

const searchMode = ref('general');
// Default to 'search' - replacing content is a deliberate, separate step.
const actionMode = ref('search');

const {
  loading,
  error,
  results,
  totalResults,
  selectedResults,
  hasSearched,
  searchParams,
  hasResults,
  selectedCount,
  hasSelected,
  allSelected,
  search,
  toggleResult,
  isSelected,
  selectAll,
  deselectAll,
  toggleSelectAll,
  replace,
  getPreview,
  reset,
} = useSearch(actionMode);

const showPresets = ref(false);
const showReplaceConfirm = ref(false);
const replacing = ref(false);
const previewResult = ref(null);
const detailResult = ref(null);
const selectedSites = ref([]);

const setSearchMode = (mode) => {
  searchMode.value = mode;

  if (mode === 'general') {
    searchParams.sections = [];
    searchParams.fields = [];
    searchParams.entryTypes = [];
    selectedSites.value = [];
  }
};

const setActionMode = (mode) => {
  actionMode.value = mode;
  // Selection means something different in each mode (export vs replace) -
  // don't carry one mode's picks into the other.
  deselectAll();
};

// In Search & Replace, matches that can't be written back (e.g. a Tags
// field's tag titles) are hidden entirely rather than shown disabled -
// they only ever appear in the read-only Search mode.
const visibleResults = computed(() => {
  if (actionMode.value !== 'replace') {
    return results.value;
  }

  const filtered = {};
  Object.entries(results.value).forEach(([siteHandle, siteData]) => {
    const rows = siteData.results.filter(r => !r.readOnly);
    if (rows.length > 0) {
      filtered[siteHandle] = { ...siteData, results: rows };
    }
  });
  return filtered;
});

const visibleTotalResults = computed(() => {
  return Object.values(visibleResults.value).reduce(
    (sum, siteData) => sum + siteData.results.length,
    0
  );
});

const totalEntries = computed(() => {
  const uniqueKeys = new Set();
  Object.values(visibleResults.value).forEach(siteData => {
    siteData.results.forEach(result => {
      uniqueKeys.add(`${result.elementType}:${result.elementId}`);
    });
  });
  return uniqueKeys.size;
});

onMounted(() => {
  searchParams.siteId = currentSiteId.value;
  restoreFromHistory();
});

// "Re-run" from Logs & History links here with the original query/scope in
// the URL. Always lands in Search mode - re-running a past search should
// never quietly turn into a replace.
const restoreFromHistory = () => {
  const params = new URLSearchParams(window.location.search);
  const q = params.get('q');
  if (!q) return;

  searchParams.query = q;
  searchParams.useRegex = params.get('regex') === '1';
  searchParams.caseInsensitive = params.get('ci') === '1';
  searchParams.wholeWords = params.get('ww') === '1';
  searchParams.allSites = params.get('allSites') === '1';
  searchParams.sections = params.getAll('sections[]');
  searchParams.fields = params.getAll('fields[]');
  searchParams.entryTypes = params.getAll('entryTypes[]');

  actionMode.value = 'search';
  searchMode.value =
    searchParams.sections.length > 0 ? 'segmented' : 'general';

  handleSearch();
};

const handleSearch = async () => {
  try {
    await search();
  } catch (err) {
    window.Craft?.cp?.displayError?.(err.message || 'Search failed');
  }
};

// Set when the confirm modal was opened from the detail modal's inline
// "Replace" field, so handleReplace() knows to replace just that one match
// instead of the bulk selection.
const singleReplaceTarget = ref(null);
const singleReplaceValue = ref('');
const confirmCount = computed(() =>
  singleReplaceTarget.value ? 1 : selectedCount.value
);

const handleRequestSingleReplace = ({ result, replaceWith }) => {
  singleReplaceTarget.value = result;
  singleReplaceValue.value = replaceWith;
  detailResult.value = null;
  previewResult.value = null;
  showReplaceConfirm.value = true;
};

const cancelReplaceConfirm = () => {
  showReplaceConfirm.value = false;
  singleReplaceTarget.value = null;
};

const handleReplace = async () => {
  replacing.value = true;

  try {
    const data = singleReplaceTarget.value
      ? await replace({
          results: [singleReplaceTarget.value],
          replaceWith: singleReplaceValue.value,
        })
      : await replace();

    if (data?.success) {
      window.Craft?.cp?.displayNotice?.(data.message);
      showReplaceConfirm.value = false;

      // Re-run search to update results
      await handleSearch();
    } else {
      window.Craft?.cp?.displayError?.(data?.error || 'Replace failed');
    }
  } catch (err) {
    window.Craft?.cp?.displayError?.(err.message || 'Replace failed');
  } finally {
    replacing.value = false;
    singleReplaceTarget.value = null;
  }
};

const openDiffPreview = (result) => {
  previewResult.value = result;
};

// Search mode gets the read-only details modal (info + link to Craft);
// Search & Replace keeps the existing diff preview with Apply/Skip.
const openView = (result) => {
  if (actionMode.value === 'replace') {
    openDiffPreview(result);
  } else {
    detailResult.value = result;
  }
};

const applyToSingle = (result) => {
  handleRequestSingleReplace({
    result,
    replaceWith: searchParams.replaceWith,
  });
};

const skipToNext = () => {
  // Move to next result in selection
  const currentIndex = selectedResults.value.findIndex(
    r => r.uniqueKey === previewResult.value?.uniqueKey
  );

  if (currentIndex >= 0 && currentIndex < selectedResults.value.length - 1) {
    previewResult.value = selectedResults.value[currentIndex + 1];
  } else {
    previewResult.value = null;
  }
};
</script>
