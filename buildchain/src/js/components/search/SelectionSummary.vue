<template>
  <div class="editrix-selection">
    <div class="editrix-selection__title">{{ t('ACTIVE SELECTION') }}</div>
    <div class="editrix-selection__stat">
      <span class="editrix-selection__stat-label">{{ t('Target Entries') }}</span>
      <span class="editrix-selection__stat-value">{{ formatNumber(totalEntries) }}</span>
    </div>
    <div class="editrix-selection__stat">
      <span class="editrix-selection__stat-label">{{ t('Fields Selected') }}</span>
      <span class="editrix-selection__stat-value">{{ selectedFields || t('All') }}</span>
    </div>
  </div>
</template>

<script setup>
import { inject } from 'vue';

const t = inject('t');

defineProps({
  totalEntries: { type: Number, default: 0 },
  selectedFields: { type: Number, default: 0 },
});

const formatNumber = (num) => {
  return num.toLocaleString();
};
</script>
