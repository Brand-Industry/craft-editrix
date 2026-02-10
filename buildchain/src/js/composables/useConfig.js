import { ref, computed, readonly } from 'vue';

// Global config from PHP
const config = ref(window.EditrixConfig || {});

export function useConfig() {
  const sites = computed(() => config.value.sites || []);
  const currentSiteId = computed(() => config.value.currentSiteId || 1);
  const apiUrl = computed(() => config.value.apiUrl || '');
  const replaceUrl = computed(() => config.value.replaceUrl || '');
  const previewUrl = computed(() => config.value.previewUrl || '');
  const cpUrl = computed(() => config.value.cpUrl || '');
  const scopeUrls = computed(() => config.value.scopeUrls || {});
  
  // Permissions
  const canReplace = computed(() => config.value.canReplace || false);
  const canExport = computed(() => config.value.canExport || false);
  
  // License
  const license = computed(() => config.value.license || {});
  const edition = computed(() => license.value.edition || 'lite');
  const features = computed(() => license.value.features || {});
  const limits = computed(() => license.value.limits || {});
  const remainingOperations = computed(() => config.value.remainingOperations ?? -1);
  
  // Environment
  const environment = computed(() => config.value.environment || 'production');
  const isProduction = computed(() => config.value.isProduction || false);
  
  // Translations
  const translations = computed(() => config.value.translations || {});
  
  // Translation function
  const t = (key, params = {}) => {
    let text = translations.value[key] || key;
    
    // Replace placeholders
    Object.entries(params).forEach(([k, v]) => {
      text = text.replace(`{${k}}`, v);
    });
    
    return text;
  };
  
  // Feature check
  const hasFeature = (feature) => {
    return features.value[feature] || false;
  };
  
  // Edition check
  const isEdition = (ed) => {
    const order = { lite: 0, standard: 1, pro: 2 };
    return (order[edition.value] || 0) >= (order[ed] || 0);
  };
  
  return {
    config: readonly(config),
    sites,
    currentSiteId,
    apiUrl,
    replaceUrl,
    previewUrl,
    cpUrl,
    scopeUrls,
    canReplace,
    canExport,
    license,
    edition,
    features,
    limits,
    remainingOperations,
    environment,
    isProduction,
    translations,
    t,
    hasFeature,
    isEdition,
  };
}
