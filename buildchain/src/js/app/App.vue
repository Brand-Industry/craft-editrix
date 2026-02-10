<template>
  <div class="editrix">
    <div class="editrix-layout">
      <!-- Sidebar (Standard+) -->
      <aside v-if="hasFeature('scopeFilters')" class="editrix-layout__sidebar">
        <ScopeFilters
          v-model:sections="searchParams.sections"
          v-model:sites="selectedSites"
          v-model:fields="searchParams.fields"
          v-model:entry-types="searchParams.entryTypes"
        />
        
        <SelectionSummary
          :total-entries="totalEntries"
          :selected-fields="searchParams.fields.length"
        />
        
        <!-- Upgrade notice for Lite -->
        <UpgradeNotice v-if="edition === 'lite'" />
      </aside>
      
      <!-- Main content -->
      <main class="editrix-layout__main">
        <!-- Header -->
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
              📋 {{ t('Presets') }}
            </button>
          </div>
        </header>
        
        <!-- Search Form -->
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
          @search="handleSearch"
        />
        
        <!-- Loading -->
        <LoadingSpinner v-if="loading" :text="t('Searching...')" />
        
        <!-- Results -->
        <ResultsTable
          v-else-if="hasSearched"
          :results="results"
          :total-results="totalResults"
          :selected-count="selectedCount"
          :all-selected="allSelected"
          :is-selected="isSelected"
          @toggle="toggleResult"
          @toggle-all="toggleSelectAll"
          @view="openDiffPreview"
          @replace="showReplaceConfirm = true"
        />
        
        <!-- Empty state -->
        <EmptyState v-else />
      </main>
    </div>
    
    <!-- Diff Preview Slideout -->
    <DiffPreview
      :show="!!previewResult"
      :result="previewResult"
      :search-query="searchParams.query"
      :replace-with="searchParams.replaceWith"
      @close="previewResult = null"
      @apply="applyToSingle"
      @skip="skipToNext"
    />
    
    <!-- Replace Confirmation Modal -->
    <ConfirmModal
      :show="showReplaceConfirm"
      :title="t('Confirm Replacement')"
      :count="selectedCount"
      :loading="replacing"
      @confirm="handleReplace"
      @cancel="showReplaceConfirm = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, provide } from 'vue';
import { useConfig } from '../composables/useConfig';
import { useSearch } from '../composables/useSearch';

// Components
import SearchForm from '../components/search/SearchForm.vue';
import ResultsTable from '../components/results/ResultsTable.vue';
import DiffPreview from '../components/results/DiffPreview.vue';
import ScopeFilters from '../components/search/ScopeFilters.vue';
import SelectionSummary from '../components/search/SelectionSummary.vue';
import ConfirmModal from '../components/common/ConfirmModal.vue';
import LoadingSpinner from '../components/common/LoadingSpinner.vue';
import EmptyState from '../components/common/EmptyState.vue';
import UpgradeNotice from '../components/common/UpgradeNotice.vue';
import EnvironmentBadge from '../components/common/EnvironmentBadge.vue';

// Config
const { t, hasFeature, edition, currentSiteId, sites } = useConfig();

// Provide t function to all children
provide('t', t);
provide('hasFeature', hasFeature);

// Search
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
} = useSearch();

// Local state
const showPresets = ref(false);
const showReplaceConfirm = ref(false);
const replacing = ref(false);
const previewResult = ref(null);
const selectedSites = ref([]);
const totalEntries = ref(0);

// Initialize
onMounted(() => {
  searchParams.siteId = currentSiteId.value;
});

// Handlers
const handleSearch = async () => {
  try {
    await search();
  } catch (err) {
    window.Craft?.cp?.displayError?.(err.message || 'Search failed');
  }
};

const handleReplace = async () => {
  replacing.value = true;
  
  try {
    const data = await replace();
    
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
  }
};

const openDiffPreview = (result) => {
  previewResult.value = result;
};

const applyToSingle = async (result) => {
  // Apply replacement to single result
  // TODO: Implement single replacement
  previewResult.value = null;
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
