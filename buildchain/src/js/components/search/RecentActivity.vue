<template>
  <div v-if="loading || logs.length > 0" class="editrix-recent-activity">
    <div class="editrix-recent-activity__header">
      <h2 class="editrix-recent-activity__title">{{ t('Recent Activity') }}</h2>
      <a
        v-if="logsPageUrl"
        :href="logsPageUrl"
        class="editrix-btn editrix-btn--ghost editrix-btn--sm"
      >
        {{ t('View all') }}
      </a>
    </div>

    <div v-if="loading" class="editrix-recent-activity__loading">
      {{ t('Loading...') }}
    </div>

    <ul v-else class="editrix-recent-activity__list">
      <li v-for="log in logs" :key="log.id" class="editrix-recent-activity__item">
        <span class="editrix-badge" :class="log.type === 'search' ? 'editrix-badge--neutral' : 'editrix-badge--info'">
          {{ log.type === 'search' ? t('Search') : t('Replace') }}
        </span>

        <span class="editrix-recent-activity__query">"{{ log.searchQuery }}"</span>

        <span v-if="log.type === 'replace'" class="editrix-recent-activity__count">
          {{ log.count }} {{ t('entries') }}
        </span>

        <span class="editrix-recent-activity__date">{{ log.relativeDate }}</span>

        <a href="#" class="editrix-recent-activity__rerun" @click.prevent="$emit('rerun', log)">
          {{ t('Re-run') }}
        </a>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { inject } from 'vue';
import { useConfig } from '../../composables/useConfig';

const t = inject('t');
const { logsPageUrl } = useConfig();

defineProps({
  logs: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
});

defineEmits(['rerun']);
</script>
