<template>
  <div class="editrix">
    <div class="editrix-logs">
      <!-- Header -->
      <header class="editrix-logs__header">
        <h1 class="editrix-logs__title">Logs &amp; History</h1>
        <div class="editrix-logs__actions">
          <button
            v-if="canExport"
            class="editrix-btn editrix-btn--secondary"
            @click="handleExport('csv')"
          >
            Export CSV
          </button>
          <button
            v-if="canExport && hasFeature('export.json')"
            class="editrix-btn editrix-btn--secondary"
            @click="handleExport('json')"
          >
            Export JSON
          </button>
        </div>
      </header>

      <!-- Type tabs -->
      <div class="editrix-mode-toggle">
        <button
          type="button"
          class="editrix-mode-toggle__option"
          :class="{ 'is-active': filters.type === 'replace' }"
          @click="setLogType('replace')"
        >
          Replacements
        </button>
        <button
          type="button"
          class="editrix-mode-toggle__option"
          :class="{ 'is-active': filters.type === 'search' }"
          @click="setLogType('search')"
        >
          Searches
        </button>
      </div>
      <p class="editrix-mode-toggle__hint">
        {{ filters.type === 'replace'
          ? 'Content changes made and whether they can still be reverted.'
          : 'Searches performed, whether or not anything was replaced.' }}
      </p>

      <!-- Filters -->
      <div class="editrix-logs__filters">
        <select v-if="filters.type === 'replace'" v-model="filters.status" @change="resetAndFetch">
          <option :value="null">All Statuses</option>
          <option value="success">Success</option>
          <option value="reverted">Reverted</option>
        </select>

        <select v-if="sites.length > 1" v-model="filters.siteId" @change="resetAndFetch">
          <option :value="null">All Sites</option>
          <option v-for="site in sites" :key="site.id" :value="site.id">
            {{ site.name }}
          </option>
        </select>

        <select v-if="users.length > 0" v-model="filters.userId" @change="resetAndFetch">
          <option :value="null">All Users</option>
          <option v-for="user in users" :key="user.id" :value="user.id">
            {{ user.name }}
          </option>
        </select>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="editrix-logs__loading">
        <LoadingSpinner text="Loading logs..." />
      </div>

      <!-- Empty -->
      <div v-else-if="logs.length === 0" class="editrix-logs__empty">
        <h3>No {{ filters.type === 'replace' ? 'replacements' : 'searches' }} found</h3>
        <p>
          {{ filters.type === 'replace'
            ? 'Content changes will appear here after you replace something.'
            : 'Searches will appear here as soon as you run one.' }}
        </p>
      </div>

      <!-- Table -->
      <div v-else class="editrix-results">
        <table class="editrix-results__table">
          <thead>
            <tr>
              <th>Date</th>
              <th>User</th>
              <th v-if="sites.length > 1">Site</th>
              <th>Search</th>
              <th v-if="filters.type === 'replace'">Replace With</th>
              <th>Count</th>
              <th v-if="filters.type === 'replace'">Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="log in logs" :key="log.id">
              <td :title="log.date">{{ log.relativeDate }}</td>
              <td>{{ log.userFullName || log.username }}</td>
              <td v-if="sites.length > 1">{{ log.siteName }}</td>
              <td class="editrix-logs__query"><code>{{ log.searchQuery }}</code></td>
              <td v-if="filters.type === 'replace'" class="editrix-logs__query">
                <code>{{ log.replaceWith }}</code>
              </td>
              <td>{{ log.count }}</td>
              <td v-if="filters.type === 'replace'">
                <span :class="'editrix-badge editrix-badge--' + badgeClass(log.status)">
                  {{ log.status }}
                </span>
              </td>
              <td class="editrix-logs__actions-cell">
                <a
                  v-if="filters.type === 'search'"
                  class="editrix-btn editrix-btn--sm editrix-btn--ghost"
                  :href="rerunUrl(log)"
                >
                  Re-run
                </a>
                <button
                  v-if="filters.type === 'replace' && canRevert && log.status !== 'reverted'"
                  class="editrix-btn editrix-btn--sm editrix-btn--ghost"
                  :disabled="actionInProgress"
                  @click="confirmRevert(log)"
                >
                  Revert
                </button>
                <button
                  v-if="canDelete"
                  class="editrix-btn editrix-btn--sm editrix-btn--ghost editrix-btn--danger-text"
                  :disabled="actionInProgress"
                  @click="confirmDelete(log)"
                >
                  Delete
                </button>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination -->
        <div class="editrix-results__footer">
          <span>Showing {{ offset + 1 }}-{{ Math.min(offset + limit, total) }} of {{ total }}</span>
          <div class="editrix-logs__pagination">
            <button
              class="editrix-btn editrix-btn--sm editrix-btn--secondary"
              :disabled="offset === 0"
              @click="prevPage"
            >
              Previous
            </button>
            <button
              class="editrix-btn editrix-btn--sm editrix-btn--secondary"
              :disabled="offset + limit >= total"
              @click="nextPage"
            >
              Next
            </button>
          </div>
        </div>
      </div>

      <!-- Confirm Modal -->
      <ConfirmActionModal
        :show="!!pendingAction"
        :title="pendingAction?.title || ''"
        :message="pendingAction?.message || ''"
        :loading="actionInProgress"
        :danger="pendingAction?.danger || false"
        @confirm="executeAction"
        @cancel="pendingAction = null"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useApi } from '../composables/useApi';
import LoadingSpinner from '../components/common/LoadingSpinner.vue';
import ConfirmActionModal from '../components/common/ConfirmActionModal.vue';

// Config from PHP
const config = window.EditrixConfig || {};
const sites = config.sites || [];
const users = config.users || [];
const apiUrl = config.apiUrl || '';
const validateRevertUrl = config.validateRevertUrl || '';
const revertUrl = config.revertUrl || '';
const revertStatusUrl = config.revertStatusUrl || '';
const deleteUrl = config.deleteUrl || '';
const exportUrl = config.exportUrl || '';
const searchPageUrl = config.searchPageUrl || '';
const canRevert = config.canRevert || false;
const canDelete = config.canDelete || false;
const canExport = config.canExport || false;
const license = config.license || {};

const hasFeature = (feature) => license.features?.[feature] || false;

// API
const { get, post } = useApi();

// State
const loading = ref(false);
const logs = ref([]);
const total = ref(0);
const limit = ref(20);
const offset = ref(0);
const actionInProgress = ref(false);
const pendingAction = ref(null);

const filters = reactive({
  siteId: null,
  userId: null,
  type: 'replace',
  status: null,
});

const setLogType = (type) => {
  filters.type = type;
  filters.status = null;
  resetAndFetch();
};

// Methods
const fetchLogs = async () => {
  loading.value = true;
  try {
    const params = {
      limit: limit.value,
      offset: offset.value,
    };
    if (filters.siteId) params.siteId = filters.siteId;
    if (filters.userId) params.userId = filters.userId;
    if (filters.type) params.type = filters.type;
    if (filters.status) params.status = filters.status;

    const data = await get(apiUrl, params);
    if (data.success) {
      logs.value = data.logs;
      total.value = data.total;
    }
  } catch (err) {
    window.Craft?.cp?.displayError?.(err.message || 'Failed to load logs');
  } finally {
    loading.value = false;
  }
};

const resetAndFetch = () => {
  offset.value = 0;
  fetchLogs();
};

// Starts a revert and, if the server queued it (a large one), follows it
// until it stops. Returns the final numbers either way.
const runRevert = async (log, force) => {
  const data = await post(revertUrl + '/' + log.id, force ? { force: true } : {});

  if (!data.success || !data.queued) return data;

  for (;;) {
    await new Promise((resolve) => setTimeout(resolve, 1500));
    const status = await get(revertStatusUrl + '/' + log.id);

    if (!status.success) return status;

    if (!status.running) {
      return {
        success: true,
        revertedCount: status.reverted,
        // Values still standing after a finished revert were left alone.
        conflictCount: status.left,
        needsForce: status.left > 0,
        message:
          status.left > 0
            ? `Reverted ${status.reverted} change(s). ${status.left} were left as they are because they were edited since.`
            : `Successfully reverted ${status.reverted} change(s).`,
      };
    }
  }
};

const confirmRevert = async (log) => {
  // First, validate the revert to see what it would do
  actionInProgress.value = true;
  try {
    const validation = await get(validateRevertUrl + '/' + log.id);

    if (!validation.success) {
      window.Craft?.cp?.displayError?.(validation.error || 'Validation failed');
      actionInProgress.value = false;
      return;
    }

    actionInProgress.value = false;

    const left = validation.modifiedCount + validation.missingCount;
    const warnings = [];
    if (validation.modifiedCount > 0) {
      warnings.push(`${validation.modifiedCount} value(s) have been edited since the replacement`);
    }
    if (validation.missingCount > 0) {
      warnings.push(`${validation.missingCount} element(s) no longer exist`);
    }

    // Values that were edited since are never overwritten by a normal
    // revert: they are left as they are and reported. Overwriting them is a
    // separate, explicit step offered afterwards.
    pendingAction.value = {
      title: left === 0 ? 'Revert Operation' : 'Revert what is safe',
      message:
        left === 0
          ? `Revert ${validation.safeCount} replacement(s) from "${log.searchQuery}" → "${log.replaceWith}"?`
          : `${warnings.join('. ')}.\n\n${validation.safeCount} of ${validation.total} value(s) can be restored. The rest will be left exactly as they are.`,
      danger: false,
      execute: async () => {
        const data = await runRevert(log, false);

        if (!data.success) {
          window.Craft?.cp?.displayError?.(data.error || 'Revert failed');
          return null;
        }

        window.Craft?.cp?.displayNotice?.(data.message);

        if (!data.needsForce) return null;

        // Offer the explicit second step for the ones that were edited.
        return {
          followUp: {
            title: 'Overwrite later edits?',
            message: `${data.conflictCount} value(s) were edited after the replacement and were left alone.\n\nOverwriting them restores the original text and discards those edits. This cannot be undone.`,
            danger: true,
            execute: async () => {
              const forced = await runRevert(log, true);
              if (forced.success) {
                window.Craft?.cp?.displayNotice?.(forced.message);
              } else {
                window.Craft?.cp?.displayError?.(forced.error || 'Revert failed');
              }
              return null;
            },
          },
        };
      },
    };
  } catch (err) {
    actionInProgress.value = false;
    window.Craft?.cp?.displayError?.(err.message || 'Failed to validate revert');
  }
};

const confirmDelete = (log) => {
  pendingAction.value = {
    title: 'Delete Log Entry',
    message: 'Permanently delete this log entry? This cannot be undone.',
    danger: true,
    execute: async () => {
      const data = await post(deleteUrl + '/' + log.id, {});
      if (data.success) {
        window.Craft?.cp?.displayNotice?.(data.message);
      } else {
        window.Craft?.cp?.displayError?.(data.error || 'Delete failed');
      }
    },
  };
};

const executeAction = async () => {
  if (!pendingAction.value?.execute) return;
  actionInProgress.value = true;
  try {
    const result = await pendingAction.value.execute();
    // An action can hand back a follow-up step to confirm next.
    pendingAction.value = result?.followUp ?? null;
    await fetchLogs();
  } catch (err) {
    window.Craft?.cp?.displayError?.(err.message || 'Action failed');
  } finally {
    actionInProgress.value = false;
  }
};

const handleExport = (format) => {
  const params = new URLSearchParams();
  params.append('format', format);
  params.append('type', filters.type);
  if (filters.siteId) params.append('siteId', filters.siteId);
  if (filters.status) params.append('status', filters.status);
  window.location.href = exportUrl + '?' + params.toString();
};

const rerunUrl = (log) => {
  const params = new URLSearchParams();
  params.set('q', log.searchQuery);
  if (log.useRegex) params.set('regex', '1');
  if (!log.caseSensitive) params.set('ci', '1');
  if (log.wholeWords) params.set('ww', '1');

  const scope = log.scope || {};
  if (scope.allSites) params.set('allSites', '1');
  (scope.sections || []).forEach(v => params.append('sections[]', v));
  (scope.fields || []).forEach(v => params.append('fields[]', v));
  (scope.entryTypes || []).forEach(v => params.append('entryTypes[]', v));

  return `${searchPageUrl}?${params.toString()}`;
};

const badgeClass = (status) => {
  if (status === 'success') return 'success';
  if (status === 'reverted') return 'warning';
  return 'neutral';
};

const prevPage = () => {
  offset.value = Math.max(0, offset.value - limit.value);
  fetchLogs();
};

const nextPage = () => {
  offset.value += limit.value;
  fetchLogs();
};

onMounted(fetchLogs);
</script>
