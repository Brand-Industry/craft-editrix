<template>
  <Teleport to="body">
    <div v-if="show && result" class="editrix-modal">
      <div class="editrix-modal__backdrop" @click="$emit('close')"></div>
      <div class="editrix-modal__content editrix-modal__content--wide">
        <header class="editrix-modal__header">
          <h2 class="editrix-modal__title">🔍 {{ t('Match Details') }}</h2>
        </header>

        <div class="editrix-modal__body">
          <div class="editrix-modal__info-row">
            <span class="editrix-modal__info-row-label">{{ t('Element') }}</span>
            <span class="editrix-modal__info-row-value">
              {{ result.elementTitle }}
              <span v-if="result.parentTitle"> → {{ result.parentTitle }}</span>
              <span class="editrix-badge editrix-badge--neutral">{{ elementTypeLabel(result.elementType) }}</span>
            </span>
          </div>
          <div class="editrix-modal__info-row">
            <span class="editrix-modal__info-row-label">{{ t('Section') }}</span>
            <span class="editrix-modal__info-row-value">{{ result.sectionName }}</span>
          </div>
          <div class="editrix-modal__info-row">
            <span class="editrix-modal__info-row-label">{{ t('Field') }}</span>
            <span class="editrix-modal__info-row-value">
              {{ result.fieldName }}
              <span v-if="result.readOnly" class="editrix-badge editrix-badge--neutral">
                {{ t('Search only') }}
              </span>
            </span>
          </div>
          <div v-if="result.siteHandle" class="editrix-modal__info-row">
            <span class="editrix-modal__info-row-label">{{ t('Site') }}</span>
            <span class="editrix-modal__info-row-value">{{ result.siteHandle }}</span>
          </div>

          <div class="editrix-modal__context" v-html="highlightedContext"></div>

          <p v-if="result.readOnlyReason === 'tag'" class="editrix-form__warning" style="margin-top: 12px;">
            ℹ️ {{ t('Rename the tag directly - it may be shared by other entries.') }}
          </p>
          <p v-else-if="result.readOnlyReason === 'formatting'" class="editrix-form__warning" style="margin-top: 12px;">
            ℹ️ {{ t('This match spans formatting (like bold or italic) and can\'t be replaced automatically - edit it directly in Craft.') }}
          </p>

          <div v-if="showReplaceField" class="editrix-form__group" style="margin-top: 16px;">
            <label class="editrix-form__label">{{ t('Replace with') }}</label>
            <textarea
              v-model="replaceValue"
              class="editrix-form__textarea"
              :placeholder="t('Enter replacement text...')"
              rows="2"
            ></textarea>
          </div>
        </div>

        <footer class="editrix-modal__footer">
          <a
            v-if="editUrl"
            :href="editUrl"
            target="_blank"
            rel="noopener"
            class="editrix-btn editrix-btn--secondary"
          >
            {{ t('Open in Craft') }} ↗
          </a>

          <template v-if="!result.readOnly">
            <template v-if="showReplaceField">
              <button class="editrix-btn editrix-btn--secondary" @click="cancelReplace">
                {{ t('Cancel') }}
              </button>
              <button
                class="editrix-btn editrix-btn--primary"
                :disabled="!replaceValue"
                @click="$emit('request-replace', { result, replaceWith: replaceValue })"
              >
                {{ t('Continue') }}
              </button>
            </template>
            <button
              v-else
              class="editrix-btn editrix-btn--secondary"
              @click="showReplaceField = true"
            >
              {{ t('Replace') }}
            </button>
          </template>

          <button v-if="!showReplaceField" class="editrix-btn editrix-btn--primary" @click="$emit('close')">
            {{ t('Close') }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, inject, ref, watch } from 'vue';
import { useConfig } from '../../composables/useConfig';
import { elementTypeLabel } from '../../utils/elementType';

const t = inject('t');
const { cpUrl } = useConfig();

const props = defineProps({
  show: { type: Boolean, default: false },
  result: { type: Object, default: null },
});

defineEmits(['close', 'request-replace']);

const showReplaceField = ref(false);
const replaceValue = ref('');

const cancelReplace = () => {
  showReplaceField.value = false;
  replaceValue.value = '';
};

// Don't leak this match's draft replacement text into the next one opened.
watch(
  () => props.show,
  (isOpen) => {
    if (!isOpen) cancelReplace();
  }
);

const editUrl = computed(() => {
  if (!props.result?.cpEditUrl) return '';
  const base = (cpUrl.value || '').replace(/\/+$/, '');
  const path = props.result.cpEditUrl.replace(/^\/+/, '');
  return `${base}/${path}`;
});

const escapeHtml = (text) => {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
};

// Highlight the match in the full field value, not just the short preview
// shown in the results row.
const highlightedContext = computed(() => {
  const r = props.result;
  if (!r) return '';

  const value = r.fieldValue ?? '';
  const hasRange =
    Number.isInteger(r.matchStart) &&
    Number.isInteger(r.matchEnd) &&
    r.matchEnd > r.matchStart &&
    r.matchEnd <= value.length;

  if (!hasRange) {
    return escapeHtml(value);
  }

  const before = escapeHtml(value.slice(0, r.matchStart));
  const match = escapeHtml(value.slice(r.matchStart, r.matchEnd));
  const after = escapeHtml(value.slice(r.matchEnd));

  return `${before}<span class="match">${match}</span>${after}`;
});
</script>
