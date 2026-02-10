<template>
  <div class="editrix-loading">
    <div class="editrix-loading__spinner"></div>
    <p class="editrix-loading__text">{{ text }}</p>
  </div>
</template>

<script setup>
defineProps({
  text: { type: String, default: 'Loading...' }
});
</script>
