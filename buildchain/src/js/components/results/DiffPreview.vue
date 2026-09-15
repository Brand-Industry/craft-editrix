<template>
  <Teleport to="body">
    <div
      class="editrix-backdrop"
      :class="{ 'is-visible': show }"
      @click="$emit('close')"
    ></div>

    <div class="editrix-diff" :class="{ 'is-open': show }">
      <header class="editrix-diff__header">
        <div>
          <h3 class="editrix-diff__title">👁️ {{ t('Visual Diff Preview') }}</h3>
          <p class="editrix-diff__subtitle">
            {{ result?.elementTitle }} • {{ result?.fieldName }}
          </p>
        </div>
        <button class="editrix-diff__close" @click="$emit('close')">✕</button>
      </header>

      <div class="editrix-diff__content">
        <div v-if="loading" class="editrix-diff__loading">{{ t('Loading...') }}</div>

        <template v-else>
          <div v-if="!changed" class="editrix-diff__notice editrix-diff__notice--warning">
            <span>⚠️</span>
            <p>{{ t("This occurrence won't be changed by this replacement.") }}</p>
          </div>

          <div class="editrix-diff__section">
            <div class="editrix-diff__section-header">
              <h4 class="editrix-diff__section-title">⊖ {{ t('ORIGINAL') }}</h4>
            </div>
            <div class="editrix-diff__text editrix-diff__text--original">
              <span v-html="originalHtml"></span>
            </div>
          </div>

          <div class="editrix-diff__arrow">↓</div>

          <div class="editrix-diff__section">
            <div class="editrix-diff__section-header">
              <h4 class="editrix-diff__section-title">⊕ {{ t('PROPOSED') }}</h4>
            </div>
            <div class="editrix-diff__text editrix-diff__text--proposed">
              <span v-html="proposedHtml"></span>
            </div>
          </div>
        </template>

        <div class="editrix-diff__notice">
          <span>ℹ️</span>
          <p>
            <strong>{{ t('Individual Update') }}</strong><br>
            {{ t('Applying this will only update the') }} "{{ result?.fieldName }}"
            {{ t('on the') }} "{{ result?.elementTitle }}" {{ t('page.') }}
          </p>
        </div>
      </div>

      <footer class="editrix-diff__footer">
        <button
          class="editrix-btn editrix-btn--secondary"
          @click="$emit('skip')"
        >
          {{ t('Skip') }}
        </button>
        <button
          class="editrix-btn editrix-btn--primary"
          :disabled="loading || !changed"
          @click="$emit('apply', result)"
        >
          ✓ {{ t('Apply to this element') }}
        </button>
      </footer>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, inject } from 'vue';

const t = inject('t');

const props = defineProps({
  show: { type: Boolean, default: false },
  result: { type: Object, default: null },
  original: { type: String, default: '' },
  proposed: { type: String, default: '' },
  changed: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});

defineEmits(['close', 'apply', 'skip']);

// The before/after text comes straight from the server - the same
// tag/entity-aware engine that would actually run on Apply - so the
// highlight here just needs to show WHERE the two differ, via their
// common prefix/suffix, rather than re-deriving the change itself.
const diff = computed(() => {
  const oldStr = props.original;
  const newStr = props.proposed;

  let prefixLen = 0;
  const maxPrefix = Math.min(oldStr.length, newStr.length);
  while (prefixLen < maxPrefix && oldStr[prefixLen] === newStr[prefixLen]) {
    prefixLen++;
  }

  let suffixLen = 0;
  const maxSuffix = maxPrefix - prefixLen;
  while (
    suffixLen < maxSuffix &&
    oldStr[oldStr.length - 1 - suffixLen] === newStr[newStr.length - 1 - suffixLen]
  ) {
    suffixLen++;
  }

  return {
    prefix: oldStr.slice(0, prefixLen),
    oldMiddle: oldStr.slice(prefixLen, oldStr.length - suffixLen),
    newMiddle: newStr.slice(prefixLen, newStr.length - suffixLen),
    oldSuffix: oldStr.slice(oldStr.length - suffixLen),
    newSuffix: newStr.slice(newStr.length - suffixLen),
  };
});

const originalHtml = computed(() => {
  const d = diff.value;
  const middle = d.oldMiddle
    ? `<span class="diff-remove">${escapeHtml(d.oldMiddle)}</span>`
    : '';
  return escapeHtml(d.prefix) + middle + escapeHtml(d.oldSuffix);
});

const proposedHtml = computed(() => {
  const d = diff.value;
  const middle = d.newMiddle
    ? `<span class="diff-add">${escapeHtml(d.newMiddle)}</span>`
    : '';
  return escapeHtml(d.prefix) + middle + escapeHtml(d.newSuffix);
});

const escapeHtml = (text) => {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
};
</script>
