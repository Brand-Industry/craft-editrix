<template>
  <Teleport to="body">
    <div v-if="show" class="editrix-modal">
      <div class="editrix-modal__backdrop" @click="$emit('cancel')"></div>
      <div class="editrix-modal__content">
        <header class="editrix-modal__header">
          <h2 class="editrix-modal__title">{{ title }}</h2>
        </header>
        
        <div class="editrix-modal__body">
          <div class="editrix-modal__warning">
            <span>⚠️</span>
            <div>
              <p style="margin: 0 0 8px;">
                {{ t('You are about to replace') }} <strong>{{ count }}</strong> {{ t('occurrence(s).') }}
              </p>
              <p style="margin: 0; font-size: 13px;">
                {{ t('This action can be reverted from the History within 30 days.') }}
              </p>
            </div>
          </div>
        </div>
        
        <footer class="editrix-modal__footer">
          <button
            class="editrix-btn editrix-btn--secondary"
            :disabled="loading"
            @click="$emit('cancel')"
          >
            {{ t('Cancel') }}
          </button>
          <button
            class="editrix-btn editrix-btn--primary"
            :disabled="loading"
            @click="$emit('confirm')"
          >
            {{ loading ? t('Replacing...') : t('Confirm Replace') }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { inject } from 'vue';

const t = inject('t');

defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, default: 'Confirm' },
  count: { type: Number, default: 0 },
  loading: { type: Boolean, default: false },
});

defineEmits(['confirm', 'cancel']);
</script>
