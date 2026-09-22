<template>
  <span
    class="editrix-badge"
    :class="isPro ? 'editrix-badge--info' : 'editrix-badge--neutral'"
  >
    {{ label }}
  </span>
</template>

<script setup>
import { computed } from 'vue';
import { useConfig } from '../../composables/useConfig';

const { edition } = useConfig();

const isPro = computed(() => edition.value === 'pro');
const label = computed(() => (isPro.value ? 'Pro' : 'Standard'));
</script>
