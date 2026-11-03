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
          <div v-if="busy" class="editrix-diff__notice editrix-diff__notice--warning">
            <span>⏳</span>
            <p>{{ t('A replace is running. You can look at this row, but nothing can be applied until it finishes.') }}</p>
          </div>

          <div v-if="replaced" class="editrix-diff__notice editrix-diff__notice--warning">
            <span>⚠️</span>
            <p>{{ t('Already replaced. This is the text as it is now, so there is nothing to apply.') }}</p>
          </div>

          <div v-if="stale && !replaced" class="editrix-diff__notice editrix-diff__notice--warning">
            <span>⚠️</span>
            <p>{{ t('Its content changed after the search. Search again to see the current text.') }}</p>
          </div>

          <div v-if="!changed && !replaced" class="editrix-diff__notice editrix-diff__notice--warning">
            <span>⚠️</span>
            <p>{{ t("This occurrence won't be changed by this replacement.") }}</p>
          </div>

          <div class="editrix-diff__section">
            <div class="editrix-diff__section-header">
              <h4 class="editrix-diff__section-title">{{ replaced ? t('CURRENT TEXT') : '⊖ ' + t('ORIGINAL') }}</h4>
            </div>
            <div class="editrix-diff__text editrix-diff__text--original">
              <span v-html="replaced ? currentHtml : originalHtml"></span>
            </div>
          </div>

          <template v-if="!replaced">
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
        </template>

        <div v-if="!replaced" class="editrix-diff__notice">
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
          v-if="!replaced"
          class="editrix-btn editrix-btn--primary"
          :disabled="busy || loading || !changed"
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
import { diffText } from '../../utils/textDiff';
import { escapeText } from '../../utils/highlight';

const t = inject('t');

const props = defineProps({
  show: { type: Boolean, default: false },
  result: { type: Object, default: null },
  original: { type: String, default: '' },
  proposed: { type: String, default: '' },
  changed: { type: Boolean, default: false },
  // The field was edited after the search.
  stale: { type: Boolean, default: false },
  // The row was already replaced in this search.
  replaced: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  // A replace is running: nothing can be applied until it ends.
  busy: { type: Boolean, default: false },
});

defineEmits(['close', 'apply', 'skip']);

// The before/after text comes straight from the server - the same
// tag/entity-aware engine that would actually run on Apply - so this only
// shows WHERE the two differ, word by word.
const parts = computed(() => diffText(props.original, props.proposed));

const render = (keep, mark) =>
  parts.value
    .filter(p => p.type === 'same' || p.type === keep)
    .map(p => (p.type === keep ? `<span class="${mark}">${escapeText(p.text)}</span>` : escapeText(p.text)))
    .join('');

const originalHtml = computed(() => render('del', 'diff-remove'));
const proposedHtml = computed(() => render('add', 'diff-add'));
// A replaced row only shows its text as it is now: replacing it again would
// show the new text twice.
const currentHtml = computed(() => escapeText(props.original));
</script>
