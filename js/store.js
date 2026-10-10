/* ==========================================================================
   store.js — Local Storage Manager
   Central CRUD + pub/sub + seeding for the entire application.
   Every module reads/writes through this single source of truth.
   ========================================================================== */

const NS = 'lsms_proto_v1';
const KEY_INDEX = `${NS}::__index`;

/** Keys for every collection in the system. */
export const COLLECTIONS = [
  'councilMembers', 'ordinances', 'resolutions', 'sessions', 'agenda',
  'committees', 'committeeMembers', 'votes', 'records', 'hearings',
  'archives', 'research', 'feedback', 'notifications', 'activities', 'settings'
];

/** Internal in-memory cache mirrored to localStorage. */
const cache = {};

/* ----------------------------- low-level ----------------------------- */
function readKey(key) {
  try { const raw = localStorage.getItem(`${NS}::${key}`); return raw ? JSON.parse(raw) : null; }
  catch { return null; }
}
function writeKey(key, val) {
  try { localStorage.setItem(`${NS}::${key}`, JSON.stringify(val)); return true; }
  catch (e) { console.error('LSMS store write failed', e); return false; }
}

/* ----------------------------- pub/sub ----------------------------- */
const subscribers = new Map(); // collection -> Set<fn>
export function on(collection, fn) {
  if (!subscribers.has(collection)) subscribers.set(collection, new Set());
  subscribers.get(collection).add(fn);
  return () => subscribers.get(collection)?.delete(fn);
}
export function onAny(fn) {
  return on('__any__', fn);
}
function emit(collection, payload) {
  subscribers.get(collection)?.forEach(fn => { try { fn(payload); } catch (e) { console.error(e); } });
  subscribers.get('__any__')?.forEach(fn => { try { fn(collection, payload); } catch (e) { console.error(e); } });
}

/* ----------------------------- initialization ----------------------------- */
export function initStore(seedFn) {
  let needsSeed = !localStorage.getItem(KEY_INDEX);
  if (needsSeed) {
    seedFn(writeKey);
    localStorage.setItem(KEY_INDEX, new Date().toISOString());
  }
  // hydrate cache
  COLLECTIONS.forEach(c => { cache[c] = readKey(c) || []; });
}

export function isSeeded() { return !!localStorage.getItem(KEY_INDEX); }

/* ----------------------------- CRUD ----------------------------- */
export function getAll(collection) {
  if (!(collection in cache)) cache[collection] = readKey(collection) || [];
  return cache[collection] || [];
}

export function getById(collection, id) {
  return getAll(collection).find(r => String(r.id) === String(id)) || null;
}

export function insert(collection, record) {
  const list = getAll(collection);
  const now = new Date().toISOString();
  const rec = { id: record.id || genId(collection), createdAt: record.createdAt || now, updatedAt: now, ...record, id: record.id || genId(collection) };
  // ensure id + timestamps preserved
  if (!record.id) rec.id = genId(collection);
  rec.createdAt = record.createdAt || now;
  rec.updatedAt = now;
  list.unshift(rec);
  writeKey(collection, list);
  cache[collection] = list;
  emit(collection, { action: 'insert', record: rec });
  logActivity('create', collection, rec);
  return rec;
}

export function update(collection, id, patch) {
  const list = getAll(collection);
  const idx = list.findIndex(r => String(r.id) === String(id));
  if (idx === -1) return null;
  const updated = { ...list[idx], ...patch, updatedAt: new Date().toISOString() };
  list[idx] = updated;
  writeKey(collection, list);
  cache[collection] = list;
  emit(collection, { action: 'update', record: updated, id });
  logActivity('update', collection, updated);
  return updated;
}

export function remove(collection, id) {
  const list = getAll(collection);
  const rec = list.find(r => String(r.id) === String(id));
  const next = list.filter(r => String(r.id) !== String(id));
  writeKey(collection, next);
  cache[collection] = next;
  emit(collection, { action: 'delete', id, record: rec });
  if (rec) logActivity('delete', collection, rec);
  return rec;
}

export function bulkSet(collection, items) {
  writeKey(collection, items);
  cache[collection] = items;
  emit(collection, { action: 'bulk', records: items });
}

/* ----------------------------- helpers ----------------------------- */
const counters = {};
export function genId(collection) {
  counters[collection] = (counters[collection] || 0) + 1;
  const prefix = (collection.slice(0, 3) || 'rec').toUpperCase();
  return `${prefix}-${Date.now().toString(36).slice(-4)}${counters[collection]}`.toUpperCase();
}

export function resetAll() {
  COLLECTIONS.forEach(c => localStorage.removeItem(`${NS}::${c}`));
  localStorage.removeItem(KEY_INDEX);
  Object.keys(cache).forEach(k => delete cache[k]);
}

export function exportData() {
  const dump = {};
  COLLECTIONS.forEach(c => { dump[c] = getAll(c); });
  dump.__exportedAt = new Date().toISOString();
  dump.__version = '1.0.0';
  return dump;
}

/* ----------------------------- activity log ----------------------------- */
function logActivity(action, collection, record) {
  const list = getAll('activities');
  const label = record?.title || record?.name || record?.subject || record?.id || 'record';
  const entry = {
    id: genId('activities'),
    action, collection, label,
    recordId: record?.id,
    time: new Date().toISOString(),
    user: 'Hon. R. Almazan'
  };
  list.unshift(entry);
  // keep last 60
  if (list.length > 60) list.length = 60;
  writeKey('activities', list);
  cache.activities = list;
}

/* ----------------------------- notifications ----------------------------- */
export function pushNotification(n) {
  const list = getAll('notifications');
  list.unshift({ id: genId('notifications'), read: false, time: new Date().toISOString(), ...n });
  if (list.length > 30) list.length = 30;
  writeKey('notifications', list);
  cache.notifications = list;
  emit('notifications', { action: 'insert' });
}

export function markAllRead() {
  const list = getAll('notifications').map(n => ({ ...n, read: true }));
  writeKey('notifications', list);
  cache.notifications = list;
  emit('notifications', { action: 'update' });
}

/* ----------------------------- settings ----------------------------- */
export function getSettings() {
  return getAll('settings')[0] || { darkMode: false, density: 'comfortable', notifications: true, theme: 'blue' };
}
export function saveSettings(patch) {
  const cur = getSettings();
  const updated = { ...cur, ...patch };
  bulkSet('settings', [updated]);
  return updated;
}
