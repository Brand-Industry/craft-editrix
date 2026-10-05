<template>
  <div class="editrix-search-progress" role="status" aria-live="polite">
    <svg
      class="editrix-search-art"
      :class="`is-${phase}`"
      viewBox="0 0 200 100"
      width="200"
      height="100"
      aria-hidden="true"
    >
      <g class="editrix-search-art__rows">
        <rect
          v-for="i in 5"
          :key="i"
          class="editrix-search-art__row"
          :class="{ 'is-hit': matches > 0 && (i === 2 || i === 4) }"
          x="40"
          :y="8 + (i - 1) * 17"
          width="120"
          height="9"
          rx="4"
        />
      </g>
      <g class="editrix-search-art__lens">
        <circle cx="100" cy="30" r="15" />
        <line x1="111" y1="41" x2="124" y2="54" />
      </g>
      <g class="editrix-search-art__dots">
        <circle cx="84" cy="50" r="5" />
        <circle cx="100" cy="50" r="5" />
        <circle cx="116" cy="50" r="5" />
      </g>
      <path class="editrix-search-art__check" d="M82 52 L96 66 L120 36" />
    </svg>

    <p class="editrix-search-progress__title">
      {{ t('Searching for "{query}"', { query }) }}
    </p>

    <div class="editrix-search-progress__bar">
      <div
        class="editrix-search-progress__fill"
        :class="{ 'is-active': !queueStalled && !jobStalled }"
        :style="{ width: total > 0 ? `${Math.max(1, Math.round(progress * 100))}%` : '4%' }"
      ></div>
    </div>

    <p class="editrix-search-progress__label">{{ phaseText }}</p>

    <p v-if="matches > 0" class="editrix-search-progress__matches">
      {{ t('{count} matches so far', { count: matches.toLocaleString() }) }}
    </p>

    <p v-if="startedAtMs" class="editrix-search-progress__time">
      {{ t('Elapsed {time}', { time: formatElapsed((nowMs - startedAtMs) / 1000) }) }}
      <template v-if="remaining">
        · {{ t('Up to about {time} left', { time: remaining }) }}
      </template>
    </p>

    <p class="editrix-search-progress__tip" :key="tipIndex">{{ t(tips[tipIndex], { limit: (resultLimit || 0).toLocaleString() }) }}</p>

    <p v-if="jobStalled" class="editrix-form__warning">
      ⚠️ {{ t("This search has stopped reporting progress, so the server may have killed it. Cancel it and try a narrower search.") }}
    </p>
    <p v-if="queueStalled" class="editrix-form__warning">
      ⚠️ {{ t("This search is still waiting to start. Craft's queue may not be running on this server. Ask your host or developer to set up a queue worker or cron job, as described in the Editrix README.") }}
    </p>

    <button
      type="button"
      class="editrix-btn editrix-btn--secondary editrix-btn--sm"
      @click="$emit('cancel')"
    >
      {{ t('Cancel search') }}
    </button>
  </div>
</template>

<script setup>
import { computed, inject, onMounted, onUnmounted, ref } from 'vue';
import { useConfig } from '../../composables/useConfig';
import {
  searchPhase,
  formatElapsed,
  formatRemaining,
  estimateRemainingSeconds,
  waitingTips,
} from '../../utils/searchProgress';

const t = inject('t');
const hasFeature = inject('hasFeature');
const { resultLimit } = useConfig();

const props = defineProps({
  query: { type: String, default: '' },
  hasId: { type: Boolean, default: false },
  jobStatus: { type: String, default: null },
  scanned: { type: Number, default: 0 },
  total: { type: Number, default: 0 },
  progress: { type: Number, default: 0 },
  matches: { type: Number, default: 0 },
  startedAtMs: { type: Number, default: null },
  scanSample: { type: Object, default: null },
  queueStalled: { type: Boolean, default: false },
  jobStalled: { type: Boolean, default: false },
});

defineEmits(['cancel']);

const phase = computed(() =>
  searchPhase({
    hasId: props.hasId,
    status: props.jobStatus,
    scanned: props.scanned,
    total: props.total,
  })
);

const phaseText = computed(() => {
  switch (phase.value) {
    case 'sending':
      return t('Sending your search…');
    case 'queued':
      return t('Waiting for the server to pick up your search…');
    case 'counting':
      return t('Counting the entries to search. On a large site this takes a few seconds.');
    case 'finishing':
      return t('Preparing your results…');
    default:
      return t('Scanning {scanned} of {total} entries ({pct}%)…', {
        scanned: props.scanned.toLocaleString(),
        total: props.total.toLocaleString(),
        pct: Math.round(props.progress * 100),
      });
  }
});

// One clock for the elapsed time and the estimate, ticking every second.
const nowMs = ref(Date.now());
const remaining = computed(() => {
  const seconds = estimateRemainingSeconds({
    sample: props.scanSample,
    scanned: props.scanned,
    total: props.total,
    nowMs: nowMs.value,
  });
  return seconds === null ? null : formatRemaining(seconds);
});

const tips = computed(() =>
  waitingTips({ hasScopeFilters: hasFeature('scopeFilters') })
);
const tipIndex = ref(0);

let clock = null;
let tipTimer = null;

onMounted(() => {
  clock = setInterval(() => (nowMs.value = Date.now()), 1000);
  tipTimer = setInterval(() => {
    tipIndex.value = (tipIndex.value + 1) % tips.value.length;
  }, 8000);
});

onUnmounted(() => {
  clearInterval(clock);
  clearInterval(tipTimer);
});
</script>
