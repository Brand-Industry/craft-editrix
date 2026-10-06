<template>
  <div class="editrix-results">
    <header class="editrix-results__header">
      <h2 class="editrix-results__title">
        {{ t('Results') }}
        <span class="editrix-results__count">{{ totalResults }}</span>
        <span v-if="occurrenceCount > totalResults" class="editrix-results__matches-total">
          {{ t('{count} matches', { count: occurrenceCount.toLocaleString() }) }}
        </span>

        <label v-if="totalResults > 0" class="editrix-results__page-size">
          {{ t('Show') }}
          <select v-model.number="pageSize">
            <option v-for="size in pageSizeOptions" :key="size" :value="size">{{ size }}</option>
          </select>
        </label>
      </h2>

      <div class="editrix-results__actions">
        <button
          class="editrix-btn editrix-btn--secondary editrix-btn--sm"
          @click="$emit('toggle-all')"
        >
          {{ allSelected ? t('Deselect All') : t('Select All') }}
        </button>

        <template v-if="actionMode === 'search'">
          <button
            v-if="canExport"
            class="editrix-btn editrix-btn--primary editrix-btn--sm"
            :disabled="totalResults === 0 || !hasFeature('export.csv')"
            @click="exportCsv"
          >
            {{ t('Export CSV') }}
            <span v-if="selectedCount > 0">({{ selectedCount }})</span>
          </button>
          <span v-if="canExport && !hasFeature('export.csv')" class="editrix-badge editrix-badge--info">Pro</span>
        </template>

        <button
          v-else
          class="editrix-btn editrix-btn--primary"
          :disabled="selectedCount === 0 || overReplaceLimit"
          @click="$emit('replace')"
        >
          {{ t('Replace Selected') }}
          <span v-if="selectedCount > 0">({{ selectedCount }})</span>
        </button>
        <span v-if="overReplaceLimit" class="editrix-results__limit-note">
          {{ t('Select at most {limit} rows to replace at once.', { limit: replaceLimit.toLocaleString() }) }}
        </span>
      </div>
    </header>

    <!-- Empty state -->
    <div v-if="totalResults === 0" class="editrix-results__empty">
      <div class="editrix-results__empty-icon">🔍</div>
      <p>{{ t('No results found.') }}</p>
    </div>

    <table v-else class="editrix-results__table">
      <thead>
        <tr>
          <th class="editrix-results__checkbox">
            <input
              type="checkbox"
              :checked="allSelected"
              @change="$emit('toggle-all')"
            />
          </th>
          <th>{{ t('TITLE') }}</th>
          <th>{{ t('FIELD NAME') }}</th>
          <th>{{ t('MATCH PREVIEW') }}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <template v-for="row in pagedRows" :key="row.result.uniqueKey">
          <tr v-if="row.showSiteHeader" class="editrix-results__site-row">
            <td colspan="5">
              <strong>📍 {{ row.siteName }}</strong>
              <span class="editrix-badge editrix-badge--neutral">{{ row.siteCount }}</span>
            </td>
          </tr>

          <tr
            :class="{
              'is-selected': isSelected(row.result),
              'is-replaced': rowState(row.result) === 'replaced',
              'is-stale': rowState(row.result) === 'stale',
              'is-failed': rowState(row.result) === 'failed',
            }"
            @click="$emit('toggle', row.result)"
          >
            <td class="editrix-results__checkbox" @click.stop>
              <input
                type="checkbox"
                :checked="isSelected(row.result)"
                :disabled="rowState(row.result) !== null"
                @change="$emit('toggle', row.result)"
              />
            </td>
            <td>
              <div class="editrix-results__element">
                <div class="editrix-results__element-title">
                  {{ row.result.elementTitle }}
                </div>
                <div class="editrix-results__element-meta">
                  {{ row.result.sectionName }}
                  <span v-if="row.result.parentTitle"> → {{ row.result.parentTitle }}</span>
                </div>
              </div>
            </td>
            <td class="editrix-results__field">
              {{ row.result.fieldName }}
              <span class="editrix-badge editrix-badge--neutral">{{ elementTypeLabel(row.result.elementType) }}</span>
              <span v-if="(row.result.matchCount || 1) > 1" class="editrix-badge editrix-badge--info">{{ t('{count} matches', { count: row.result.matchCount }) }}</span>
              <span v-if="rowState(row.result) === 'replaced'" class="editrix-badge editrix-badge--success">{{ t('Replaced') }}</span>
              <span v-else-if="rowState(row.result) === 'stale'" class="editrix-badge editrix-badge--warning" :title="t('Its content changed after the search. Search again to see the current text.')">{{ t('Changed since search') }}</span>
              <span v-else-if="rowState(row.result) === 'failed'" class="editrix-badge editrix-badge--warning">{{ t('Not saved') }}</span>
            </td>
            <td class="editrix-results__preview">
              <span v-html="formatPreview(row.result.matchContext)"></span>
              <button
                v-if="(row.result.matchCount || 1) > 1"
                type="button"
                class="editrix-results__expand"
                :aria-expanded="isExpanded(row.result)"
                @click.stop="toggleExpanded(row.result)"
              >
                {{ isExpanded(row.result)
                  ? t('Hide matches')
                  : t('Show all {count} matches', { count: row.result.matchCount }) }}
              </button>
            </td>
            <td>
              <button
                class="editrix-btn editrix-btn--ghost editrix-btn--sm"
                @click.stop="$emit('view', row.result)"
              >
                {{ t('View') }}
              </button>
            </td>
          </tr>

          <tr v-if="isExpanded(row.result)" class="editrix-results__matches-row">
            <td></td>
            <td colspan="4">
              <ol class="editrix-results__matches">
                <li v-for="(m, i) in row.result.matches" :key="i" v-html="formatPreview(m.context)"></li>
              </ol>
              <p v-if="row.result.matchCount > row.result.matches.length" class="editrix-results__matches-more">
                {{ t('and {count} more', { count: row.result.matchCount - row.result.matches.length }) }}
              </p>
            </td>
          </tr>
        </template>
      </tbody>
    </table>

    <footer v-if="totalResults > 0" class="editrix-results__footer">
      <div class="editrix-results__selection-info">
        <span v-if="selectedCount > 0">
          <strong>{{ selectedCount }}</strong> {{ t('entries selected') }}
        </span>
        <span>{{ t('Total occurrences:') }} <strong>{{ occurrenceCount }}</strong></span>
      </div>

      <div class="editrix-results__pagination">
        <span class="editrix-results__page-range">
          {{ pageRangeStart }}–{{ pageRangeEnd }} {{ t('of') }} {{ flatRows.length }}
        </span>

        <button
          class="editrix-btn editrix-btn--sm editrix-btn--secondary"
          :disabled="currentPage === 1"
          @click="currentPage--"
        >
          {{ t('Previous') }}
        </button>
        <button
          class="editrix-btn editrix-btn--sm editrix-btn--secondary"
          :disabled="currentPage >= totalPages"
          @click="currentPage++"
        >
          {{ t('Next') }}
        </button>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { inject, ref, computed, watch } from 'vue';
import { elementTypeLabel } from '../../utils/elementType';

const t = inject('t');
const hasFeature = inject('hasFeature');
const canExport = inject('canExport');

const props = defineProps({
  results: { type: Object, required: true },
  totalResults: { type: Number, required: true },
  selectedCount: { type: Number, required: true },
  allSelected: { type: Boolean, default: false },
  isSelected: { type: Function, required: true },
  // Most rows one replace may change; 0 means no limit.
  replaceLimit: { type: Number, default: 0 },
  // 'replaced', 'stale' or null for a result.
  rowState: { type: Function, default: () => null },
  actionMode: { type: String, default: 'replace' },
  searchQuery: { type: String, default: '' },
});

// Results come from the backend as one big grouped-by-site batch (the
// search endpoint has no offset/limit of its own), so pagination here is
// purely a client-side slice over that already-fetched list.
const pageSizeOptions = [5, 10, 25, 50, 100];
const pageSize = ref(10);
const currentPage = ref(1);

const flatRows = computed(() => {
  const rows = [];
  Object.values(props.results).forEach(siteData => {
    siteData.results.forEach((result, index) => {
      rows.push({
        result,
        siteName: siteData.siteName,
        siteCount: siteData.results.length,
        isFirstInSite: index === 0,
      });
    });
  });
  return rows;
});

// A row is a field; it can match several times. Which rows show their
// individual matches is purely a view concern.
const expanded = ref(new Set());
const isExpanded = result => expanded.value.has(result.uniqueKey);
const toggleExpanded = result => {
  const next = new Set(expanded.value);
  if (!next.delete(result.uniqueKey)) next.add(result.uniqueKey);
  expanded.value = next;
};

const overReplaceLimit = computed(
  () => props.replaceLimit > 0 && props.selectedCount > props.replaceLimit
);

const occurrenceCount = computed(() =>
  flatRows.value.reduce((sum, row) => sum + (row.result.matchCount || 1), 0)
);

const showSiteHeaders = computed(() => Object.keys(props.results).length > 1);

const totalPages = computed(() =>
  Math.max(1, Math.ceil(flatRows.value.length / pageSize.value))
);

const pagedRows = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value;
  const slice = flatRows.value.slice(start, start + pageSize.value);

  let lastSiteName = null;
  return slice.map((row, index) => {
    const showSiteHeader =
      showSiteHeaders.value && (index === 0 || row.siteName !== lastSiteName);
    lastSiteName = row.siteName;
    return { ...row, showSiteHeader };
  });
});

const pageRangeStart = computed(() =>
  flatRows.value.length === 0 ? 0 : (currentPage.value - 1) * pageSize.value + 1
);
const pageRangeEnd = computed(() =>
  Math.min(currentPage.value * pageSize.value, flatRows.value.length)
);

// A new search result set, or a page size change, both invalidate whatever
// page we were on.
watch(() => props.results, () => { currentPage.value = 1; });
watch(pageSize, () => { currentPage.value = 1; });

defineEmits(['toggle', 'toggle-all', 'view', 'replace']);

// Format preview with highlighted match
const formatPreview = (context) => {
  if (!context) return '';
  return context
    .replace(/\[\[MATCH\]\]/g, '<span class="match">')
    .replace(/\[\[\/MATCH\]\]/g, '</span>');
};

const csvCell = (value) => {
  const str = String(value ?? '');
  return /[",\n]/.test(str) ? `"${str.replace(/"/g, '""')}"` : str;
};

const exportCsv = () => {
  const exportAll = props.selectedCount === 0;
  const rows = [
    ['Site', 'Type', 'Element', 'Section', 'Field', 'Match', 'Edit URL'],
  ];

  Object.values(props.results).forEach(siteData => {
    siteData.results.forEach(result => {
      if (!exportAll && !props.isSelected(result)) return;

      // One line per occurrence, so the file counts what the search found.
      // Only the first contexts of a field with very many are kept.
      (result.matches?.length ? result.matches.map(m => m.context) : [result.matchContext]).forEach(context => {
        rows.push([
          siteData.siteName,
          elementTypeLabel(result.elementType),
          result.elementTitle,
          result.sectionName,
          result.fieldName,
          context.replace(/\[\[\/?MATCH\]\]/g, ''),
          result.cpEditUrl || '',
        ]);
      });
    });
  });

  const csv = rows.map(row => row.map(csvCell).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  const query = props.searchQuery.replace(/[^a-z0-9]+/gi, '-').slice(0, 40);

  link.href = url;
  link.download = `editrix-search${query ? `-${query}` : ''}.csv`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
};
</script>

<style scoped>
.editrix-results__site-row td {
  background: #f4f4f5;
  padding: 8px 16px;
}
</style>
