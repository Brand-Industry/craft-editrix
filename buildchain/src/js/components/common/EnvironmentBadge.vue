<template>
  <span :class="['editrix-env', `editrix-env--${envClass}`]">
    <span>●</span>
    {{ envLabel }}
  </span>
</template>

<script setup>
import { computed } from 'vue';
import { useConfig } from '../../composables/useConfig';

const { environment } = useConfig();

const envClass = computed(() => {
  const env = environment.value?.toLowerCase() || '';
  if (env.includes('prod')) return 'production';
  if (env.includes('stag')) return 'staging';
  return 'dev';
});

const envLabel = computed(() => {
  return environment.value?.toUpperCase() || 'UNKNOWN';
});
</script>
