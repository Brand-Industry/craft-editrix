<template>
  <Teleport to="body">
    <div v-if="show" class="editrix-modal">
      <div class="editrix-modal__backdrop" @click="$emit('cancel')"></div>
      <div class="editrix-modal__content">
        <header class="editrix-modal__header">
          <h2 class="editrix-modal__title">{{ title }}</h2>
        </header>
        <div class="editrix-modal__body">
          <p
            v-for="(line, idx) in messageLines"
            :key="idx"
            :class="{ 'editrix-modal__warning': danger }"
          >{{ line }}</p>
          <p v-if="loading && progressText" class="editrix-modal__progress" role="status" aria-live="polite" style="margin: 12px 0 0; font-size: 13px;">
            {{ progressText }}
          </p>
          <button
            v-if="loading && stoppable"
            type="button"
            class="editrix-btn editrix-btn--secondary editrix-btn--sm"
            style="margin-top: 12px;"
            @click="$emit('stop')"
          >
            Stop revert
          </button>
        </div>
        <footer class="editrix-modal__footer">
          <button
            class="editrix-btn editrix-btn--secondary"
            :disabled="loading"
            @click="$emit('cancel')"
          >
            Cancel
          </button>
          <button
            :class="danger ? 'editrix-btn editrix-btn--danger' : 'editrix-btn editrix-btn--primary'"
            :disabled="loading"
            @click="$emit('confirm')"
          >
            {{ loading ? 'Processing...' : 'Confirm' }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, default: 'Confirm' },
  message: { type: String, default: '' },
  loading: { type: Boolean, default: false },
  progressText: { type: String, default: '' },
  // A revert is running in the queue and can be stopped.
  stoppable: { type: Boolean, default: false },
  danger: { type: Boolean, default: false },
});
defineEmits(['confirm', 'cancel', 'stop']);

const messageLines = computed(() => props.message.split('\n').filter(l => l.trim() !== ''));
</script>
