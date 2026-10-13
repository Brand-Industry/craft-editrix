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

          <p v-if="valueLoading" class="editrix-modal__context">{{ t('Loading...') }}</p>
          <ol v-else-if="valueError" class="editrix-modal__context editrix-results__matches">
            <li v-for="(m, i) in result.matches" :key="i" v-html="formatContext(m.context)"></li>
          </ol>
          <div v-else class="editrix-modal__context" v-html="highlightedContext"></div>

          <p v-if="valueStale" class="editrix-form__warning" style="margin-top: 12px;">
            ⚠️ {{ t('Its content changed after the search. Search again to see the current text.') }}
          </p>

          <p v-if="result.readOnlyReason === 'tag'" class="editrix-form__warning" style="margin-top: 12px;">
            ℹ️ {{ t('Rename the tag directly - it may be shared by other entries.') }}
          </p>
          <p v-else-if="result.readOnlyReason === 'protected'" class="editrix-form__warning" style="margin-top: 12px;">
            ℹ️ {{ t('This field or section is protected in Editrix settings and can\'t be replaced.') }}
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
import { useApi } from '../../composables/useApi';
import { fieldValueParams } from '../../utils/fieldValue';
import { elementTypeLabel } from '../../utils/elementType';
import { highlightMatches, contextToHtml } from '../../utils/highlight';

const t = inject('t');
const { cpUrl, fieldValueUrl } = useConfig();
const { get } = useApi();

const props = defineProps({
  show: { type: Boolean, default: false },
  result: { type: Object, default: null },
});

defineEmits(['close', 'request-replace']);

// The row holds a short context, not the field's text; the whole value is
// read from the server when the modal opens. Tag rows and searches saved
// before this change still carry their own value.
const fullValue = ref(null);
const valueLoading = ref(false);
const valueError = ref(false);
const valueStale = ref(false);

watch(
  () => [props.show, props.result?.uniqueKey],
  async ([isOpen]) => {
    fullValue.value = null;
    valueError.value = false;
    valueStale.value = false;
    valueLoading.value = false;

    const r = props.result;
    if (!isOpen || !r) return;

    if (typeof r.fieldValue === 'string') {
      fullValue.value = r.fieldValue;
      return;
    }

    const key = r.uniqueKey;
    valueLoading.value = true;
    try {
      const data = await get(fieldValueUrl.value, fieldValueParams(r));
      if (props.result?.uniqueKey !== key) return;
      if (data.success) {
        fullValue.value = data.value;
        valueStale.value = !!data.stale;
      } else {
        valueError.value = true;
      }
    } catch (err) {
      if (props.result?.uniqueKey === key) valueError.value = true;
    } finally {
      if (props.result?.uniqueKey === key) valueLoading.value = false;
    }
  },
  { immediate: true }
);

const formatContext = contextToHtml;

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

  const value = fullValue.value ?? '';

  // Positions belong to the text the search saw; if it changed, they would
  // highlight the wrong words.
  if (valueStale.value) return escapeHtml(value);

  // A row stands for a whole field, so every match in it is highlighted.
  return highlightMatches(
    value,
    r.matches?.length ? r.matches : [{ start: r.matchStart, end: r.matchEnd }],
    escapeHtml
  );
});
</script>
