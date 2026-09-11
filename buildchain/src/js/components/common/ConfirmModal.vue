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

          <div v-if="requireConfirmationCode" class="editrix-modal__warning" style="margin-top: 12px;">
            <span>🔒</span>
            <div style="width: 100%;">
              <p style="margin: 0 0 4px; font-weight: 600;">{{ t('Confirmation required') }}</p>
              <p v-for="reason in confirmationReasons" :key="reason" style="margin: 0 0 8px; font-size: 13px;">
                {{ reason }}
              </p>
              <p style="margin: 0 0 4px; font-size: 13px;">{{ t('Type "REPLACE" below to confirm.') }}</p>
              <input
                v-model="confirmationText"
                type="text"
                placeholder="REPLACE"
                style="width: 100%; box-sizing: border-box;"
              />
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
            :disabled="loading || !canConfirm"
            @click="$emit('confirm', confirmationText)"
          >
            {{ loading ? t('Replacing...') : t('Confirm Replace') }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { inject, ref, computed, watch } from 'vue';

const t = inject('t');

const props = defineProps({
  show: { type: Boolean, default: false },
  title: { type: String, default: 'Confirm' },
  count: { type: Number, default: 0 },
  loading: { type: Boolean, default: false },
  requireConfirmationCode: { type: Boolean, default: false },
  confirmationReasons: { type: Array, default: () => [] },
});

defineEmits(['confirm', 'cancel']);

const confirmationText = ref('');

// Start blank each time the modal opens, so a stale "REPLACE" from a
// previous confirmation can't linger and silently satisfy this one.
watch(
  () => props.show,
  (isShown) => {
    if (isShown) confirmationText.value = '';
  }
);

const canConfirm = computed(() => {
  if (!props.requireConfirmationCode) return true;
  return confirmationText.value.trim().toUpperCase() === 'REPLACE';
});
</script>
