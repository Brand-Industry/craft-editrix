<template>
  <div class="editrix-results">
    <header class="editrix-results__header">
      <h2 class="editrix-results__title">
        {{ t('Results') }}
        <span class="editrix-results__count">{{ totalResults }}</span>
      </h2>

      <div class="editrix-results__actions">
        <button
          class="editrix-btn editrix-btn--secondary editrix-btn--sm"
          @click="$emit('toggle-all')"
        >
          {{ allSelected ? t('Deselect All') : t('Select All') }}
        </button>

        <button
          v-if="actionMode === 'search'"
          class="editrix-btn editrix-btn--primary editrix-btn--sm"
          :disabled="totalResults === 0 || !hasFeature('export.csv')"
          @click="exportCsv"
        >
          {{ t('Export CSV') }}
          <span v-if="selectedCount > 0">({{ selectedCount }})</span>
        </button>
        <span v-if="actionMode === 'search' && !hasFeature('export.csv')" class="editrix-badge editrix-badge--info">Pro</span>

        <button
          v-else
          class="editrix-btn editrix-btn--primary"
          :disabled="selectedCount === 0"
          @click="$emit('replace')"
        >
          {{ t('Replace Selected') }}
          <span v-if="selectedCount > 0">({{ selectedCount }})</span>
        </button>
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
        <template v-for="(siteData, siteHandle) in results" :key="siteHandle">
          <tr v-if="Object.keys(results).length > 1" class="editrix-results__site-row">
            <td colspan="5">
              <strong>📍 {{ siteData.siteName }}</strong>
              <span class="editrix-badge editrix-badge--neutral">{{ siteData.results.length }}</span>
            </td>
          </tr>

          <tr
            v-for="result in siteData.results"
            :key="result.uniqueKey"
            :class="{ 'is-selected': isSelected(result) }"
            @click="$emit('toggle', result)"
          >
            <td class="editrix-results__checkbox" @click.stop>
              <input
                type="checkbox"
                :checked="isSelected(result)"
                @change="$emit('toggle', result)"
              />
            </td>
            <td>
              <div class="editrix-results__element">
                <div class="editrix-results__element-title">
                  {{ result.elementTitle }}
                </div>
                <div class="editrix-results__element-meta">
                  {{ result.sectionName }}
                  <span v-if="result.parentTitle"> → {{ result.parentTitle }}</span>
                </div>
              </div>
            </td>
            <td class="editrix-results__field">
              {{ result.fieldName }}
              <span class="editrix-badge editrix-badge--neutral">{{ elementTypeLabel(result.elementType) }}</span>
            </td>
            <td class="editrix-results__preview" v-html="formatPreview(result.matchContext)"></td>
            <td>
              <button
                class="editrix-btn editrix-btn--ghost editrix-btn--sm"
                @click.stop="$emit('view', result)"
              >
                {{ t('View') }}
              </button>
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
        <span>{{ t('Total occurrences:') }} <strong>{{ totalResults }}</strong></span>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { inject } from 'vue';
import { elementTypeLabel } from '../../utils/elementType';

const t = inject('t');
const hasFeature = inject('hasFeature');

const props = defineProps({
  results: { type: Object, required: true },
  totalResults: { type: Number, required: true },
  selectedCount: { type: Number, required: true },
  allSelected: { type: Boolean, default: false },
  isSelected: { type: Function, required: true },
  actionMode: { type: String, default: 'replace' },
  searchQuery: { type: String, default: '' },
});

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

      rows.push([
        siteData.siteName,
        elementTypeLabel(result.elementType),
        result.elementTitle,
        result.sectionName,
        result.fieldName,
        result.matchContext.replace(/\[\[\/?MATCH\]\]/g, ''),
        result.cpEditUrl || '',
      ]);
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
