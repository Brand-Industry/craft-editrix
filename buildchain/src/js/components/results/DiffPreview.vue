<template>
  <Teleport to="body">
    <!-- Backdrop -->
    <div
      class="editrix-backdrop"
      :class="{ 'is-visible': show }"
      @click="$emit('close')"
    ></div>
    
    <!-- Slideout -->
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
        <!-- Original -->
        <div class="editrix-diff__section">
          <div class="editrix-diff__section-header">
            <h4 class="editrix-diff__section-title">⊖ {{ t('ORIGINAL') }}</h4>
            <span class="editrix-diff__badge editrix-diff__badge--remove">-1 deletion</span>
          </div>
          <div class="editrix-diff__text editrix-diff__text--original">
            <span v-html="originalHtml"></span>
          </div>
        </div>
        
        <!-- Arrow -->
        <div class="editrix-diff__arrow">↓</div>
        
        <!-- Proposed -->
        <div class="editrix-diff__section">
          <div class="editrix-diff__section-header">
            <h4 class="editrix-diff__section-title">⊕ {{ t('PROPOSED') }}</h4>
            <span class="editrix-diff__badge editrix-diff__badge--add">+1 addition</span>
          </div>
          <div class="editrix-diff__text editrix-diff__text--proposed">
            <span v-html="proposedHtml"></span>
          </div>
        </div>
        
        <!-- Notice -->
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
  searchQuery: { type: String, default: '' },
  replaceWith: { type: String, default: '' },
});

defineEmits(['close', 'apply', 'skip']);

// Generate diff HTML
const originalHtml = computed(() => {
  if (!props.result?.fieldValue || !props.searchQuery) return '';
  
  const value = props.result.fieldValue;
  const query = props.searchQuery;
  
  // Simple highlight for now - could use diff library for more complex
  const escaped = escapeHtml(value);
  const pattern = new RegExp(`(${escapeRegExp(query)})`, 'gi');
  
  return escaped.replace(pattern, '<span class="diff-remove">$1</span>');
});

const proposedHtml = computed(() => {
  if (!props.result?.fieldValue || !props.searchQuery) return '';
  
  const value = props.result.fieldValue;
  const query = props.searchQuery;
  const replacement = props.replaceWith;
  
  // Replace and highlight
  const newValue = value.replace(new RegExp(escapeRegExp(query), 'gi'), replacement);
  const escaped = escapeHtml(newValue);
  
  if (replacement) {
    const pattern = new RegExp(`(${escapeRegExp(replacement)})`, 'gi');
    return escaped.replace(pattern, '<span class="diff-add">$1</span>');
  }
  
  return escaped;
});

// Helpers
const escapeHtml = (text) => {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
};

const escapeRegExp = (string) => {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
};
</script>
