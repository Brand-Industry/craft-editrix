// What a running search is doing right now, derived from what the status
// endpoint already reports. The wait has several stages and only one of
// them (scanning) has numbers to show.
export const PHASES = ['sending', 'queued', 'counting', 'scanning', 'finishing'];

export function searchPhase({ hasId, status, scanned, total }) {
  if (!hasId || !status) return 'sending';
  if (status === 'queued') return 'queued';
  if (!total) return 'counting';
  return scanned >= total ? 'finishing' : 'scanning';
}

// 42 -> "0:42", 3725 -> "1:02:05"
export function formatElapsed(seconds) {
  const s = Math.max(0, Math.floor(seconds));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = String(s % 60).padStart(2, '0');
  return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
}

// Rounded up and deliberately coarse: "about 12 min" is honest, "11:47" is not.
export function formatRemaining(seconds) {
  if (seconds < 60) return null;
  const minutes = Math.ceil(seconds / 60);
  if (minutes < 90) return `${minutes} min`;
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m === 0 ? `${h} h` : `${h} h ${m} min`;
}

// A search that starts scanning is sampled once; later readings give the
// pace. Too little data gives null so the UI says nothing instead of
// guessing. It is an upper bound: a search can end earlier, at the result
// cap or on cancel.
export const MIN_SAMPLE_SECONDS = 15;
export const MIN_SAMPLE_ENTRIES = 500;

export function estimateRemainingSeconds({ sample, scanned, total, nowMs }) {
  if (!sample || !total || scanned >= total) return null;

  const seconds = (nowMs - sample.atMs) / 1000;
  const entries = scanned - sample.scanned;

  if (seconds < MIN_SAMPLE_SECONDS || entries < MIN_SAMPLE_ENTRIES) return null;

  return Math.round((total - scanned) / (entries / seconds));
}

// Hints shown while waiting, one at a time. Only ones that are true for
// this install: scope filters are a Pro feature.
export function waitingTips({ hasScopeFilters }) {
  return [
    'You can leave this page. The search keeps running on the server, and you will find it here when you come back.',
    ...(hasScopeFilters
      ? ['Limit the search to a section or field in Segmented Search to finish much faster.']
      : []),
    'A search stops at {limit} results. A more specific phrase gives you a complete list.',
    'Finished searches stay under Recent searches for a day.',
    'Dry Run Mode shows what a replace would do without saving anything.',
    'Every replace is logged, and you can revert it from Logs & History.',
  ];
}
