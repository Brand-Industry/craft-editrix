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

      <!-- Filters -->
      <div class="editrix-logs__filters">
        <select v-model="filters.status" @change="resetAndFetch">
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
        <h3>No logs found</h3>
        <p>Operation logs will appear here after you perform search &amp; replace operations.</p>
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
              <th>Replace With</th>
              <th>Count</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="log in logs" :key="log.id">
              <td :title="log.date">{{ log.relativeDate }}</td>
              <td>{{ log.userFullName || log.username }}</td>
              <td v-if="sites.length > 1">{{ log.siteName }}</td>
              <td class="editrix-logs__query"><code>{{ log.searchQuery }}</code></td>
              <td class="editrix-logs__query"><code>{{ log.replaceWith }}</code></td>
              <td>{{ log.count }}</td>
              <td>
                <span :class="'editrix-badge editrix-badge--' + badgeClass(log.status)">
                  {{ log.status }}
                </span>
              </td>
              <td class="editrix-logs__actions-cell">
                <button
                  v-if="canRevert && log.status !== 'reverted'"
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
const deleteUrl = config.deleteUrl || '';
const exportUrl = config.exportUrl || '';
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
  status: null,
});

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

const confirmRevert = async (log) => {
  // First, validate the revert to check for modified content
  actionInProgress.value = true;
  try {
    const validation = await get(validateRevertUrl + '/' + log.id);

    if (!validation.success) {
      window.Craft?.cp?.displayError?.(validation.error || 'Validation failed');
      actionInProgress.value = false;
      return;
    }

    actionInProgress.value = false;

    if (validation.safe) {
      // All content is safe to revert
      pendingAction.value = {
        title: 'Revert Operation',
        message: `Revert ${log.count} replacement(s) from "${log.searchQuery}" → "${log.replaceWith}"?`,
        danger: false,
        execute: async () => {
          const data = await post(revertUrl + '/' + log.id, {});
          if (data.success) {
            window.Craft?.cp?.displayNotice?.(data.message);
          } else {
            window.Craft?.cp?.displayError?.(data.error || 'Revert failed');
          }
        },
      };
    } else {
      // Some content was modified after replacement
      const warnings = [];
      if (validation.modifiedCount > 0) {
        warnings.push(`${validation.modifiedCount} element(s) have been modified since the replacement`);
      }
      if (validation.missingCount > 0) {
        warnings.push(`${validation.missingCount} element(s) no longer exist`);
      }

      pendingAction.value = {
        title: 'Warning: Content Changed',
        message: `${warnings.join('. ')}. Only ${validation.safeCount} of ${validation.total} element(s) can be safely reverted.\n\nDo you want to force revert anyway? This will overwrite any changes made after the original replacement.`,
        danger: true,
        execute: async () => {
          const data = await post(revertUrl + '/' + log.id, { force: true });
          if (data.success) {
            window.Craft?.cp?.displayNotice?.(data.message);
          } else {
            window.Craft?.cp?.displayError?.(data.error || 'Revert failed');
          }
        },
      };
    }
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
    await pendingAction.value.execute();
    pendingAction.value = null;
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
  if (filters.siteId) params.append('siteId', filters.siteId);
  if (filters.status) params.append('status', filters.status);
  window.location.href = exportUrl + '?' + params.toString();
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
