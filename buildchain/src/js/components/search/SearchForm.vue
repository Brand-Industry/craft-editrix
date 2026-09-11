<template>
  <div class="editrix-form">
    <div class="editrix-form__row" :class="{ 'editrix-form__row--single': actionMode !== 'replace' }">
      <div class="editrix-form__group">
        <label class="editrix-form__label">
          {{ t('Find') }}
          <span class="editrix-form__label--hint">{{ t('INPUT STRING') }}</span>
        </label>
        <textarea
          :value="query"
          class="editrix-form__textarea"
          :placeholder="t('Enter text to search...')"
          rows="3"
          @input="$emit('update:query', $event.target.value)"
        ></textarea>
      </div>

      <div v-if="actionMode === 'replace'" class="editrix-form__group">
        <label class="editrix-form__label">
          {{ t('Replace with') }}
          <span class="editrix-form__label--hint">{{ t('OUTPUT STRING') }}</span>
        </label>
        <textarea
          :value="replaceWith"
          class="editrix-form__textarea"
          :placeholder="t('Enter replacement text...')"
          rows="3"
          @input="$emit('update:replaceWith', $event.target.value)"
        ></textarea>
      </div>
    </div>

    <div class="editrix-form__options">
      <label class="editrix-form__checkbox">
        <input
          type="checkbox"
          :checked="useRegex"
          :disabled="!hasFeature('regex')"
          @change="$emit('update:useRegex', $event.target.checked)"
        />
        <span>{{ t('Regex') }}</span>
        <span v-if="!hasFeature('regex')" class="editrix-badge editrix-badge--info">Pro</span>
      </label>

      <label class="editrix-form__checkbox">
        <input
          type="checkbox"
          :checked="caseInsensitive"
          @change="$emit('update:caseInsensitive', $event.target.checked)"
        />
        <span>{{ t('Case Insensitive') }}</span>
      </label>

      <label class="editrix-form__checkbox">
        <input
          type="checkbox"
          :checked="wholeWords"
          :disabled="!hasFeature('wholeWords')"
          @change="$emit('update:wholeWords', $event.target.checked)"
        />
        <span>{{ t('Whole Words') }}</span>
        <span v-if="!hasFeature('wholeWords')" class="editrix-badge editrix-badge--info">Standard</span>
      </label>

      <label v-if="hasFeature('dryRun')" class="editrix-toggle">
        <input
          type="checkbox"
          :checked="dryRun"
          @change="$emit('update:dryRun', $event.target.checked)"
        />
        <span class="editrix-toggle__track"></span>
        <span>{{ t('Dry Run Mode') }}</span>
      </label>
    </div>

    <div v-if="showScopeInline" class="editrix-form__options" style="margin-top: 16px;">
      <label class="editrix-form__checkbox">
        <input
          type="checkbox"
          :checked="searchEntries"
          @change="$emit('update:searchEntries', $event.target.checked)"
        />
        <span>{{ t('Entries') }}</span>
      </label>

      <label class="editrix-form__checkbox">
        <input
          type="checkbox"
          :checked="searchGlobals"
          @change="$emit('update:searchGlobals', $event.target.checked)"
        />
        <span>{{ t('Globals') }}</span>
      </label>

      <label class="editrix-form__checkbox">
        <input
          type="checkbox"
          :checked="searchMatrix"
          :disabled="!hasFeature('search.matrix')"
          @change="$emit('update:searchMatrix', $event.target.checked)"
        />
        <span>{{ t('Matrix fields') }}</span>
        <span v-if="!hasFeature('search.matrix')" class="editrix-badge editrix-badge--info">Pro</span>
      </label>
    </div>

    <div class="editrix-form__footer">
      <div v-if="actionMode === 'replace' && query" class="editrix-form__warning">
        ⚠️ {{ t('Changes will be applied across your database.') }}
      </div>
      <div v-else class="editrix-form__warning"></div>

      <button
        class="editrix-btn editrix-btn--primary editrix-btn--lg"
        :disabled="!query || loading"
        @click="$emit('search')"
      >
        <span v-if="loading">{{ t('Searching...') }}</span>
        <span v-else-if="actionMode === 'replace'">{{ t('Preview Changes') }}</span>
        <span v-else>{{ t('Search') }}</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { inject } from 'vue';

const t = inject('t');
const hasFeature = inject('hasFeature');

defineProps({
  query: { type: String, default: '' },
  replaceWith: { type: String, default: '' },
  siteId: { type: Number, default: null },
  allSites: { type: Boolean, default: false },
  useRegex: { type: Boolean, default: false },
  caseInsensitive: { type: Boolean, default: false },
  wholeWords: { type: Boolean, default: false },
  dryRun: { type: Boolean, default: false },
  searchEntries: { type: Boolean, default: true },
  searchGlobals: { type: Boolean, default: true },
  searchMatrix: { type: Boolean, default: true },
  searchCategories: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  showScopeInline: { type: Boolean, default: false },
  actionMode: { type: String, default: 'replace' },
});

defineEmits([
  'update:query',
  'update:replaceWith',
  'update:siteId',
  'update:allSites',
  'update:useRegex',
  'update:caseInsensitive',
  'update:wholeWords',
  'update:dryRun',
  'update:searchEntries',
  'update:searchGlobals',
  'update:searchMatrix',
  'update:searchCategories',
  'search',
]);
</script>
