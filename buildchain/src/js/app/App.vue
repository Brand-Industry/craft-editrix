<template>
  <div class="editrix">
    <div class="editrix-layout">
      <aside v-if="searchType === 'text' && hasFeature('scopeFilters') && searchMode === 'segmented'" class="editrix-layout__sidebar">
        <ScopeFilters
          v-model:sections="searchParams.sections"
          v-model:sites="searchParams.siteIds"
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
            {{ pageTitle }}
            <EditionBadge />
            <EnvironmentBadge v-if="hasFeature('envIndicator') && safety.showEnvironmentIndicator" />
          </h1>

        </header>

        <ActivityChart
          v-if="!searchType"
          :days="dailyCounts"
          :loading="dailyCountsLoading"
        />

        <h2 v-if="!searchType" class="editrix-section-title">{{ t('What do you want to search?') }}</h2>

        <div v-if="!searchType" class="editrix-tool-picker">
          <button type="button" class="editrix-tool-card editrix-tool-card--text" @click="searchType = 'text'">
            <span class="editrix-tool-card__icon">🔤</span>
            <h3>{{ t('Text') }}</h3>
            <p>{{ t('Search for text within field content.') }}</p>
            <span class="editrix-tool-card__arrow">→</span>
          </button>
          <button
            type="button"
            class="editrix-tool-card editrix-tool-card--category"
            :class="{ 'editrix-tool-card--locked': !hasFeature('assignmentSearch') }"
            :disabled="!hasFeature('assignmentSearch')"
            @click="searchType = 'category'"
          >
            <span class="editrix-tool-card__icon">🏷️</span>
            <h3>
              {{ t('Categories') }}
              <span v-if="!hasFeature('assignmentSearch')" class="editrix-badge editrix-badge--info">Pro</span>
            </h3>
            <p>{{ t('See which entries a category is assigned to.') }}</p>
            <span class="editrix-tool-card__arrow">→</span>
          </button>
          <button
            type="button"
            class="editrix-tool-card editrix-tool-card--tag"
            :class="{ 'editrix-tool-card--locked': !hasFeature('assignmentSearch') }"
            :disabled="!hasFeature('assignmentSearch')"
            @click="searchType = 'tag'"
          >
            <span class="editrix-tool-card__icon">🔖</span>
            <h3>
              {{ t('Tags') }}
              <span v-if="!hasFeature('assignmentSearch')" class="editrix-badge editrix-badge--info">Pro</span>
            </h3>
            <p>{{ t('See which entries a tag is assigned to.') }}</p>
            <span class="editrix-tool-card__arrow">→</span>
          </button>
        </div>

        <RecentActivity
          v-if="!searchType"
          :logs="recentLogs"
          :loading="recentLogsLoading"
          @rerun="rerunLog"
        />

        <template v-else>
          <p class="editrix-tool-back">
            <a href="#" @click.prevent="searchType = null">← {{ t('Choose a different tool') }}</a>
          </p>

          <template v-if="searchType === 'text'">
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
        </template>

        <div v-if="searchType !== 'text' && !hasFeature('assignmentSearch')" class="editrix-form__warning" style="margin-top: 16px;">
          🔒 {{ t('Category & tag assignment search requires Pro edition.') }}
        </div>

        <div v-else-if="searchType !== 'text' && !hasAssignmentGroups" class="editrix-form__warning" style="margin-top: 16px;">
          <div>
            <p style="margin: 0 0 8px;">ℹ️ {{ assignmentEmptyMessage }}</p>
            <a
              v-if="assignmentSettingsUrl"
              :href="assignmentSettingsUrl"
              target="_blank"
              rel="noopener"
              class="editrix-btn editrix-btn--secondary editrix-btn--sm"
            >
              {{ assignmentSettingsLabel }}
            </a>
          </div>
        </div>

        <template v-else-if="searchType !== 'text'">
          <AssignmentSearchForm
            v-model:query="assignmentParams.query"
            v-model:sections="assignmentParams.sections"
            :type="searchType"
            :loading="assignmentLoading"
            @search="handleAssignmentSearch"
          />

          <LoadingSpinner v-if="assignmentLoading" :text="t('Searching...')" />

          <div v-else-if="assignmentError" class="editrix-form__warning" style="margin-top: 16px;">
            ⚠️ {{ assignmentError }}
          </div>

          <AssignmentResultsList
            v-else-if="assignmentHasSearched"
            :results="assignmentResults"
            :type="searchType"
          />
        </template>

        <template v-if="searchType === 'text'">
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
          </template>
        </template>
      </main>
    </div>

    <DiffPreview
      :show="!!previewResult"
      :result="previewResult"
      :original="previewData?.original ?? previewResult?.fieldValue ?? ''"
      :proposed="previewData?.proposed ?? previewResult?.fieldValue ?? ''"
      :changed="previewData?.changed ?? false"
      :loading="previewLoading"
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
      :require-confirmation-code="requiresConfirmationCode"
      :confirmation-reasons="confirmationReasons"
      @confirm="handleReplace"
      @cancel="cancelReplaceConfirm"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, provide } from 'vue';
import { useConfig } from '../composables/useConfig';
import { useSearch } from '../composables/useSearch';
import { useAssignmentSearch } from '../composables/useAssignmentSearch';
import { useApi } from '../composables/useApi';

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
import EditionBadge from '../components/common/EditionBadge.vue';
import AssignmentSearchForm from '../components/assignments/AssignmentSearchForm.vue';
import AssignmentResultsList from '../components/assignments/AssignmentResultsList.vue';
import RecentActivity from '../components/search/RecentActivity.vue';
import ActivityChart from '../components/logs/ActivityChart.vue';

const {
  t,
  hasFeature,
  currentSiteId,
  logsUrl,
  dailyCountsUrl,
  safety,
  isProduction,
  assignmentAvailability,
  categoriesSettingsUrl,
  tagsSettingsUrl,
} = useConfig();
const { get: apiGet } = useApi();

provide('t', t);
provide('hasFeature', hasFeature);

// null = no tool chosen yet (shows the picker). 'text' = search field
// content (existing flow); 'category'/'tag' = which entries have this
// category/tag assigned (Pro only, search-only for now).
const searchType = ref(null);

// "Find & Replace" only fits the text tool - Categories/Tags don't replace
// anything, so the on-page heading needs to change with it rather than
// showing a misleading fixed name.
const pageTitle = computed(() => {
  if (searchType.value === 'category') return t('Category search');
  if (searchType.value === 'tag') return t('Tag search');
  return t('Find & Replace');
});

// A site with zero category/tag groups can never have anything for this
// search to find - show a "create some first" notice instead of a form
// that would only ever come back empty.
const hasAssignmentGroups = computed(() => {
  if (searchType.value === 'category') return !!assignmentAvailability.value.categories;
  if (searchType.value === 'tag') return !!assignmentAvailability.value.tags;
  return true;
});

const assignmentEmptyMessage = computed(() => {
  return searchType.value === 'tag'
    ? t('There are no tags yet. Create tags and assign them to entries to search them here.')
    : t('There are no categories yet. Create categories and assign them to entries to search them here.');
});

const assignmentSettingsUrl = computed(() => {
  return searchType.value === 'tag' ? tagsSettingsUrl.value : categoriesSettingsUrl.value;
});

const assignmentSettingsLabel = computed(() => {
  return searchType.value === 'tag'
    ? t('Go to Tags settings')
    : t('Go to Categories settings');
});

const {
  loading: assignmentLoading,
  error: assignmentError,
  results: assignmentResults,
  hasSearched: assignmentHasSearched,
  params: assignmentParams,
  search: searchAssignments,
  reset: resetAssignmentSearch,
} = useAssignmentSearch();

const handleAssignmentSearch = async () => {
  try {
    await searchAssignments(searchType.value);
  } catch (err) {
    window.Craft?.cp?.displayError?.(err.message || 'Search failed');
  }
};

// Category, Tag, and text search share this one App instance (it's an SPA,
// so there's no page reload between them) - without this, switching away
// from one tool kept showing its query/results/scope-filter sidebar as if
// it were still active.
watch(searchType, (newType, oldType) => {
  if (oldType === 'category' || oldType === 'tag') {
    assignmentParams.query = '';
    assignmentParams.sections = [];
    resetAssignmentSearch();
  }
  if (oldType === 'text') {
    setSearchMode('general');
  }
});

// Shown on the tool-picker landing screen so users don't have to visit
// Logs & History just to see (or repeat) what they last searched.
const recentLogs = ref([]);
const recentLogsLoading = ref(false);

const fetchRecentActivity = async () => {
  if (!logsUrl.value) return;

  recentLogsLoading.value = true;
  try {
    const data = await apiGet(logsUrl.value, { limit: 5 });
    recentLogs.value = data?.logs || [];
  } catch (err) {
    // Non-critical - the panel just stays hidden.
    recentLogs.value = [];
  } finally {
    recentLogsLoading.value = false;
  }
};

// Daily search/replace activity chart shown above the tool-picker cards.
const dailyCounts = ref([]);
const dailyCountsLoading = ref(false);

const fetchDailyCounts = async () => {
  if (!dailyCountsUrl.value) return;

  dailyCountsLoading.value = true;
  try {
    const data = await apiGet(dailyCountsUrl.value, { days: 14 });
    dailyCounts.value = data?.success ? data.days || [] : [];
  } catch (err) {
    dailyCounts.value = [];
  } finally {
    dailyCountsLoading.value = false;
  }
};

const rerunLog = (log) => {
  searchParams.query = log.searchQuery;
  searchParams.useRegex = !!log.useRegex;
  searchParams.caseInsensitive = !log.caseSensitive;
  searchParams.wholeWords = !!log.wholeWords;

  const scope = log.scope || {};
  searchParams.allSites = !!scope.allSites;
  searchParams.sections = scope.sections || [];
  searchParams.fields = scope.fields || [];
  searchParams.entryTypes = scope.entryTypes || [];

  searchType.value = 'text';
  actionMode.value = 'search';
  searchMode.value =
    searchParams.sections.length > 0 ? 'segmented' : 'general';

  handleSearch();
};

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

const showReplaceConfirm = ref(false);
const replacing = ref(false);
const previewResult = ref(null);
const detailResult = ref(null);

const setSearchMode = (mode) => {
  searchMode.value = mode;

  if (mode === 'general') {
    searchParams.sections = [];
    searchParams.fields = [];
    searchParams.entryTypes = [];
    searchParams.siteIds = [];
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
  assignmentParams.siteId = currentSiteId.value;
  restoreFromHistory();
  fetchRecentActivity();
  fetchDailyCounts();
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

  searchType.value = 'text';
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

// Safety settings make a large or production replace a deliberate act:
// past the bulk threshold, or in production with Safe Mode on, the
// confirm modal requires typing "REPLACE" (also enforced server-side).
const requiresConfirmationCode = computed(() => {
  const threshold = safety.value.bulkConfirmationThreshold;
  const overThreshold =
    typeof threshold === 'number' && confirmCount.value > threshold;
  const productionRisk = safety.value.productionSafeMode && isProduction.value;
  return overThreshold || productionRisk;
});

const confirmationReasons = computed(() => {
  const reasons = [];
  const threshold = safety.value.bulkConfirmationThreshold;
  if (typeof threshold === 'number' && confirmCount.value > threshold) {
    reasons.push(
      t('This will affect {count} entries, above your bulk confirmation threshold of {threshold}.', {
        count: confirmCount.value,
        threshold,
      })
    );
  }
  if (safety.value.productionSafeMode && isProduction.value) {
    reasons.push(t('You are replacing content in a production environment.'));
  }
  return reasons;
});

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

const handleReplace = async (confirmationCode) => {
  replacing.value = true;

  try {
    const data = singleReplaceTarget.value
      ? await replace({
          results: [singleReplaceTarget.value],
          replaceWith: singleReplaceValue.value,
          confirmationCode,
        })
      : await replace({ confirmationCode });

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

// The diff shown in DiffPreview must come from the same tag/entity-aware
// engine that ReplaceService actually uses to save - computing it again
// in JS (as this used to do) can disagree with what Apply would really
// do. Whenever the previewed result changes, fetch the real before/after
// from the server.
const previewData = ref(null);
const previewLoading = ref(false);

// Guards against a slower, older request resolving after a newer one and
// overwriting the currently-displayed preview with mismatched data.
let previewRequestId = 0;

watch(previewResult, async (result) => {
  const requestId = ++previewRequestId;
  previewData.value = null;

  if (!result) return;

  previewLoading.value = true;
  try {
    const data = await getPreview(result);
    if (requestId === previewRequestId) {
      previewData.value = data;
    }
  } finally {
    if (requestId === previewRequestId) {
      previewLoading.value = false;
    }
  }
});

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
