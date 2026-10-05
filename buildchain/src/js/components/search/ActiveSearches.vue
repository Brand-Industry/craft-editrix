<template>
  <div v-if="searches.length > 0" class="editrix-active-searches">
    <h2 class="editrix-active-searches__title">{{ t('Searches in progress') }}</h2>

    <ul class="editrix-active-searches__list">
      <li v-for="s in searches" :key="s.searchId" class="editrix-active-searches__item">
        <span class="editrix-active-searches__query">"{{ s.query }}"</span>

        <span class="editrix-active-searches__progress">
          <span class="editrix-active-searches__bar">
            <span
              class="editrix-active-searches__fill"
              :style="{ width: `${percent(s)}%` }"
            ></span>
          </span>
          <span class="editrix-active-searches__pct">
            {{ s.status === 'queued' || s.totalCount === 0 ? t('Starting search…') : `${percent(s)}%` }}
          </span>
        </span>

        <button
          type="button"
          class="editrix-btn editrix-btn--secondary editrix-btn--sm"
          @click="$emit('open', s.searchId)"
        >
          {{ t('Open') }}
        </button>
        <button
          type="button"
          class="editrix-btn editrix-btn--ghost editrix-btn--sm"
          @click="$emit('cancel', s.searchId)"
        >
          {{ t('Cancel search') }}
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { inject } from 'vue';

const t = inject('t');

defineProps({
  searches: { type: Array, default: () => [] },
});

defineEmits(['open', 'cancel']);

const percent = s =>
  s.totalCount > 0 ? Math.min(100, Math.round((s.scannedCount / s.totalCount) * 100)) : 0;
</script>
