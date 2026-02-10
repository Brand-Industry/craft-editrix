<template>
  <div class="editrix-sidebar">
    <div class="editrix-sidebar__section">
      <div class="editrix-sidebar__title">{{ t('SCOPE FILTERS') }}</div>
      
      <!-- Sections -->
      <div class="editrix-scope__group">
        <div class="editrix-scope__header" @click="toggleSection('sections')">
          <span>📁 {{ t('Sections') }}</span>
          <span class="editrix-scope__toggle" :class="{ 'is-open': openSections.sections }">▶</span>
        </div>
        <div v-show="openSections.sections" class="editrix-scope__content">
          <div v-for="section in availableSections" :key="section.handle" class="editrix-scope__item">
            <input
              type="checkbox"
              :id="`section-${section.handle}`"
              :checked="sections.includes(section.handle)"
              @change="toggleItem('sections', section.handle)"
            />
            <label :for="`section-${section.handle}`">{{ section.name }}</label>
          </div>
          <div v-if="availableSections.length === 0" style="color: #a1a1aa; font-size: 13px; padding: 8px 0;">
            {{ t('Loading...') }}
          </div>
        </div>
      </div>
      
      <!-- Sites -->
      <div v-if="availableSites.length > 1" class="editrix-scope__group">
        <div class="editrix-scope__header" @click="toggleSection('sites')">
          <span>🌐 {{ t('Sites') }}</span>
          <span class="editrix-scope__toggle" :class="{ 'is-open': openSections.sites }">▶</span>
        </div>
        <div v-show="openSections.sites" class="editrix-scope__content">
          <div v-for="site in availableSites" :key="site.handle" class="editrix-scope__item">
            <input
              type="checkbox"
              :id="`site-${site.handle}`"
              :checked="sites.includes(site.id)"
              @change="toggleItem('sites', site.id)"
            />
            <label :for="`site-${site.handle}`">{{ site.name }}</label>
          </div>
        </div>
      </div>
      
      <!-- Fields (Pro) -->
      <div v-if="hasFeature('scopeFilters.full')" class="editrix-scope__group">
        <div class="editrix-scope__header" @click="toggleSection('fields')">
          <span>📝 {{ t('Fields') }}</span>
          <span class="editrix-scope__toggle" :class="{ 'is-open': openSections.fields }">▶</span>
        </div>
        <div v-show="openSections.fields" class="editrix-scope__content">
          <div v-for="field in availableFields" :key="field.handle" class="editrix-scope__item">
            <input
              type="checkbox"
              :id="`field-${field.handle}`"
              :checked="fields.includes(field.handle)"
              @change="toggleItem('fields', field.handle)"
            />
            <label :for="`field-${field.handle}`">{{ field.name }}</label>
          </div>
          <div v-if="availableFields.length === 0" style="color: #a1a1aa; font-size: 13px; padding: 8px 0;">
            {{ t('Loading...') }}
          </div>
        </div>
      </div>
      
      <!-- Entry Types (Pro) -->
      <div v-if="hasFeature('scopeFilters.full')" class="editrix-scope__group">
        <div class="editrix-scope__header" @click="toggleSection('entryTypes')">
          <span>🏷️ {{ t('Entry Types') }}</span>
          <span class="editrix-scope__toggle" :class="{ 'is-open': openSections.entryTypes }">▶</span>
        </div>
        <div v-show="openSections.entryTypes" class="editrix-scope__content">
          <div v-for="type in availableEntryTypes" :key="type.handle" class="editrix-scope__item">
            <input
              type="checkbox"
              :id="`type-${type.handle}`"
              :checked="entryTypes.includes(type.handle)"
              @change="toggleItem('entryTypes', type.handle)"
            />
            <label :for="`type-${type.handle}`">{{ type.name }}</label>
          </div>
          <div v-if="availableEntryTypes.length === 0" style="color: #a1a1aa; font-size: 13px; padding: 8px 0;">
            {{ t('Loading...') }}
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, inject } from 'vue';
import { useConfig } from '../../composables/useConfig';
import { useApi } from '../../composables/useApi';

const t = inject('t');
const hasFeature = inject('hasFeature');
const { sites: configSites, scopeUrls } = useConfig();
const { get } = useApi();

const props = defineProps({
  sections: { type: Array, default: () => [] },
  sites: { type: Array, default: () => [] },
  fields: { type: Array, default: () => [] },
  entryTypes: { type: Array, default: () => [] },
});

const emit = defineEmits([
  'update:sections',
  'update:sites',
  'update:fields',
  'update:entryTypes',
]);

// UI State
const openSections = reactive({
  sections: true,
  sites: false,
  fields: false,
  entryTypes: false,
});

// Available options
const availableSections = ref([]);
const availableSites = ref([]);
const availableFields = ref([]);
const availableEntryTypes = ref([]);

// Load data
onMounted(async () => {
  availableSites.value = configSites.value;
  
  try {
    if (scopeUrls.value.sections) {
      const data = await get(scopeUrls.value.sections);
      availableSections.value = data.sections || [];
    }
    
    if (hasFeature('scopeFilters.full')) {
      if (scopeUrls.value.fields) {
        const data = await get(scopeUrls.value.fields);
        availableFields.value = data.fields || [];
      }
      
      if (scopeUrls.value.entryTypes) {
        const data = await get(scopeUrls.value.entryTypes);
        availableEntryTypes.value = data.entryTypes || [];
      }
    }
  } catch (err) {
    console.error('Failed to load scope data:', err);
  }
});

// Toggle section visibility
const toggleSection = (section) => {
  openSections[section] = !openSections[section];
};

// Toggle item selection
const toggleItem = (type, value) => {
  const current = props[type];
  const index = current.indexOf(value);
  
  let newValue;
  if (index === -1) {
    newValue = [...current, value];
  } else {
    newValue = current.filter(v => v !== value);
  }
  
  emit(`update:${type}`, newValue);
};
</script>
