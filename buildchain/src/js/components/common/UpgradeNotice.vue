<template>
  <div class="editrix-upgrade">
    <div class="editrix-upgrade__title">⚡ {{ t('Upgrade to Standard') }}</div>
    <p class="editrix-upgrade__text">
      {{ t('Unlock Matrix search, regex, multi-site, and more.') }}
    </p>
    <a href="#" class="editrix-upgrade__btn">{{ t('Learn More') }}</a>
  </div>
</template>

<script setup>
import { inject } from 'vue';
const t = inject('t');
</script>
