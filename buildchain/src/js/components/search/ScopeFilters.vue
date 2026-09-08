<template>
  <div class="editrix-sidebar">
    <div class="editrix-sidebar__section">
      <div class="editrix-sidebar__title">{{ t('SCOPE FILTERS') }}</div>
      <p class="editrix-scope__hint">{{ t('Section, then Entry Type, then Fields.') }}</p>

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
          <div v-if="availableSections.length === 0" class="editrix-scope__note">
            {{ t('Loading...') }}
          </div>
        </div>
      </div>

      <template v-if="hasFeature('scopeFilters.full')">
        <div v-if="sections.length === 0" class="editrix-scope__group editrix-scope__group--locked">
          <div class="editrix-scope__header">
            <span>🏷️ {{ t('Entry Types') }}</span>
          </div>
          <p class="editrix-scope__note">{{ t('Select a section first.') }}</p>
        </div>

        <div v-else class="editrix-scope__group">
          <div class="editrix-scope__header" @click="toggleSection('entryTypes')">
            <span>🏷️ {{ t('Entry Types') }}</span>
            <span class="editrix-scope__toggle" :class="{ 'is-open': openSections.entryTypes }">▶</span>
          </div>
          <div v-show="openSections.entryTypes" class="editrix-scope__content">
            <div v-if="loadingEntryTypes" class="editrix-scope__note">{{ t('Loading...') }}</div>
            <template v-else-if="availableEntryTypes.length === 1">
              <div class="editrix-scope__note">
                {{ availableEntryTypes[0].name }} <span class="editrix-badge editrix-badge--neutral">{{ t('Only option') }}</span>
              </div>
            </template>
            <template v-else>
              <div v-for="type in availableEntryTypes" :key="type.handle" class="editrix-scope__item">
                <input
                  type="checkbox"
                  :id="`type-${type.handle}`"
                  :checked="entryTypes.includes(type.handle)"
                  @change="toggleItem('entryTypes', type.handle)"
                />
                <label :for="`type-${type.handle}`">{{ type.name }}</label>
              </div>
              <div v-if="availableEntryTypes.length === 0" class="editrix-scope__note">
                {{ t('No entry types in the selected section(s).') }}
              </div>
            </template>
          </div>
        </div>

        <div v-if="entryTypes.length === 0" class="editrix-scope__group editrix-scope__group--locked">
          <div class="editrix-scope__header">
            <span>📝 {{ t('Fields') }}</span>
          </div>
          <p class="editrix-scope__note">{{ t('Select an entry type first.') }}</p>
        </div>

        <div v-else class="editrix-scope__group">
          <div class="editrix-scope__header" @click="toggleSection('fields')">
            <span>📝 {{ t('Fields') }}</span>
            <span class="editrix-scope__toggle" :class="{ 'is-open': openSections.fields }">▶</span>
          </div>
          <div v-show="openSections.fields" class="editrix-scope__content">
            <div v-if="loadingFields" class="editrix-scope__note">{{ t('Loading...') }}</div>
            <template v-else>
              <div v-for="field in visibleFields" :key="field.handle" class="editrix-scope__item">
                <input
                  type="checkbox"
                  :id="`field-${field.handle}`"
                  :checked="fields.includes(field.handle)"
                  @change="toggleItem('fields', field.handle)"
                />
                <label :for="`field-${field.handle}`">{{ field.name }}</label>
                <span v-if="field.readOnly" class="editrix-badge editrix-badge--neutral" :title="t('Rename the tag directly - it may be shared by other entries.')">
                  {{ t('Search only') }}
                </span>
              </div>
              <div v-if="visibleFields.length === 0" class="editrix-scope__note">
                {{ t('No searchable fields on the selected entry type(s).') }}
              </div>
            </template>
          </div>
        </div>
      </template>

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
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch, inject } from 'vue';
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
  actionMode: { type: String, default: 'replace' },
});

const emit = defineEmits([
  'update:sections',
  'update:sites',
  'update:fields',
  'update:entryTypes',
]);

const openSections = reactive({
  sections: true,
  sites: false,
  fields: true,
  entryTypes: true,
});

const availableSections = ref([]);
const availableSites = ref([]);
const availableFields = ref([]);
const availableEntryTypes = ref([]);
const loadingEntryTypes = ref(false);
const loadingFields = ref(false);

// Section/Entry Type handles are unique per install, so these lookups stay
// valid across refetches without needing to track ids on the selection itself.
const sectionHandleToId = computed(() =>
  Object.fromEntries(availableSections.value.map(s => [s.handle, s.id]))
);
const entryTypeHandleToId = computed(() =>
  Object.fromEntries(availableEntryTypes.value.map(t => [t.handle, t.id]))
);

// Search & Replace only offers fields it can actually write back to - a
// Tags match (readOnly) only ever shows up in the read-only Search mode.
const visibleFields = computed(() =>
  props.actionMode === 'replace'
    ? availableFields.value.filter(f => !f.readOnly)
    : availableFields.value
);

onMounted(async () => {
  availableSites.value = configSites.value;

  try {
    if (scopeUrls.value.sections) {
      const data = await get(scopeUrls.value.sections);
      availableSections.value = data.sections || [];

      if (availableSections.value.length === 1 && props.sections.length === 0) {
        // Only one section on the whole site - nothing to choose, move on.
        emit('update:sections', [availableSections.value[0].handle]);
      } else if (props.sections.length > 0) {
        // Scope arrived pre-filled (e.g. re-running a past search from
        // History) - the sections list just loaded, so kick off the same
        // cascade the watchers below run on a later change.
        await syncEntryTypesForSections(props.sections);
        if (props.entryTypes.length > 0) {
          await syncFieldsForEntryTypes(props.entryTypes);
        }
      }
    }
  } catch (err) {
    console.error('Failed to load sections:', err);
  }
});

const fetchEntryTypes = async (sectionHandles) => {
  if (!scopeUrls.value.entryTypes) return;

  loadingEntryTypes.value = true;
  try {
    const sectionId = sectionHandles
      .map(handle => sectionHandleToId.value[handle])
      .filter(Boolean);
    const data = await get(scopeUrls.value.entryTypes, { sectionId });
    availableEntryTypes.value = data.entryTypes || [];
  } catch (err) {
    console.error('Failed to load entry types:', err);
    availableEntryTypes.value = [];
  } finally {
    loadingEntryTypes.value = false;
  }
};

const fetchFields = async (entryTypeHandles) => {
  if (!scopeUrls.value.fields) return;

  loadingFields.value = true;
  try {
    const entryTypeId = entryTypeHandles
      .map(handle => entryTypeHandleToId.value[handle])
      .filter(Boolean);
    const data = await get(scopeUrls.value.fields, { entryTypeId });
    availableFields.value = data.fields || [];
  } catch (err) {
    console.error('Failed to load fields:', err);
    availableFields.value = [];
  } finally {
    loadingFields.value = false;
  }
};

// Sections -> Entry Types: refetch scoped entry types, drop stale selections,
// and auto-select when a section only exposes a single entry type.
const syncEntryTypesForSections = async (sectionHandles) => {
  if (!hasFeature('scopeFilters.full')) return;

  if (sectionHandles.length === 0) {
    availableEntryTypes.value = [];
    if (props.entryTypes.length > 0) emit('update:entryTypes', []);
    return;
  }

  await fetchEntryTypes(sectionHandles);

  const validHandles = new Set(availableEntryTypes.value.map(t => t.handle));
  const stillValid = props.entryTypes.filter(h => validHandles.has(h));

  if (availableEntryTypes.value.length === 1) {
    const onlyHandle = availableEntryTypes.value[0].handle;
    if (stillValid.length !== 1 || stillValid[0] !== onlyHandle) {
      emit('update:entryTypes', [onlyHandle]);
    }
  } else if (stillValid.length !== props.entryTypes.length) {
    emit('update:entryTypes', stillValid);
  }
};

watch(() => props.sections, syncEntryTypesForSections);

// Entry Types -> Fields: refetch scoped fields and drop stale selections.
const syncFieldsForEntryTypes = async (entryTypeHandles) => {
  if (!hasFeature('scopeFilters.full')) return;

  if (entryTypeHandles.length === 0) {
    availableFields.value = [];
    if (props.fields.length > 0) emit('update:fields', []);
    return;
  }

  await fetchFields(entryTypeHandles);

  const validHandles = new Set(visibleFields.value.map(f => f.handle));
  const stillValid = props.fields.filter(h => validHandles.has(h));
  if (stillValid.length !== props.fields.length) {
    emit('update:fields', stillValid);
  }
};

watch(() => props.entryTypes, syncFieldsForEntryTypes);

// Switching to Search & Replace can make the current Fields selection
// invalid (e.g. a Tags field that's only offered in Search mode).
watch(
  () => props.actionMode,
  () => {
    const validHandles = new Set(visibleFields.value.map(f => f.handle));
    const stillValid = props.fields.filter(h => validHandles.has(h));
    if (stillValid.length !== props.fields.length) {
      emit('update:fields', stillValid);
    }
  }
);

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
