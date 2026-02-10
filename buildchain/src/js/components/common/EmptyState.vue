<template>
  <div class="editrix-results__empty" style="padding: 80px 24px; text-align: center;">
    <div style="font-size: 64px; margin-bottom: 16px;">🔍</div>
    <h3 style="margin: 0 0 8px; font-weight: 600;">{{ t('Find & Replace') }}</h3>
    <p style="color: #71717a; margin: 0;">
      {{ t('Enter a search term above to find content across your site.') }}
    </p>
  </div>
</template>

<script setup>
import { inject } from 'vue';
const t = inject('t');
</script>
