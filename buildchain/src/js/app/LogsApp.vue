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

      <!-- Checking a revert: the dialog only opens once the server has looked at every value -->
      <div v-if="busyText" class="editrix-logs__loading" role="status" aria-live="polite">
        <LoadingSpinner :text="busyText" />
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
        :progress-text="progressText"
        :stoppable="revertingLogId !== null && !stopRequested"
        @stop="stopRevert"
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
import { urlWithId, urlWithQuery } from '../utils/urlWithId';
import { formatElapsed, formatRemaining, estimateRevertSeconds } from '../utils/searchProgress';
import { kickQueue } from '../utils/queue';

// Config from PHP
const config = window.EditrixConfig || {};
const sites = config.sites || [];
const users = config.users || [];
const apiUrl = config.apiUrl || '';
const validateRevertUrl = config.validateRevertUrl || '';
const revertUrl = config.revertUrl || '';
const revertStatusUrl = config.revertStatusUrl || '';
const cancelRevertUrl = config.cancelRevertUrl || '';
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
// What a long step is doing, so a big log does not look stuck.
const busyText = ref('');
const progressText = ref('');
// A queued revert that can be stopped, and whether the user asked to.
const revertingLogId = ref(null);
const stopRequested = ref(false);
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
// With `resume`, the revert is already running (the page was reloaded), so
// nothing is started: it is only followed.
const runRevert = async (log, force, { resume = false } = {}) => {
  stopRequested.value = false;
  const startedAt = Date.now();
  let counts = null;
  let first = null;
  let lastMoveAt = startedAt;

  // Redrawn every second, so the clock moves even when the count does not.
  const describe = () => {
    let text = stopRequested.value
      ? 'Stopping. This can take a few seconds.'
      : counts && counts.reverted === 0
        ? "Waiting for Craft's queue to start the revert. Other jobs may be ahead of it."
        : counts
          ? `Reverting: ${counts.reverted.toLocaleString()} of ${counts.total.toLocaleString()} values restored.`
          : 'Reverting.';
    text += ` ${formatElapsed((Date.now() - startedAt) / 1000)} elapsed.`;
    const left = counts && !stopRequested.value
      ? formatRemaining(estimateRevertSeconds({ first, reverted: counts.reverted, total: counts.total, nowMs: Date.now() }) ?? 0)
      : null;
    if (left) text += ` Up to ${left} left.`;
    text += ' Keep this page open until it finishes.';
    if (Date.now() - lastMoveAt > 30000) {
      text += " Nothing has moved for a while, so Craft's queue may not be running.";
    }
    return text;
  };
  progressText.value = describe();
  const ticker = setInterval(() => { progressText.value = describe(); }, 1000);

  try {
    const data = resume
      ? { success: true, queued: true }
      : await post(urlWithId(revertUrl, log.id), force ? { force: true } : {});

    if (!data.success || !data.queued) return data;

    revertingLogId.value = log.id;
    // The revert was pushed by this request, so Craft would not start it until
    // another panel page loads. Ask for it now, and again if it still waits.
    kickQueue();
    let lastKickAt = Date.now();

    for (;;) {
      await new Promise((resolve) => setTimeout(resolve, 1500));
      const status = await get(urlWithId(revertStatusUrl, log.id));

      if (!status.success) return status;

      if (status.running) {
        if (!counts || status.reverted !== counts.reverted) lastMoveAt = Date.now();
        counts = { reverted: status.reverted, total: status.total };
        if (!first) first = { reverted: status.reverted, atMs: Date.now() };
        if (Date.now() - lastMoveAt > 10000 && Date.now() - lastKickAt > 10000) {
          kickQueue();
          lastKickAt = Date.now();
        }
        continue;
      }

      if (stopRequested.value || status.released) {
        const lead = stopRequested.value
          ? 'Revert stopped.'
          : "The revert stopped before it finished, so Craft's queue may not be running.";
        return {
          success: true,
          revertedCount: status.reverted,
          needsForce: false,
          message: `${lead} ${status.reverted} value(s) were restored. Revert again to restore the rest.`,
        };
      }

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
  } finally {
    clearInterval(ticker);
    revertingLogId.value = null;
  }
};

const stopRevert = async () => {
  if (revertingLogId.value === null || stopRequested.value) return;
  stopRequested.value = true;
  progressText.value = 'Stopping. This can take a few seconds.';
  try {
    await post(urlWithId(cancelRevertUrl, revertingLogId.value), {});
  } catch (err) {
    window.Craft?.cp?.displayError?.(err.message || 'Could not stop the revert');
  }
};

// Runs a revert and says what came of it. Values edited since are never
// overwritten by a normal revert: they are left as they are and reported.
// Overwriting them is a separate, explicit step offered afterwards.
const revertAndReport = async (log, options = {}) => {
  const data = await runRevert(log, false, options);

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
};

// A reload drops the page but not the queued revert. If a log is still
// reverting, show its progress again, with the same Stop button.
const resumeRevert = async () => {
  if (!canRevert || actionInProgress.value || revertingLogId.value !== null) return;

  const active = logs.value.find((l) => l.status === 'reverting');
  if (!active) return;

  pendingAction.value = {
    title: 'Revert in progress',
    message: `Reverting "${active.searchQuery}" → "${active.replaceWith}".`,
    danger: false,
    execute: () => revertAndReport(active, { resume: true }),
  };
  await executeAction();
};

const confirmRevert = async (log) => {
  // First, validate the revert to see what it would do
  actionInProgress.value = true;
  busyText.value = 'Checking which values can be restored. A large log can take a minute.';
  try {
    const validation = await get(urlWithId(validateRevertUrl, log.id));
    busyText.value = '';

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
      title: left === 0 && !validation.partial ? 'Revert Operation' : 'Revert what is safe',
      message: validation.partial
        ? `This log is large: ${validation.checked.toLocaleString()} of ${validation.total.toLocaleString()} values were checked in the time allowed.\n\n${warnings.length ? warnings.join('. ') + ' so far.\n\n' : ''}Reverting checks every value again and leaves alone anything edited since.`
        : left === 0
          ? `Revert ${validation.safeCount} replacement(s) from "${log.searchQuery}" → "${log.replaceWith}"?`
          : `${warnings.join('. ')}.\n\n${validation.safeCount} of ${validation.total} value(s) can be restored. The rest will be left exactly as they are.`,
      danger: false,
      execute: () => revertAndReport(log),
    };
  } catch (err) {
    actionInProgress.value = false;
    busyText.value = '';
    window.Craft?.cp?.displayError?.(err.message || 'Failed to validate revert');
  }
};

const confirmDelete = (log) => {
  pendingAction.value = {
    title: 'Delete Log Entry',
    message: 'Permanently delete this log entry? This cannot be undone.',
    danger: true,
    execute: async () => {
      const data = await post(urlWithId(deleteUrl, log.id), {});
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
    progressText.value = '';
  }
};

const handleExport = (format) => {
  const params = new URLSearchParams();
  params.append('format', format);
  params.append('type', filters.type);
  if (filters.siteId) params.append('siteId', filters.siteId);
  if (filters.status) params.append('status', filters.status);
  window.location.href = urlWithQuery(exportUrl, params);
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

  return urlWithQuery(searchPageUrl, params);
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

onMounted(async () => {
  await fetchLogs();
  await resumeRevert();
});
</script>
