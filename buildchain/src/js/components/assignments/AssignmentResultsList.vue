<template>
  <div class="editrix-results">
    <header class="editrix-results__header">
      <h2 class="editrix-results__title">
        {{ type === 'category' ? t('Categories') : t('Tags') }}
        <span class="editrix-results__count">{{ results.length }}</span>
      </h2>
    </header>

    <div v-if="results.length === 0" class="editrix-results__empty">
      <div class="editrix-results__empty-icon">🔍</div>
      <p>{{ t('No results found.') }}</p>
    </div>

    <div v-else class="editrix-assignment-list">
      <div v-for="item in results" :key="`${item.siteId}-${item.id}`" class="editrix-assignment-card">
        <div class="editrix-assignment-card__header">
          <div>
            <strong>{{ item.title }}</strong>
            <span v-if="item.groupName" class="editrix-badge editrix-badge--neutral">{{ item.groupName }}</span>
            <span v-if="item.siteHandle" class="editrix-badge editrix-badge--neutral">{{ item.siteHandle }}</span>
          </div>
          <div class="editrix-assignment-card__meta">
            <span class="editrix-badge editrix-badge--info">
              {{ item.entryCount }} {{ t('entries') }}
            </span>
            <a
              v-if="item.cpEditUrl"
              :href="item.cpEditUrl"
              target="_blank"
              rel="noopener"
              class="editrix-btn editrix-btn--ghost editrix-btn--sm"
            >
              {{ t('Open in Craft') }} ↗
            </a>
          </div>
        </div>

        <ul class="editrix-assignment-card__entries">
          <li v-for="entry in visibleEntries(item)" :key="entry.id">
            <a :href="entry.cpEditUrl" target="_blank" rel="noopener">{{ entry.title }}</a>
            <span class="editrix-assignment-card__entry-meta">{{ entry.sectionName }}</span>
          </li>
        </ul>

        <button
          v-if="item.entries.length > entryLimit && !expanded[itemKey(item)]"
          type="button"
          class="editrix-assignment-card__show-more"
          @click="expanded[itemKey(item)] = true"
        >
          {{ t('Show {count} more entries', { count: item.entries.length - entryLimit }) }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { inject, reactive } from 'vue';

const t = inject('t');

defineProps({
  results: { type: Array, default: () => [] },
  type: { type: String, default: 'category' },
});

const entryLimit = 4;
const expanded = reactive({});

const itemKey = (item) => `${item.siteId}-${item.id}`;

const visibleEntries = (item) => {
  return expanded[itemKey(item)]
    ? item.entries
    : item.entries.slice(0, entryLimit);
};
</script>
