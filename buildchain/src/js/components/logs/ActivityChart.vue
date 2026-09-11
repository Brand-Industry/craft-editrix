<template>
  <div class="editrix-activity-chart">
    <div class="editrix-activity-chart__header">
      <h3 class="editrix-activity-chart__title">Activity (last {{ days.length }} days)</h3>
      <div class="editrix-activity-chart__legend">
        <span class="editrix-activity-chart__legend-item">
          <span class="editrix-activity-chart__swatch editrix-activity-chart__swatch--search"></span>
          Search
        </span>
        <span class="editrix-activity-chart__legend-item">
          <span class="editrix-activity-chart__swatch editrix-activity-chart__swatch--replace"></span>
          Replace
        </span>
      </div>
    </div>

    <div v-if="loading" class="editrix-activity-chart__loading">Loading...</div>

    <div v-else-if="days.length > 0" class="editrix-activity-chart__bars">
      <div
        v-for="day in days"
        :key="day.date"
        class="editrix-activity-chart__col"
        :title="`${day.date}: ${day.search} search, ${day.replace} replace`"
      >
        <div class="editrix-activity-chart__track">
          <div
            class="editrix-activity-chart__bar editrix-activity-chart__bar--replace"
            :style="{ height: pct(day.replace) + '%' }"
          ></div>
          <div
            class="editrix-activity-chart__bar editrix-activity-chart__bar--search"
            :style="{ height: pct(day.search) + '%' }"
          ></div>
        </div>
        <span class="editrix-activity-chart__label">{{ shortLabel(day.date) }}</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  days: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
});

const maxTotal = computed(() => {
  const max = Math.max(
    1,
    ...props.days.map((d) => (d.search || 0) + (d.replace || 0))
  );
  return max;
});

const pct = (count) => ((count || 0) / maxTotal.value) * 100;

const shortLabel = (dateStr) => {
  const d = new Date(`${dateStr}T00:00:00`);
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
};
</script>
