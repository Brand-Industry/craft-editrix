<template>
  <div class="editrix-form">
    <div class="editrix-form__row editrix-form__row--single">
      <div class="editrix-form__group">
        <label class="editrix-form__label">
          {{ t('Find') }}
          <span class="editrix-form__label--hint">
            {{ type === 'category' ? t('CATEGORY NAME') : t('TAG NAME') }}
          </span>
        </label>
        <input
          type="text"
          :value="query"
          class="editrix-form__input"
          :placeholder="type === 'category' ? t('Enter a category name...') : t('Enter a tag name...')"
          @input="$emit('update:query', $event.target.value)"
          @keyup.enter="query && $emit('search')"
        />
      </div>
    </div>

    <div v-if="availableSections.length > 0" class="editrix-form__group" style="margin-top: 16px;">
      <label class="editrix-form__label">{{ t('Limit to sections (optional)') }}</label>

      <div class="editrix-assignment-scope">
        <div class="editrix-assignment-scope__header">
          <input
            v-model="sectionFilter"
            type="text"
            :placeholder="t('Filter sections')"
          />
          <span class="editrix-assignment-scope__count">
            {{ t('{count} selected', { count: sections.length }) }}
          </span>
        </div>

        <div class="editrix-assignment-scope__list">
          <label
            v-for="section in filteredSections"
            :key="section.handle"
            class="editrix-assignment-scope__option"
          >
            <input
              type="checkbox"
              :checked="sections.includes(section.handle)"
              @change="toggleSection(section.handle)"
            />
            <span>{{ section.name }}</span>
          </label>

          <p v-if="filteredSections.length === 0" class="editrix-assignment-scope__empty">
            {{ t('No sections match your filter.') }}
          </p>
        </div>

        <div class="editrix-assignment-scope__footer">
          <a @click="selectAllSections">{{ t('Select All') }}</a>
          <a class="editrix-assignment-scope__footer-link--muted" @click="clearSections">{{ t('Clear') }}</a>
        </div>
      </div>
    </div>

    <div class="editrix-form__footer">
      <div></div>
      <button
        class="editrix-btn editrix-btn--primary editrix-btn--lg"
        :disabled="!query || loading"
        @click="$emit('search')"
      >
        <span v-if="loading">{{ t('Searching...') }}</span>
        <span v-else>{{ t('Search') }}</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, inject, onMounted, ref, watch } from 'vue';
import { useConfig } from '../../composables/useConfig';
import { useApi } from '../../composables/useApi';

const t = inject('t');
const { assignmentUrls } = useConfig();
const { get } = useApi();

const props = defineProps({
  query: { type: String, default: '' },
  sections: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  type: { type: String, default: 'category' }, // 'category' | 'tag'
});

const emit = defineEmits(['update:query', 'update:sections', 'search']);

const availableSections = ref([]);
const sectionFilter = ref('');

const filteredSections = computed(() => {
  const term = sectionFilter.value.trim().toLowerCase();
  if (!term) return availableSections.value;
  return availableSections.value.filter((section) =>
    section.name.toLowerCase().includes(term)
  );
});

const toggleSection = (handle) => {
  const current = props.sections;
  const next = current.includes(handle)
    ? current.filter((h) => h !== handle)
    : [...current, handle];
  emit('update:sections', next);
};

const selectAllSections = () => {
  emit('update:sections', filteredSections.value.map((section) => section.handle));
};

const clearSections = () => {
  emit('update:sections', []);
};

const loadSections = async () => {
  sectionFilter.value = '';
  if (!assignmentUrls.value.sections) return;
  try {
    const data = await get(assignmentUrls.value.sections, { type: props.type });
    availableSections.value = data.sections || [];
  } catch (err) {
    console.error('Failed to load sections:', err);
  }
};

// This form stays mounted when switching between Category and Tag search
// (only its `type` prop changes), so the section list has to be re-fetched
// on that change too, not just once at mount.
onMounted(loadSections);
watch(() => props.type, loadSections);
</script>
