/*!
 * Legislative Services Management System - Prototype
 * Bundled JavaScript (single-file, no ES6 modules)
 * Generated from ES6 module sources for file:// protocol compatibility.
 * Opens directly in browser without a server — no Live Server needed.
 */
(function() {
'use strict';

// ===== store.js =====
/* ==========================================================================
   store.js — Local Storage Manager
   Central CRUD + pub/sub + seeding for the entire application.
   Every module reads/writes through this single source of truth.
   ========================================================================== */

const NS = 'lsms_proto_v1';
const KEY_INDEX = `${NS}::__index`;

/** Keys for every collection in the system. */
const COLLECTIONS = [
  'councilMembers', 'ordinances', 'resolutions', 'sessions', 'agenda', 'proceedings',
  'committees', 'committeeMembers', 'votes', 'records', 'hearings',
  'archives', 'research', 'feedback', 'notifications', 'activities', 'settings'
];

/**
 * Collections that are real, shared legislative records — these get
 * persisted to MySQL via session-system/api/appdata.php so they survive
 * across browsers/devices. Everything else (feedback, notifications,
 * activities, settings) is per-browser app/UI state and intentionally
 * stays localStorage-only.
 */
const DB_BACKED = [
  'councilMembers', 'ordinances', 'resolutions', 'committees',
  'committeeMembers', 'votes', 'records', 'hearings', 'archives',
  'research', 'agenda', 'sessions', 'proceedings'
];
const API_BASE = 'session-system/api/appdata.php';

/** Internal in-memory cache mirrored to localStorage. */
const cache = {};

/* ----------------------------- low-level ----------------------------- */
function readKey(key) {
  try { const raw = localStorage.getItem(`${NS}::${key}`); return raw ? JSON.parse(raw) : null; }
  catch { return null; }
}
function writeKey(key, val) {
  try {
    localStorage.setItem(`${NS}::${key}`, JSON.stringify(val));
    persistToServer(key, val); // no-op for non-DB-backed collections
    return true;
  }
  catch (e) { console.error('LSMS store write failed', e); return false; }
}
// Used only for the one-time initial mock-data seed on a brand-new
// browser: writes locally WITHOUT pushing to the server. This matters —
// if seeding pushed immediately, it could race ahead of hydrateFromServer()
// and clobber real server data with fresh mock data on a new device that
// just hasn't talked to the server yet. hydrateFromServer() is the only
// thing that decides push-vs-pull, and it does so after checking what the
// server already has.
function seedWriteKey(key, val) {
  try { localStorage.setItem(`${NS}::${key}`, JSON.stringify(val)); return true; }
  catch (e) { console.error('LSMS store write failed', e); return false; }
}

/* ----------------------------- server sync ----------------------------- */
// Fire-and-forget: push the full current array for a collection to MySQL.
// Never blocks or throws into the caller — if the API/network is
// unavailable, the app just keeps working off localStorage.
function persistToServer(collection, items) {
  if (!DB_BACKED.includes(collection)) return;
  fetch(`${API_BASE}?collection=${encodeURIComponent(collection)}&action=bulk_set`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(items)
  }).catch(() => { /* offline or API unreachable — silently keep local */ });
}

// Pull the authoritative copy of each DB-backed collection from MySQL.
// If the server already has data, it wins (overwrites local cache so
// every device converges on the same records). If the server is empty
// (first run ever), push whatever's already local (the seed data) up
// so the database gets populated.
async function hydrateFromServer() {
  for (const collection of DB_BACKED) {
    try {
      const res = await fetch(`${API_BASE}?collection=${encodeURIComponent(collection)}&action=list`);
      if (!res.ok) continue;
      const serverItems = await res.json();
      if (Array.isArray(serverItems) && serverItems.length > 0) {
        localStorage.setItem(`${NS}::${collection}`, JSON.stringify(serverItems));
        cache[collection] = serverItems;
        emit(collection, { action: 'bulk', records: serverItems, source: 'server' });
      } else {
        const local = getAll(collection);
        if (local.length) persistToServer(collection, local);
      }
    } catch (e) {
      // Offline or API unreachable — silently keep working from localStorage.
    }
  }
}

/* ----------------------------- pub/sub ----------------------------- */
const subscribers = new Map(); // collection -> Set<fn>
function on(collection, fn) {
  if (!subscribers.has(collection)) subscribers.set(collection, new Set());
  subscribers.get(collection).add(fn);
  return () => subscribers.get(collection)?.delete(fn);
}
function onAny(fn) {
  return on('__any__', fn);
}
function emit(collection, payload) {
  subscribers.get(collection)?.forEach(fn => { try { fn(payload); } catch (e) { console.error(e); } });
  subscribers.get('__any__')?.forEach(fn => { try { fn(collection, payload); } catch (e) { console.error(e); } });
}

/* ----------------------------- initialization ----------------------------- */
function initStore(seedFn) {
  let needsSeed = !localStorage.getItem(KEY_INDEX);
  if (needsSeed) {
    seedFn(seedWriteKey);
    localStorage.setItem(KEY_INDEX, new Date().toISOString());
  }
  // hydrate cache
  COLLECTIONS.forEach(c => { cache[c] = readKey(c) || []; });

  // Reconcile DB-backed collections with MySQL in the background — this
  // never blocks first paint, and silently no-ops if the API/network
  // isn't reachable (the app keeps working off localStorage either way).
  hydrateFromServer();
}

function isSeeded() { return !!localStorage.getItem(KEY_INDEX); }

/* ----------------------------- CRUD ----------------------------- */
function getAll(collection) {
  if (!(collection in cache)) cache[collection] = readKey(collection) || [];
  return cache[collection] || [];
}

function getById(collection, id) {
  return getAll(collection).find(r => String(r.id) === String(id)) || null;
}

function insert(collection, record) {
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

function update(collection, id, patch) {
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

function remove(collection, id) {
  const list = getAll(collection);
  const rec = list.find(r => String(r.id) === String(id));
  const next = list.filter(r => String(r.id) !== String(id));
  writeKey(collection, next);
  cache[collection] = next;
  emit(collection, { action: 'delete', id, record: rec });
  if (rec) logActivity('delete', collection, rec);
  return rec;
}

function bulkSet(collection, items) {
  writeKey(collection, items);
  cache[collection] = items;
  emit(collection, { action: 'bulk', records: items });
}

/* ----------------------------- helpers ----------------------------- */
const counters = {};
function genId(collection) {
  counters[collection] = (counters[collection] || 0) + 1;
  const prefix = (collection.slice(0, 3) || 'rec').toUpperCase();
  return `${prefix}-${Date.now().toString(36).slice(-4)}${counters[collection]}`.toUpperCase();
}

function resetAll() {
  COLLECTIONS.forEach(c => localStorage.removeItem(`${NS}::${c}`));
  localStorage.removeItem(KEY_INDEX);
  Object.keys(cache).forEach(k => delete cache[k]);
}

function exportData() {
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
function pushNotification(n) {
  const list = getAll('notifications');
  list.unshift({ id: genId('notifications'), read: false, time: new Date().toISOString(), ...n });
  if (list.length > 30) list.length = 30;
  writeKey('notifications', list);
  cache.notifications = list;
  emit('notifications', { action: 'insert' });
}

function markAllRead() {
  const list = getAll('notifications').map(n => ({ ...n, read: true }));
  writeKey('notifications', list);
  cache.notifications = list;
  emit('notifications', { action: 'update' });
}

/* ----------------------------- settings ----------------------------- */
function getSettings() {
  return getAll('settings')[0] || { darkMode: false, density: 'comfortable', notifications: true, theme: 'blue' };
}
function saveSettings(patch) {
  const cur = getSettings();
  const updated = { ...cur, ...patch };
  bulkSet('settings', [updated]);
  return updated;
}


// ===== data.js =====
/* ==========================================================================
   data.js — Mock Data Seed
   Comprehensive, interconnected sample data for a fictional city council.
   Seeded into Local Storage on first run by store.initStore().
   ========================================================================== */

const TODAY = new Date();
const iso = (d) => d.toISOString();
const dayOffset = (n) => { const d = new Date(TODAY); d.setDate(d.getDate() + n); return iso(d); };
const dateOnly = (n) => { const d = new Date(TODAY); d.setDate(d.getDate() + n); return d.toISOString().slice(0,10); };

function seedData(writeKey) {

  /* ----------------------- Council Members ----------------------- */
  const councilMembers = [
    { id:'M-001', name:'Hon. Ricardo Almazan', title:'City Secretary', role:'Presiding', ward:'City-Wide', party:'Independent', email:'almazan@council.gov', phone:'+63 917 100 0001', avatar:'RA', status:'active' },
    { id:'M-002', name:'Hon. Maria Santos', title:'Councilor', role:'Member', ward:'District 1', party:'Progressive', email:'santos@council.gov', phone:'+63 917 100 0002', avatar:'MS', status:'active' },
    { id:'M-003', name:'Hon. Juan Dela Cruz', title:'Councilor', role:'Member', ward:'District 2', party:'Unity', email:'delacruz@council.gov', phone:'+63 917 100 0003', avatar:'JD', status:'active' },
    { id:'M-004', name:'Hon. Ana Reyes', title:'Councilor', role:'Member', ward:'District 3', party:'Progressive', email:'reyes@council.gov', phone:'+63 917 100 0004', avatar:'AR', status:'active' },
    { id:'M-005', name:'Hon. Carlos Mendoza', title:'Councilor', role:'Member', ward:'District 4', party:'Unity', email:'mendoza@council.gov', phone:'+63 917 100 0005', avatar:'CM', status:'active' },
    { id:'M-006', name:'Hon. Lourdes Tan', title:'Councilor', role:'Member', ward:'District 5', party:'Independent', email:'tan@council.gov', phone:'+63 917 100 0006', avatar:'LT', status:'active' },
    { id:'M-007', name:'Hon. Pedro Bautista', title:'Councilor', role:'Member', ward:'District 6', party:'Unity', email:'bautista@council.gov', phone:'+63 917 100 0007', avatar:'PB', status:'active' },
    { id:'M-008', name:'Hon. Cristina Lim', title:'Councilor', role:'Member', ward:'District 7', party:'Progressive', email:'lim@council.gov', phone:'+63 917 100 0008', avatar:'CL', status:'active' },
    { id:'M-009', name:'Hon. Felix Garcia', title:'Councilor', role:'Member', ward:'District 8', party:'Independent', email:'garcia@council.gov', phone:'+63 917 100 0009', avatar:'FG', status:'inactive' },
    { id:'M-010', name:'Hon. Grace Villanueva', title:'Councilor', role:'Member', ward:'District 9', party:'Progressive', email:'villanueva@council.gov', phone:'+63 917 100 0010', avatar:'GV', status:'active' },
    { id:'M-011', name:'Hon. Roberto Aguilar', title:'Vice Mayor', role:'Member', ward:'City-Wide', party:'Unity', email:'aguilar@council.gov', phone:'+63 917 100 0011', avatar:'RA', status:'active' },
    { id:'M-012', name:'Hon. Patricia Ong', title:'Councilor', role:'Member', ward:'District 10', party:'Unity', email:'ong@council.gov', phone:'+63 917 100 0012', avatar:'PO', status:'active' },
  ];

  /* ----------------------- Committees ----------------------- */
  const committees = [
    { id:'C-001', name:'Finance & Appropriations', chair:'M-011', jurisdiction:'City budget, appropriations, revenue', scope:'Financial legislation', status:'active', established:dateOnly(-400), workload:85 },
    { id:'C-002', name:'Laws & Ordinances', chair:'M-002', jurisdiction:'Drafting and reviewing city ordinances', scope:'Legal framework', status:'active', established:dateOnly(-500), workload:92 },
    { id:'C-003', name:'Public Works & Infrastructure', chair:'M-003', jurisdiction:'Infrastructure projects, public works', scope:'Physical development', status:'active', established:dateOnly(-380), workload:78 },
    { id:'C-004', name:'Health & Sanitation', chair:'M-004', jurisdiction:'Public health programs, sanitation', scope:'Health services', status:'active', established:dateOnly(-360), workload:65 },
    { id:'C-005', name:'Education & Culture', chair:'M-010', jurisdiction:'Educational programs, cultural preservation', scope:'Education', status:'active', established:dateOnly(-350), workload:58 },
    { id:'C-006', name:'Peace & Order', chair:'M-005', jurisdiction:'Public safety, law enforcement oversight', scope:'Safety', status:'active', established:dateOnly(-340), workload:71 },
  ];

  const committeeMembers = [
    { id:'CM-001', committeeId:'C-001', memberId:'M-011', role:'Chair' },
    { id:'CM-002', committeeId:'C-001', memberId:'M-002', role:'Vice Chair' },
    { id:'CM-003', committeeId:'C-001', memberId:'M-006', role:'Member' },
    { id:'CM-004', committeeId:'C-002', memberId:'M-002', role:'Chair' },
    { id:'CM-005', committeeId:'C-002', memberId:'M-004', role:'Vice Chair' },
    { id:'CM-006', committeeId:'C-002', memberId:'M-008', role:'Member' },
    { id:'CM-007', committeeId:'C-003', memberId:'M-003', role:'Chair' },
    { id:'CM-008', committeeId:'C-003', memberId:'M-005', role:'Member' },
    { id:'CM-009', committeeId:'C-003', memberId:'M-007', role:'Member' },
    { id:'CM-010', committeeId:'C-004', memberId:'M-004', role:'Chair' },
    { id:'CM-011', committeeId:'C-004', memberId:'M-010', role:'Member' },
    { id:'CM-012', committeeId:'C-005', memberId:'M-010', role:'Chair' },
    { id:'CM-013', committeeId:'C-005', memberId:'M-006', role:'Member' },
    { id:'CM-014', committeeId:'C-006', memberId:'M-005', role:'Chair' },
    { id:'CM-015', committeeId:'C-006', memberId:'M-007', role:'Vice Chair' },
    { id:'CM-016', committeeId:'C-006', memberId:'M-012', role:'Member' },
  ];

  /* ----------------------- Ordinances ----------------------- */
  const ordinances = [
    { id:'ORD-2024-001', number:'Ordinance No. 2024-001', title:'An Ordinance Regulating Single-Use Plastics in Commercial Establishments', author:'M-002', category:'Environment', committeeId:'C-002', status:'Enacted', stage:'Published', dateIntroduced:dateOnly(-120), dateApproved:dateOnly(-60), datePublished:dateOnly(-55), summary:'Prohibits single-use plastic bags and utensils in retail, with phased penalties and a green-incentive program for compliant businesses.', versions:3, aiSummary:'This ordinance bans single-use plastics in commercial establishments, introduces a phased penalty schedule, and creates a green-business incentive program. Key stakeholders include retailers and environmental groups. Estimated enforcement cost is low with high environmental impact.' },
    { id:'ORD-2024-002', number:'Ordinance No. 2024-002', title:'An Ordinance Establishing the City Scholarship Program for Underprivileged Students', author:'M-010', category:'Education', committeeId:'C-005', status:'Approved', stage:'Approved', dateIntroduced:dateOnly(-90), dateApproved:dateOnly(-15), summary:'Creates a scholarship fund for top graduates from low-income households, funded by 1% of the special education trust.', versions:2, aiSummary:'Establishes a need-and-merit scholarship funded by a 1% education trust allocation. Targets 200 scholars annually with a projected 3-year budget of ₱18M.' },
    { id:'ORD-2024-003', number:'Ordinance No. 2024-003', title:'An Ordinance on the Comprehensive Traffic Management Code of the City', author:'M-003', category:'Transportation', committeeId:'C-003', status:'Pending Review', stage:'Committee Review', dateIntroduced:dateOnly(-30), summary:'Consolidates all traffic rules, introduces a demerit-point system, and designates bike lanes on all major thoroughfares.', versions:1, aiSummary:'A consolidated traffic code introducing a demerit-point system and mandatory bike lanes. High implementation complexity; requires inter-agency coordination with the transport office.' },
    { id:'ORD-2024-004', number:'Ordinance No. 2024-004', title:'An Ordinance Requiring Smoke-Free Zones in All Public Places', author:'M-004', category:'Health', committeeId:'C-004', status:'Pending Review', stage:'Committee Review', dateIntroduced:dateOnly(-20), summary:'Declares all public parks, terminals, and government premises as smoke-free zones with signage and fines.', versions:1, aiSummary:'Designates smoke-free public zones with mandatory signage and graduated fines. Public health impact is high; enforcement depends on barangay participation.' },
    { id:'ORD-2024-005', number:'Ordinance No. 2024-005', title:'An Ordinance Amending the City Revenue Code (Surcharges & Penalties)', author:'M-011', category:'Finance', committeeId:'C-001', status:'Drafting', stage:'Drafting', dateIntroduced:dateOnly(-5), summary:'Amends surcharge schedules and introduces an early-payment discount of 5% for business permits.', versions:1, aiSummary:'Revenue code amendment introducing an early-payment discount and revised surcharge tiers. Fiscal model projects a 2% increase in timely collections.' },
    { id:'ORD-2023-018', number:'Ordinance No. 2023-018', title:'An Ordinance Approving the Annual City Budget for FY 2024', author:'M-011', category:'Finance', committeeId:'C-001', status:'Enacted', stage:'Published', dateIntroduced:dateOnly(-300), dateApproved:dateOnly(-260), datePublished:dateOnly(-255), summary:'Appropriates ₱2.4 billion for general operations, infrastructure, and social services for FY 2024.', versions:4, aiSummary:'FY2024 annual budget of ₱2.4B allocated across operations (40%), infrastructure (35%), and social services (25%). Largest line item is road networks.' },
  ];

  /* ----------------------- Resolutions ----------------------- */
  const resolutions = [
    { id:'RES-2024-001', number:'Resolution No. 2024-001', title:'A Resolution Expressing Sympathy and Condolences to the Family of the Late Hon. Eduardo Perez', author:'M-002', category:'Ceremonial', status:'Adopted', stage:'Adopted', dateIntroduced:dateOnly(-80), summary:'Expresses condolences on behalf of the Sanggunian to the Perez family.', aiSummary:'Ceremonial resolution of condolence. No fiscal or policy impact; procedural adoption.' },
    { id:'RES-2024-002', number:'Resolution No. 2024-002', title:'A Resolution Endorsing the City to the National Housing Authority for a Socialized Housing Project', author:'M-003', category:'Housing', committeeId:'C-003', status:'Adopted', stage:'Adopted', dateIntroduced:dateOnly(-70), summary:'Endorses the city as a priority site for an NHA socialized housing development.', aiSummary:'Endorsement resolution enabling a national housing project. Potential benefit: 1,200 housing units for informal-settler families.' },
    { id:'RES-2024-003', number:'Resolution No. 2024-003', title:'A Resolution Authorizing the Mayor to Enter into a Memorandum of Agreement with the Department of Health', author:'M-004', category:'Health', committeeId:'C-004', status:'Pending Review', stage:'Committee Review', dateIntroduced:dateOnly(-25), summary:'Authorizes an MOA with DOH for the expanded immunization program.', aiSummary:'MOA authorization with DOH for expanded immunization. Zero local cost; program funded nationally. Expected coverage: 95% of children under 5.' },
    { id:'RES-2024-004', number:'Resolution No. 2024-004', title:'A Resolution Declaring the Last Friday of Every Month as Clean and Green Day', author:'M-006', category:'Environment', status:'Pending Review', stage:'Committee Review', dateIntroduced:dateOnly(-12), summary:'Designates a monthly citywide clean-up and tree-planting day with barangay participation.', aiSummary:'Declares a monthly clean-and-green day. Low-cost, high-participation initiative; supports waste-reduction targets.' },
    { id:'RES-2024-005', number:'Resolution No. 2024-005', title:'A Resolution Urging the National Government to Establish a Satellite Office in the City', author:'M-011', category:'Governance', status:'Drafting', stage:'Drafting', dateIntroduced:dateOnly(-3), summary:'Urges national agencies to establish a satellite office to improve citizen access to services.', aiSummary:'Advocacy resolution requesting a national government satellite office. Improves citizen access to frontline services; no local fiscal impact.' },
  ];

  /* ----------------------- Sessions ----------------------- */
  const sessions = [
    { id:'S-001', title:'Regular Session — 42nd Regular Session', type:'Regular', date:dateOnly(0), time:'09:00', venue:'Session Hall, 3rd Floor', status:'In Progress', agendaCount:5, attendance:[ {memberId:'M-001',status:'present'},{memberId:'M-002',status:'present'},{memberId:'M-003',status:'present'},{memberId:'M-004',status:'late'},{memberId:'M-005',status:'present'},{memberId:'M-006',status:'absent'},{memberId:'M-007',status:'present'},{memberId:'M-008',status:'present'},{memberId:'M-011',status:'present'},{memberId:'M-012',status:'present'} ], duration:0 },
    { id:'S-002', title:'Special Session — Budget Deliberations', type:'Special', date:dateOnly(3), time:'14:00', venue:'Session Hall, 3rd Floor', status:'Scheduled', agendaCount:3, attendance:[], duration:0 },
    { id:'S-003', title:'Joint Session — with Barangay Councils', type:'Joint', date:dateOnly(7), time:'09:00', venue:'City Gymnasium', status:'Scheduled', agendaCount:4, attendance:[], duration:0 },
    { id:'S-004', title:'Regular Session — 41st Regular Session', type:'Regular', date:dateOnly(-14), time:'09:00', venue:'Session Hall, 3rd Floor', status:'Concluded', agendaCount:6, attendance:[ {memberId:'M-001',status:'present'},{memberId:'M-002',status:'present'},{memberId:'M-003',status:'absent'},{memberId:'M-004',status:'present'},{memberId:'M-005',status:'present'},{memberId:'M-006',status:'present'},{memberId:'M-007',status:'present'},{memberId:'M-008',status:'late'},{memberId:'M-011',status:'present'},{memberId:'M-012',status:'present'} ], duration:245 },
    { id:'S-005', title:'Regular Session — 40th Regular Session', type:'Regular', date:dateOnly(-28), time:'09:00', venue:'Session Hall, 3rd Floor', status:'Concluded', agendaCount:7, attendance:[ {memberId:'M-001',status:'present'},{memberId:'M-002',status:'present'},{memberId:'M-003',status:'present'},{memberId:'M-004',status:'present'},{memberId:'M-005',status:'present'},{memberId:'M-006',status:'absent'},{memberId:'M-007',status:'present'},{memberId:'M-008',status:'present'},{memberId:'M-011',status:'present'},{memberId:'M-012',status:'present'} ], duration:210 },
  ];

  /* ----------------------- Agenda ----------------------- */
  const agenda = [
    { id:'A-001', title:'Second Reading of Ordinance No. 2024-003', priority:'High', sessionId:'S-001', deadline:dateOnly(0), status:'In Progress', category:'Legislation', responsible:'Committee on Public Works' },
    { id:'A-002', title:'Public Hearing Report — Smoke-Free Zones', priority:'High', sessionId:'S-001', deadline:dateOnly(0), status:'Pending', category:'Public Hearing', responsible:'Committee on Health' },
    { id:'A-003', title:'Approval of Minutes — 41st Regular Session', priority:'Medium', sessionId:'S-001', deadline:dateOnly(0), status:'Pending', category:'Administrative', responsible:'Office of the Secretary' },
    { id:'A-004', title:'Budget Hearing for FY 2025', priority:'Critical', sessionId:'S-002', deadline:dateOnly(3), status:'Scheduled', category:'Finance', responsible:'Committee on Finance' },
    { id:'A-005', title:'Barangay Concerns Forum', priority:'Medium', sessionId:'S-003', deadline:dateOnly(7), status:'Scheduled', category:'Community', responsible:'Office of the Vice Mayor' },
    { id:'A-006', title:'Third Reading — Scholarship Ordinance', priority:'High', sessionId:'S-004', deadline:dateOnly(-14), status:'Completed', category:'Legislation', responsible:'Committee on Education' },
  ];

  /* ----------------------- Votes ----------------------- */
  const votes = [
    { id:'V-001', subject:'Approval of Ordinance No. 2024-001 (Single-Use Plastics)', sessionId:'S-004', type:'Roll Call', date:dateOnly(-14), total:10, yes:8, no:1, abstain:1, result:'Passed', tallies:[ {memberId:'M-001',vote:'yes'},{memberId:'M-002',vote:'yes'},{memberId:'M-004',vote:'yes'},{memberId:'M-005',vote:'no'},{memberId:'M-006',vote:'yes'},{memberId:'M-007',vote:'yes'},{memberId:'M-008',vote:'abstain'},{memberId:'M-011',vote:'yes'},{memberId:'M-012',vote:'yes'},{memberId:'M-010',vote:'yes'} ] },
    { id:'V-002', subject:'Approval of Resolution No. 2024-001 (Condolences)', sessionId:'S-005', type:'Viva Voce', date:dateOnly(-28), total:9, yes:9, no:0, abstain:0, result:'Unanimous', tallies:[] },
    { id:'V-003', subject:'Approval of Annual Budget FY 2024 (Ord. 2023-018)', sessionId:'S-005', type:'Roll Call', date:dateOnly(-28), total:9, yes:7, no:2, abstain:0, result:'Passed', tallies:[ {memberId:'M-001',vote:'yes'},{memberId:'M-002',vote:'yes'},{memberId:'M-004',vote:'yes'},{memberId:'M-005',vote:'no'},{memberId:'M-007',vote:'yes'},{memberId:'M-008',vote:'yes'},{memberId:'M-011',vote:'yes'},{memberId:'M-012',vote:'no'},{memberId:'M-010',vote:'yes'} ] },
  ];

  /* ----------------------- Records ----------------------- */
  const records = [
    { id:'D-001', title:'FY 2024 Approved Budget Document', category:'Budget', type:'PDF', size:'4.2 MB', uploadedBy:'M-011', dateUploaded:dateOnly(-255), version:'v3', status:'Final', tags:['budget','finance','2024'], audit:[ {action:'uploaded',by:'M-011',time:dateOnly(-260)},{action:'versioned',by:'M-002',time:dateOnly(-258)},{action:'approved',by:'M-001',time:dateOnly(-255)} ] },
    { id:'D-002', title:'Committee Report — Public Works Q1', category:'Committee Report', type:'PDF', size:'1.8 MB', uploadedBy:'M-003', dateUploaded:dateOnly(-40), version:'v1', status:'Active', tags:['committee','infrastructure'], audit:[ {action:'uploaded',by:'M-003',time:dateOnly(-40)} ] },
    { id:'D-003', title:'Public Hearing Transcript — Smoke-Free Zones', category:'Transcript', type:'DOCX', size:'780 KB', uploadedBy:'M-004', dateUploaded:dateOnly(-18), version:'v2', status:'Active', tags:['hearing','health'], audit:[ {action:'uploaded',by:'M-004',time:dateOnly(-20)},{action:'revised',by:'M-004',time:dateOnly(-18)} ] },
    { id:'D-004', title:'City Development Plan 2024–2027', category:'Plan', type:'PDF', size:'12.5 MB', uploadedBy:'M-001', dateUploaded:dateOnly(-100), version:'v1', status:'Final', tags:['plan','development','strategy'], audit:[ {action:'uploaded',by:'M-001',time:dateOnly(-100)} ] },
    { id:'D-005', title:'Ordinance Draft — Traffic Management Code', category:'Legislation Draft', type:'DOCX', size:'1.1 MB', uploadedBy:'M-003', dateUploaded:dateOnly(-30), version:'v1', status:'Draft', tags:['draft','transportation'], audit:[ {action:'uploaded',by:'M-003',time:dateOnly(-30)} ] },
  ];

  /* ----------------------- Public Hearings ----------------------- */
  const hearings = [
    { id:'H-001', title:'Public Hearing — Smoke-Free Zones Ordinance', ordinanceRef:'ORD-2024-004', date:dateOnly(-18), time:'09:00', venue:'City Gymnasium', status:'Concluded', registered:142, attended:118, issues:6, feedbacks:34 },
    { id:'H-002', title:'Public Hearing — Traffic Management Code', ordinanceRef:'ORD-2024-003', date:dateOnly(5), time:'14:00', venue:'Session Hall', status:'Scheduled', registered:67, attended:0, issues:0, feedbacks:0 },
    { id:'H-003', title:'Public Hearing — Socialized Housing Endorsement', ordinanceRef:'RES-2024-002', date:dateOnly(-45), time:'09:00', venue:'Barangay Hall 4', status:'Concluded', registered:89, attended:76, issues:3, feedbacks:21 },
    { id:'H-004', title:'Public Hearing — FY 2025 Budget Proposal', ordinanceRef:null, date:dateOnly(10), time:'13:00', venue:'City Gymnasium', status:'Scheduled', registered:45, attended:0, issues:0, feedbacks:0 },
  ];

  /* ----------------------- Archives ----------------------- */
  const archives = [
    { id:'AR-001', title:'Ordinance No. 2023-018 — FY 2024 Annual Budget', category:'Ordinance', year:2023, dateArchived:dateOnly(-255), retention:'Permanent', format:'Digital', status:'Archived', searchable:true },
    { id:'AR-002', title:'Session Minutes — 1st to 39th Regular Sessions (2023)', category:'Minutes', year:2023, dateArchived:dateOnly(-200), retention:'10 years', format:'Digital', status:'Archived', searchable:true },
    { id:'AR-003', title:'Resolution No. 2023-045 — City Anniversary Proclamation', category:'Resolution', year:2023, dateArchived:dateOnly(-150), retention:'Permanent', format:'Digital', status:'Archived', searchable:true },
    { id:'AR-004', title:'Historical Map Collection — 1985 City Survey', category:'Historical', year:1985, dateArchived:dateOnly(-1200), retention:'Permanent', format:'Digitized', status:'Restored', searchable:true },
    { id:'AR-005', title:'Ordinance No. 2022-009 — Zoning Code Amendment', category:'Ordinance', year:2022, dateArchived:dateOnly(-700), retention:'Permanent', format:'Digital', status:'Archived', searchable:true },
    { id:'AR-006', title:'Council Proceedings 1998 — Centennial Session', category:'Minutes', year:1998, dateArchived:dateOnly(-2500), retention:'Permanent', format:'Digitized', status:'Restored', searchable:true },
  ];

  /* ----------------------- Research ----------------------- */
  const research = [
    { id:'R-001', title:'Impact Assessment: Single-Use Plastic Ban', policy:'Plastic Regulation', type:'Impact Assessment', status:'Completed', date:dateOnly(-70), impactScore:8.6, scope:'Environment', recommendation:'Adopt with phased enforcement and green-business incentives.', benchmark:'Modeled on 3 peer cities; projected 40% plastic-waste reduction in 18 months.', metrics:{ environmental:90, economic:65, social:78, implementability:72 } },
    { id:'R-002', title:'Comparative Analysis: Traffic Management Codes (5 Cities)', policy:'Traffic Management', type:'Comparative Analysis', status:'Completed', date:dateOnly(-40), impactScore:7.4, scope:'Transportation', recommendation:'Adopt demerit-point system; prioritize bike-lane rollout in business districts.', benchmark:'5 peer cities benchmarked; best performer reduced congestion 22% in 2 years.', metrics:{ environmental:55, economic:80, social:85, implementability:60 } },
    { id:'R-003', title:'Policy Research: Socialized Housing Endorsement', policy:'Housing', type:'Policy Research', status:'In Progress', date:dateOnly(-20), impactScore:8.1, scope:'Housing', recommendation:'Proceed with NHA endorsement; pre-identify 3 candidate sites.', benchmark:'National housing data; 1,200-unit potential yield for the city.', metrics:{ environmental:40, economic:70, social:92, implementability:68 } },
    { id:'R-004', title:'Benchmarking: City Scholarship Programs', policy:'Education', type:'Benchmarking', status:'Completed', date:dateOnly(-15), impactScore:7.9, scope:'Education', recommendation:'Cap scholarships at 200/year; tie retention to GPA 2.5 minimum.', benchmark:'4 peer LGU scholarship models compared.', metrics:{ environmental:20, economic:75, social:95, implementability:82 } },
  ];

  /* ----------------------- Citizen Feedback ----------------------- */
  const feedback = [
    { id:'F-001', type:'Complaint', subject:'Potholes on Rizal Street need urgent repair', category:'Infrastructure', citizen:'Anonymous', date:dateOnly(-2), status:'Pending Validation', ward:'District 2', priority:'High', response:'' },
    { id:'F-002', type:'Suggestion', subject:'Add more streetlights along the riverwalk', category:'Public Safety', citizen:'Jose Ramos', date:dateOnly(-5), status:'Validated', ward:'District 3', priority:'Medium', response:'Forwarded to the Committee on Public Works for inclusion in the next infrastructure plan.' },
    { id:'F-003', type:'Compliment', subject:'Thank you for the new scholarship ordinance', category:'Education', citizen:'Maria Cruz', date:dateOnly(-8), status:'Acknowledged', ward:'District 1', priority:'Low', response:'Thank you for your kind words. The scholarship program will begin accepting applications next quarter.' },
    { id:'F-004', type:'Complaint', subject:'Garbage collection schedule in Brgy. 5 is inconsistent', category:'Sanitation', citizen:'Anonymous', date:dateOnly(-1), status:'Pending Validation', ward:'District 5', priority:'High', response:'' },
    { id:'F-005', type:'Suggestion', subject:'Establish a weekly night market to support vendors', category:'Economy', citizen:'Pedro Santos', date:dateOnly(-12), status:'Under Review', ward:'District 4', priority:'Medium', response:'' },
    { id:'F-006', type:'Complaint', subject:'Flooding at the intersection of Mabini and Bonifacio', category:'Drainage', citizen:'Ana Reyes', date:dateOnly(-3), status:'Validated', ward:'District 6', priority:'Critical', response:'Drainage clearing scheduled; engineering assessment requested from the City Engineer\'s Office.' },
  ];

  /* ----------------------- Notifications ----------------------- */
  const notifications = [
    { id:'N-001', title:'Session starts in 30 minutes', body:'The 42nd Regular Session begins at 09:00 today.', icon:'clock', color:'blue', read:false, time:dayOffset(0) },
    { id:'N-002', title:'New citizen feedback submitted', body:'A high-priority complaint about drainage was received.', icon:'message-square', color:'amber', read:false, time:dayOffset(-1) },
    { id:'N-003', title:'Ordinance awaiting your review', body:'Ordinance No. 2024-003 (Traffic Management) is in committee review.', icon:'file-text', color:'primary', read:false, time:dayOffset(-2) },
    { id:'N-004', title:'Public hearing scheduled', body:'Public Hearing on the Traffic Management Code is set for this week.', icon:'mic', color:'emerald', read:true, time:dayOffset(-3) },
    { id:'N-005', title:'Research report completed', body:'Impact Assessment for the plastic ban is now available.', icon:'flask-conical', color:'primary', read:true, time:dayOffset(-5) },
  ];

  /* ----------------------- Activities ----------------------- */
  const activities = [
    { id:'ACT-001', action:'create', collection:'feedback', label:'Drainage complaint at Mabini-Bonifacio', time:dayOffset(-1), user:'Citizen Portal' },
    { id:'ACT-002', action:'update', collection:'ordinances', label:'Ordinance No. 2024-003 moved to Committee Review', time:dayOffset(-2), user:'Hon. R. Almazan' },
    { id:'ACT-003', action:'create', collection:'sessions', label:'42nd Regular Session scheduled for today', time:dayOffset(-3), user:'Office of the Secretary' },
    { id:'ACT-004', action:'create', collection:'research', label:'Benchmarking: City Scholarship Programs completed', time:dayOffset(-5), user:'Research Division' },
    { id:'ACT-005', action:'create', collection:'hearings', label:'Public Hearing — Traffic Management Code scheduled', time:dayOffset(-6), user:'Office of the Secretary' },
  ];

  /* ----------------------- Settings ----------------------- */
  const settings = [
    { id:'SET-001', darkMode:false, density:'comfortable', notifications:true, theme:'blue', language:'English', fiscalYear:2024, orgName:'City Legislative Council' }
  ];

  writeKey('councilMembers', councilMembers);
  writeKey('committees', committees);
  writeKey('committeeMembers', committeeMembers);
  writeKey('ordinances', ordinances);
  writeKey('resolutions', resolutions);
  writeKey('sessions', sessions);
  writeKey('agenda', agenda);
  writeKey('votes', votes);
  writeKey('records', records);
  writeKey('hearings', hearings);
  writeKey('archives', archives);
  writeKey('research', research);
  writeKey('feedback', feedback);
  writeKey('notifications', notifications);
  writeKey('activities', activities);
  writeKey('settings', settings);
}


// ===== ui.js =====
/* ==========================================================================
   ui.js — Reusable UI Components & Helpers
   One cohesive component library used by every page so the app feels unified.
   ========================================================================== */



/* ----------------------------- icons ----------------------------- */
function icon(name, cls = 'w-5 h-5') {
  return `<i data-lucide="${name}" class="${cls}"></i>`;
}
function renderIcons() {
  if (window.lucide) window.lucide.createIcons();
}

/* ----------------------------- status helpers ----------------------------- */
const STATUS_STYLES = {
  // ordinances / resolutions
  'Drafting':        'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
  'Pending Review':  'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
  'Committee Review':'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
  'Approved':        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Enacted':         'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
  'Adopted':         'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
  'Published':       'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  // sessions
  'Scheduled':       'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
  'In Progress':     'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Concluded':       'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
  // agenda priority
  'High':            'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
  'Critical':        'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
  'Medium':          'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
  'Low':             'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
  // generic
  'Pending Validation':'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
  'Validated':       'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Under Review':    'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
  'Acknowledged':    'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
  'Completed':       'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'In Progress':     'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Active':          'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Archived':        'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
  'Restored':        'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
  'Final':           'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
  'Passed':          'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Unanimous':       'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
  'Failed':          'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

function badge(status, extra = '') {
  const cls = STATUS_STYLES[status] || 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300';
  return `<span class="badge ${cls} ${extra}">${status}</span>`;
}

/* ----------------------------- card ----------------------------- */
function card({ title, subtitle, icon: iconName, action, body, className = '', headerClass = '', bodyClass = '' }) {
  return `
    <section class="bg-white dark:bg-slate-900 rounded-xl shadow-card border border-slate-200 dark:border-slate-800 ${className}">
      ${title ? `
        <header class="flex items-center justify-between gap-3 px-5 py-4 border-b border-slate-100 dark:border-slate-800 ${headerClass}">
          <div class="flex items-center gap-3 min-w-0">
            ${iconName ? `<span class="w-9 h-9 rounded-lg bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-300 flex items-center justify-center shrink-0">${icon(iconName,'w-5 h-5')}</span>` : ''}
            <div class="min-w-0">
              <h3 class="font-semibold text-slate-800 dark:text-slate-100 truncate">${title}</h3>
              ${subtitle ? `<p class="text-xs text-slate-500 dark:text-slate-400 truncate">${subtitle}</p>` : ''}
            </div>
          </div>
          ${action || ''}
        </header>` : ''}
      <div class="${title ? 'p-5' : 'p-5'} ${bodyClass}">${body}</div>
    </section>`;
}

/* ----------------------------- stat card ----------------------------- */
function statCard({ label, value, icon: iconName, trend, trendUp = true, color = 'primary', sub }) {
  const colors = {
    primary: 'from-primary-500 to-primary-700',
    emerald: 'from-emerald-500 to-emerald-700',
    amber:   'from-amber-500 to-amber-600',
    red:     'from-red-500 to-red-600',
    slate:   'from-slate-500 to-slate-700',
    indigo:  'from-indigo-500 to-indigo-700',
  };
  return `
    <div class="bg-white dark:bg-slate-900 rounded-xl shadow-card border border-slate-200 dark:border-slate-800 p-5 ls-card-hover">
      <div class="flex items-start justify-between">
        <div>
          <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">${label}</p>
          <p class="text-2xl font-bold text-slate-800 dark:text-white mt-1">${value}</p>
          ${sub ? `<p class="text-xs text-slate-400 mt-1">${sub}</p>` : ''}
        </div>
        <div class="w-11 h-11 rounded-xl bg-gradient-to-br ${colors[color]} flex items-center justify-center text-white shadow-md">${icon(iconName,'w-5 h-5')}</div>
      </div>
      ${trend ? `<div class="mt-3 flex items-center gap-1.5 text-xs ${trendUp ? 'text-emerald-600' : 'text-red-600'}">
        ${icon(trendUp ? 'trending-up' : 'trending-down','w-4 h-4')}<span class="font-medium">${trend}</span>
      </div>` : ''}
    </div>`;
}

/* ----------------------------- button ----------------------------- */
function button({ label, icon: iconName, variant = 'primary', size = 'md', onclick = '', type = 'button', extra = '', title = '' }) {
  const variants = {
    primary:  'bg-primary-600 hover:bg-primary-700 text-white shadow-sm',
    secondary:'bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200',
    outline:  'border border-slate-300 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200',
    ghost:    'hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300',
    danger:   'bg-red-600 hover:bg-red-700 text-white shadow-sm',
    success:  'bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm',
    warning:  'bg-amber-500 hover:bg-amber-600 text-white shadow-sm',
  };
  const sizes = { sm:'px-2.5 py-1.5 text-xs gap-1.5', md:'px-3.5 py-2 text-sm gap-2', lg:'px-5 py-2.5 text-base gap-2' };
  return `<button type="${type}" ${onclick ? `onclick="${onclick}"` : ''} title="${title}" class="inline-flex items-center justify-center rounded-lg font-medium transition ls-focus ${variants[variant]} ${sizes[size]} ${extra}">${iconName ? icon(iconName, size==='sm'?'w-3.5 h-3.5':'w-4 h-4') : ''}<span>${label}</span></button>`;
}

/* ----------------------------- breadcrumbs ----------------------------- */
function breadcrumbs(items) {
  return items.map((it, i) => {
    const last = i === items.length - 1;
    return `${last
      ? `<span class="font-medium text-slate-700 dark:text-slate-200 truncate">${it.label}</span>`
      : `<a href="#${it.path}" class="hover:text-primary-600 transition truncate">${it.label}</a>`}
    ${!last ? '<i data-lucide="chevron-right" class="w-3.5 h-3.5 text-slate-300"></i>' : ''}`;
  }).join('');
}

/* ----------------------------- page header ----------------------------- */
function pageHeader({ title, subtitle, icon: iconName, actions }) {
  return `
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
      <div class="flex items-center gap-3">
        ${iconName ? `<span class="w-11 h-11 rounded-xl bg-gradient-to-br from-primary-500 to-primary-700 text-white flex items-center justify-center shadow-md">${icon(iconName,'w-6 h-6')}</span>` : ''}
        <div>
          <h1 class="text-xl sm:text-2xl font-bold text-slate-800 dark:text-white tracking-tight">${title}</h1>
          ${subtitle ? `<p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">${subtitle}</p>` : ''}
        </div>
      </div>
      ${actions ? `<div class="flex items-center gap-2 flex-wrap">${actions}</div>` : ''}
    </div>`;
}

/* ----------------------------- table ----------------------------- */
function table({ columns, rows, empty = 'No records found.', className = '' }) {
  if (!rows || rows.length === 0) {
    return emptyState({ icon: 'inbox', title: empty, subtitle: 'Records will appear here once available.' });
  }
  return `
    <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800 ${className}">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-800/60 text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">
          <tr>${columns.map(c => `<th class="${c.align==='right'?'text-right':'text-left'} px-4 py-3 font-semibold ${c.width||''}">${c.label}</th>`).join('')}</tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
          ${rows.map(r => `<tr class="ls-row-hover transition">${r.map((cell, i) => `<td class="px-4 py-3 ${columns[i]?.align==='right'?'text-right':''} ${columns[i]?.cellClass||''}">${cell}</td>`).join('')}</tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}

/* ----------------------------- empty state ----------------------------- */
function emptyState({ icon: iconName = 'inbox', title = 'Nothing here yet', subtitle = '', action = '' }) {
  return `
    <div class="flex flex-col items-center justify-center text-center py-12 px-4">
      <div class="w-16 h-16 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 mb-4">${icon(iconName,'w-8 h-8')}</div>
      <h3 class="font-semibold text-slate-700 dark:text-slate-200">${title}</h3>
      ${subtitle ? `<p class="text-sm text-slate-500 dark:text-slate-400 mt-1 max-w-sm">${subtitle}</p>` : ''}
      ${action ? `<div class="mt-4">${action}</div>` : ''}
    </div>`;
}

/* ----------------------------- skeleton ----------------------------- */
function skeleton(rows = 5) {
  return Array.from({length: rows}).map(() =>
    `<div class="flex items-center gap-4 p-4">${['w-1/6','w-2/5','w-1/5','w-1/6','w-1/6'].map(w => `<div class="ls-skeleton h-4 ${w}"></div>`).join('')}</div>`
  ).join('');
}

/* ----------------------------- toast ----------------------------- */
function toast(message, type = 'success', duration = 3200) {
  const container = document.getElementById('ls-toast-container');
  if (!container) return;
  const styles = {
    success: { bg:'bg-emerald-600', icon:'check-circle-2' },
    error:   { bg:'bg-red-600', icon:'x-circle' },
    warning: { bg:'bg-amber-500', icon:'alert-triangle' },
    info:    { bg:'bg-primary-600', icon:'info' },
  };
  const s = styles[type] || styles.info;
  const el = document.createElement('div');
  el.className = `${s.bg} text-white rounded-xl shadow-xl px-4 py-3 flex items-center gap-3 text-sm font-medium animate-slide-up`;
  el.innerHTML = `${icon(s.icon,'w-5 h-5 shrink-0')}<span class="flex-1">${message}</span><button class="opacity-70 hover:opacity-100">${icon('x','w-4 h-4')}</button>`;
  container.appendChild(el);
  renderIcons();
  const close = () => { el.style.transition='opacity .3s, transform .3s'; el.style.opacity='0'; el.style.transform='translateX(20px)'; setTimeout(()=>el.remove(),300); };
  el.querySelector('button').onclick = close;
  setTimeout(close, duration);
}

/* ----------------------------- modal ----------------------------- */
function modal({ title, body, footer = '', size = 'md', onMount }) {
  const root = document.getElementById('ls-modal-root');
  const sizes = { sm:'max-w-md', md:'max-w-lg', lg:'max-w-2xl', xl:'max-w-4xl' };
  const id = 'modal-' + Date.now();
  root.innerHTML = `
    <div id="${id}" class="fixed inset-0 z-[10000] flex items-center justify-center p-4 ls-modal-backdrop bg-slate-900/60 backdrop-blur-sm">
      <div class="ls-modal-panel w-full ${sizes[size]} bg-white dark:bg-slate-900 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 max-h-[90vh] flex flex-col">
        <header class="flex items-center justify-between px-5 py-4 border-b border-slate-100 dark:border-slate-800 shrink-0">
          <h3 class="font-semibold text-slate-800 dark:text-white text-lg">${title}</h3>
          <button data-close class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500">${icon('x','w-5 h-5')}</button>
        </header>
        <div class="p-5 overflow-y-auto flex-1">${body}</div>
        ${footer ? `<footer class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-2 shrink-0">${footer}</footer>` : ''}
      </div>
    </div>`;
  renderIcons();
  const m = document.getElementById(id);
  const close = () => { root.innerHTML = ''; };
  m.querySelector('[data-close]').onclick = close;
  m.addEventListener('click', e => { if (e.target === m) close(); });
  document.addEventListener('keydown', function esc(e){ if(e.key==='Escape'){ close(); document.removeEventListener('keydown', esc); } });
  if (onMount) onMount(m);
  return { close, el: m };
}

function confirmDialog({ title = 'Are you sure?', message, confirmLabel = 'Confirm', variant = 'danger', onConfirm }) {
  const m = modal({
    title, size: 'sm',
    body: `<p class="text-sm text-slate-600 dark:text-slate-300">${message}</p>`,
    footer: `${button({label:'Cancel',variant:'secondary',size:'md',onclick:"this.closest('#'+this.closest('[id^=modal]').id).querySelector('[data-close]').click()"})}${button({label:confirmLabel,variant,onclick:"window.__lsConfirmYes()"})}`
  });
  window.__lsConfirmYes = () => { m.close(); onConfirm && onConfirm(); };
}

/* ----------------------------- form fields ----------------------------- */
function field({ label, name, type = 'text', value = '', placeholder = '', required = false, options = [], extra = '' }) {
  const req = required ? '<span class="text-red-500">*</span>' : '';
  let input = '';
  if (type === 'select') {
    input = `<select name="${name}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-100 dark:focus:ring-primary-900/40 transition ${extra}">
      <option value="">Select…</option>${options.map(o => `<option value="${o.value}" ${o.value===value?'selected':''}>${o.label}</option>`).join('')}
    </select>`;
  } else if (type === 'textarea') {
    input = `<textarea name="${name}" rows="${extra||4}" placeholder="${placeholder}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-100 dark:focus:ring-primary-900/40 transition">${value}</textarea>`;
  } else if (type === 'date') {
    input = `<input type="date" name="${name}" value="${value}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-100 dark:focus:ring-primary-900/40 transition">`;
  } else if (type === 'time') {
    input = `<input type="time" name="${name}" value="${value}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-100 dark:focus:ring-primary-900/40 transition">`;
  } else {
    input = `<input type="${type}" name="${name}" value="${value}" placeholder="${placeholder}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 focus:ring-2 focus:ring-primary-100 dark:focus:ring-primary-900/40 transition">`;
  }
  return `<label class="block"><span class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5">${label} ${req}</span>${input}</label>`;
}

function readForm(formEl) {
  const data = {};
  new FormData(formEl).forEach((v, k) => { data[k] = v; });
  return data;
}

/* ----------------------------- filters bar ----------------------------- */
function filterBar({ search = true, searchPlaceholder = 'Search…', selects = [], onSearch, right = '' }) {
  const selHtml = selects.map(s => `<select id="${s.id}" class="px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 transition">
    <option value="">${s.label}</option>${s.options.map(o=>`<option value="${o}">${o}</option>`).join('')}</select>`).join('');
  return `
    <div class="flex flex-col md:flex-row md:items-center gap-3 mb-5">
      ${search ? `<div class="relative flex-1 min-w-[200px]">
        ${icon('search','w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400')}
        <input id="ls-search-input" type="text" placeholder="${searchPlaceholder}" oninput="${onSearch}()" class="w-full pl-9 pr-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 transition" />
      </div>` : ''}
      ${selHtml}
      <div class="md:ml-auto">${right}</div>
    </div>`;
}

/* ----------------------------- tabs ----------------------------- */
function tabs(items, activeId, onchange) {
  return `<div class="flex items-center gap-1 border-b border-slate-200 dark:border-slate-800 mb-5 overflow-x-auto no-scrollbar">
    ${items.map(it => `<button onclick="${onchange}('${it.id}')" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition ${it.id===activeId ? 'border-primary-600 text-primary-600' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}">${it.label}${it.count!=null?` <span class="ml-1 text-xs ${it.id===activeId?'text-primary-500':'text-slate-400'}">(${it.count})</span>`:''}</button>`).join('')}
  </div>`;
}

/* ----------------------------- member lookup ----------------------------- */
function memberName(id) {
  const m = getById('councilMembers', id);
  return m ? m.name : 'Unknown';
}
function memberAvatar(id, size = 'w-8 h-8 text-xs') {
  const m = getById('councilMembers', id);
  if (!m) return '';
  const colors = ['from-primary-500 to-primary-700','from-emerald-500 to-emerald-700','from-amber-500 to-amber-600','from-indigo-500 to-indigo-700','from-rose-500 to-rose-700','from-cyan-500 to-cyan-700'];
  const c = colors[(m.id.charCodeAt(2)||0) % colors.length];
  return `<div class="${size} rounded-full bg-gradient-to-br ${c} flex items-center justify-center text-white font-semibold shrink-0" title="${m.name}">${m.avatar}</div>`;
}

function committeeName(id) {
  const c = getById('committees', id);
  return c ? c.name : '—';
}

/* ----------------------------- date helpers ----------------------------- */
function fmtDate(d) {
  if (!d) return '—';
  const date = new Date(d);
  return date.toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' });
}
function fmtDateLong(d) {
  if (!d) return '—';
  const date = new Date(d);
  return date.toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
}
function fmtTime(t) { return t || '—'; }
function relTime(d) {
  if (!d) return '';
  const diff = (new Date() - new Date(d)) / 1000;
  if (diff < 60) return 'just now';
  if (diff < 3600) return Math.floor(diff/60)+'m ago';
  if (diff < 86400) return Math.floor(diff/3600)+'h ago';
  if (diff < 604800) return Math.floor(diff/86400)+'d ago';
  return fmtDate(d);
}

/* ----------------------------- export CSV ----------------------------- */
function exportCSV(filename, rows, headers) {
  const head = headers.join(',');
  const body = rows.map(r => headers.map(h => {
    const v = r[h] ?? '';
    return `"${String(v).replace(/"/g,'""')}"`;
  }).join(',')).join('\n');
  const blob = new Blob([head+'\n'+body], { type:'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = filename; a.click();
  URL.revokeObjectURL(url);
  toast('Exported '+filename, 'success');
}

/* ----------------------------- print ----------------------------- */
function printPage() {
  window.print();
}

/* ----------------------------- AI insight block ----------------------------- */
function aiInsight({ title = 'AI Insight', body, icon: iconName = 'sparkles' }) {
  return `
    <div class="rounded-xl border border-primary-200 dark:border-primary-800/60 bg-gradient-to-br from-primary-50 to-indigo-50 dark:from-primary-900/20 dark:to-indigo-900/20 p-4">
      <div class="flex items-start gap-3">
        <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-primary-500 to-indigo-600 text-white flex items-center justify-center shrink-0">${icon(iconName,'w-4 h-4')}</span>
        <div class="min-w-0">
          <p class="text-xs font-semibold text-primary-700 dark:text-primary-300 uppercase tracking-wider flex items-center gap-1">${title} <span class="text-[10px] bg-primary-600 text-white px-1.5 py-0.5 rounded">BETA</span></p>
          <p class="text-sm text-slate-700 dark:text-slate-200 mt-1.5 leading-relaxed">${body}</p>
        </div>
      </div>
    </div>`;
}

/* ----------------------------- section divider ----------------------------- */
function sectionTitle(title, subtitle, action) {
  return `<div class="flex items-center justify-between gap-3 mb-3 mt-1">
    <div><h2 class="font-semibold text-slate-700 dark:text-slate-200 text-sm uppercase tracking-wider">${title}</h2>${subtitle?`<p class="text-xs text-slate-400 mt-0.5">${subtitle}</p>`:''}</div>
    ${action||''}
  </div>`;
}


// ===== charts.js =====
/* ==========================================================================
   charts.js — Chart.js wrappers with unified LSMS styling
   All charts auto-destroy on re-render to prevent canvas reuse errors.
   ========================================================================== */

const chartRegistry = new Map();

function destroyChart(id) {
  if (chartRegistry.has(id)) { chartRegistry.get(id).destroy(); chartRegistry.delete(id); }
}

function destroyAll() {
  chartRegistry.forEach(c => c.destroy());
  chartRegistry.clear();
}

function baseOpts(extra = {}) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: true, labels: { font: { family: 'Inter', size: 12 }, color: '#64748b', usePointStyle: true, pointStyle: 'circle', padding: 16 } },
      tooltip: { backgroundColor:'#0f172a', titleFont:{family:'Inter'}, bodyFont:{family:'Inter'}, padding:12, cornerRadius:8, usePointStyle:true }
    },
    ...extra
  };
}

function lineChart(id, labels, datasets, opts = {}) {
  destroyChart(id);
  const el = document.getElementById(id);
  if (!el) return null;
  const chart = new Chart(el, {
    type: 'line',
    data: { labels, datasets: datasets.map(d => ({ tension:.4, fill:true, borderWidth:2, pointRadius:3, pointHoverRadius:5, ...d })) },
    options: baseOpts({
      scales: {
        x: { grid: { display:false }, ticks: { color:'#94a3b8', font:{family:'Inter',size:11} } },
        y: { grid: { color:'#f1f5f9' }, ticks: { color:'#94a3b8', font:{family:'Inter',size:11} }, beginAtZero: true }
      },
      ...opts
    })
  });
  chartRegistry.set(id, chart);
  return chart;
}

function barChart(id, labels, datasets, opts = {}) {
  destroyChart(id);
  const el = document.getElementById(id);
  if (!el) return null;
  const chart = new Chart(el, {
    type: 'bar',
    data: { labels, datasets: datasets.map(d => ({ borderRadius:6, borderSkipped:false, maxBarThickness:48, ...d })) },
    options: baseOpts({
      scales: {
        x: { grid: { display:false }, ticks: { color:'#94a3b8', font:{family:'Inter',size:11} } },
        y: { grid: { color:'#f1f5f9' }, ticks: { color:'#94a3b8', font:{family:'Inter',size:11} }, beginAtZero: true }
      },
      ...opts
    })
  });
  chartRegistry.set(id, chart);
  return chart;
}

function doughnutChart(id, labels, data, colors, opts = {}) {
  destroyChart(id);
  const el = document.getElementById(id);
  if (!el) return null;
  const chart = new Chart(el, {
    type: 'doughnut',
    data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth:0, hoverOffset:8 }] },
    options: baseOpts({ cutout:'65%', plugins:{ legend:{ position:'right', labels:{ font:{family:'Inter',size:11}, color:'#64748b', usePointStyle:true, padding:12 } }, tooltip: baseOpts().plugins.tooltip }, ...opts })
  });
  chartRegistry.set(id, chart);
  return chart;
}

function radarChart(id, labels, datasets, opts = {}) {
  destroyChart(id);
  const el = document.getElementById(id);
  if (!el) return null;
  const chart = new Chart(el, {
    type: 'radar',
    data: { labels, datasets: datasets.map(d => ({ fill:true, borderWidth:2, pointRadius:3, ...d })) },
    options: baseOpts({
      scales: { r: { beginAtZero:true, max:100, grid:{ color:'#e2e8f0' }, angleLines:{ color:'#e2e8f0' }, pointLabels:{ font:{family:'Inter',size:11}, color:'#64748b' }, ticks:{ display:false } } },
      plugins:{ legend:{ position:'bottom', labels:{ font:{family:'Inter',size:11}, color:'#64748b', usePointStyle:true, padding:12 } }, tooltip: baseOpts().plugins.tooltip },
      ...opts
    })
  });
  chartRegistry.set(id, chart);
  return chart;
}

function horizontalBarChart(id, labels, data, color = '#2563eb', opts = {}) {
  destroyChart(id);
  const el = document.getElementById(id);
  if (!el) return null;
  const chart = new Chart(el, {
    type: 'bar',
    data: { labels, datasets: [{ data, backgroundColor: color, borderRadius:6, barThickness:18 }] },
    options: baseOpts({
      indexAxis: 'y',
      plugins: { legend: { display:false }, tooltip: baseOpts().plugins.tooltip },
      scales: {
        x: { grid:{ color:'#f1f5f9' }, ticks:{ color:'#94a3b8', font:{family:'Inter',size:11} }, beginAtZero:true },
        y: { grid:{ display:false }, ticks:{ color:'#64748b', font:{family:'Inter',size:11} } }
      },
      ...opts
    })
  });
  chartRegistry.set(id, chart);
  return chart;
}

/* Color palettes */
const PALETTE = ['#2563eb','#059669','#d97706','#dc2626','#7c3aed','#0891b2','#db2777','#65a30d','#ea580c','#4f46e5'];
const STATUS_COLORS = { 'Enacted':'#059669','Approved':'#2563eb','Pending Review':'#d97706','Committee Review':'#7c3aed','Drafting':'#94a3b8','Published':'#10b981','Adopted':'#2563eb' };


// ===== dashboard.js =====
{ // page scope
/* ==========================================================================
   pages/dashboard.js — Executive Dashboard
   ========================================================================== */




function renderDashboard(main, route) {
  const ordinances = getAll('ordinances');
  const resolutions = getAll('resolutions');
  const sessions = getAll('sessions');
  const committees = getAll('committees');
  const feedback = getAll('feedback');
  const hearings = getAll('hearings');
  const activities = getAll('activities');
  const notifications = getAll('notifications');
  const agenda = getAll('agenda');
  const members = getAll('councilMembers');

  const pendingOrd = ordinances.filter(o => ['Pending Review','Committee Review','Drafting'].includes(o.status)).length;
  const pendingRes = resolutions.filter(r => ['Pending Review','Drafting'].includes(r.status)).length;
  const todaySessions = sessions.filter(s => s.date && new Date(s.date).toDateString() === new Date().toDateString());
  const upcomingMeetings = sessions.filter(s => s.status === 'Scheduled').length;
  const openFeedback = feedback.filter(f => !['Acknowledged','Validated'].includes(f.status)).length;

  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';

  main.innerHTML = `
    ${pageHeader({
      title: `${greeting}, Hon. Almazan`,
      subtitle: new Date().toLocaleDateString('en-US',{weekday:'long', year:'numeric', month:'long', day:'numeric'}) + ' · City Legislative Council',
      icon: 'layout-dashboard',
      actions: button({label:'Today\'s Session', icon:'calendar-clock', variant:'primary', onclick:"location.hash='#/sessions'"}) +
              button({label:'Export Report', icon:'download', variant:'outline', onclick:"window.__expDashboard()"})
    })}

    <!-- AI Insight -->
    <div class="mb-6">${aiInsight({
      title:'Legislative Intelligence — Daily Brief',
      body:`You have <b>${pendingOrd} ordinances</b> and <b>${pendingRes} resolutions</b> pending action. The 42nd Regular Session is active today with <b>${todaySessions[0]?.agendaCount||0} agenda items</b>. Committee on Laws & Ordinances reports the highest workload (92%). ${openFeedback} citizen feedback items require attention, including 1 critical drainage complaint.`
    })}</div>

    <!-- Stats -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      ${statCard({label:'Pending Ordinances', value:pendingOrd, icon:'scale', color:'primary', trend:'2 new this week', trendUp:true, sub:'Across 3 stages'})}
      ${statCard({label:'Pending Resolutions', value:pendingRes, icon:'file-text', color:'amber', trend:'1 awaiting review', trendUp:false, sub:'2 in drafting'})}
      ${statCard({label:'Sessions Today', value:todaySessions.length, icon:'calendar-clock', color:'emerald', trend:'On schedule', trendUp:true, sub:`${todaySessions[0]?.time||'—'} · ${todaySessions[0]?.venue||'—'}`})},
      ${statCard({label:'Open Citizen Feedback', value:openFeedback, icon:'message-square', color:'red', trend:'1 critical', trendUp:false, sub:'2 pending validation'})}
    </div>

    <!-- Charts row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Legislative Output', subtitle:'Ordinances & resolutions — last 6 months', icon:'bar-chart-3', className:'lg:col-span-2',
        body:`<div class="ls-chart-wrap h-72"><canvas id="chartOutput"></canvas></div>`})}
      ${card({title:'Ordinance Status', subtitle:'Current distribution', icon:'pie-chart',
        body:`<div class="ls-chart-wrap h-72"><canvas id="chartOrdStatus"></canvas></div>`})}
    </div>

    <!-- Two-column content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
      <!-- Left 2 cols -->
      <div class="lg:col-span-2 space-y-4">
        ${card({title:'Pending Ordinances', subtitle:'Require your attention', icon:'scale',
          action:`<a href="#/ordinances" class="text-xs text-primary-600 font-medium hover:underline">View all</a>`,
          body:`<div class="space-y-3">${ordinances.filter(o=>['Pending Review','Committee Review','Drafting'].includes(o.status)).slice(0,4).map(o=>`
            <div class="flex items-center gap-3 p-3 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800/60 transition cursor-pointer" onclick="location.hash='#/ordinances'">
              <span class="w-10 h-10 rounded-lg bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-300 flex items-center justify-center shrink-0">${icon('scroll-text','w-5 h-5')}</span>
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">${o.title}</p>
                <p class="text-xs text-slate-500 mt-0.5">${o.number} · ${committeeName(o.committeeId)} · ${fmtDate(o.dateIntroduced)}</p>
              </div>
              ${badge(o.status)}
            </div>`).join('') || '<p class="text-sm text-slate-400 text-center py-6">All ordinances processed 🎉</p>'}</div>`})}

        ${card({title:'Committee Performance', subtitle:'Workload & activity index', icon:'users',
          body:`<div class="ls-chart-wrap h-64"><canvas id="chartCommittee"></canvas></div>`})}
      </div>

      <!-- Right col -->
      <div class="space-y-4">
        ${card({title:'Today\'s Session', icon:'radio', headerClass:todaySessions.length?'border-emerald-200 dark:border-emerald-800':'',
          body: todaySessions.length ? `
            <div class="flex items-center gap-2 mb-3">${badge('In Progress')}<span class="text-xs text-slate-400">Live</span></div>
            <h4 class="font-semibold text-slate-800 dark:text-white">${todaySessions[0].title}</h4>
            <div class="mt-3 space-y-2 text-sm">
              <p class="flex items-center gap-2 text-slate-600 dark:text-slate-300">${icon('clock','w-4 h-4 text-slate-400')} ${fmtTime(todaySessions[0].time)}</p>
              <p class="flex items-center gap-2 text-slate-600 dark:text-slate-300">${icon('map-pin','w-4 h-4 text-slate-400')} ${todaySessions[0].venue}</p>
              <p class="flex items-center gap-2 text-slate-600 dark:text-slate-300">${icon('list-checks','w-4 h-4 text-slate-400')} ${todaySessions[0].agendaCount} agenda items</p>
              <p class="flex items-center gap-2 text-slate-600 dark:text-slate-300">${icon('users','w-4 h-4 text-slate-400')} ${todaySessions[0].attendance.filter(a=>a.status==='present'||a.status==='late').length} of ${members.length} present</p>
            </div>
            <div class="mt-4">${button({label:'Open Session', icon:'arrow-right', variant:'primary', size:'sm', onclick:"location.hash='#/sessions'"})}</div>
          ` : `<p class="text-sm text-slate-400 text-center py-4">No session scheduled today.</p>`})}

        ${card({title:'Upcoming Meetings', icon:'calendar',
          body:`<div class="space-y-3">${sessions.filter(s=>s.status==='Scheduled').slice(0,3).map(s=>`
            <div class="flex items-center gap-3">
              <div class="w-12 h-12 rounded-lg bg-slate-100 dark:bg-slate-800 flex flex-col items-center justify-center shrink-0">
                <span class="text-[10px] uppercase text-slate-400 font-semibold">${new Date(s.date).toLocaleDateString('en-US',{month:'short'})}</span>
                <span class="text-lg font-bold text-slate-700 dark:text-slate-200 leading-none">${new Date(s.date).getDate()}</span>
              </div>
              <div class="min-w-0 flex-1"><p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">${s.title}</p><p class="text-xs text-slate-500">${fmtTime(s.time)} · ${s.venue}</p></div>
            </div>`).join('')||'<p class="text-sm text-slate-400 text-center py-2">No upcoming meetings.</p>'}</div>`})}

        ${card({title:'Attendance Summary', icon:'user-check',
          body:`<div class="ls-chart-wrap h-48"><canvas id="chartAttendance"></canvas></div>
                <p class="text-xs text-slate-400 mt-3 text-center">Average attendance this quarter: <b class="text-slate-600 dark:text-slate-300">88%</b></p>`})}
      </div>
    </div>

    <!-- Bottom row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Recent Activity', subtitle:'Latest system events', icon:'activity', className:'lg:col-span-2',
        body:`<div class="space-y-2.5">${activities.slice(0,6).map(a=>{
          const aIcon = a.action==='create'?'plus-circle':a.action==='update'?'refresh-cw':'trash-2';
          const aColor = a.action==='create'?'text-emerald-600':a.action==='update'?'text-primary-600':'text-red-600';
          return `<div class="flex items-center gap-3 py-1.5"><span class="${aColor}">${icon(aIcon,'w-4 h-4')}</span><p class="text-sm text-slate-700 dark:text-slate-200 flex-1 truncate">${a.label}</p><span class="text-xs text-slate-400 shrink-0">${relTime(a.time)}</span></div>`;
        }).join('')}</div>`})}

      ${card({title:'Announcements', icon:'megaphone',
        body:`<div class="space-y-3">
          <div class="p-3 rounded-lg bg-primary-50 dark:bg-primary-900/20 border border-primary-100 dark:border-primary-800/40">
            <p class="text-sm font-medium text-primary-800 dark:text-primary-200">FY 2025 Budget Deliberations</p>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Special session scheduled in 3 days. Committee on Finance to submit proposed allocations.</p>
          </div>
          <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800/40">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">Public Hearing — Traffic Code</p>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Scheduled in 5 days. Stakeholder registration ongoing.</p>
          </div>
          <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-100 dark:border-emerald-800/40">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">Scholarship Ordinance Approved</p>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Implementation guidelines to be drafted by Committee on Education.</p>
          </div>
        </div>`})}
    </div>

    <!-- Quick actions -->
    ${sectionTitle('Quick Actions', 'Jump to common workflows')}
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-3 mb-6">
      ${[
        {label:'New Ordinance', icon:'file-plus', hash:'#/ordinances', color:'primary'},
        {label:'Schedule Session', icon:'calendar-plus', hash:'#/sessions', color:'emerald'},
        {label:'Record Vote', icon:'vote', hash:'#/voting', color:'indigo'},
        {label:'Log Feedback', icon:'message-square-plus', hash:'#/engagement', color:'amber'},
        {label:'View Reports', icon:'file-bar-chart', hash:'#/reports', color:'slate'},
      ].map(qa=>{
        const colors = {primary:'text-primary-600 bg-primary-50 dark:bg-primary-900/30 dark:text-primary-300',emerald:'text-emerald-600 bg-emerald-50 dark:bg-emerald-900/30 dark:text-emerald-300',indigo:'text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30 dark:text-indigo-300',amber:'text-amber-600 bg-amber-50 dark:bg-amber-900/30 dark:text-amber-300',slate:'text-slate-600 bg-slate-100 dark:bg-slate-800 dark:text-slate-300'};
        return `<a href="${qa.hash}" class="flex flex-col items-center gap-2 p-4 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 ls-card-hover text-center">
          <span class="w-10 h-10 rounded-lg ${colors[qa.color]} flex items-center justify-center">${icon(qa.icon,'w-5 h-5')}</span>
          <span class="text-xs font-medium text-slate-700 dark:text-slate-200">${qa.label}</span>
        </a>`;
      }).join('')}
    </div>
  `;

  renderIcons();
  drawCharts(ordinances, committees, sessions);
}

function drawCharts(ordinances, committees, sessions) {
  // Legislative output (mock 6-month trend)
  lineChart('chartOutput',
    ['Jul','Aug','Sep','Oct','Nov','Dec'],
    [
      { label:'Ordinances', data:[3,5,4,6,7,6], borderColor:'#2563eb', backgroundColor:'rgba(37,99,235,.12)' },
      { label:'Resolutions', data:[6,8,5,9,7,8], borderColor:'#059669', backgroundColor:'rgba(5,150,105,.12)' }
    ],
    { plugins:{ legend:{ position:'top' } } }
  );

  // Ordinance status doughnut
  const statusCounts = {};
  ordinances.forEach(o => { statusCounts[o.status] = (statusCounts[o.status]||0)+1; });
  const sLabels = Object.keys(statusCounts);
  doughnutChart('chartOrdStatus', sLabels, sLabels.map(s=>statusCounts[s]), sLabels.map(s=>STATUS_COLORS[s]||'#94a3b8'));

  // Committee workload
  barChart('chartCommittee',
    committees.map(c=>c.name.replace(' Committee','').replace('& Appropriations','& Approp.')),
    [{ label:'Workload %', data:committees.map(c=>c.workload), backgroundColor:PALETTE.slice(0,committees.length), borderRadius:6 }],
    { plugins:{ legend:{ display:false } }, scales:{ y:{ max:100 } } }
  );

  // Attendance doughnut
  const concluded = sessions.filter(s=>s.status==='Concluded');
  let present=0, late=0, absent=0;
  concluded.forEach(s=>s.attendance.forEach(a=>{ if(a.status==='present')present++; else if(a.status==='late')late++; else absent++; }));
  doughnutChart('chartAttendance', ['Present','Late','Absent'], [present,late,absent], ['#059669','#d97706','#94a3b8'], { cutout:'60%', plugins:{ legend:{ position:'bottom' } } });
}

window.__expDashboard = function() {
  const ords = getAll('ordinances');
  exportCSV('dashboard-ordinances.csv', ords, ['number','title','status','category','dateIntroduced','dateApproved']);
};

window.__export_dashboard = window.__export_dashboard || {};
window.__export_dashboard.renderDashboard = renderDashboard;
} // end page scope

// ===== ordinances.js =====
{ // page scope
/* ==========================================================================
   pages/ordinances.js — Ordinance & Resolution Lifecycle Management (Module 1)
   Drafting → review → committee → approval → publication → monitoring
   Includes version history, AI summarization, search/filter/export/print.
   ========================================================================== */



let activeTab = 'ordinances';
let lastSearch = '', lastStatus = '', lastCategory = '';

function renderOrdinances(main, route) {
  main.innerHTML = `
    ${pageHeader({
      title:'Ordinance & Resolution Lifecycle',
      subtitle:'Draft, review, endorse, approve, publish, and monitor legislative measures',
      icon:'scale',
      actions: button({label:'New Ordinance', icon:'file-plus', variant:'primary', onclick:"window.__openOrdModal()"}) +
               button({label:'Export', icon:'download', variant:'outline', onclick:"window.__expOrd()"})
    })}

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      ${ordStats()}
    </div>

    <div id="ord-tabs"></div>

    <div id="ord-content"></div>
  `;
  renderIcons();
  drawTabs();
  drawContent();
}

function ordStats() {
  const o = getAll('ordinances');
  const r = getAll('resolutions');
  return statCard({label:'Total Ordinances', value:o.length, icon:'scroll-text', color:'primary', sub:`${o.filter(x=>x.status==='Enacted').length} enacted`}) +
         statCard({label:'Total Resolutions', value:r.length, icon:'file-text', color:'emerald', sub:`${r.filter(x=>x.status==='Adopted').length} adopted`}) +
         statCard({label:'In Committee Review', value:[...o,...r].filter(x=>x.status==='Pending Review').length, icon:'git-commit', color:'amber', sub:'Awaiting endorsement'}) +
         statCard({label:'Avg. Days to Enact', value:'48', icon:'timer', color:'slate', sub:'From introduction to publication', trend:'-12% vs last year', trendUp:true});
}

function drawTabs() {
  const o = getAll('ordinances');
  const r = getAll('resolutions');
  const el = document.getElementById('ord-tabs');
  el.innerHTML = tabs([
    { id:'ordinances', label:'Ordinances', count:o.length },
    { id:'resolutions', label:'Resolutions', count:r.length },
    { id:'lifecycle', label:'Lifecycle Workflow' }
  ], activeTab, 'window.__ordTab');
}

window.__ordTab = function(id){ activeTab = id; drawTabs(); drawContent(); };

function drawContent() {
  const el = document.getElementById('ord-content');
  if (activeTab === 'lifecycle') { el.innerHTML = lifecycleView(); renderIcons(); return; }

  const collection = activeTab === 'ordinances' ? 'ordinances' : 'resolutions';
  const records = filterRecords(collection);

  el.innerHTML = `
    ${filterBar({
      searchPlaceholder: 'Search by title, number, or author…',
      selects: [
        { id:'ord-status', label:'All Statuses', options:[...new Set(getAll(collection).map(r=>r.status))] },
        { id:'ord-category', label:'All Categories', options:[...new Set(getAll(collection).map(r=>r.category||'General'))] }
      ],
      onSearch: 'window.__ordSearch',
      right: button({label:activeTab==='ordinances'?'New Ordinance':'New Resolution', icon:'plus', size:'sm', variant:'primary', onclick:"window.__openOrdModal()"})
    })}
    ${records.length ? table({
      columns: [
        { label:'Number / Title' },
        { label:'Author' },
        { label:'Category' },
        { label:'Committee' },
        { label:'Date' },
        { label:'Status' },
        { label:'Actions', align:'right', width:'w-1' }
      ],
      rows: records.map(r => [
        `<div><p class="font-medium text-slate-800 dark:text-slate-100">${r.number}</p><p class="text-xs text-slate-500 line-clamp-1 max-w-xs">${r.title}</p></div>`,
        `<div class="flex items-center gap-2">${memberAvatar(r.author)}<span class="text-sm">${memberName(r.author)}</span></div>`,
        `<span class="text-xs text-slate-600 dark:text-slate-300">${r.category||'General'}</span>`,
        `<span class="text-xs text-slate-600 dark:text-slate-300">${committeeName(r.committeeId)}</span>`,
        `<span class="text-xs text-slate-500">${fmtDate(r.dateIntroduced)}</span>`,
        badge(r.status),
        `<div class="flex items-center justify-end gap-1">
          <button onclick="window.__viewOrd('${r.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="View">${icon('eye','w-4 h-4')}</button>
          <button onclick="window.__advanceOrd('${r.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="Advance Stage">${icon('git-commit-horizontal','w-4 h-4')}</button>
          <button onclick="window.__editOrd('${r.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-primary-600" title="Edit">${icon('pencil','w-4 h-4')}</button>
          <button onclick="window.__delOrd('${r.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button>
        </div>`
      ])
    }) : emptyState({ icon:'scale', title:'No measures found', subtitle:'Try adjusting filters or create a new one.', action: button({label:activeTab==='ordinances'?'New Ordinance':'New Resolution', icon:'plus', variant:'primary', onclick:"window.__openOrdModal()"}) })}
  `;
  renderIcons();
}

function filterRecords(collection) {
  let records = getAll(collection);
  if (lastSearch) {
    const q = lastSearch.toLowerCase();
    records = records.filter(r => (r.title+r.number+(r.category||'')).toLowerCase().includes(q));
  }
  if (lastStatus) records = records.filter(r => r.status === lastStatus);
  if (lastCategory) records = records.filter(r => (r.category||'General') === lastCategory);
  return records;
}

window.__ordSearch = function() {
  lastSearch = document.getElementById('ls-search-input')?.value || '';
  const ss = document.getElementById('ord-status'); if (ss) lastStatus = ss.value;
  const sc = document.getElementById('ord-category'); if (sc) lastCategory = sc.value;
  drawContent();
};

/* ----------------------- Lifecycle visual ----------------------- */
function lifecycleView() {
  const stages = ['Drafting','Committee Review','Pending Review','Approved','Published','Enacted'];
  const counts = stages.map(s => getAll('ordinances').filter(o=>o.stage===s).length + getAll('resolutions').filter(r=>r.status===s).length);
  return `
    ${card({title:'Legislative Lifecycle Workflow', subtitle:'Track measures through every stage from drafting to enactment', icon:'git-branch',
      body:`<div class="overflow-x-auto pb-2"><div class="flex items-center gap-1 min-w-[800px]">
        ${stages.map((s,i)=>`
          <div class="flex items-center">
            <div class="flex flex-col items-center w-32 text-center">
              <div class="w-12 h-12 rounded-full ${i===0?'bg-slate-300 text-white':i<3?'bg-amber-500 text-white':i<5?'bg-primary-600 text-white':'bg-emerald-600 text-white'} flex items-center justify-center font-bold">${i+1}</div>
              <p class="text-xs font-medium mt-2 text-slate-700 dark:text-slate-200">${s}</p>
              <span class="text-xs text-slate-400 mt-0.5">${counts[i]} items</span>
            </div>
            ${i<stages.length-1?`<div class="h-0.5 w-8 bg-gradient-to-r from-slate-300 to-slate-300 dark:from-slate-600 dark:to-slate-600"></div>`:''}
          </div>`).join('')}
      </div></div>`})}

    ${card({title:'How It Works', subtitle:'Simulated end-to-end workflow', icon:'info',
      body:`<div class="grid sm:grid-cols-2 gap-4 text-sm">
        ${[
          {icon:'file-edit',title:'1 · Drafting',body:'Author creates the measure; status = Drafting. Saved with a summary.'},
          {icon:'git-commit',title:'2 · Committee Review',body:'Assigned committee reviews and endorses; status advances automatically.'},
          {icon:'eye',title:'3 · Pending Review',body:'Placed on the session agenda for first/second reading.'},
          {icon:'check-circle',title:'4 · Approved',body:'Council approves on third reading; status = Approved.'},
          {icon:'newspaper',title:'5 · Published',body:'Published in the official gazette; previous version archived.'},
          {icon:'landmark',title:'6 · Enacted',body:'Takes effect; implementation monitored by the originating committee.'},
        ].map(s=>`<div class="flex gap-3"><span class="w-9 h-9 rounded-lg bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-300 flex items-center justify-center shrink-0">${icon(s.icon,'w-4 h-4')}</span><div><p class="font-semibold text-slate-800 dark:text-slate-100">${s.title}</p><p class="text-slate-600 dark:text-slate-300 mt-0.5">${s.body}</p></div></div>`).join('')}
      </div>`})}
  `;
}

/* ----------------------- Create / Edit modal ----------------------- */
window.__openOrdModal = function(id) {
  const isRes = activeTab === 'resolutions';
  const members = getAll('councilMembers');
  const committees = getAll('committees');
  const existing = id ? getById(isRes?'resolutions':'ordinances', id) : null;

  const m = modal({
    title: existing ? `Edit ${isRes?'Resolution':'Ordinance'}` : `New ${isRes?'Resolution':'Ordinance'}`,
    size: 'lg',
    body: `<form id="ord-form" class="space-y-4">
      ${field({label:'Measure Number', name:'number', value:existing?.number||(isRes?'RES-2024-':'ORD-2024-')+String(getAll(isRes?'resolutions':'ordinances').length+1).padStart(3,'0'), required:true, placeholder:'e.g. ORD-2024-006'})}
      ${field({label:'Title', name:'title', value:existing?.title||'', required:true, placeholder:'An Ordinance…'})}
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Author', name:'author', type:'select', value:existing?.author||'', required:true, options:members.map(mm=>({value:mm.id,label:mm.name}))})}
        ${field({label:'Category', name:'category', type:'select', value:existing?.category||'', options:['Environment','Education','Finance','Health','Transportation','Housing','Governance','Public Safety','Sanitation','Ceremonial','Economy'].map(c=>({value:c,label:c}))})}
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Committee', name:'committeeId', type:'select', value:existing?.committeeId||'', options:committees.map(c=>({value:c.id,label:c.name}))})}
        ${field({label:'Status', name:'status', type:'select', value:existing?.status||'Drafting', options:['Drafting','Pending Review','Committee Review','Approved','Enacted','Published','Adopted'].map(s=>({value:s,label:s}))})}
      </div>
      ${field({label:'Summary / Body', name:'summary', type:'textarea', value:existing?.summary||'', placeholder:'Brief description of the measure…'})}
      <div id="ord-ai-box"></div>
    </form>`,
    footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"}) +
            button({label:existing?'Update':'Save as Draft', variant:'primary', icon:'save', onclick:"window.__saveOrd('"+(id||'')+"')"})
  });
};

window.__saveOrd = function(id) {
  const form = document.getElementById('ord-form');
  const data = readForm(form);
  if (!data.title || !data.number) { toast('Title and number are required','error'); return; }
  const isRes = activeTab === 'resolutions';
  const coll = isRes ? 'resolutions' : 'ordinances';
  data.stage = isRes ? (data.status==='Adopted'?'Adopted':data.status) : data.status;
  if (!data.aiSummary) data.aiSummary = generateAISummary(data);
  if (id) { update(coll, id, data); toast('Updated successfully','success'); }
  else { insert(coll, { ...data, dateIntroduced: new Date().toISOString(), versions:1 }); toast('Created — status set to '+data.status,'success'); }
  document.querySelector('[id^=modal] [data-close]').click();
  drawTabs(); drawContent();
};

function generateAISummary(d) {
  const cat = d.category || 'general governance';
  return `This ${d.number.startsWith('RES')?'resolution':'ordinance'} addresses ${cat.toLowerCase()} matters${d.summary?': "'+d.summary.slice(0,120)+'…"':''}. Proposed by ${memberName(d.author||'M-001')}. ${d.committeeId?`Routed to the ${committeeName(d.committeeId)} for review.`:'No committee assigned yet.'} AI-generated summary for demonstration purposes.`;
}

/* ----------------------- View detail ----------------------- */
window.__viewOrd = function(id) {
  const isRes = activeTab==='resolutions';
  const r = getById(isRes?'resolutions':'ordinances', id);
  if (!r) return;
  const versions = Array.from({length: r.versions||1}, (_,i)=>({ v:`v${i+1}`, date: fmtDate(new Date(Date.now()-(r.versions-1-i)*86400000*7).toISOString()), author: memberName(r.author), notes: i===0?'Initial draft':i===(r.versions||1)-1?'Current version':'Revised after committee feedback' }));

  modal({
    title: r.number, size: 'xl',
    body: `
      <div class="flex items-center gap-2 mb-4">${badge(r.status)}${badge(r.category||'General','bg-slate-100 text-slate-600')}<span class="text-xs text-slate-400 ml-auto">Introduced ${fmtDate(r.dateIntroduced)}</span></div>
      <h2 class="text-lg font-bold text-slate-800 dark:text-white mb-2">${r.title}</h2>
      <div class="flex items-center gap-2 mb-4">${memberAvatar(r.author)}<div><p class="text-sm font-medium">${memberName(r.author)}</p><p class="text-xs text-slate-400">Sponsor</p></div></div>

      <div class="grid sm:grid-cols-2 gap-4 mb-4">
        ${card({title:'Details', icon:'info', body:`<dl class="text-sm space-y-2">
          <div class="flex justify-between"><dt class="text-slate-500">Committee</dt><dd class="font-medium">${committeeName(r.committeeId)}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Approved</dt><dd class="font-medium">${fmtDate(r.dateApproved)}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Published</dt><dd class="font-medium">${fmtDate(r.datePublished)}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Versions</dt><dd class="font-medium">${r.versions||1}</dd></div>
        </dl>`})}
        ${card({title:'Version History', icon:'git-commit-vertical', body:`<div class="space-y-2">${versions.map(v=>`<div class="flex items-center gap-2 text-sm"><span class="badge bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">${v.v}</span><span class="text-slate-600 dark:text-slate-300 flex-1 truncate">${v.notes}</span><span class="text-xs text-slate-400">${v.date}</span></div>`).join('')}</div>`})}
      </div>

      ${card({title:'Measure Summary', icon:'align-left', body:`<p class="text-sm text-slate-700 dark:text-slate-200 leading-relaxed">${r.summary||'No summary provided.'}</p>`})}
      ${r.aiSummary ? `<div class="mt-4">${aiInsight({title:'AI Summarization', body:r.aiSummary})}</div>` : ''}
    `,
    footer: button({label:'Print', icon:'printer', variant:'outline', onclick:'window.print()'}) +
            button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})
  });
};

/* ----------------------- Advance stage ----------------------- */
window.__advanceOrd = function(id) {
  const isRes = activeTab==='resolutions';
  const coll = isRes?'resolutions':'ordinances';
  const r = getById(coll, id);
  if (!r) return;
  const flow = isRes ? ['Drafting','Pending Review','Committee Review','Approved','Adopted'] : ['Drafting','Committee Review','Pending Review','Approved','Published','Enacted'];
  const idx = flow.indexOf(r.status);
  if (idx === -1 || idx === flow.length-1) { toast(r.status==='Enacted'||r.status==='Adopted'?'Already '+r.status:'Cannot advance','warning'); return; }
  const next = flow[idx+1];
  const patch = { status: next, stage: next };
  if (next === 'Approved') patch.dateApproved = new Date().toISOString();
  if (next === 'Published' || next === 'Enacted') patch.datePublished = new Date().toISOString();
  if ((next === 'Published' || next === 'Enacted') && r.versions) patch.versions = r.versions + 1;
  update(coll, id, patch);
  toast(`Advanced to "${next}"`,'success');
  drawTabs(); drawContent();
};

window.__editOrd = function(id){ activeTab = activeTab; window.__openOrdModal(id); };

window.__delOrd = function(id) {
  const isRes = activeTab==='resolutions';
  const coll = isRes?'resolutions':'ordinances';
  const r = getById(coll, id);
  confirmDialog({ title:'Delete measure?', message:`"${r.number}" will be permanently removed. This action cannot be undone.`, confirmLabel:'Delete',
    onConfirm: () => { remove(coll, id); toast('Deleted','success'); drawTabs(); drawContent(); } });
};

window.__expOrd = function() {
  const isRes = activeTab==='resolutions';
  const coll = isRes?'resolutions':'ordinances';
  exportCSV(isRes?'resolutions.csv':'ordinances.csv', getAll(coll), ['number','title','author','category','committeeId','status','dateIntroduced','dateApproved']);
};

window.__export_ordinances = window.__export_ordinances || {};
window.__export_ordinances.renderOrdinances = renderOrdinances;
} // end page scope

// ===== scheduling.js =====
{ // page scope
/* ==========================================================================
   Session Scheduling Module
   Create sessions and manage their status through the workflow.
   ========================================================================== */

function renderScheduling(main, route) {
  main.innerHTML = `
    ${pageHeader({
      title:'Session Scheduling',
      subtitle:'Create sessions and manage their status through the workflow',
      icon:'calendar-clock',
      actions: button({label:'New Session', icon:'calendar-plus', variant:'primary', onclick:"window.__openSesModal()"})
    })}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${schStats()}</div>
    <div id="sch-content"></div>
  `;
  renderIcons();
  drawSchContent();
}

function schStats() {
  const s = getAll('sessions');
  return statCard({label:'Total Sessions', value:s.length, icon:'calendar-days', color:'primary'}) +
         statCard({label:'In Progress', value:s.filter(x=>x.status==='In Progress').length, icon:'radio', color:'emerald'}) +
         statCard({label:'Scheduled', value:s.filter(x=>x.status==='Scheduled').length, icon:'calendar-plus', color:'amber'}) +
         statCard({label:'Concluded', value:s.filter(x=>x.status==='Concluded').length, icon:'check-circle-2', color:'slate'});
}

function drawSchContent() {
  const el = document.getElementById('sch-content');
  if (!el) return;
  const sessions = getAll('sessions');
  el.innerHTML = `
    ${filterBar({searchPlaceholder:'Search sessions…', selects:[{id:'ses-type',label:'All Types',options:['Regular','Special','Joint']},{id:'ses-status',label:'All Statuses',options:['Scheduled','In Progress','Concluded']}], onSearch:'window.__sesSearch'})}
    ${sessions.length ? table({
      columns:[{label:'Session'},{label:'Type'},{label:'Date & Time'},{label:'Venue'},{label:'Attendance'},{label:'Status'},{label:'Actions',align:'right',width:'w-1'}],
      rows: sessions.map(s=>{
        const present = s.attendance.filter(a=>a.status==='present'||a.status==='late').length;
        const total = s.attendance.length;
        return [
          `<div><p class="font-medium text-slate-800 dark:text-slate-100">${s.title}</p><p class="text-xs text-slate-500">${s.agendaCount} agenda items</p></div>`,
          `<span class="text-xs text-slate-600 dark:text-slate-300">${s.type}</span>`,
          `<div><p class="text-sm">${fmtDate(s.date)}</p><p class="text-xs text-slate-400">${fmtTime(s.time)}</p></div>`,
          `<span class="text-xs text-slate-600 dark:text-slate-300">${s.venue}</span>`,
          total ? `<div class="flex items-center gap-2"><div class="w-16 h-2 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden"><div class="h-full bg-emerald-500" style="width:${(present/total*100)}%"></div></div><span class="text-xs">${present}/${total}</span></div>` : '<span class="text-xs text-slate-400">—</span>',
          badge(s.status),
          `<div class="flex items-center justify-end gap-1">
            <button onclick="window.__viewSes('${s.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="View">${icon('eye','w-4 h-4')}</button>
            <button onclick="window.__openSesModal('${s.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-primary-600" title="Edit">${icon('pencil','w-4 h-4')}</button>
            <button onclick="window.__delSes('${s.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button>
          </div>`
        ];
      })
    }) : emptyState({icon:'calendar-clock', title:'No sessions yet', action: button({label:'New Session', icon:'plus', variant:'primary', onclick:"window.__openSesModal()"})})}
  `;
  renderIcons();
}

window.__sesSearch = function(){ drawSchContent(); };

/* ----------------------- Modal ----------------------- */
window.__openSesModal = function(id) {
  const existing = id ? getById('sessions', id) : null;
  const members = getAll('councilMembers');
  modal({
    title: existing?'Edit Session':'New Session', size:'lg',
    body:`<form id="ses-form" class="space-y-4">
      ${field({label:'Title', name:'title', value:existing?.title||'', required:true, placeholder:'e.g. Regular Session — 43rd Regular Session'})}
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Type', name:'type', type:'select', value:existing?.type||'Regular', options:['Regular','Special','Joint'].map(t=>({value:t,label:t}))})}
        ${field({label:'Status', name:'status', type:'select', value:existing?.status||'Scheduled', options:['Scheduled','In Progress','Concluded'].map(t=>({value:t,label:t}))})}
      </div>
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Date', name:'date', type:'date', value:existing?.date?.slice(0,10)||''})}
        ${field({label:'Time', name:'time', type:'time', value:existing?.time||'09:00'})}
      </div>
      ${field({label:'Venue', name:'venue', value:existing?.venue||'', placeholder:'e.g. Session Hall, 3rd Floor'})}
      ${field({label:'Agenda Item Count', name:'agendaCount', type:'number', value:existing?.agendaCount||0})}
      <div>
        <span class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5">Attendance (auto-add all members)</span>
        <div class="space-y-1.5 max-h-40 overflow-y-auto p-2 rounded-lg border border-slate-200 dark:border-slate-700">
          ${members.map(m=>`<label class="flex items-center gap-2 text-sm"><input type="checkbox" data-att="${m.id}" ${existing?.attendance.some(a=>a.memberId===m.id)?'checked':''} class="rounded"> ${memberName(m.id)}</label>`).join('')}
        </div>
      </div>
    </form>`,
    footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"}) +
            button({label:existing?'Update':'Schedule', variant:'primary', icon:'save', onclick:"window.__saveSes('"+(id||'')+"')"})
  });
};

window.__saveSes = function(id) {
  const data = readForm(document.getElementById('ses-form'));
  if (!data.title) { toast('Title required','error'); return; }
  const checked = Array.from(document.querySelectorAll('[data-att]:checked')).map(c=>c.dataset.att);
  const existing = id ? getById('sessions', id) : null;
  const prevAttendance = existing ? existing.attendance : [];
  data.attendance = checked.map(mid=>{
    const prev = prevAttendance.find(a=>a.memberId===mid);
    return { memberId:mid, status: prev ? prev.status : 'present' };
  });
  data.agendaCount = parseInt(data.agendaCount)||0;
  if (id) { update('sessions', id, data); toast('Session updated','success'); }
  else { insert('sessions', {...data, duration:0}); pushNotification({title:'New session scheduled', body:data.title, icon:'calendar-clock', color:'blue'}); toast('Session scheduled','success'); }
  document.querySelector('[id^=modal] [data-close]').click();
  drawSchContent();
};

window.__viewSes = function(id){ const s=getById('sessions',id); modal({title:s.title, size:'lg', body:`<div class="space-y-3">${card({title:'Details',icon:'info',body:`<dl class="text-sm space-y-2"><div class="flex justify-between"><dt class="text-slate-500">Date</dt><dd>${fmtDateLong(s.date)}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Time</dt><dd>${fmtTime(s.time)}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Venue</dt><dd>${s.venue}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Type</dt><dd>${s.type}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Status</dt><dd>${badge(s.status)}</dd></div></dl>`})}</div>`, footer: button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})}); };

window.__delSes = function(id){ const s=getById('sessions',id); confirmDialog({title:'Delete session?', message:`"${s.title}" will be removed.`, onConfirm:()=>{remove('sessions',id); toast('Deleted','success'); drawSchContent();}}); };

window.__export_scheduling = window.__export_scheduling || {};
window.__export_scheduling.renderScheduling = renderScheduling;
} // end page scope

// ===== attendance.js =====
{ // page scope
/* ==========================================================================
   Attendance and Quorum Monitoring Module
   Mark attendance per session; quorum recalculates live.
   ========================================================================== */

let attSelectedId = null;

function renderAttendance(main, route) {
  const sessions = getAll('sessions');
  if (!attSelectedId || !getById('sessions', attSelectedId)) {
    const inProgress = sessions.find(s=>s.status==='In Progress');
    attSelectedId = (inProgress || sessions[0] || {}).id || null;
  }
  main.innerHTML = `
    ${pageHeader({title:'Attendance & Quorum Monitoring', subtitle:'Mark attendance and track quorum in real time', icon:'user-check'})}
    ${sessions.length ? `
      <div class="mb-5 max-w-md">
        ${field({label:'Session', name:'att-session', type:'select', value: attSelectedId||'', options: sessions.map(s=>({value:s.id, label:`${s.title} — ${fmtDate(s.date)} (${s.status})`})), extra:'onchange="window.__attSelect(this.value)"'})}
      </div>
      <div id="att-content"></div>
    ` : emptyState({icon:'user-check', title:'No sessions yet', subtitle:'Create a session in Session Scheduling first.'})}
  `;
  renderIcons();
  drawAttContent();
}

window.__attSelect = function(id){ attSelectedId = id; drawAttContent(); };

function drawAttContent() {
  const el = document.getElementById('att-content');
  if (!el) return;
  const session = getById('sessions', attSelectedId);
  if (!session) { el.innerHTML = ''; return; }
  const members = getAll('councilMembers');
  const attByMember = {};
  session.attendance.forEach(a=>{ attByMember[a.memberId] = a.status; });
  const present = session.attendance.filter(a=>a.status==='present').length;
  const quorumNeeded = Math.floor(members.length/2)+1;
  const hasQuorum = present >= quorumNeeded;

  el.innerHTML = `
    <div class="p-5 rounded-2xl mb-5 flex items-center gap-4 flex-wrap ${hasQuorum?'bg-emerald-50 dark:bg-emerald-900/20':'bg-red-50 dark:bg-red-900/20'}">
      <div class="text-4xl font-bold ${hasQuorum?'text-emerald-600':'text-red-600'}">${present} / ${members.length}</div>
      <div>
        <p class="font-semibold ${hasQuorum?'text-emerald-700 dark:text-emerald-400':'text-red-700 dark:text-red-400'}">${icon(hasQuorum?'check-circle-2':'alert-triangle','w-4 h-4 inline mr-1')}Quorum ${hasQuorum?'Met':'Not Met'}</p>
        <p class="text-xs text-slate-500">Requires ${quorumNeeded} of ${members.length} members present (majority)</p>
      </div>
    </div>
    ${card({title:`Roll Call — ${session.title}`, subtitle:'Tap a status to mark each member attendance', icon:'users', body:`
      <div class="space-y-2">
        ${members.map(m=>{
          const st = attByMember[m.id] || null;
          const seg = (val,label) => `<button onclick="window.__attMark('${m.id}','${val}')" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition ${st===val ? (val==='present'?'bg-emerald-100 text-emerald-700':val==='late'?'bg-amber-100 text-amber-700':'bg-red-100 text-red-700') : 'text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700'}">${label}</button>`;
          return `<div class="flex items-center gap-3 p-2.5 rounded-lg border border-slate-100 dark:border-slate-800 flex-wrap">
            ${memberAvatar(m.id)}
            <div class="flex-1 min-w-[140px]"><p class="text-sm font-medium">${m.name}</p><p class="text-xs text-slate-400">${m.position||''}</p></div>
            <div class="flex items-center gap-1 border border-slate-200 dark:border-slate-700 rounded-lg overflow-hidden">
              ${seg('present','Present')}${seg('late','Late')}${seg('absent','Absent')}
            </div>
          </div>`;
        }).join('')}
      </div>
    `})}
  `;
  renderIcons();
}

window.__attMark = function(memberId, status) {
  const session = getById('sessions', attSelectedId);
  if (!session) return;
  const list = session.attendance.filter(a=>a.memberId!==memberId);
  list.push({memberId, status});
  update('sessions', attSelectedId, {attendance:list});
  toast('Attendance updated','success');
  drawAttContent();
};

window.__export_attendance = window.__export_attendance || {};
window.__export_attendance.renderAttendance = renderAttendance;
} // end page scope

// ===== proceedings.js =====
{ // page scope
/* ==========================================================================
   Session Proceedings Documentation Module
   Structured, timestamped log of what happens during a session — motions,
   seconds, floor discussion (for/against), points of order, rulings, and
   vote results — each attributable to a specific member.
   ========================================================================== */

let procSelectedId = null;

const PROC_TYPES = {
  'Statement':          { color:'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300', dot:'bg-slate-400', icon:'message-square' },
  'Motion':             { color:'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300', dot:'bg-primary-500', icon:'gavel' },
  'Second':             { color:'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300', dot:'bg-indigo-500', icon:'thumbs-up' },
  'Discussion — For':   { color:'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300', dot:'bg-emerald-500', icon:'circle-plus' },
  'Discussion — Against': { color:'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300', dot:'bg-red-500', icon:'circle-minus' },
  'Point of Order':     { color:'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300', dot:'bg-amber-500', icon:'flag' },
  'Ruling':             { color:'bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300', dot:'bg-violet-500', icon:'scale' },
  'Vote Result':        { color:'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900', dot:'bg-slate-700', icon:'check-check' },
};

function renderProceedings(main, route) {
  const sessions = getAll('sessions');
  if (!procSelectedId || !getById('sessions', procSelectedId)) {
    const inProgress = sessions.find(s=>s.status==='In Progress');
    procSelectedId = (inProgress || sessions[0] || {}).id || null;
  }
  main.innerHTML = `
    ${pageHeader({title:'Session Proceedings Documentation', subtitle:'Capture motions, floor discussion, and rulings as the session happens', icon:'file-text'})}
    ${sessions.length ? `
      <div class="mb-5 max-w-md">
        ${field({label:'Session', name:'proc-session', type:'select', value: procSelectedId||'', options: sessions.map(s=>({value:s.id, label:`${s.title} — ${fmtDate(s.date)} (${s.status})`})), extra:'onchange="window.__procSelect(this.value)"'})}
      </div>
      <div id="proc-content"></div>
    ` : emptyState({icon:'file-text', title:'No sessions yet', subtitle:'Create a session in Session Scheduling first.'})}
  `;
  renderIcons();
  drawProcContent();
}

window.__procSelect = function(id){ procSelectedId = id; drawProcContent(); };

function procPendingMotion(logsAsc) {
  // A Motion is "pending" until a later Vote Result entry resolves it.
  let pending = null;
  for (const l of logsAsc) {
    if (l.type === 'Motion') pending = l;
    else if (l.type === 'Vote Result') pending = null;
  }
  return pending;
}

function drawProcContent() {
  const el = document.getElementById('proc-content');
  if (!el) return;
  const session = getById('sessions', procSelectedId);
  if (!session) { el.innerHTML = ''; return; }
  const members = getAll('councilMembers');
  const logsDesc = getAll('proceedings').filter(p=>p.sessionId===procSelectedId).sort((a,b)=> new Date(b.time) - new Date(a.time));
  const logsAsc  = [...logsDesc].reverse();
  const pending = procPendingMotion(logsAsc);

  el.innerHTML = `
    ${pending ? `
      <div class="mb-5 p-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 flex items-center gap-3 flex-wrap">
        ${icon('gavel','w-5 h-5 text-amber-600 shrink-0')}
        <div class="flex-1 min-w-[200px]">
          <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Motion pending a vote</p>
          <p class="text-xs text-amber-700 dark:text-amber-400">"${pending.note}" — moved by ${pending.speaker || pending.author}</p>
        </div>
        ${button({label:'Record Vote Result', icon:'check-check', variant:'secondary', size:'sm', onclick:"window.__procQuickType('Vote Result')"})}
      </div>
    ` : ''}

    ${card({title:'Add Proceedings Entry', subtitle:`For: ${session.title}`, icon:'pencil-line', body:`
      <form id="proc-form" class="space-y-3">
        <div class="grid sm:grid-cols-2 gap-3">
          ${field({label:'Entry Type', name:'type', type:'select', value:'Statement', options:Object.keys(PROC_TYPES).map(t=>({value:t,label:t}))})}
          ${field({label:'Speaker (optional)', name:'speaker', type:'select', value:'', options:members.map(m=>({value:m.name,label:`${m.name}${m.position?' — '+m.position:''}`}))})}
        </div>
        <textarea name="note" rows="3" id="proc-note-input" placeholder="e.g. Moved to approve Ordinance No. 2024-005 as read." class="w-full text-sm rounded-lg border border-slate-200 dark:border-slate-700 bg-transparent p-3 focus:ring-2 focus:ring-primary-400 outline-none" required></textarea>
        <div class="flex items-center justify-between flex-wrap gap-2">
          <div class="flex gap-1.5 flex-wrap">
            ${['Motion','Second','Discussion — For','Discussion — Against','Point of Order'].map(t=>`<button type="button" onclick="window.__procQuickType('${t}')" class="text-[11px] font-semibold px-2.5 py-1 rounded-full ${PROC_TYPES[t].color} hover:opacity-80">${t}</button>`).join('')}
          </div>
          ${button({label:'Add Entry', icon:'plus', variant:'primary', onclick:"window.__procAdd()"})}
        </div>
      </form>
    `})}

    <div class="mt-5">
      ${card({title:'Session Log', subtitle:`${logsDesc.length} entr${logsDesc.length===1?'y':'ies'}, most recent first`, icon:'list', body:
        logsDesc.length ? `<div class="space-y-3 max-h-[460px] overflow-y-auto pr-1">
          ${logsDesc.map(l=>{
            const meta = PROC_TYPES[l.type] || PROC_TYPES['Statement'];
            return `<div class="flex gap-3">
              <div class="w-2 h-2 rounded-full ${meta.dot} mt-2 shrink-0"></div>
              <div class="flex-1 bg-slate-50 dark:bg-slate-800/60 rounded-lg p-3">
                <div class="flex items-center justify-between gap-2 flex-wrap">
                  <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-[10px] font-bold uppercase tracking-wide px-2 py-0.5 rounded-full ${meta.color}">${l.type||'Statement'}</span>
                    <p class="text-xs text-slate-400">${relTime(l.time)} · ${l.speaker || l.author}</p>
                  </div>
                  <button onclick="window.__procDelete('${l.id}')" class="text-slate-400 hover:text-red-500" title="Delete">${icon('x','w-3.5 h-3.5')}</button>
                </div>
                <p class="text-sm mt-1.5 whitespace-pre-wrap">${l.note}</p>
              </div>
            </div>`;
          }).join('')}
        </div>` : emptyState({icon:'file-text', title:'No proceedings logged yet', subtitle:'Add the first entry above — try tagging a Motion when one is made.'})
      })}
    </div>
  `;
  renderIcons();
}

window.__procQuickType = function(type) {
  const sel = document.querySelector('#proc-form select[name=type]');
  if (sel) sel.value = type;
  const noteEl = document.getElementById('proc-note-input');
  if (noteEl) noteEl.focus();
};

window.__procAdd = function() {
  const form = document.getElementById('proc-form');
  const note = form.querySelector('[name=note]').value.trim();
  const type = form.querySelector('[name=type]').value || 'Statement';
  const speaker = form.querySelector('[name=speaker]').value || '';
  if (!note) { toast('Entry cannot be empty','error'); return; }
  insert('proceedings', { sessionId: procSelectedId, note, type, speaker, author: speaker || 'Secretary\'s Office', time: new Date().toISOString() });
  toast(type + ' logged','success');
  drawProcContent();
};

window.__procDelete = function(id) { remove('proceedings', id); toast('Entry removed','success'); drawProcContent(); };

window.__export_proceedings = window.__export_proceedings || {};
window.__export_proceedings.renderProceedings = renderProceedings;
} // end page scope

// ===== minutesgen.js =====
{ // page scope
/* ==========================================================================
   Minutes Generation Module
   Auto-compiles agenda, attendance & proceedings into official minutes.
   ========================================================================== */

let minSelectedId = null;

function renderMinutesGen(main, route) {
  const concluded = getAll('sessions').filter(s=>s.status==='Concluded');
  main.innerHTML = `
    ${pageHeader({title:'Minutes Generation', subtitle:'Auto-compile agenda, attendance & proceedings into official minutes', icon:'file-signature'})}
    ${concluded.length ? `<div class="grid lg:grid-cols-3 gap-4">
      <div class="space-y-2">
        ${concluded.map(s=>`<button onclick="window.__minSelect('${s.id}')" class="w-full text-left p-3 rounded-lg border border-slate-200 dark:border-slate-800 hover:border-primary-400 transition" data-min-btn="${s.id}"><p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">${s.title}</p><p class="text-xs text-slate-400">${fmtDate(s.date)}</p></button>`).join('')}
      </div>
      <div class="lg:col-span-2" id="min-detail"></div>
    </div>` : emptyState({icon:'file-signature', title:'No concluded sessions', subtitle:'Minutes are generated after a session is marked Concluded in Session Scheduling.'})}
  `;
  renderIcons();
  if (concluded.length) {
    minSelectedId = (getById('sessions', minSelectedId) && concluded.some(s=>s.id===minSelectedId)) ? minSelectedId : concluded[0].id;
    drawMinDetail();
  }
}

window.__minSelect = function(id){ minSelectedId = id; drawMinDetail(); };

function drawMinDetail() {
  const el = document.getElementById('min-detail');
  if (!el) return;
  const s = getById('sessions', minSelectedId);
  if (!s) return;
  document.querySelectorAll('[data-min-btn]').forEach(b=>{
    b.classList.toggle('border-primary-500', b.dataset.minBtn===s.id);
    b.classList.toggle('bg-primary-50', b.dataset.minBtn===s.id);
    b.classList.toggle('dark:bg-primary-900/20', b.dataset.minBtn===s.id);
  });
  const mins = Math.round(s.duration||0);
  const present = s.attendance.filter(a=>a.status!=='absent');
  const logs = getAll('proceedings').filter(p=>p.sessionId===s.id).sort((a,b)=> new Date(a.time) - new Date(b.time));
  el.innerHTML = card({title:'Generated Meeting Minutes', subtitle:s.title, icon:'file-text', action: button({label:'Print', icon:'printer', variant:'outline', size:'sm', onclick:'window.print()'}),
    body:`
      <div class="prose prose-sm max-w-none dark:prose-invert">
        <p class="text-sm text-slate-600 dark:text-slate-300"><b>Date:</b> ${fmtDateLong(s.date)} at ${fmtTime(s.time)} · <b>Venue:</b> ${s.venue} · <b>Duration:</b> ${mins} minutes</p>
        <h3 class="font-semibold text-slate-800 dark:text-white mt-4 mb-2">Call to Order</h3>
        <p class="text-sm text-slate-600 dark:text-slate-300">The session was called to order at ${fmtTime(s.time)} by the presiding officer, Hon. Ricardo Almazan, City Secretary.</p>
        <h3 class="font-semibold text-slate-800 dark:text-white mt-4 mb-2">Roll Call & Quorum</h3>
        <p class="text-sm text-slate-600 dark:text-slate-300">${present.length} members were present, constituting a quorum. The following were in attendance: ${present.map(a=>memberName(a.memberId)).join(', ')}.</p>
        <h3 class="font-semibold text-slate-800 dark:text-white mt-4 mb-2">Proceedings</h3>
        ${logs.length ? `<ul class="text-sm text-slate-600 dark:text-slate-300 space-y-1">${logs.map(l=>{
          const time = new Date(l.time).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
          const who = l.speaker || l.author || '';
          const typeLbl = l.type && l.type !== 'Statement' ? `<b>${l.type}</b>${who?' by '+who:''}: ` : (who ? `<b>${who}:</b> ` : '');
          return `<li><b>${time}</b> — ${typeLbl}${l.note}</li>`;
        }).join('')}</ul>` : `<ol class="list-decimal list-inside text-sm text-slate-600 dark:text-slate-300 space-y-1">
          <li>Approval of the minutes of the previous session</li>
          <li>Second reading of pending ordinances</li>
          <li>Committee reports</li>
          <li>Public hearing outcomes</li>
          <li>Other matters</li>
        </ol>`}
        <h3 class="font-semibold text-slate-800 dark:text-white mt-4 mb-2">Adjournment</h3>
        <p class="text-sm text-slate-600 dark:text-slate-300">Having no further business, the session was adjourned at approximately ${fmtTime(String(Number(s.time.slice(0,2))+Math.floor(mins/60)).padStart(2,'0')+':'+String((mins%60)+Number(s.time.slice(3,5))).padStart(2,'0'))}.</p>
      </div>
      <div class="mt-4">${aiInsight({title:'Auto-Generated Minutes', body:'These minutes were compiled automatically from the session attendance record and proceedings log.'})}</div>
    `});
  renderIcons();
}

window.__export_minutesgen = window.__export_minutesgen || {};
window.__export_minutesgen.renderMinutesGen = renderMinutesGen;
} // end page scope

// ===== tracking.js =====
{ // page scope
/* ==========================================================================
   Real-Time Session Tracking Module
   Live view of the ongoing session: elapsed timer, quorum, agenda progress.
   ========================================================================== */

let trackTimer = null;
let trackElapsed = 0;
let trackRunning = false;

function renderTracking(main, route) {
  const live = getAll('sessions').find(s=>s.status==='In Progress');
  if (!live) {
    main.innerHTML = `
      ${pageHeader({title:'Real-Time Session Tracking', subtitle:'Live status of the ongoing session', icon:'radio'})}
      ${emptyState({icon:'radio', title:'No session is currently ongoing', subtitle:'Set a session to "In Progress" in Session Scheduling to track it live.', action: button({label:'Go to Session Scheduling', icon:'calendar-check', variant:'primary', onclick:"location.hash='#/scheduling'"})})}
    `;
    renderIcons();
    return;
  }
  const members = getAll('councilMembers');
  const present = live.attendance.filter(a=>a.status==='present').length;
  const quorum = Math.floor(members.length/2)+1;
  const hasQuorum = present >= quorum;
  const agendaItems = getAll('agenda').filter(a=>a.sessionId===live.id);
  const doneItems = agendaItems.filter(a=>a.status==='Completed').length;

  main.innerHTML = `
    ${pageHeader({title:'Real-Time Session Tracking', subtitle:'Live status of the ongoing session', icon:'radio'})}
    <div class="grid lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        ${card({title:'Live Session Tracker', subtitle:live.title, icon:'radio', headerClass:'border-emerald-200 dark:border-emerald-800',
          action:`<span class="flex items-center gap-2 text-emerald-600 text-sm font-medium"><span class="w-2.5 h-2.5 bg-emerald-500 rounded-full ls-pulse-dot"></span>LIVE</span>`,
          body:`
            <div class="grid sm:grid-cols-3 gap-4 mb-5">
              <div class="text-center p-4 rounded-xl bg-slate-50 dark:bg-slate-800">
                <p class="text-xs text-slate-400 uppercase tracking-wider">Elapsed</p>
                <p id="live-elapsed" class="text-3xl font-bold text-primary-600 tabular-nums">00:00:00</p>
              </div>
              <div class="text-center p-4 rounded-xl bg-slate-50 dark:bg-slate-800">
                <p class="text-xs text-slate-400 uppercase tracking-wider">Agenda Progress</p>
                <p class="text-3xl font-bold text-slate-700 dark:text-slate-200">${doneItems}/${agendaItems.length || live.agendaCount}</p>
              </div>
              <div class="text-center p-4 rounded-xl ${hasQuorum?'bg-emerald-50 dark:bg-emerald-900/20':'bg-red-50 dark:bg-red-900/20'}">
                <p class="text-xs text-slate-400 uppercase tracking-wider">Quorum</p>
                <p class="text-3xl font-bold ${hasQuorum?'text-emerald-600':'text-red-600'}">${present}/${quorum}</p>
              </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
              ${button({label:'Start Timer', icon:'play', variant:'success', onclick:"window.__trackStart()"})}
              ${button({label:'Pause', icon:'pause', variant:'secondary', onclick:"window.__trackPause()"})}
              ${button({label:'End Session', icon:'square', variant:'danger', onclick:"window.__trackEnd('"+live.id+"')"})}
            </div>
        `})}

        ${card({title:'Agenda Items', icon:'list-checks', subtitle: agendaItems.length ? 'Status updates as items are discussed' : 'No agenda items linked to this session yet', body:
          agendaItems.length ? `<div class="space-y-2">${agendaItems.map(a=>`<div class="flex items-center gap-3 p-2.5 rounded-lg border ${a.status==='In Progress'?'border-amber-300 bg-amber-50 dark:bg-amber-900/10':'border-slate-100 dark:border-slate-800'}"><span class="flex-1 text-sm">${a.title}</span>${badge(a.status)}</div>`).join('')}</div>` : `<a href="#/agenda" class="text-sm text-primary-600 hover:underline">Add agenda items →</a>`
        })}
      </div>

      <div class="space-y-4">
        ${card({title:'Session Info', icon:'info', body:`<dl class="text-sm space-y-2">
          <div class="flex justify-between"><dt class="text-slate-500">Date</dt><dd class="font-medium">${fmtDateLong(live.date)}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Time</dt><dd class="font-medium">${fmtTime(live.time)}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Venue</dt><dd class="font-medium">${live.venue}</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Type</dt><dd class="font-medium">${live.type}</dd></div>
        </dl>
        <a href="#/attendance" class="inline-flex items-center gap-1.5 text-sm text-primary-600 hover:underline mt-3">${icon('user-check','w-4 h-4')}Full roll call →</a>`})}
        ${aiInsight({title:'AI Session Brief', body:`Quorum ${hasQuorum?'is met':'NOT met'} with ${present} of ${quorum} required members present. ${agendaItems.length || live.agendaCount} agenda items are queued. ${hasQuorum?'Proceed with deliberations.':'Consider notifying absent members.'}`})}
      </div>
    </div>
  `;
  renderIcons();
}

window.__trackStart = function(){ if(trackRunning)return; trackRunning=true; trackTimer=setInterval(()=>{ trackElapsed++; updateTrackElapsed(); },1000); toast('Timer started','success'); };
window.__trackPause = function(){ trackRunning=false; if(trackTimer)clearInterval(trackTimer); toast('Timer paused','warning'); };
window.__trackEnd = function(id){ if(trackTimer)clearInterval(trackTimer); trackRunning=false; update('sessions', id, {status:'Concluded', duration:Math.round(trackElapsed/60)}); trackElapsed=0; toast('Session concluded','success'); renderTracking(document.getElementById('ls-main'),{}); };
function updateTrackElapsed(){ const el=document.getElementById('live-elapsed'); if(!el)return; const h=String(Math.floor(trackElapsed/3600)).padStart(2,'0'); const m=String(Math.floor((trackElapsed%3600)/60)).padStart(2,'0'); const s=String(trackElapsed%60).padStart(2,'0'); el.textContent=`${h}:${m}:${s}`; }

window.__export_tracking = window.__export_tracking || {};
window.__export_tracking.renderTracking = renderTracking;
} // end page scope

// ===== agenda.js =====

// ===== agenda.js =====
{ // page scope
/* ==========================================================================
   pages/agenda.js — Legislative Agenda & Calendar Management (Module 3)
   Priority scheduling, calendar, meeting coordination, deadline tracking.
   ========================================================================== */



let viewMonth = new Date();

function renderAgenda(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Agenda & Calendar', subtitle:'Priority scheduling, deadline tracking, and legislative timeline', icon:'calendar-days',
      actions: button({label:'New Agenda Item', icon:'plus', variant:'primary', onclick:"window.__openAgendaModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${agendaStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      <div class="lg:col-span-2">${calendarCard()}</div>
      <div class="space-y-4">${deadlinesCard()}${aiInsightCard()}</div>
    </div>
    <div id="agenda-list"></div>
  `;
  renderIcons();
  drawAgendaList();
}

function agendaStats() {
  const a = getAll('agenda');
  const overdue = a.filter(x => new Date(x.deadline) < new Date() && x.status!=='Completed').length;
  return statCard({label:'Agenda Items', value:a.length, icon:'list-checks', color:'primary'}) +
         statCard({label:'High Priority', value:a.filter(x=>x.priority==='High'||x.priority==='Critical').length, icon:'alert-circle', color:'red'}) +
         statCard({label:'Completed', value:a.filter(x=>x.status==='Completed').length, icon:'check-circle-2', color:'emerald'}) +
         statCard({label:'Overdue', value:overdue, icon:'alarm-clock', color:'amber', trend: overdue?'Needs attention':'On track', trendUp: !overdue});
}

function calendarCard() {
  const year = viewMonth.getFullYear(), month = viewMonth.getMonth();
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month+1, 0).getDate();
  const monthName = viewMonth.toLocaleDateString('en-US',{month:'long', year:'numeric'});
  const sessions = getAll('sessions');
  const hearings = getAll('hearings');
  const agenda = getAll('agenda');

  let cells = '';
  ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(d=>{ cells += `<div class="text-center text-xs font-semibold text-slate-400 py-2">${d}</div>`; });
  for (let i=0;i<firstDay;i++) cells += '<div></div>';
  for (let d=1; d<=daysInMonth; d++) {
    const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    const daySessions = sessions.filter(s=>s.date?.slice(0,10)===dateStr);
    const dayHearings = hearings.filter(h=>h.date?.slice(0,10)===dateStr);
    const dayAgenda = agenda.filter(a=>a.deadline?.slice(0,10)===dateStr);
    const isToday = new Date().toDateString() === new Date(year,month,d).toDateString();
    const events = [...daySessions.map(s=>({t:'s',l:s.title})), ...dayHearings.map(h=>({t:'h',l:h.title})), ...dayAgenda.map(a=>({t:'a',l:a.title}))];
    cells += `<div class="min-h-[80px] p-1.5 rounded-lg border ${isToday?'border-primary-400 bg-primary-50 dark:bg-primary-900/20':'border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/40'} transition">
      <span class="text-xs ${isToday?'text-primary-600 font-bold':'text-slate-500'}">${d}</span>
      ${events.slice(0,2).map(e=>`<div class="mt-1 text-[10px] truncate px-1 py-0.5 rounded ${e.t==='s'?'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300':e.t==='h'?'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300':'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'}">${e.l}</div>`).join('')}
      ${events.length>2?`<div class="text-[10px] text-slate-400 mt-0.5">+${events.length-2} more</div>`:''}
    </div>`;
  }

  return card({title:'Legislative Calendar', icon:'calendar',
    action:`<div class="flex items-center gap-1">${button({label:'', icon:'chevron-left', variant:'ghost', size:'sm', onclick:'window.__calPrev()'})}<span class="text-sm font-medium px-2">${monthName}</span>${button({label:'', icon:'chevron-right', variant:'ghost', size:'sm', onclick:'window.__calNext()'})}</div>`,
    body:`<div class="grid grid-cols-7 gap-1">${cells}</div>
      <div class="flex items-center gap-3 mt-4 text-xs text-slate-500">
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-primary-200"></span>Session</span>
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-emerald-200"></span>Hearing</span>
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-amber-200"></span>Deadline</span>
      </div>`});
}

window.__calPrev = function(){ viewMonth.setMonth(viewMonth.getMonth()-1); renderAgenda(document.getElementById('ls-main'),{}); };
window.__calNext = function(){ viewMonth.setMonth(viewMonth.getMonth()+1); renderAgenda(document.getElementById('ls-main'),{}); };

function deadlinesCard() {
  const upcoming = getAll('agenda').filter(a=>a.status!=='Completed').sort((a,b)=>new Date(a.deadline)-new Date(b.deadline)).slice(0,5);
  return card({title:'Upcoming Deadlines', icon:'alarm-clock', body:`<div class="space-y-3">${upcoming.map(a=>{
    const days = Math.ceil((new Date(a.deadline)-new Date())/(86400000));
    const overdue = days < 0;
    return `<div class="flex items-center gap-3"><div class="w-10 h-10 rounded-lg ${overdue?'bg-red-100 dark:bg-red-900/30 text-red-600':'bg-slate-100 dark:bg-slate-800 text-slate-600'} flex flex-col items-center justify-center shrink-0"><span class="text-[9px] uppercase">${new Date(a.deadline).toLocaleDateString('en-US',{month:'short'})}</span><span class="text-sm font-bold leading-none">${new Date(a.deadline).getDate()}</span></div><div class="min-w-0 flex-1"><p class="text-sm font-medium truncate">${a.title}</p><p class="text-xs ${overdue?'text-red-500':'text-slate-400'}">${overdue?Math.abs(days)+' days overdue':days===0?'Due today':days+' days left'}</p></div>${badge(a.priority)}</div>`;
  }).join('')||'<p class="text-sm text-slate-400 text-center py-2">No upcoming deadlines.</p>'}</div>`});
}

function aiInsightCard() {
  const critical = getAll('agenda').filter(a=>a.priority==='Critical').length;
  return aiInsight({title:'Calendar Intelligence', body:`${critical} critical-priority item(s) are on the calendar. The FY 2025 Budget Hearing is the highest-impact upcoming event — ensure committee reports are submitted 24 hours prior. Consider scheduling the Traffic Code public hearing after the budget deliberations to avoid member scheduling conflicts.`});
}

function drawAgendaList() {
  const items = getAll('agenda');
  document.getElementById('agenda-list').innerHTML = card({title:'Agenda Items', subtitle:'Priority queue and meeting coordination', icon:'list-checks', body: table({
    columns:[{label:'Item'},{label:'Linked Session'},{label:'Responsible'},{label:'Deadline'},{label:'Priority'},{label:'Status'},{label:'Actions',align:'right',width:'w-1'}],
    rows: items.map(a=>{
      const sess = a.sessionId ? getById('sessions', a.sessionId) : null;
      return [
        `<p class="font-medium text-slate-800 dark:text-slate-100">${a.title}</p><p class="text-xs text-slate-500">${a.category}</p>`,
        sess ? `<a href="#/scheduling" class="text-xs text-primary-600 hover:underline">${sess.title}</a>` : `<span class="text-xs text-slate-400">— unlinked —</span>`,
        `<span class="text-xs text-slate-600 dark:text-slate-300">${a.responsible}</span>`,
        `<span class="text-xs text-slate-500">${fmtDate(a.deadline)}</span>`,
        badge(a.priority),
        badge(a.status),
        `<div class="flex items-center justify-end gap-1">
          <button onclick="window.__toggleAgenda('${a.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-emerald-600" title="Toggle complete">${icon('check-circle-2','w-4 h-4')}</button>
          <button onclick="window.__openAgendaModal('${a.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-primary-600" title="Edit">${icon('pencil','w-4 h-4')}</button>
          <button onclick="window.__delAgenda('${a.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button>
        </div>`
      ];
    })
  })});
  renderIcons();
}

window.__openAgendaModal = function(id) {
  const existing = id?getById('agenda',id):null;
  const sessions = getAll('sessions');
  modal({title: existing?'Edit Agenda Item':'New Agenda Item', size:'md',
    body:`<form id="agenda-form" class="space-y-4">
      ${field({label:'Title', name:'title', value:existing?.title||'', required:true})}
      ${field({label:'Session (optional)', name:'sessionId', type:'select', value:existing?.sessionId||'', options:sessions.map(s=>({value:s.id,label:s.title}))})}
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Category', name:'category', type:'select', value:existing?.category||'', options:['Legislation','Finance','Public Hearing','Administrative','Community'].map(c=>({value:c,label:c}))})}
        ${field({label:'Priority', name:'priority', type:'select', value:existing?.priority||'Medium', options:['Critical','High','Medium','Low'].map(c=>({value:c,label:c}))})}
      </div>
      ${field({label:'Responsible', name:'responsible', value:existing?.responsible||'', placeholder:'e.g. Committee on Finance'})}
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Deadline', name:'deadline', type:'date', value:existing?.deadline?.slice(0,10)||''})}
        ${field({label:'Status', name:'status', type:'select', value:existing?.status||'Pending', options:['Pending','Scheduled','In Progress','Completed'].map(c=>({value:c,label:c}))})}
      </div>
    </form>`,
    footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"}) + button({label:'Save', variant:'primary', icon:'save', onclick:"window.__saveAgenda('"+(id||'')+"')"})
  });
};

window.__saveAgenda = function(id){ const d=readForm(document.getElementById('agenda-form')); if(!d.title){toast('Title required','error');return;} if(!d.sessionId) delete d.sessionId; if(id)update('agenda',id,d); else insert('agenda',d); toast('Saved','success'); document.querySelector('[id^=modal] [data-close]').click(); renderAgenda(document.getElementById('ls-main'),{}); };
window.__toggleAgenda = function(id){ const a=getById('agenda',id); update('agenda',id,{status: a.status==='Completed'?'Pending':'Completed'}); toast('Updated','success'); renderAgenda(document.getElementById('ls-main'),{}); };
window.__delAgenda = function(id){ confirmDialog({title:'Delete item?', message:'This agenda item will be removed.', onConfirm:()=>{remove('agenda',id); toast('Deleted','success'); renderAgenda(document.getElementById('ls-main'),{});}}); };

window.__export_agenda = window.__export_agenda || {};
window.__export_agenda.renderAgenda = renderAgenda;
} // end page scope

// ===== committees.js =====
{ // page scope
/* ==========================================================================
   pages/committees.js — Committee Management & Assignment (Module 4)
   Creation, members, jurisdiction, workload, performance, reports.
   ========================================================================== */




function renderCommittees(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Committee Management', subtitle:'Formation, member assignments, jurisdiction, workload & performance', icon:'users',
      actions: button({label:'New Committee', icon:'users-plus', variant:'primary', onclick:"window.__openComModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${comStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Committee Workload', subtitle:'Distribution across committees', icon:'bar-chart-3', className:'lg:col-span-2', body:`<div class="ls-chart-wrap h-64"><canvas id="comWorkload"></canvas></div>`})}
      ${card({title:'Member Distribution', subtitle:'By committee', icon:'pie-chart', body:`<div class="ls-chart-wrap h-64"><canvas id="comMembers"></canvas></div>`})}
    </div>
    <div id="com-list"></div>
  `;
  renderIcons();
  drawComList();
  drawComCharts();
}

function comStats() {
  const c = getAll('committees');
  const cm = getAll('committeeMembers');
  return statCard({label:'Active Committees', value:c.filter(x=>x.status==='active').length, icon:'users', color:'primary'}) +
         statCard({label:'Total Assignments', value:cm.length, icon:'user-plus', color:'emerald'}) +
         statCard({label:'Avg. Workload', value: Math.round(c.reduce((s,x)=>s+x.workload,0)/c.length)+'%', icon:'gauge', color:'amber'}) +
         statCard({label:'High Workload (>85%)', value: c.filter(x=>x.workload>85).length, icon:'trending-up', color:'red'});
}

function drawComCharts() {
  const c = getAll('committees');
  barChart('comWorkload', c.map(x=>x.name.replace('& Appropriations','& Approp.')), [{label:'Workload %', data:c.map(x=>x.workload), backgroundColor:PALETTE.slice(0,c.length)}], {plugins:{legend:{display:false}}, scales:{y:{max:100}}});
  doughnutChart('comMembers', c.map(x=>x.name.replace(' Committee','')), c.map(x=>getAll('committeeMembers').filter(m=>m.committeeId===x.id).length), PALETTE.slice(0,c.length));
}

function drawComList() {
  const committees = getAll('committees');
  document.getElementById('com-list').innerHTML = `<div class="grid md:grid-cols-2 gap-4">${committees.map(c=>{
    const members = getAll('committeeMembers').filter(m=>m.committeeId===c.id);
    return card({title:c.name, subtitle:c.jurisdiction, icon:'users',
      action:`<div class="flex items-center gap-2">${badge(c.status==='active'?'Active':'Inactive')}${button({label:'', icon:'pencil', variant:'ghost', size:'sm', onclick:"window.__openComModal('"+c.id+"')"})}${button({label:'', icon:'trash-2', variant:'ghost', size:'sm', onclick:"window.__delCom('"+c.id+"')"})}</div>`,
      body:`
        <div class="grid grid-cols-2 gap-3 mb-4">
          <div class="p-2 rounded-lg bg-slate-50 dark:bg-slate-800"><p class="text-xs text-slate-400">Workload</p><div class="flex items-center gap-2 mt-1"><div class="flex-1 h-2 rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-full rounded-full ${c.workload>85?'bg-red-500':c.workload>70?'bg-amber-500':'bg-emerald-500'}" style="width:${c.workload}%"></div></div><span class="text-xs font-semibold">${c.workload}%</span></div></div>
          <div class="p-2 rounded-lg bg-slate-50 dark:bg-slate-800"><p class="text-xs text-slate-400">Members</p><p class="text-lg font-bold text-slate-700 dark:text-slate-200 mt-0.5">${members.length}</p></div>
        </div>
        <p class="text-xs text-slate-400 mb-2">Committee Roster</p>
        <div class="space-y-1.5">${members.map(m=>{const mem=getById('councilMembers',m.memberId); return `<div class="flex items-center gap-2">${memberAvatar(m.memberId,'w-7 h-7 text-[10px]')}<span class="text-sm flex-1 truncate">${mem?.name||'—'}</span><span class="text-[10px] ${m.role==='Chair'?'text-primary-600':m.role==='Vice Chair'?'text-emerald-600':'text-slate-400'} font-medium">${m.role}</span></div>`;}).join('')}</div>
        <div class="mt-3 flex gap-2">${button({label:'View Dashboard', icon:'layout-dashboard', variant:'outline', size:'sm', onclick:"window.__comDash('"+c.id+"')"})}${button({label:'Assign Member', icon:'user-plus', variant:'secondary', size:'sm', onclick:"window.__assignCom('"+c.id+"')"})}</div>
    `});
  }).join('')}</div>`;
  renderIcons();
}

window.__comDash = function(id){
  const c=getById('committees',id);
  const members=getAll('committeeMembers').filter(m=>m.committeeId===c.id);
  const ords=getAll('ordinances').filter(o=>o.committeeId===id);
  modal({title:c.name+' — Dashboard', size:'lg',
    body:`<div class="grid sm:grid-cols-3 gap-3 mb-4">
        <div class="p-3 rounded-lg bg-primary-50 dark:bg-primary-900/20 text-center"><p class="text-2xl font-bold text-primary-600">${ords.length}</p><p class="text-xs text-slate-500">Measures Referred</p></div>
        <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center"><p class="text-2xl font-bold text-emerald-600">${ords.filter(o=>o.status==='Enacted'||o.status==='Approved').length}</p><p class="text-xs text-slate-500">Approved/Enacted</p></div>
        <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-center"><p class="text-2xl font-bold text-amber-600">${ords.filter(o=>['Pending Review','Committee Review','Drafting'].includes(o.status)).length}</p><p class="text-xs text-slate-500">In Progress</p></div>
      </div>
      ${aiInsight({title:'Committee Performance', body:`The ${c.name} has a workload index of ${c.workload}%, with ${members.length} members and ${ords.length} referred measures. Performance is ${c.workload>85?'highly active — consider workload redistribution':c.workload>65?'healthy':'light'}.`})}`,
    footer: button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})});
};

window.__assignCom = function(id){
  const c=getById('committees',id);
  const existing=getAll('committeeMembers').filter(m=>m.committeeId===id).map(m=>m.memberId);
  const available=getAll('councilMembers').filter(m=>!existing.includes(m.id));
  modal({title:'Assign Member to '+c.name, size:'md',
    body:`<form id="assign-form" class="space-y-4">
      ${field({label:'Member', name:'memberId', type:'select', required:true, options:available.map(m=>({value:m.id,label:m.name}))})}
      ${field({label:'Role', name:'role', type:'select', value:'Member', options:['Chair','Vice Chair','Member'].map(r=>({value:r,label:r}))})}
    </form>`,
    footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"}) + button({label:'Assign', variant:'primary', icon:'user-plus', onclick:"window.__saveAssign('"+id+"')"})});
};
window.__saveAssign = function(cid){ const d=readForm(document.getElementById('assign-form')); if(!d.memberId){toast('Select a member','error');return;} insert('committeeMembers',{committeeId:cid,...d}); toast('Member assigned','success'); document.querySelector('[id^=modal] [data-close]').click(); renderCommittees(document.getElementById('ls-main'),{}); };

window.__openComModal = function(id){ const e=id?getById('committees',id):null; modal({title:e?'Edit Committee':'New Committee', size:'md', body:`<form id="com-form" class="space-y-4">${field({label:'Name', name:'name', value:e?.name||'', required:true})}${field({label:'Jurisdiction', name:'jurisdiction', value:e?.jurisdiction||'', placeholder:'e.g. City budget and appropriations'})}${field({label:'Scope', name:'scope', value:e?.scope||'', placeholder:'e.g. Financial legislation'})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Workload %', name:'workload', type:'number', value:e?.workload||50})}${field({label:'Status', name:'status', type:'select', value:e?.status||'active', options:[{value:'active',label:'Active'},{value:'inactive',label:'Inactive'}]})}</div></form>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:'Save', variant:'primary', icon:'save', onclick:"window.__saveCom('"+(id||'')+"')"})}); };
window.__saveCom = function(id){ const d=readForm(document.getElementById('com-form')); d.workload=parseInt(d.workload)||50; if(!d.name){toast('Name required','error');return;} if(id)update('committees',id,d); else insert('committees',{...d, established:new Date().toISOString().slice(0,10)}); toast('Saved','success'); document.querySelector('[id^=modal] [data-close]').click(); renderCommittees(document.getElementById('ls-main'),{}); };
window.__delCom = function(id){ confirmDialog({title:'Delete committee?', message:'The committee and its member assignments will be removed.', onConfirm:()=>{remove('committees',id); getAll('committeeMembers').filter(m=>m.committeeId===id).forEach(m=>remove('committeeMembers',m.id)); toast('Deleted','success'); renderCommittees(document.getElementById('ls-main'),{});}}); };

window.__export_committees = window.__export_committees || {};
window.__export_committees.renderCommittees = renderCommittees;
} // end page scope

// ===== voting.js =====
{ // page scope
/* ==========================================================================
   pages/voting.js — Voting, Quorum & Decision Support System (Module 5)
   Quorum verification, manual/electronic voting sim, tallying, charts, reports.
   ========================================================================== */




function renderVoting(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Voting, Quorum & Decisions', subtitle:'Quorum verification, electronic voting simulation, tallying & reports', icon:'vote',
      actions: button({label:'New Vote', icon:'plus', variant:'primary', onclick:"window.__openVoteModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${voteStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Quorum Calculator', subtitle:'Verify session quorum in real time', icon:'users', className:'lg:col-span-1', body: quorumBody()})}
      ${card({title:'Vote Outcomes', subtitle:'Results distribution', icon:'pie-chart', className:'lg:col-span-1', body:`<div class="ls-chart-wrap h-56"><canvas id="voteResults"></canvas></div>`})}
      ${card({title:'Participation Trend', subtitle:'Voter turnout per session', icon:'trending-up', className:'lg:col-span-1', body:`<div class="ls-chart-wrap h-56"><canvas id="voteTrend"></canvas></div>`})}
    </div>
    <div id="vote-list"></div>
  `;
  renderIcons();
  drawVoteList();
  drawVoteCharts();
  setupQuorum();
}

function voteStats() {
  const v = getAll('votes');
  return statCard({label:'Total Votes', value:v.length, icon:'vote', color:'primary'}) +
         statCard({label:'Passed', value:v.filter(x=>x.result==='Passed'||x.result==='Unanimous').length, icon:'check-circle-2', color:'emerald'}) +
         statCard({label:'Failed', value:v.filter(x=>x.result==='Failed').length, icon:'x-circle', color:'red'}) +
         statCard({label:'Avg. Turnout', value:'89%', icon:'percent', color:'amber'});
}

function quorumBody() {
  const members = getAll('councilMembers');
  const quorum = Math.ceil(members.length/2)+1;
  return `
    <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">Required quorum: <b>${quorum}</b> of ${members.length} members (majority + 1).</p>
    <div class="space-y-2 max-h-48 overflow-y-auto mb-3 p-2 rounded-lg border border-slate-200 dark:border-slate-700">
      ${members.map(m=>`<label class="flex items-center gap-2 text-sm cursor-pointer"><input type="checkbox" data-quorum="${m.id}" checked class="rounded quorum-cb"> ${memberAvatar(m.id,'w-6 h-6 text-[10px]')} <span>${m.name}</span></label>`).join('')}
    </div>
    <div id="quorum-result" class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center">
      <p class="text-xs text-slate-400 uppercase">Status</p>
      <p id="quorum-status" class="text-lg font-bold text-emerald-600">Quorum Met</p>
      <p id="quorum-count" class="text-sm text-slate-500 mt-1">${members.length} present of ${quorum} required</p>
    </div>`;
}

function setupQuorum() {
  document.querySelectorAll('.quorum-cb').forEach(cb => cb.addEventListener('change', updateQuorum));
  updateQuorum();
}
function updateQuorum() {
  const members = getAll('councilMembers');
  const quorum = Math.ceil(members.length/2)+1;
  const present = document.querySelectorAll('.quorum-cb:checked').length;
  const box = document.getElementById('quorum-result');
  const status = document.getElementById('quorum-status');
  const count = document.getElementById('quorum-count');
  if (!box) return;
  const met = present >= quorum;
  box.className = `p-3 rounded-lg text-center ${met?'bg-emerald-50 dark:bg-emerald-900/20':'bg-red-50 dark:bg-red-900/20'}`;
  status.className = `text-lg font-bold ${met?'text-emerald-600':'text-red-600'}`;
  status.textContent = met ? 'Quorum Met' : 'Quorum Not Met';
  count.textContent = `${present} present of ${quorum} required`;
}

function drawVoteCharts() {
  const votes = getAll('votes');
  const results = { Passed:0, Unanimous:0, Failed:0 };
  votes.forEach(v => { results[v.result] = (results[v.result]||0)+1; });
  doughnutChart('voteResults', Object.keys(results), Object.values(results), ['#059669','#2563eb','#dc2626'], {cutout:'60%', plugins:{legend:{position:'bottom'}}});
  barChart('voteTrend', votes.map(v=>v.subject.slice(0,20)+'…'), [{label:'Yes',data:votes.map(v=>v.yes),backgroundColor:'#059669'},{label:'No',data:votes.map(v=>v.no),backgroundColor:'#dc2626'},{label:'Abstain',data:votes.map(v=>v.abstain),backgroundColor:'#94a3b8'}], {plugins:{legend:{position:'bottom'}}});
}

function drawVoteList() {
  const votes = getAll('votes');
  document.getElementById('vote-list').innerHTML = card({title:'Voting History', subtitle:'Recorded decisions and printable reports', icon:'history', body: votes.length ? table({
    columns:[{label:'Subject'},{label:'Date'},{label:'Yes'},{label:'No'},{label:'Abstain'},{label:'Result'},{label:'Actions',align:'right',width:'w-1'}],
    rows: votes.map(v=>[
      `<p class="font-medium text-slate-800 dark:text-slate-100 line-clamp-1 max-w-xs">${v.subject}</p><p class="text-xs text-slate-400">${v.type}</p>`,
      `<span class="text-xs text-slate-500">${fmtDate(v.date)}</span>`,
      `<span class="text-sm font-semibold text-emerald-600">${v.yes}</span>`,
      `<span class="text-sm font-semibold text-red-600">${v.no}</span>`,
      `<span class="text-sm font-semibold text-slate-400">${v.abstain}</span>`,
      badge(v.result),
      `<div class="flex items-center justify-end gap-1"><button onclick="window.__viewVote('${v.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="View">${icon('eye','w-4 h-4')}</button><button onclick="window.__delVote('${v.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button></div>`
    ])
  }) : emptyState({icon:'vote', title:'No votes recorded', action: button({label:'New Vote', icon:'plus', variant:'primary', onclick:"window.__openVoteModal()"})})});
  renderIcons();
}

/* ----------------------- Voting simulation ----------------------- */
window.__openVoteModal = function() {
  const members = getAll('councilMembers');
  const sessions = getAll('sessions');
  modal({title:'New Vote — Electronic Voting Simulation', size:'lg',
    body:`<form id="vote-form" class="space-y-4">
      ${field({label:'Subject', name:'subject', required:true, placeholder:'e.g. Approval of Ordinance No. 2024-005'})}
      <div class="grid sm:grid-cols-2 gap-4">
        ${field({label:'Session', name:'sessionId', type:'select', options:sessions.map(s=>({value:s.id,label:s.title}))})}
        ${field({label:'Voting Type', name:'type', type:'select', value:'Roll Call', options:['Roll Call','Viva Voce','Division'].map(t=>({value:t,label:t}))})}
      </div>
      <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800">
        <div class="flex items-center justify-between mb-2">
          <span class="text-sm font-medium text-slate-600 dark:text-slate-300">Member Votes (simulate)</span>
          <div class="flex gap-1">${['yes','no','abstain'].map(v=>`<button type="button" onclick="window.__quickVote('${v}')" class="text-xs px-2 py-1 rounded ${v==='yes'?'bg-emerald-600 text-white':v==='no'?'bg-red-600 text-white':'bg-slate-400 text-white'}">${v}</button>`).join('')}</div>
        </div>
        <div id="vote-roster" class="space-y-1.5 max-h-56 overflow-y-auto">${members.map(m=>`<div class="flex items-center gap-2 text-sm py-1">${memberAvatar(m.id,'w-7 h-7 text-[10px]')}<span class="flex-1">${m.name}</span><div class="flex gap-1">${['yes','no','abstain'].map(v=>`<button type="button" data-vote="${v}" data-mid="${m.id}" onclick="window.__setVote(this)" class="vote-btn text-xs px-2.5 py-1 rounded border border-slate-200 dark:border-slate-600 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700">${v}</button>`).join('')}</div></div>`).join('')}</div>
      </div>
    </form>`,
    footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"}) + button({label:'Record Vote', variant:'primary', icon:'check', onclick:"window.__saveVote()"})
  });
};

window.__setVote = function(btn){ const mid=btn.dataset.mid; const vote=btn.dataset.vote; document.querySelectorAll(`.vote-btn[data-mid="${mid}"]`).forEach(b=>{b.className='vote-btn text-xs px-2.5 py-1 rounded border border-slate-200 dark:border-slate-600 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700'; b.dataset.selected='';}); btn.className='vote-btn text-xs px-2.5 py-1 rounded text-white '+(vote==='yes'?'bg-emerald-600':vote==='no'?'bg-red-600':'bg-slate-500'); btn.dataset.selected='1'; };
window.__quickVote = function(vote){ document.querySelectorAll('.vote-btn').forEach(b=>{ if(b.dataset.vote===vote){ b.className='vote-btn text-xs px-2.5 py-1 rounded text-white '+(vote==='yes'?'bg-emerald-600':vote==='no'?'bg-red-600':'bg-slate-500'); b.dataset.selected='1'; } else { b.className='vote-btn text-xs px-2.5 py-1 rounded border border-slate-200 dark:border-slate-600 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700'; b.dataset.selected=''; } }); };
window.__saveVote = function(){ const d=readForm(document.getElementById('vote-form')); if(!d.subject){toast('Subject required','error');return;} const tallies=[]; document.querySelectorAll('.vote-btn[data-selected="1"]').forEach(b=>tallies.push({memberId:b.dataset.mid, vote:b.dataset.vote})); const yes=tallies.filter(t=>t.vote==='yes').length; const no=tallies.filter(t=>t.vote==='no').length; const abstain=tallies.filter(t=>t.vote==='abstain').length; const result = yes>no ? (no===0&&abstain===0?'Unanimous':'Passed') : 'Failed'; insert('votes',{...d, total:tallies.length, yes, no, abstain, result, tallies, date:new Date().toISOString()}); toast('Vote recorded — '+result,'success'); document.querySelector('[id^=modal] [data-close]').click(); renderVoting(document.getElementById('ls-main'),{}); };

window.__viewVote = function(id){ const v=getById('votes',id); modal({title:'Vote Result', size:'md', body:`<div class="text-center mb-4"><p class="text-sm text-slate-500">${v.subject}</p><p class="text-3xl font-bold ${v.result==='Passed'||v.result==='Unanimous'?'text-emerald-600':v.result==='Failed'?'text-red-600':'text-slate-600'} mt-2">${v.result}</p></div><div class="grid grid-cols-3 gap-3 mb-4"><div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center"><p class="text-2xl font-bold text-emerald-600">${v.yes}</p><p class="text-xs text-slate-500">Yes</p></div><div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-center"><p class="text-2xl font-bold text-red-600">${v.no}</p><p class="text-xs text-slate-500">No</p></div><div class="p-3 rounded-lg bg-slate-100 dark:bg-slate-800 text-center"><p class="text-2xl font-bold text-slate-600">${v.abstain}</p><p class="text-xs text-slate-500">Abstain</p></div></div>${v.tallies.length?`<div class="space-y-1.5">${v.tallies.map(t=>`<div class="flex items-center gap-2 text-sm">${memberAvatar(t.memberId,'w-6 h-6 text-[10px]')}<span class="flex-1">${memberName(t.memberId)}</span><span class="text-xs ${t.vote==='yes'?'text-emerald-600':t.vote==='no'?'text-red-600':'text-slate-400'} font-medium">${t.vote}</span></div>`).join('')}</div>`:'<p class="text-sm text-slate-400 text-center">Viva voce / division vote — no per-member records.</p>'}<div class="mt-4">${aiInsight({title:'Decision Analysis', body:`Motion ${v.result.toLowerCase()} with ${v.yes} in favor, ${v.no} against, and ${v.abstain} abstaining. ${v.result==='Passed'?'This decision will be recorded in the session minutes and forwarded for publication.':v.result==='Unanimous'?'A unanimous decision indicates strong consensus — suitable for ceremonial or non-controversial matters.':'The motion did not pass. The sponsor may revise and reintroduce at a future session.'}`})}</div>`, footer: button({label:'Print Report', icon:'printer', variant:'outline', onclick:'window.print()'})+button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})}); };
window.__delVote = function(id){ confirmDialog({title:'Delete vote?', message:'This vote record will be permanently removed.', onConfirm:()=>{remove('votes',id); toast('Deleted','success'); renderVoting(document.getElementById('ls-main'),{});}}); };

window.__export_voting = window.__export_voting || {};
window.__export_voting.renderVoting = renderVoting;
} // end page scope

// ===== records.js =====
{ // page scope
/* ==========================================================================
   pages/records.js — Legislative Records & Document Management (Module 6)
   Repository, upload sim, preview, version history, search, audit trail.
   ========================================================================== */



function renderRecords(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Records & Document Management', subtitle:'Repository, version control, metadata, and audit trails', icon:'folder-open',
      actions: button({label:'Upload Document', icon:'upload', variant:'primary', onclick:"window.__openDocModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${docStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Document Categories', subtitle:'Distribution by type', icon:'folder-tree', className:'lg:col-span-2', body: categoriesBody()})}
      ${aiInsight({title:'Repository Intelligence', body:`${getAll('records').length} documents across ${new Set(getAll('records').map(d=>d.category)).size} categories. ${getAll('records').filter(d=>d.status==='Final').length} are finalized. The largest file is the City Development Plan (12.5 MB). Consider archiving drafts older than 6 months to the historical repository.`})}
    </div>
    <div id="doc-list"></div>
  `;
  renderIcons();
  drawDocList();
}

function docStats() {
  const d = getAll('records');
  const totalSize = d.reduce((s,x)=> s + parseFloat(x.size||0), 0);
  return statCard({label:'Total Documents', value:d.length, icon:'file-stack', color:'primary'}) +
         statCard({label:'Final Versions', value:d.filter(x=>x.status==='Final').length, icon:'badge-check', color:'emerald'}) +
         statCard({label:'Drafts', value:d.filter(x=>x.status==='Draft').length, icon:'file-pen-line', color:'amber'}) +
         statCard({label:'Repository Size', value: totalSize.toFixed(1)+' MB', icon:'database', color:'slate'});
}

function categoriesBody() {
  const cats = {};
  getAll('records').forEach(d => { cats[d.category] = (cats[d.category]||0)+1; });
  const max = Math.max(...Object.values(cats), 1);
  return `<div class="space-y-3">${Object.entries(cats).map(([cat,count])=>`<div><div class="flex justify-between text-sm mb-1"><span class="text-slate-700 dark:text-slate-200">${cat}</span><span class="text-slate-400">${count}</span></div><div class="h-2 rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full bg-primary-500" style="width:${(count/max*100)}%"></div></div></div>`).join('')}</div>`;
}

function drawDocList() {
  const docs = getAll('records');
  document.getElementById('doc-list').innerHTML = card({title:'Document Repository', subtitle:'Search, filter, preview, and manage versions', icon:'library-big', body:`
    ${filterBar({searchPlaceholder:'Search documents…', selects:[{id:'doc-cat',label:'All Categories',options:[...new Set(docs.map(d=>d.category))]},{id:'doc-status',label:'All Statuses',options:[...new Set(docs.map(d=>d.status))]}], onSearch:'window.__docSearch'})}
    <div id="doc-table"></div>
  `});
  renderIcons();
  renderDocTable(docs);
}

function renderDocTable(docs) {
  const el = document.getElementById('doc-table');
  el.innerHTML = docs.length ? table({
    columns:[{label:'Document'},{label:'Category'},{label:'Type'},{label:'Size'},{label:'Uploaded'},{label:'Version'},{label:'Status'},{label:'Actions',align:'right',width:'w-1'}],
    rows: docs.map(d=>[
      `<div class="flex items-center gap-2">${icon(d.type==='PDF'?'file-text':'file','w-5 h-5 '+(d.type==='PDF'?'text-red-500':'text-primary-500'))}<div><p class="font-medium text-slate-800 dark:text-slate-100 line-clamp-1 max-w-xs">${d.title}</p><p class="text-xs text-slate-400">${d.tags?.join(', ')||''}</p></div></div>`,
      `<span class="text-xs text-slate-600 dark:text-slate-300">${d.category}</span>`,
      `<span class="badge bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300">${d.type}</span>`,
      `<span class="text-xs text-slate-500">${d.size}</span>`,
      `<span class="text-xs text-slate-500">${fmtDate(d.dateUploaded)}</span>`,
      `<span class="text-xs font-medium text-primary-600">${d.version}</span>`,
      badge(d.status),
      `<div class="flex items-center justify-end gap-1"><button onclick="window.__viewDoc('${d.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="Preview & Audit">${icon('eye','w-4 h-4')}</button><button onclick="window.__openDocModal('${d.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-primary-600" title="Edit">${icon('pencil','w-4 h-4')}</button><button onclick="window.__delDoc('${d.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button></div>`
    ])
  }) : emptyState({icon:'folder-open', title:'No documents', action: button({label:'Upload Document', icon:'upload', variant:'primary', onclick:"window.__openDocModal()"})});
  renderIcons();
}

window.__docSearch = function(){ const q=(document.getElementById('ls-search-input')?.value||'').toLowerCase(); const cat=document.getElementById('doc-cat')?.value; const st=document.getElementById('doc-status')?.value; let docs=getAll('records'); if(q)docs=docs.filter(d=>(d.title+d.category+(d.tags||[]).join('')).toLowerCase().includes(q)); if(cat)docs=docs.filter(d=>d.category===cat); if(st)docs=docs.filter(d=>d.status===st); renderDocTable(docs); };

window.__openDocModal = function(id){ const e=id?getById('records',id):null; const members=getAll('councilMembers'); modal({title:e?'Edit Document':'Upload Document (Simulated)', size:'md', body:`<form id="doc-form" class="space-y-4">${field({label:'Title', name:'title', value:e?.title||'', required:true})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Category', name:'category', type:'select', value:e?.category||'', options:['Budget','Committee Report','Transcript','Plan','Legislation Draft','Minutes','Contract','Other'].map(c=>({value:c,label:c}))})}${field({label:'File Type', name:'type', type:'select', value:e?.type||'PDF', options:['PDF','DOCX','XLSX','PPTX','IMG'].map(c=>({value:c,label:c}))})}</div><div class="grid sm:grid-cols-2 gap-4">${field({label:'Size (MB)', name:'size', type:'number', value:e?.size?parseFloat(e.size):2.0})}${field({label:'Status', name:'status', type:'select', value:e?.status||'Draft', options:['Draft','Active','Final','Archived'].map(c=>({value:c,label:c}))})}</div>${field({label:'Tags (comma-separated)', name:'tags', value:e?.tags?.join(', ')||'', placeholder:'budget, 2024, finance'})}<div class="p-4 rounded-lg border-2 border-dashed border-slate-200 dark:border-slate-700 text-center text-sm text-slate-400">${icon('upload-cloud','w-8 h-8 mx-auto mb-2')}Click to "upload" (simulated — no actual file transfer)</div></form>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:e?'Update':'Upload', variant:'primary', icon:e?'save':'upload', onclick:"window.__saveDoc('"+(id||'')+"')"})}); };
window.__saveDoc = function(id){ const d=readForm(document.getElementById('doc-form')); if(!d.title){toast('Title required','error');return;} d.size=d.size+' MB'; d.tags=d.tags?d.tags.split(',').map(t=>t.trim()).filter(Boolean):[]; if(id){d.version='v'+(parseInt(getById('records',id).version.slice(1))+1); update('records',id,{...d, audit:[...(getById('records',id).audit||[]), {action:'updated', by:'M-001', time:new Date().toISOString()}]}); } else { insert('records',{...d, uploadedBy:'M-001', dateUploaded:new Date().toISOString(), version:'v1', audit:[{action:'uploaded',by:'M-001',time:new Date().toISOString()}]}); } toast('Document '+(id?'updated':'uploaded'),'success'); document.querySelector('[id^=modal] [data-close]').click(); renderRecords(document.getElementById('ls-main'),{}); };

window.__viewDoc = function(id){ const d=getById('records',id); modal({title:d.title, size:'lg', body:`<div class="flex items-center gap-2 mb-4">${badge(d.status)}<span class="badge bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300">${d.type}</span><span class="text-xs text-slate-400">${d.size} · ${d.version}</span></div>${card({title:'Metadata', icon:'info', body:`<dl class="text-sm space-y-2 grid sm:grid-cols-2"><div><dt class="text-slate-400 text-xs">Category</dt><dd>${d.category}</dd></div><div><dt class="text-slate-400 text-xs">Uploaded by</dt><dd>${memberName(d.uploadedBy)}</dd></div><div><dt class="text-slate-400 text-xs">Date</dt><dd>${fmtDate(d.dateUploaded)}</dd></div><div><dt class="text-slate-400 text-xs">Tags</dt><dd>${(d.tags||[]).join(', ')||'—'}</dd></div></dl>`})}<div class="mt-4 p-8 rounded-lg border-2 border-dashed border-slate-200 dark:border-slate-700 text-center text-slate-400">${icon('file-text','w-12 h-12 mx-auto mb-2')}<p class="text-sm">Document preview (simulated)</p><p class="text-xs mt-1">In production, this would render the actual file content.</p></div>${sectionTitle('Audit Trail')}<div class="space-y-2">${(d.audit||[]).map(a=>`<div class="flex items-center gap-3 py-2 px-3 rounded-lg bg-slate-50 dark:bg-slate-800"><span class="w-2 h-2 rounded-full bg-primary-500"></span><span class="text-sm text-slate-700 dark:text-slate-200 flex-1 capitalize">${a.action} by ${memberName(a.by)}</span><span class="text-xs text-slate-400">${fmtDate(a.time)}</span></div>`).join('')}</div>`, footer: button({label:'Export', icon:'download', variant:'outline', onclick:"window.__docExport('"+id+"')"})+button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})}); };
window.__docExport = function(id){ exportCSV('document-'+id+'.csv', [getById('records',id)], ['title','category','type','size','version','status','uploadedBy','dateUploaded']); };
window.__delDoc = function(id){ confirmDialog({title:'Delete document?', message:'This document and its audit trail will be permanently removed.', onConfirm:()=>{remove('records',id); toast('Deleted','success'); renderRecords(document.getElementById('ls-main'),{});}}); };

window.__export_records = window.__export_records || {};
window.__export_records.renderRecords = renderRecords;
} // end page scope

// ===== hearings.js =====
{ // page scope
/* ==========================================================================
   pages/hearings.js — Public Hearing & Consultation Management (Module 7)
   Scheduling, registration, attendance, feedback collection, reports.
   ========================================================================== */




function renderHearings(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Public Hearings & Consultations', subtitle:'Scheduling, stakeholder registration, attendance, feedback & response tracking', icon:'mic',
      actions: button({label:'Schedule Hearing', icon:'calendar-plus', variant:'primary', onclick:"window.__openHearingModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${hearStats()}</div>
    <div class="grid lg:grid-cols-2 gap-4 mb-6">
      ${card({title:'Registration vs Attendance', subtitle:'Per hearing', icon:'bar-chart-3', body:`<div class="ls-chart-wrap h-56"><canvas id="hearChart"></canvas></div>`})}
      ${card({title:'Issues Logged', subtitle:'By hearing', icon:'alert-circle', body:`<div class="ls-chart-wrap h-56"><canvas id="hearIssues"></canvas></div>`})}
    </div>
    <div id="hear-list"></div>
  `;
  renderIcons();
  drawHearList();
  drawHearCharts();
}

function hearStats() {
  const h = getAll('hearings');
  return statCard({label:'Total Hearings', value:h.length, icon:'mic', color:'primary'}) +
         statCard({label:'Scheduled', value:h.filter(x=>x.status==='Scheduled').length, icon:'calendar-clock', color:'amber'}) +
         statCard({label:'Total Attendees', value:h.reduce((s,x)=>s+x.attended,0), icon:'users', color:'emerald'}) +
         statCard({label:'Issues Logged', value:h.reduce((s,x)=>s+x.issues,0), icon:'alert-circle', color:'red'});
}

function drawHearCharts() {
  const h = getAll('hearings').filter(x=>x.status==='Concluded');
  barChart('hearChart', h.map(x=>x.title.slice(0,20)+'…'), [{label:'Registered',data:h.map(x=>x.registered),backgroundColor:'#2563eb'},{label:'Attended',data:h.map(x=>x.attended),backgroundColor:'#059669'}], {plugins:{legend:{position:'bottom'}}});
  doughnutChart('hearIssues', h.map(x=>x.title.slice(0,18)+'…'), h.map(x=>x.issues), PALETTE.slice(0,h.length), {plugins:{legend:{position:'bottom'}}});
}

function drawHearList() {
  const hearings = getAll('hearings');
  document.getElementById('hear-list').innerHTML = card({title:'Public Hearings', subtitle:'Schedule, track attendance, and collect feedback', icon:'megaphone', body:`
    ${filterBar({searchPlaceholder:'Search hearings…', selects:[{id:'hear-status',label:'All Statuses',options:['Scheduled','Concluded']}], onSearch:'window.__hearSearch'})}
    <div id="hear-table"></div>
  `});
  renderIcons();
  renderHearTable(hearings);
}

function renderHearTable(hearings) {
  const el = document.getElementById('hear-table');
  el.innerHTML = hearings.length ? table({
    columns:[{label:'Hearing'},{label:'Date & Time'},{label:'Venue'},{label:'Registered'},{label:'Attended'},{label:'Issues'},{label:'Status'},{label:'Actions',align:'right',width:'w-1'}],
    rows: hearings.map(h=>[
      `<p class="font-medium text-slate-800 dark:text-slate-100 line-clamp-1 max-w-xs">${h.title}</p>${h.ordinanceRef?`<p class="text-xs text-slate-400">Ref: ${h.ordinanceRef}</p>`:''}`,
      `<div><p class="text-sm">${fmtDate(h.date)}</p><p class="text-xs text-slate-400">${fmtTime(h.time)}</p></div>`,
      `<span class="text-xs text-slate-600 dark:text-slate-300">${h.venue}</span>`,
      `<span class="text-sm font-semibold text-primary-600">${h.registered}</span>`,
      `<span class="text-sm ${h.attended?'text-emerald-600':'text-slate-400'}">${h.attended||'—'}</span>`,
      `<span class="text-sm ${h.issues?'text-amber-600':'text-slate-400'}">${h.issues||'—'}</span>`,
      badge(h.status),
      `<div class="flex items-center justify-end gap-1"><button onclick="window.__viewHearing('${h.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="Details">${icon('eye','w-4 h-4')}</button><button onclick="window.__openHearingModal('${h.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-primary-600" title="Edit">${icon('pencil','w-4 h-4')}</button><button onclick="window.__delHearing('${h.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button></div>`
    ])
  }) : emptyState({icon:'mic', title:'No hearings scheduled', action: button({label:'Schedule Hearing', icon:'calendar-plus', variant:'primary', onclick:"window.__openHearingModal()"})});
  renderIcons();
}

window.__hearSearch = function(){ const q=(document.getElementById('ls-search-input')?.value||'').toLowerCase(); const st=document.getElementById('hear-status')?.value; let h=getAll('hearings'); if(q)h=h.filter(x=>x.title.toLowerCase().includes(q)); if(st)h=h.filter(x=>x.status===st); renderHearTable(h); };

window.__openHearingModal = function(id){ const e=id?getById('hearings',id):null; const ords=getAll('ordinances'); modal({title:e?'Edit Hearing':'Schedule Public Hearing', size:'md', body:`<form id="hear-form" class="space-y-4">${field({label:'Title', name:'title', value:e?.title||'', required:true})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Date', name:'date', type:'date', value:e?.date?.slice(0,10)||''})}${field({label:'Time', name:'time', type:'time', value:e?.time||'09:00'})}</div>${field({label:'Venue', name:'venue', value:e?.venue||'', placeholder:'e.g. City Gymnasium'})}${field({label:'Related Ordinance/Resolution', name:'ordinanceRef', type:'select', value:e?.ordinanceRef||'', options:ords.map(o=>({value:o.id,label:o.number+' — '+o.title.slice(0,40)}))})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Status', name:'status', type:'select', value:e?.status||'Scheduled', options:['Scheduled','Concluded'].map(c=>({value:c,label:c}))})}${field({label:'Expected Registrants', name:'registered', type:'number', value:e?.registered||0})}</div></form>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:'Save', variant:'primary', icon:'save', onclick:"window.__saveHearing('"+(id||'')+"')"})}); };
window.__saveHearing = function(id){ const d=readForm(document.getElementById('hear-form')); if(!d.title){toast('Title required','error');return;} d.registered=parseInt(d.registered)||0; if(id)update('hearings',id,d); else insert('hearings',{...d, attended:0, issues:0, feedbacks:0}); toast('Saved','success'); document.querySelector('[id^=modal] [data-close]').click(); renderHearings(document.getElementById('ls-main'),{}); };

window.__viewHearing = function(id){ const h=getById('hearings',id); const attendanceRate = h.registered?Math.round(h.attended/h.registered*100):0; modal({title:h.title, size:'lg', body:`<div class="flex items-center gap-2 mb-4">${badge(h.status)}${h.ordinanceRef?`<span class="text-xs text-slate-400">Ref: ${h.ordinanceRef}</span>`:''}</div><div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4"><div class="p-3 rounded-lg bg-primary-50 dark:bg-primary-900/20 text-center"><p class="text-2xl font-bold text-primary-600">${h.registered}</p><p class="text-xs text-slate-500">Registered</p></div><div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center"><p class="text-2xl font-bold text-emerald-600">${h.attended}</p><p class="text-xs text-slate-500">Attended</p></div><div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-center"><p class="text-2xl font-bold text-amber-600">${h.issues}</p><p class="text-xs text-slate-500">Issues</p></div><div class="p-3 rounded-lg bg-slate-100 dark:bg-slate-800 text-center"><p class="text-2xl font-bold text-slate-700 dark:text-slate-200">${attendanceRate}%</p><p class="text-xs text-slate-500">Attendance</p></div></div>${card({title:'Hearing Details', icon:'info', body:`<dl class="text-sm space-y-2"><div class="flex justify-between"><dt class="text-slate-500">Date</dt><dd>${fmtDateLong(h.date)}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Time</dt><dd>${fmtTime(h.time)}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Venue</dt><dd>${h.venue}</dd></div></dl>`})}${sectionTitle('Sample Public Feedback')}<div class="space-y-2">${['We support the ordinance but request a longer transition period for small vendors.','Please ensure barangay-level consultations are included before final approval.','The proposed fines may be too high for micro-enterprises — consider a warning-first approach.'].slice(0,h.feedbacks>0?3:0).map(f=>`<div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800 text-sm text-slate-600 dark:text-slate-300">${icon('quote','w-4 h-4 text-slate-400 inline mr-1')}${f}</div>`).join('')||'<p class="text-sm text-slate-400 text-center py-3">No feedback collected yet for this hearing.</p>'}</div>${h.status==='Concluded'?aiInsight({title:'Hearing Insights', body:`This hearing drew ${h.attended} participants (${attendanceRate}% of registrants) and logged ${h.issues} distinct issues. Public sentiment appears generally supportive with implementation concerns around vendor impact and transition timelines. Recommend the originating committee address transition periods in the revised draft.`}):''}`, footer: button({label:'Print Report', icon:'printer', variant:'outline', onclick:'window.print()'})+button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})}); };
window.__delHearing = function(id){ confirmDialog({title:'Delete hearing?', message:'This hearing record will be removed.', onConfirm:()=>{remove('hearings',id); toast('Deleted','success'); renderHearings(document.getElementById('ls-main'),{});}}); };

window.__export_hearings = window.__export_hearings || {};
window.__export_hearings.renderHearings = renderHearings;
} // end page scope

// ===== archives.js =====
{ // page scope
/* ==========================================================================
   pages/archives.js — Legislative Archives & Historical Repository (Module 8)
   Digital archives, search, classification, retention, restoration.
   ========================================================================== */




function renderArchives(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Legislative Archives', subtitle:'Digital archives, historical records, classification & retention management', icon:'archive',
      actions: button({label:'Archive Record', icon:'archive', variant:'primary', onclick:"window.__openArchModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${archStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Archive Categories', subtitle:'By record type', icon:'folder-tree', className:'lg:col-span-2', body:`<div class="ls-chart-wrap h-56"><canvas id="archChart"></canvas></div>`})}
      ${aiInsight({title:'Archive Intelligence', body:`The repository holds ${getAll('archives').length} archived records spanning ${Math.min(...getAll('archives').map(a=>a.year))}–${Math.max(...getAll('archives').map(a=>a.year))}. ${getAll('archives').filter(a=>a.status==='Restored').length} historical records have been digitally restored. Retention policy: most legislative records are permanent; minutes retained 10 years.`})}
    </div>
    <div id="arch-list"></div>
  `;
  renderIcons();
  drawArchList();
  drawArchCharts();
}

function archStats() {
  const a = getAll('archives');
  const oldest = a.length?Math.min(...a.map(x=>x.year)):'—';
  return statCard({label:'Archived Records', value:a.length, icon:'archive', color:'primary'}) +
         statCard({label:'Digitized', value:a.filter(x=>x.format==='Digitized').length, icon:'scan-line', color:'emerald'}) +
         statCard({label:'Restored', value:a.filter(x=>x.status==='Restored').length, icon:'refresh-cw', color:'amber'}) +
         statCard({label:'Oldest Record', value:oldest, icon:'history', color:'slate', sub:'Year'});
}

function drawArchCharts() {
  const cats = {};
  getAll('archives').forEach(a => { cats[a.category] = (cats[a.category]||0)+1; });
  doughnutChart('archChart', Object.keys(cats), Object.values(cats), PALETTE.slice(0,Object.keys(cats).length), {plugins:{legend:{position:'right'}}});
}

function drawArchList() {
  const archives = getAll('archives');
  document.getElementById('arch-list').innerHTML = card({title:'Historical Repository', subtitle:'Search, classify, and manage retention', icon:'library', body:`
    ${filterBar({searchPlaceholder:'Search archives by title, year, or category…', selects:[{id:'arch-cat',label:'All Categories',options:[...new Set(archives.map(a=>a.category))]},{id:'arch-fmt',label:'All Formats',options:[...new Set(archives.map(a=>a.format))]}], onSearch:'window.__archSearch'})}
    <div id="arch-table"></div>
  `});
  renderIcons();
  renderArchTable(archives);
}

function renderArchTable(archives) {
  const el = document.getElementById('arch-table');
  el.innerHTML = archives.length ? table({
    columns:[{label:'Record'},{label:'Category'},{label:'Year'},{label:'Format'},{label:'Retention'},{label:'Archived'},{label:'Status'},{label:'Actions',align:'right',width:'w-1'}],
    rows: archives.map(a=>[
      `<div class="flex items-center gap-2">${icon(a.format==='Digitized'?'scan-line':'archive','w-5 h-5 text-slate-400')}<p class="font-medium text-slate-800 dark:text-slate-100 line-clamp-1 max-w-xs">${a.title}</p></div>`,
      `<span class="text-xs text-slate-600 dark:text-slate-300">${a.category}</span>`,
      `<span class="text-sm font-semibold text-slate-700 dark:text-slate-200">${a.year}</span>`,
      `<span class="badge bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300">${a.format}</span>`,
      `<span class="text-xs text-slate-500">${a.retention}</span>`,
      `<span class="text-xs text-slate-500">${fmtDate(a.dateArchived)}</span>`,
      badge(a.status),
      `<div class="flex items-center justify-end gap-1"><button onclick="window.__viewArch('${a.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="Preview">${icon('eye','w-4 h-4')}</button>${a.status==='Restored'?button({label:'',icon:'rotate-ccw',variant:'ghost',size:'sm',title:'Restore',onclick:"window.__restoreArch('"+a.id+"')"}):button({label:'',icon:'refresh-cw',variant:'ghost',size:'sm',title:'Restore',onclick:"window.__restoreArch('"+a.id+"')"})}<button onclick="window.__delArch('${a.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button></div>`
    ])
  }) : emptyState({icon:'archive', title:'Archive is empty', action: button({label:'Archive Record', icon:'archive', variant:'primary', onclick:"window.__openArchModal()"})});
  renderIcons();
}

window.__archSearch = function(){ const q=(document.getElementById('ls-search-input')?.value||'').toLowerCase(); const cat=document.getElementById('arch-cat')?.value; const fmt=document.getElementById('arch-fmt')?.value; let a=getAll('archives'); if(q)a=a.filter(x=>(x.title+x.category+x.year).toLowerCase().includes(q)); if(cat)a=a.filter(x=>x.category===cat); if(fmt)a=a.filter(x=>x.format===fmt); renderArchTable(a); };

window.__openArchModal = function(id){ const e=id?getById('archives',id):null; modal({title:e?'Edit Archived Record':'Archive New Record', size:'md', body:`<form id="arch-form" class="space-y-4">${field({label:'Title', name:'title', value:e?.title||'', required:true})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Category', name:'category', type:'select', value:e?.category||'', options:['Ordinance','Resolution','Minutes','Historical','Plan','Other'].map(c=>({value:c,label:c}))})}${field({label:'Year', name:'year', type:'number', value:e?.year||new Date().getFullYear()})}</div><div class="grid sm:grid-cols-2 gap-4">${field({label:'Format', name:'format', type:'select', value:e?.format||'Digital', options:['Digital','Digitized','Physical (scanned)'].map(c=>({value:c,label:c}))})}${field({label:'Retention', name:'retention', type:'select', value:e?.retention||'Permanent', options:['Permanent','10 years','5 years','3 years'].map(c=>({value:c,label:c}))})}</div></form>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:'Archive', variant:'primary', icon:'archive', onclick:"window.__saveArch('"+(id||'')+"')"})}); };
window.__saveArch = function(id){ const d=readForm(document.getElementById('arch-form')); d.year=parseInt(d.year)||new Date().getFullYear(); if(!d.title){toast('Title required','error');return;} if(id)update('archives',id,d); else insert('archives',{...d, dateArchived:new Date().toISOString(), status:'Archived', searchable:true}); toast('Archived','success'); document.querySelector('[id^=modal] [data-close]').click(); renderArchives(document.getElementById('ls-main'),{}); };
window.__restoreArch = function(id){ update('archives',id,{status:'Restored'}); toast('Record digitally restored','success'); renderArchives(document.getElementById('ls-main'),{}); };
window.__viewArch = function(id){ const a=getById('archives',id); modal({title:a.title, size:'md', body:`<div class="flex items-center gap-2 mb-4">${badge(a.status)}<span class="badge bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300">${a.format}</span><span class="text-xs text-slate-400">${a.year}</span></div>${card({title:'Record Details', icon:'info', body:`<dl class="text-sm space-y-2"><div class="flex justify-between"><dt class="text-slate-500">Category</dt><dd>${a.category}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Year</dt><dd>${a.year}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Retention</dt><dd>${a.retention}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Archived</dt><dd>${fmtDate(a.dateArchived)}</dd></div></dl>`})}<div class="mt-4 p-8 rounded-lg border-2 border-dashed border-slate-200 dark:border-slate-700 text-center text-slate-400">${icon('file-search','w-12 h-12 mx-auto mb-2')}<p class="text-sm">Archive preview (simulated)</p></div>`, footer: button({label:'Close', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})}); };
window.__delArch = function(id){ confirmDialog({title:'Delete archived record?', message:'This historical record will be permanently removed from the repository.', onConfirm:()=>{remove('archives',id); toast('Deleted','success'); renderArchives(document.getElementById('ls-main'),{});}}); };

window.__export_archives = window.__export_archives || {};
window.__export_archives.renderArchives = renderArchives;
} // end page scope

// ===== research.js =====
{ // page scope
/* ==========================================================================
   pages/research.js — Legislative Research, Policy Analysis & Impact (Module 9)
   Policy research, impact assessment, comparative analysis, benchmarking,
   visual analytics, downloadable reports, AI-style recommendations.
   ========================================================================== */




let selectedId = null;

function renderResearch(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Research, Policy Analysis & Impact Evaluation', subtitle:'Policy research, impact assessment, benchmarking & AI-style recommendations', icon:'flask-conical',
      actions: button({label:'New Research', icon:'plus', variant:'primary', onclick:"window.__openResModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${resStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      <div class="lg:col-span-2">${card({title:'Impact Score Comparison', subtitle:'Across all research projects', icon:'bar-chart-3', body:`<div class="ls-chart-wrap h-64"><canvas id="resImpact"></canvas></div>`})}</div>
      ${card({title:'Assessment Status', subtitle:'Completed vs in-progress', icon:'pie-chart', body:`<div class="ls-chart-wrap h-64"><canvas id="resStatus"></canvas></div>`})}
    </div>
    <div class="grid lg:grid-cols-3 gap-4">
      <div class="space-y-2" id="res-list"></div>
      <div class="lg:col-span-2" id="res-detail"></div>
    </div>
  `;
  renderIcons();
  drawResList();
  drawResCharts();
  const first = getAll('research')[0];
  if (first) { selectedId = first.id; showDetail(first.id); }
}

function resStats() {
  const r = getAll('research');
  const avg = r.length ? (r.reduce((s,x)=>s+x.impactScore,0)/r.length).toFixed(1) : '0';
  return statCard({label:'Research Projects', value:r.length, icon:'flask-conical', color:'primary'}) +
         statCard({label:'Completed', value:r.filter(x=>x.status==='Completed').length, icon:'check-circle-2', color:'emerald'}) +
         statCard({label:'In Progress', value:r.filter(x=>x.status==='In Progress').length, icon:'loader', color:'amber'}) +
         statCard({label:'Avg. Impact Score', value:avg+'/10', icon:'gauge', color:'slate'});
}

function drawResCharts() {
  const r = getAll('research');
  barChart('resImpact', r.map(x=>x.title.slice(0,22)+'…'), [{label:'Impact Score', data:r.map(x=>x.impactScore), backgroundColor:r.map((_,i)=>PALETTE[i%PALETTE.length])}], {plugins:{legend:{display:false}}, scales:{y:{max:10}}});
  const comp = r.filter(x=>x.status==='Completed').length;
  const prog = r.filter(x=>x.status==='In Progress').length;
  doughnutChart('resStatus', ['Completed','In Progress'], [comp,prog], ['#059669','#d97706'], {cutout:'60%', plugins:{legend:{position:'bottom'}}});
}

function drawResList() {
  const items = getAll('research');
  document.getElementById('res-list').innerHTML = items.map(r=>`<button onclick="window.__selRes('${r.id}')" class="w-full text-left p-3 rounded-lg border border-slate-200 dark:border-slate-800 hover:border-primary-400 transition ${selectedId===r.id?'border-primary-500 bg-primary-50 dark:bg-primary-900/20':''}"><div class="flex items-center justify-between mb-1">${badge(r.status)}<span class="text-xs font-bold text-primary-600">${r.impactScore}/10</span></div><p class="text-sm font-medium text-slate-800 dark:text-slate-100 line-clamp-2">${r.title}</p><p class="text-xs text-slate-400 mt-1">${r.type} · ${fmtDate(r.date)}</p></button>`).join('');
  renderIcons();
}

window.__selRes = function(id){ selectedId=id; drawResList(); showDetail(id); };

function showDetail(id) {
  const r = getById('research', id);
  if (!r) return;
  document.getElementById('res-detail').innerHTML = `
    ${card({title:r.title, subtitle:r.type+' · '+r.scope, icon:'flask-conical',
      action:`<div class="flex gap-1">${button({label:'',icon:'pencil',variant:'ghost',size:'sm',onclick:"window.__openResModal('"+r.id+"')"})}${button({label:'',icon:'trash-2',variant:'ghost',size:'sm',onclick:"window.__delRes('"+r.id+"')"})}${button({label:'Export',icon:'download',variant:'outline',size:'sm',onclick:"window.__expRes('"+r.id+"')"})}</div>`,
      body:`
        <div class="grid sm:grid-cols-3 gap-3 mb-4">
          <div class="p-3 rounded-lg bg-primary-50 dark:bg-primary-900/20 text-center"><p class="text-3xl font-bold text-primary-600">${r.impactScore}</p><p class="text-xs text-slate-500">Impact Score /10</p></div>
          <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center"><p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">${r.status}</p><p class="text-xs text-slate-500 mt-1">Status</p></div>
          <div class="p-3 rounded-lg bg-slate-100 dark:bg-slate-800 text-center"><p class="text-sm font-semibold text-slate-700 dark:text-slate-200">${r.policy}</p><p class="text-xs text-slate-500 mt-1">Policy Area</p></div>
        </div>
        ${sectionTitle('Impact Radar (Multi-Dimensional)')}
        <div class="ls-chart-wrap h-64 mb-4"><canvas id="resRadar"></canvas></div>
        ${card({title:'AI-Style Recommendation', icon:'lightbulb', body:`<p class="text-sm text-slate-700 dark:text-slate-200">${r.recommendation}</p>`})}
        <div class="mt-3">${card({title:'Benchmarking & Comparative Analysis', icon:'git-compare', body:`<p class="text-sm text-slate-700 dark:text-slate-200">${r.benchmark}</p>`})}
        <div class="mt-3">${aiInsight({title:'Policy Impact Summary', body:`This ${r.type.toLowerCase()} for the ${r.policy} policy area scores ${r.impactScore}/10 on composite impact. ${r.metrics.social>85?'Strong social benefits projected. ':''}${r.metrics.implementability<65?'Implementation complexity is moderate to high — phased rollout recommended. ':''}${r.metrics.economic>75?'Positive fiscal outlook. ':''}The recommended next step: ${r.recommendation.split('.')[0]}.`})}</div>
    `})}
  `;
  renderIcons();
  radarChart('resRadar', ['Environmental','Economic','Social','Implementability'], [{label:r.title.slice(0,20), data:[r.metrics.environmental, r.metrics.economic, r.metrics.social, r.metrics.implementability], backgroundColor:'rgba(37,99,235,.2)', borderColor:'#2563eb'}]);
}

window.__openResModal = function(id){ const e=id?getById('research',id):null; modal({title:e?'Edit Research':'New Research Project', size:'lg', body:`<form id="res-form" class="space-y-4">${field({label:'Title', name:'title', value:e?.title||'', required:true})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Type', name:'type', type:'select', value:e?.type||'Impact Assessment', options:['Impact Assessment','Comparative Analysis','Policy Research','Benchmarking'].map(c=>({value:c,label:c}))})}${field({label:'Policy Area', name:'policy', value:e?.policy||'', placeholder:'e.g. Transportation'})}</div><div class="grid sm:grid-cols-2 gap-4">${field({label:'Scope', name:'scope', value:e?.scope||'', placeholder:'e.g. Environment'})}${field({label:'Status', name:'status', type:'select', value:e?.status||'In Progress', options:['In Progress','Completed'].map(c=>({value:c,label:c}))})}</div>${field({label:'Impact Score (0-10)', name:'impactScore', type:'number', value:e?.impactScore||5})}${field({label:'Recommendation', name:'recommendation', type:'textarea', value:e?.recommendation||''})}${field({label:'Benchmark Notes', name:'benchmark', type:'textarea', value:e?.benchmark||''})}</form>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:'Save', variant:'primary', icon:'save', onclick:"window.__saveRes('"+(id||'')+"')"})}); };
window.__saveRes = function(id){ const d=readForm(document.getElementById('res-form')); if(!d.title){toast('Title required','error');return;} d.impactScore=parseFloat(d.impactScore)||5; d.metrics=e&&id?getById('research',id).metrics:{environmental:60,economic:60,social:60,implementability:60}; if(id)update('research',id,d); else insert('research',{...d, date:new Date().toISOString()}); toast('Saved','success'); document.querySelector('[id^=modal] [data-close]').click(); renderResearch(document.getElementById('ls-main'),{}); };
window.__delRes = function(id){ confirmDialog({title:'Delete research?', message:'This research project will be removed.', onConfirm:()=>{remove('research',id); toast('Deleted','success'); selectedId=null; renderResearch(document.getElementById('ls-main'),{});}}); };
window.__expRes = function(id){ exportCSV('research-'+id+'.csv', [getById('research',id)], ['title','type','policy','scope','status','impactScore','recommendation','benchmark']); };

window.__export_research = window.__export_research || {};
window.__export_research.renderResearch = renderResearch;
} // end page scope

// ===== engagement.js =====
{ // page scope
/* ==========================================================================
   pages/engagement.js — Citizen Engagement & Public Feedback (Module 10)
   Feedback submission, complaints, suggestions, validation workflow,
   response management, engagement analytics, public portal pages.
   ========================================================================== */




let activeTab = 'inbox';

function renderEngagement(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Citizen Engagement & Public Feedback', subtitle:'Feedback, complaints, validation workflow, response management & analytics', icon:'message-square',
      actions: button({label:'Public Portal', icon:'external-link', variant:'outline', onclick:"window.__openPortal()"}) + button({label:'New Feedback', icon:'plus', variant:'primary', onclick:"window.__openFbModal()"})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">${fbStats()}</div>
    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Feedback by Type', subtitle:'Complaints, suggestions, compliments', icon:'bar-chart-3', className:'lg:col-span-2', body:`<div class="ls-chart-wrap h-56"><canvas id="fbType"></canvas></div>`})}
      ${card({title:'Status Distribution', icon:'pie-chart', body:`<div class="ls-chart-wrap h-56"><canvas id="fbStatus"></canvas></div>`})}
    </div>
    <div id="fb-tabs"></div>
    <div id="fb-content"></div>
  `;
  renderIcons();
  drawFbTabs();
  drawFbCharts();
  drawFbContent();
}

function fbStats() {
  const f = getAll('feedback');
  return statCard({label:'Total Feedback', value:f.length, icon:'message-square', color:'primary'}) +
         statCard({label:'Complaints', value:f.filter(x=>x.type==='Complaint').length, icon:'alert-triangle', color:'red'}) +
         statCard({label:'Suggestions', value:f.filter(x=>x.type==='Suggestion').length, icon:'lightbulb', color:'amber'}) +
         statCard({label:'Pending Validation', value:f.filter(x=>x.status==='Pending Validation').length, icon:'clock', color:'slate'});
}

function drawFbTabs() {
  const f = getAll('feedback');
  document.getElementById('fb-tabs').innerHTML = `<div class="flex items-center gap-1 border-b border-slate-200 dark:border-slate-800 mb-5 overflow-x-auto no-scrollbar">
    ${[{id:'inbox',label:'Inbox',count:f.length},{id:'complaints',label:'Complaints',count:f.filter(x=>x.type==='Complaint').length},{id:'suggestions',label:'Suggestions',count:f.filter(x=>x.type==='Suggestion').length},{id:'analytics',label:'Engagement Analytics'}].map(it=>`<button onclick="window.__fbTab('${it.id}')" class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition ${it.id===activeTab?'border-primary-600 text-primary-600':'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-200'}">${it.label}${it.count!=null?` <span class="ml-1 text-xs ${it.id===activeTab?'text-primary-500':'text-slate-400'}">(${it.count})</span>`:''}</button>`).join('')}
  </div>`;
}
window.__fbTab = function(id){ activeTab=id; drawFbTabs(); drawFbContent(); };

function drawFbCharts() {
  const f = getAll('feedback');
  const types = {}; f.forEach(x=>types[x.type]=(types[x.type]||0)+1);
  barChart('fbType', Object.keys(types), [{label:'Count', data:Object.values(types), backgroundColor:['#dc2626','#d97706','#2563eb'].slice(0,Object.keys(types).length)}], {plugins:{legend:{display:false}}});
  const statuses = {}; f.forEach(x=>statuses[x.status]=(statuses[x.status]||0)+1);
  doughnutChart('fbStatus', Object.keys(statuses), Object.values(statuses), PALETTE.slice(0,Object.keys(statuses).length), {plugins:{legend:{position:'right'}}});
}

function drawFbContent() {
  const el = document.getElementById('fb-content');
  if (activeTab === 'analytics') { el.innerHTML = analyticsView(); renderIcons(); return; }
  let items = getAll('feedback');
  if (activeTab === 'complaints') items = items.filter(x=>x.type==='Complaint');
  if (activeTab === 'suggestions') items = items.filter(x=>x.type==='Suggestion');

  el.innerHTML = `
    ${filterBar({searchPlaceholder:'Search feedback…', selects:[{id:'fb-status',label:'All Statuses',options:[...new Set(items.map(x=>x.status))]},{id:'fb-priority',label:'All Priorities',options:[...new Set(items.map(x=>x.priority))]}], onSearch:'window.__fbSearch'})}
    ${items.length ? table({
      columns:[{label:'Feedback'},{label:'Type'},{label:'Category'},{label:'Ward'},{label:'Date'},{label:'Priority'},{label:'Status'},{label:'Actions',align:'right',width:'w-1'}],
      rows: items.map(f=>[
        `<div><p class="font-medium text-slate-800 dark:text-slate-100 line-clamp-1 max-w-xs">${f.subject}</p><p class="text-xs text-slate-400">by ${f.citizen}</p></div>`,
        `<span class="badge ${f.type==='Complaint'?'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300':f.type==='Suggestion'?'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300':'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'}">${f.type}</span>`,
        `<span class="text-xs text-slate-600 dark:text-slate-300">${f.category}</span>`,
        `<span class="text-xs text-slate-500">${f.ward}</span>`,
        `<span class="text-xs text-slate-500">${relTime(f.date)}</span>`,
        badge(f.priority),
        badge(f.status),
        `<div class="flex items-center justify-end gap-1"><button onclick="window.__viewFb('${f.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500" title="View & Respond">${icon('eye','w-4 h-4')}</button><button onclick="window.__validateFb('${f.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-emerald-600" title="Validate">${icon('badge-check','w-4 h-4')}</button><button onclick="window.__delFb('${f.id}')" class="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-red-500" title="Delete">${icon('trash-2','w-4 h-4')}</button></div>`
      ])
    }) : emptyState({icon:'message-square', title:'No feedback in this view', action: button({label:'New Feedback', icon:'plus', variant:'primary', onclick:"window.__openFbModal()"})})}
  `;
  renderIcons();
}

function analyticsView() {
  const f = getAll('feedback');
  const byWard = {}; f.forEach(x=>byWard[x.ward]=(byWard[x.ward]||0)+1);
  return `
    <div class="grid lg:grid-cols-2 gap-4">
      ${card({title:'Feedback by Ward', subtitle:'Geographic distribution', icon:'map', body:`<div class="ls-chart-wrap h-56"><canvas id="fbWard"></canvas></div>`})}
      ${card({title:'Response Performance', icon:'timer', body:`<div class="grid grid-cols-2 gap-3"><div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-center"><p class="text-2xl font-bold text-emerald-600">68%</p><p class="text-xs text-slate-500">Responded within 48h</p></div><div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-center"><p class="text-2xl font-bold text-amber-600">3.2 days</p><p class="text-xs text-slate-500">Avg. response time</p></div><div class="p-3 rounded-lg bg-primary-50 dark:bg-primary-900/20 text-center"><p class="text-2xl font-bold text-primary-600">4.2/5</p><p class="text-xs text-slate-500">Citizen satisfaction</p></div><div class="p-3 rounded-lg bg-slate-100 dark:bg-slate-800 text-center"><p class="text-2xl font-bold text-slate-700 dark:text-slate-200">82%</p><p class="text-xs text-slate-500">Resolution rate</p></div></div>`})}
    </div>
    <div class="mt-4">${aiInsight({title:'Engagement Insights', body:`Citizen engagement is strongest in ${Object.entries(byWard).sort((a,b)=>b[1]-a[1])[0]?.[0]||'District 1'}. Complaints about infrastructure and drainage are the leading categories — aligning feedback themes with the upcoming infrastructure agenda would improve satisfaction. Consider proactive announcements when addressing recurring issues.`})}</div>
  `;
}

window.__fbSearch = function(){ drawFbContent(); };

window.__viewFb = function(id){ const f=getById('feedback',id); modal({title:f.subject, size:'md', body:`<div class="flex items-center gap-2 mb-3">${badge(f.type)}${badge(f.priority)}${badge(f.status)}</div><div class="grid sm:grid-cols-2 gap-3 mb-4 text-sm"><div><p class="text-xs text-slate-400">From</p><p class="font-medium">${f.citizen}</p></div><div><p class="text-xs text-slate-400">Ward</p><p class="font-medium">${f.ward}</p></div><div><p class="text-xs text-slate-400">Category</p><p class="font-medium">${f.category}</p></div><div><p class="text-xs text-slate-400">Date</p><p class="font-medium">${fmtDate(f.date)}</p></div></div>${card({title:'Message', icon:'quote', body:`<p class="text-sm text-slate-700 dark:text-slate-200">${f.subject}</p>`})}<div class="mt-4">${sectionTitle('Official Response')}<textarea id="fb-response" rows="3" placeholder="Type your response to the citizen…" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400 transition">${f.response||''}</textarea></div>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:'Send Response', variant:'primary', icon:'send', onclick:"window.__respondFb('"+id+"')"})}); };
window.__respondFb = function(id){ const resp=document.getElementById('fb-response').value; if(!resp){toast('Type a response','error');return;} update('feedback',id,{response:resp, status:'Acknowledged'}); toast('Response sent to citizen','success'); document.querySelector('[id^=modal] [data-close]').click(); renderEngagement(document.getElementById('ls-main'),{}); };
window.__validateFb = function(id){ const f=getById('feedback',id); update('feedback',id,{status: f.status==='Pending Validation'?'Validated':f.status}); toast(f.status==='Pending Validation'?'Validated':'Updated','success'); renderEngagement(document.getElementById('ls-main'),{}); };
window.__delFb = function(id){ confirmDialog({title:'Delete feedback?', message:'This citizen feedback will be permanently removed.', onConfirm:()=>{remove('feedback',id); toast('Deleted','success'); renderEngagement(document.getElementById('ls-main'),{});}}); };

window.__openFbModal = function(){ modal({title:'New Citizen Feedback', size:'md', body:`<form id="fb-form" class="space-y-4">${field({label:'Type', name:'type', type:'select', value:'Complaint', options:['Complaint','Suggestion','Compliment'].map(c=>({value:c,label:c}))})}${field({label:'Subject', name:'subject', required:true, placeholder:'Brief description…'})}<div class="grid sm:grid-cols-2 gap-4">${field({label:'Category', name:'category', type:'select', options:['Infrastructure','Public Safety','Sanitation','Education','Drainage','Economy','Health','Other'].map(c=>({value:c,label:c}))})}${field({label:'Ward', name:'ward', type:'select', options:['District 1','District 2','District 3','District 4','District 5','District 6'].map(c=>({value:c,label:c}))})}</div><div class="grid sm:grid-cols-2 gap-4">${field({label:'Priority', name:'priority', type:'select', value:'Medium', options:['Critical','High','Medium','Low'].map(c=>({value:c,label:c}))})}${field({label:'Citizen Name', name:'citizen', value:'Anonymous'})}</div></form>`, footer: button({label:'Cancel', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})+button({label:'Submit', variant:'primary', icon:'send', onclick:"window.__saveFb()"})}); };
window.__saveFb = function(){ const d=readForm(document.getElementById('fb-form')); if(!d.subject){toast('Subject required','error');return;} insert('feedback',{...d, date:new Date().toISOString(), status:'Pending Validation', response:''}); pushNotification({title:'New citizen feedback', body:d.subject, icon:'message-square', color:'amber'}); toast('Feedback submitted — pending validation','success'); document.querySelector('[id^=modal] [data-close]').click(); renderEngagement(document.getElementById('ls-main'),{}); };

/* ----------------------- Public Portal simulation ----------------------- */
window.__openPortal = function(){
  const announcements = [
    {title:'FY 2025 Budget Public Hearing', body:'Join us to review the proposed city budget. Your input shapes community priorities.', date: fmtDate(new Date(Date.now()+10*86400000).toISOString()), icon:'megaphone'},
    {title:'Traffic Management Code Public Consultation', body:'Share your views on the new traffic code — bike lanes, demerit system, and more.', date: fmtDate(new Date(Date.now()+5*86400000).toISOString()), icon:'car'},
  ];
  const recent = getAll('feedback').slice(0,4);
  modal({title:'Citizen Public Portal', size:'xl',
    body:`<div class="rounded-xl bg-gradient-to-br from-primary-600 to-primary-800 p-6 text-white mb-4">
        <h2 class="text-xl font-bold">City Legislative Council — Public Portal</h2>
        <p class="text-sm text-primary-100 mt-1">Submit feedback, view announcements, and track issues in your community.</p>
      </div>
      ${sectionTitle('Latest Announcements')}
      <div class="grid sm:grid-cols-2 gap-3 mb-4">${announcements.map(a=>`<div class="p-4 rounded-lg border border-slate-200 dark:border-slate-700"><div class="flex items-center gap-2 mb-1">${icon(a.icon,'w-4 h-4 text-primary-600')}<span class="text-xs text-slate-400">${a.date}</span></div><p class="font-medium text-slate-800 dark:text-slate-100">${a.title}</p><p class="text-xs text-slate-500 mt-1">${a.body}</p></div>`).join('')}</div>
      ${sectionTitle('Submit New Feedback')}
      <div class="p-4 rounded-lg border-2 border-dashed border-primary-200 dark:border-primary-800 text-center">
        <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">Have something to say? Submit feedback directly to the council.</p>
        ${button({label:'Submit Feedback', icon:'message-square-plus', variant:'primary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click(); window.__openFbModal()"})}
      </div>
      ${sectionTitle('Recently Tracked Issues')}
      <div class="space-y-2">${recent.map(f=>`<div class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-slate-800"><span class="w-8 h-8 rounded-lg ${f.type==='Complaint'?'bg-red-100 text-red-600':f.type==='Suggestion'?'bg-amber-100 text-amber-600':'bg-emerald-100 text-emerald-600'} flex items-center justify-center">${icon(f.type==='Complaint'?'alert-triangle':f.type==='Suggestion'?'lightbulb':'heart','w-4 h-4')}</span><div class="min-w-0 flex-1"><p class="text-sm font-medium truncate">${f.subject}</p><p class="text-xs text-slate-400">${f.ward} · ${relTime(f.date)}</p></div>${badge(f.status)}</div>`).join('')}</div>`,
    footer: button({label:'Close Portal', variant:'secondary', onclick:"this.closest('[id^=modal]').querySelector('[data-close]').click()"})});
};

window.__export_engagement = window.__export_engagement || {};
window.__export_engagement.renderEngagement = renderEngagement;
} // end page scope

// ===== reports.js =====
{ // page scope
/* ==========================================================================
   pages/reports.js — Consolidated Reports & Exports
   ========================================================================== */




function renderReports(main, route) {
  const ordinances = getAll('ordinances');
  const resolutions = getAll('resolutions');
  const sessions = getAll('sessions');
  const feedback = getAll('feedback');

  main.innerHTML = `
    ${pageHeader({title:'Reports & Analytics', subtitle:'Consolidated legislative reports and exports', icon:'file-bar-chart',
      actions: button({label:'Print All', icon:'printer', variant:'outline', onclick:'window.print()'})})}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
      ${statCard({label:'Ordinances Enacted', value:ordinances.filter(o=>o.status==='Enacted').length, icon:'scale', color:'primary'})}
      ${statCard({label:'Resolutions Adopted', value:resolutions.filter(r=>r.status==='Adopted').length, icon:'file-text', color:'emerald'})}
      ${statCard({label:'Sessions Held', value:sessions.filter(s=>s.status==='Concluded').length, icon:'calendar-check', color:'amber'})}
      ${statCard({label:'Citizen Feedback', value:feedback.length, icon:'message-square', color:'slate'})}
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-6">
      ${card({title:'Legislative Output Trend', subtitle:'Monthly measures processed', icon:'trending-up', body:`<div class="ls-chart-wrap h-56"><canvas id="repTrend"></canvas></div>`})}
      ${card({title:'Status Overview', subtitle:'All measures', icon:'pie-chart', body:`<div class="ls-chart-wrap h-56"><canvas id="repStatus"></canvas></div>`})}
    </div>

    ${sectionTitle('Downloadable Reports')}
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
      ${reportCard('Ordinance Registry', 'Complete list of all ordinances with status and dates', 'scroll-text', 'primary', "exportCSV('ordinances-registry.csv', getAll('ordinances'), ['number','title','status','category','dateIntroduced','dateApproved'])")}
      ${reportCard('Resolution Registry', 'All resolutions with sponsors and outcomes', 'file-text', 'emerald', "exportCSV('resolutions-registry.csv', getAll('resolutions'), ['number','title','status','category','dateIntroduced'])")}
      ${reportCard('Session Log', 'Session history with attendance and duration', 'calendar-days', 'amber', "exportCSV('session-log.csv', getAll('sessions'), ['title','type','date','time','venue','status','duration'])")}
      ${reportCard('Committee Roster', 'Committee members and assignments', 'users', 'primary', "exportCSV('committee-roster.csv', getAll('committeeMembers'), ['committeeId','memberId','role'])")}
      ${reportCard('Voting Record', 'All recorded votes with results', 'vote', 'indigo', "exportCSV('voting-record.csv', getAll('votes'), ['subject','date','type','yes','no','abstain','result'])")}
      ${reportCard('Citizen Feedback Report', 'All public feedback with responses', 'message-square', 'red', "exportCSV('feedback-report.csv', getAll('feedback'), ['type','subject','category','ward','priority','status','date','response'])")}
    </div>

    <div class="mb-6">${aiInsight({title:'Executive Summary (AI-Generated)', body:`This reporting period covers ${ordinances.length} ordinances and ${resolutions.length} resolutions, of which ${ordinances.filter(o=>o.status==='Enacted').length} ordinances were enacted and ${resolutions.filter(r=>r.status==='Adopted').length} resolutions adopted. ${sessions.filter(s=>s.status==='Concluded').length} sessions concluded with an average attendance of 88%. Citizen engagement generated ${feedback.length} feedback items with a ${feedback.filter(f=>f.response).length}/${feedback.length} response rate. Legislative productivity is trending upward, with the Committees on Laws & Ordinances and Finance driving the majority of enacted measures.`})}</div>
  `;
  renderIcons();

  lineChart('repTrend', ['Jul','Aug','Sep','Oct','Nov','Dec'], [{label:'Ordinances',data:[3,5,4,6,7,6],borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,.12)'},{label:'Resolutions',data:[6,8,5,9,7,8],borderColor:'#059669',backgroundColor:'rgba(5,150,105,.12)'}], {plugins:{legend:{position:'bottom'}}});
  const all = [...ordinances.map(o=>o.status), ...resolutions.map(r=>r.status)];
  const counts = {}; all.forEach(s=>counts[s]=(counts[s]||0)+1);
  doughnutChart('repStatus', Object.keys(counts), Object.values(counts), Object.keys(counts).map(s=>STATUS_COLORS[s]||'#94a3b8'), {plugins:{legend:{position:'right'}}});
}

function reportCard(title, desc, iconName, color, onclick) {
  const colors = {primary:'text-primary-600 bg-primary-50 dark:bg-primary-900/30 dark:text-primary-300',emerald:'text-emerald-600 bg-emerald-50 dark:bg-emerald-900/30 dark:text-emerald-300',amber:'text-amber-600 bg-amber-50 dark:bg-amber-900/30 dark:text-amber-300',indigo:'text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30 dark:text-indigo-300',red:'text-red-600 bg-red-50 dark:bg-red-900/30 dark:text-red-300'};
  return `<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-5 ls-card-hover">
    <div class="flex items-center gap-3 mb-3"><span class="w-10 h-10 rounded-lg ${colors[color]} flex items-center justify-center">${icon(iconName,'w-5 h-5')}</span><div><h3 class="font-semibold text-slate-800 dark:text-slate-100">${title}</h3><p class="text-xs text-slate-400">${desc}</p></div></div>
    ${button({label:'Export CSV', icon:'download', variant:'outline', size:'sm', onclick:"window.__repExport(()=>{"+onclick+"})"})}
  </div>`;
}

window.__repExport = function(fn){ fn(); };

window.__export_reports = window.__export_reports || {};
window.__export_reports.renderReports = renderReports;
} // end page scope

// ===== settings.js =====
{ // page scope
/* ==========================================================================
   pages/settings.js — Settings & Preferences
   Profile, dark mode, density, notifications, data management (reset/export).
   ========================================================================== */



function renderSettings(main, route) {
  const s = getSettings();
  const members = getAll('councilMembers').slice(0,1)[0];

  main.innerHTML = `
    ${pageHeader({title:'Settings', subtitle:'Manage your profile, preferences, and application data', icon:'settings'})}

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="space-y-4">
        ${card({title:'Your Profile', icon:'user', body:`
          <div class="flex flex-col items-center text-center py-2">
            <div class="w-20 h-20 rounded-full bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white text-2xl font-bold mb-3">RA</div>
            <h3 class="font-semibold text-slate-800 dark:text-white">Hon. Ricardo Almazan</h3>
            <p class="text-sm text-slate-500">City Secretary · Presiding</p>
            <p class="text-xs text-slate-400 mt-1">${members?.email||'almazan@council.gov'}</p>
          </div>
        `})}
        ${aiInsight({title:'Data Status', body:`Your workspace is stored locally in the browser. No data is sent to any server. ${getAll('ordinances').length} ordinances, ${getAll('feedback').length} feedback items, and ${getAll('sessions').length} sessions are currently saved.`})}
      </div>

      <div class="lg:col-span-2 space-y-4">
        ${card({title:'Appearance', icon:'palette', body:`
          <div class="space-y-4">
            <div class="flex items-center justify-between">
              <div><p class="text-sm font-medium text-slate-700 dark:text-slate-200">Dark Mode</p><p class="text-xs text-slate-400">Toggle the UI theme (UI only)</p></div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" id="set-dark" ${s.darkMode?'checked':''} class="sr-only peer">
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div>
              </label>
            </div>
            <div class="flex items-center justify-between">
              <div><p class="text-sm font-medium text-slate-700 dark:text-slate-200">Compact Density</p><p class="text-xs text-slate-400">Reduce spacing for more content</p></div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" id="set-compact" ${s.density==='compact'?'checked':''} class="sr-only peer">
                <div class="w-11 h-6 bg-slate-200 rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div>
              </label>
            </div>
          </div>
        `})}

        ${card({title:'Notifications', icon:'bell', body:`
          <div class="flex items-center justify-between">
            <div><p class="text-sm font-medium text-slate-700 dark:text-slate-200">Enable Notifications</p><p class="text-xs text-slate-400">Receive in-app alerts for new feedback, sessions, etc.</p></div>
            <label class="relative inline-flex items-center cursor-pointer">
              <input type="checkbox" id="set-notif" ${s.notifications?'checked':''} class="sr-only peer">
              <div class="w-11 h-6 bg-slate-200 rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary-600"></div>
            </label>
          </div>
        `})}

        ${card({title:'Organization', icon:'building-2', body:`
          <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5">Organization Name</label><input id="set-org" type="text" value="${s.orgName||'City Legislative Council'}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400"></div>
            <div><label class="block text-xs font-medium text-slate-600 dark:text-slate-300 mb-1.5">Fiscal Year</label><input id="set-fy" type="number" value="${s.fiscalYear||2024}" class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 outline-none focus:border-primary-400"></div>
          </div>
        `})}

        ${sectionTitle('Save Changes')}
        ${button({label:'Save Settings', icon:'save', variant:'primary', onclick:"window.__saveSettings()"})}

        ${sectionTitle('Data Management')}
        ${card({title:'Export & Reset', icon:'database', body:`
          <div class="flex flex-wrap gap-3">
            ${button({label:'Export All Data (JSON)', icon:'download', variant:'outline', onclick:"window.__exportData()"})}
            ${button({label:'Reset to Sample Data', icon:'refresh-ccw', variant:'danger', onclick:"window.__resetData()"})}
          </div>
          <p class="text-xs text-slate-400 mt-3">Export downloads a JSON backup of all Local Storage data. Reset clears and re-seeds the application with the original sample dataset.</p>
        `})}
      </div>
    </div>
  `;
  renderIcons();

  document.getElementById('set-dark')?.addEventListener('change', e => {
    saveSettings({ darkMode: e.target.checked });
    document.documentElement.classList.toggle('dark', e.target.checked);
    toast('Theme updated','success');
  });
}

window.__saveSettings = function(){
  const patch = {
    density: document.getElementById('set-compact')?.checked ? 'compact' : 'comfortable',
    notifications: document.getElementById('set-notif')?.checked,
    orgName: document.getElementById('set-org')?.value,
    fiscalYear: parseInt(document.getElementById('set-fy')?.value)||2024
  };
  saveSettings(patch);
  toast('Settings saved','success');
};

window.__exportData = function(){
  const data = exportData();
  const blob = new Blob([JSON.stringify(data, null, 2)], {type:'application/json'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href=url; a.download='lsms-backup.json'; a.click();
  URL.revokeObjectURL(url);
  toast('Data exported','success');
};

window.__resetData = function(){
  confirmDialog({title:'Reset all data?', message:'This will erase all your changes and restore the original sample dataset. This cannot be undone.', confirmLabel:'Reset', onConfirm:()=>{ resetAll(); toast('Data reset — reloading…','success'); setTimeout(()=>location.reload(),1000); }});
};

window.__export_settings = window.__export_settings || {};
window.__export_settings.renderSettings = renderSettings;
} // end page scope

// ===== help.js =====
{ // page scope
/* ==========================================================================
   pages/help.js — Help, User Guide, UI Guide, Workflows
   ========================================================================== */


function renderHelp(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'Help & Documentation', subtitle:'User guide, UI guide, and workflow documentation', icon:'help-circle'})}

    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${helpCard('Getting Started', 'Quick start guide for first-time users', 'rocket', 'primary', '#/help#getting-started')}
      ${helpCard('Core Workflows', 'Step-by-step legislative processes', 'git-branch', 'emerald', '#/help#workflows')}
      ${helpCard('UI Guide', 'Understand the interface and components', 'layout-panel-top', 'amber', '#/help#ui-guide')}
      ${helpCard('FAQ', 'Frequently asked questions', 'message-circle-question', 'indigo', '#/help#faq')}
      ${helpCard('Keyboard Shortcuts', 'Speed up your navigation', 'keyboard', 'slate', '#/help#shortcuts')}
      ${helpCard('Troubleshooting', 'Common issues and solutions', 'wrench', 'red', '#/help#troubleshooting')}
    </div>

    <div class="space-y-4" id="help-content">
      ${section('getting-started','Getting Started', 'rocket', `
        <p class="text-sm text-slate-600 dark:text-slate-300 mb-3">Welcome to the Legislative Services Management System. This prototype runs entirely in your browser using Local Storage — no backend or login required. On first launch, the system auto-seeds with comprehensive sample data including council members, ordinances, sessions, committees, and more.</p>
        <div class="grid sm:grid-cols-2 gap-3">
          ${step('1','Open the Dashboard','View executive statistics, charts, and AI insights at a glance.')}
          ${step('2','Explore Modules','Use the sidebar to navigate all 10 legislative management modules.')}
          ${step('3','Create Records','Use the "New" buttons to add ordinances, sessions, votes, and more.')}
          ${step('4','Track Changes','Every action updates the dashboard and recent activity in real time.')}
        </div>
      `)}

      ${section('workflows','Core Workflows', 'git-branch', `
        <div class="space-y-4">
          ${workflow('Ordinance Lifecycle','scale','Drafting → Committee Review → Pending Review → Approved → Published → Enacted','Create an ordinance, then use the "Advance Stage" button to move it through each stage. Each advancement updates the status, dates, and dashboard statistics automatically.')}
          ${workflow('Session Management','calendar-clock','Schedule → Attendance → Live Tracking → Conclude → Minutes','Schedule a session, mark attendance, start the live timer during the session, then conclude to auto-generate meeting minutes.')}
          ${workflow('Voting Simulation','vote','Quorum Check → Cast Votes → Tally → Record Result','Verify quorum, open a new vote, simulate each member\'s yes/no/abstain vote, then record the result with animated charts.')}
          ${workflow('Citizen Feedback','message-square','Submit → Validate → Respond → Acknowledge','Citizens submit feedback via the public portal; staff validate, respond, and acknowledge — each step tracked with timestamps.')}
        </div>
      `)}

      ${section('ui-guide','UI Guide', 'layout-panel-top', `
        <div class="grid sm:grid-cols-2 gap-3">
          ${uiItem('Sidebar','Menu','The fixed left sidebar contains all 16 navigation items grouped logically. Active items are highlighted with a blue accent.')}
          ${uiItem('Top Bar','Bar','Contains breadcrumbs, global search, dark mode toggle, notifications, and quick actions.')}
          ${uiItem('Cards','Box','Reusable cards display grouped content with optional icons, titles, subtitles, and action buttons.')}
          ${uiItem('Tables','Table','Sortable, filterable tables with hover states, status badges, and per-row action menus.')}
          ${uiItem('Modals','Dialog','Click "New" or "Edit" buttons to open modal forms. Press ESC or click outside to close.')}
          ${uiItem('Toasts','Bell','Success, error, warning, and info notifications appear in the top-right corner.')}
        </div>
      `)}

      ${section('faq','Frequently Asked Questions', 'message-circle-question', `
        <div class="space-y-3">
          ${faq('Where is my data stored?','All data is stored in your browser\'s Local Storage. Nothing is sent to any server. Clearing your browser data will reset the application.')}
          ${faq('Do I need to log in?','No. This is a front-end prototype for demonstration. Simply open index.html with Live Server.')}
          ${faq('Can I export my data?','Yes. Go to Settings → Data Management → Export All Data to download a JSON backup. You can also export individual reports from the Reports page.')}
          ${faq('How do I reset the sample data?','Go to Settings → Data Management → Reset to Sample Data. This restores the original dataset.')}
          ${faq('Does dark mode persist?','Yes. Dark mode preference is saved in Local Storage and applied on every visit.')}
          ${faq('Is this a real production system?','No. This is a high-fidelity prototype for a Capstone Proposal. It simulates backend behavior using JavaScript and Local Storage.')}
        </div>
      `)}

      ${section('shortcuts','Keyboard Shortcuts', 'keyboard', `
        <div class="grid sm:grid-cols-2 gap-3">
          ${kbd('ESC','Close any open modal')}
          ${kbd('/','Focus the global search bar')}
          ${kbd('g then d','Go to Dashboard')}
          ${kbd('g then o','Go to Ordinances')}
          ${kbd('g then s','Go to Sessions')}
          ${kbd('Ctrl/Cmd + P','Print current page')}
        </div>
      `)}

      ${section('troubleshooting','Troubleshooting', 'wrench', `
        <div class="space-y-3">
          ${faq('Charts not displaying?','Ensure you have an internet connection — Chart.js loads from a CDN. Refresh the page.')}
          ${faq('Icons showing as text?','Lucide icons load from a CDN. Check your connection and refresh.')}
          ${faq('Data disappeared?','You may have cleared browser storage or used a private window. Reset to sample data from Settings.')}
          ${faq('Page not loading with Live Server?','Make sure you open index.html (not a page file) and that Live Server is running on the correct port.')}
        </div>
      `)}
    </div>
  `;
  renderIcons();
}

function helpCard(title, desc, iconName, color, hash) {
  const colors = {primary:'text-primary-600 bg-primary-50 dark:bg-primary-900/30 dark:text-primary-300',emerald:'text-emerald-600 bg-emerald-50 dark:bg-emerald-900/30 dark:text-emerald-300',amber:'text-amber-600 bg-amber-50 dark:bg-amber-900/30 dark:text-amber-300',indigo:'text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30 dark:text-indigo-300',slate:'text-slate-600 bg-slate-100 dark:bg-slate-800 dark:text-slate-300',red:'text-red-600 bg-red-50 dark:bg-red-900/30 dark:text-red-300'};
  return `<a href="${hash}" class="flex items-center gap-3 p-4 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 ls-card-hover">
    <span class="w-10 h-10 rounded-lg ${colors[color]} flex items-center justify-center shrink-0">${icon(iconName,'w-5 h-5')}</span>
    <div class="min-w-0"><p class="font-medium text-slate-800 dark:text-slate-100">${title}</p><p class="text-xs text-slate-400">${desc}</p></div>
    ${icon('arrow-right','w-4 h-4 text-slate-400 ml-auto')}
  </a>`;
}

function section(id, title, iconName, body) {
  return card({title, icon: iconName, className: 'scroll-mt-20', body});
}

function step(n, title, desc) {
  return `<div class="flex gap-3 p-3 rounded-lg bg-slate-50 dark:bg-slate-800"><span class="w-7 h-7 rounded-full bg-primary-600 text-white flex items-center justify-center text-sm font-bold shrink-0">${n}</span><div><p class="text-sm font-medium text-slate-800 dark:text-slate-100">${title}</p><p class="text-xs text-slate-500">${desc}</p></div></div>`;
}

function workflow(title, iconName, flow, desc) {
  return `<div class="p-4 rounded-lg border border-slate-200 dark:border-slate-700"><div class="flex items-center gap-2 mb-2"><span class="text-primary-600">${icon(iconName,'w-5 h-5')}</span><h4 class="font-semibold text-slate-800 dark:text-slate-100">${title}</h4></div><p class="text-xs font-mono text-primary-600 bg-primary-50 dark:bg-primary-900/20 rounded px-2 py-1 mb-2">${flow}</p><p class="text-sm text-slate-600 dark:text-slate-300">${desc}</p></div>`;
}

function uiItem(title, label, desc) {
  return `<div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800"><div class="flex items-center gap-2 mb-1"><span class="badge bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">${label}</span><span class="text-sm font-medium text-slate-800 dark:text-slate-100">${title}</span></div><p class="text-xs text-slate-500">${desc}</p></div>`;
}

function faq(q, a) {
  return `<details class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800 group"><summary class="text-sm font-medium text-slate-800 dark:text-slate-100 cursor-pointer flex items-center justify-between">${q}<span class="text-slate-400 group-open:rotate-180 transition">${icon('chevron-down','w-4 h-4')}</span></summary><p class="text-sm text-slate-600 dark:text-slate-300 mt-2">${a}</p></details>`;
}

function kbd(key, desc) {
  return `<div class="flex items-center gap-3 p-3 rounded-lg bg-slate-50 dark:bg-slate-800"><kbd class="px-2 py-1 text-xs font-mono bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded shadow-sm">${key}</kbd><span class="text-sm text-slate-600 dark:text-slate-300">${desc}</span></div>`;
}

window.__export_help = window.__export_help || {};
window.__export_help.renderHelp = renderHelp;
} // end page scope

// ===== about.js =====
{ // page scope
/* ==========================================================================
   pages/about.js — About / Project Overview
   ========================================================================== */


function renderAbout(main, route) {
  main.innerHTML = `
    ${pageHeader({title:'About This Project', subtitle:'Capstone Proposal Prototype — Legislative Services Management System', icon:'info'})}

    <div class="rounded-2xl bg-gradient-to-br from-primary-600 via-primary-700 to-primary-900 p-8 text-white mb-6 relative overflow-hidden">
      <div class="absolute -right-8 -top-8 opacity-10">${icon('landmark','w-48 h-48')}</div>
      <div class="relative">
        <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center mb-4">${icon('landmark','w-7 h-7')}</div>
        <h1 class="text-2xl font-bold mb-2">Legislative Services Management System</h1>
        <p class="text-primary-100 max-w-2xl">A high-fidelity, front-end prototype designed for a Capstone Proposal presentation. It simulates a complete enterprise government information system for managing the full legislative lifecycle — entirely in the browser, with no backend.</p>
        <div class="flex flex-wrap gap-2 mt-4">
          ${['HTML5','Tailwind CSS','Vanilla JS (ES6)','Chart.js','Lucide Icons','Local Storage'].map(t=>`<span class="px-3 py-1 rounded-full bg-white/15 text-xs font-medium">${t}</span>`).join('')}
        </div>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4 mb-6">
      ${card({title:'Project Objectives', icon:'target', body:`<ul class="space-y-2 text-sm text-slate-600 dark:text-slate-300">${['Deliver a realistic, production-ready appearance','Implement 10 fully interactive legislative modules','Use only front-end technologies (no backend)','Persist all data in Local Storage','Maintain one cohesive enterprise design system','Provide AI-style insights and simulated workflows'].map(o=>`<li class="flex items-start gap-2">${icon('check-circle-2','w-4 h-4 text-emerald-500 shrink-0 mt-0.5')}<span>${o}</span></li>`).join('')}</ul>`})}
      ${card({title:'10 Core Modules', icon:'grid-3x3', body:`<ul class="space-y-1.5 text-sm text-slate-600 dark:text-slate-300">${['Ordinance & Resolution Lifecycle','Session & Meeting Management','Agenda & Calendar Management','Committee Management & Assignment','Voting, Quorum & Decision Support','Records & Document Management','Public Hearing & Consultation','Archives & Historical Repository','Research, Policy Analysis & Impact','Citizen Engagement & Feedback'].map((m,i)=>`<li class="flex items-center gap-2"><span class="w-5 h-5 rounded bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300 text-xs font-bold flex items-center justify-center">${i+1}</span>${m}</li>`).join('')}</ul>`})}
      ${card({title:'Technology Stack', icon:'layers', body:`<div class="space-y-3 text-sm">${tech('HTML5','Structure & semantic markup').join('')}${tech('Tailwind CSS','Utility-first styling + custom design tokens').join('')}${tech('Vanilla JS (ES6 Modules)','No framework, no build step').join('')}${tech('Chart.js','Interactive data visualizations').join('')}${tech('Lucide Icons','Consistent iconography').join('')}${tech('Local Storage','Client-side data persistence').join('')}</div>`})}
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-6">
      ${card({title:'Key Features', icon:'sparkles', body:`<div class="grid sm:grid-cols-2 gap-2 text-sm text-slate-600 dark:text-slate-300">${['Executive dashboard with live charts','Hash-based SPA routing','Reusable component library','Dark mode toggle','Global search','Toast notifications','Modal forms & confirmations','Loading skeletons','Empty states','Print & CSV export','AI-style insights','Animated voting simulation','Live session timer','Quorum calculator','Version history tracking','Audit trails'].map(f=>`<span class="flex items-center gap-1.5">${icon('check','w-4 h-4 text-emerald-500')}<span>${f}</span></span>`).join('')}</div>`})}
      ${card({title:'Design System', icon:'palette', body:`<div class="space-y-3"><div class="flex items-center gap-2"><span class="w-6 h-6 rounded bg-primary-600"></span><span class="text-sm text-slate-600 dark:text-slate-300">Primary — Blue (#1e40af)</span></div><div class="flex items-center gap-2"><span class="w-6 h-6 rounded bg-slate-500"></span><span class="text-sm text-slate-600 dark:text-slate-300">Slate Gray — Neutral base</span></div><div class="flex items-center gap-2"><span class="w-6 h-6 rounded bg-emerald-600"></span><span class="text-sm text-slate-600 dark:text-slate-300">Emerald — Success states</span></div><div class="flex items-center gap-2"><span class="w-6 h-6 rounded bg-amber-500"></span><span class="text-sm text-slate-600 dark:text-slate-300">Amber — Warnings</span></div><div class="flex items-center gap-2"><span class="w-6 h-6 rounded bg-red-600"></span><span class="text-sm text-slate-600 dark:text-slate-300">Red — Errors / critical</span></div></div><p class="text-xs text-slate-400 mt-3">Inter font · rounded-xl components · soft shadows · subtle animations · responsive layouts</p>`})}
    </div>

    ${aiInsight({title:'About This Prototype', body:'This system was designed to convincingly demonstrate a complete legislative management workflow during a Capstone Proposal presentation. Every module is fully interactive using Local Storage — you can create, edit, delete, search, filter, print, and export records. The dashboard updates in real time as you interact with any module, simulating a connected backend. No authentication, database, or server is required.'})}

    ${card({title:'Disclaimer', icon:'shield-alert', body:`<p class="text-sm text-slate-600 dark:text-slate-300">This is a demonstration prototype intended for academic and presentation purposes only. It is not affiliated with any real government body. All council members, ordinances, and data are fictional. No real data is collected, transmitted, or stored on any server.</p>`})}
  `;
  renderIcons();
}

function tech(name, desc) {
  return [`<div class="flex items-center justify-between p-2 rounded-lg bg-slate-50 dark:bg-slate-800"><div><p class="font-medium text-slate-800 dark:text-slate-100">${name}</p><p class="text-xs text-slate-400">${desc}</p></div>${icon('check-circle-2','w-4 h-4 text-emerald-500')}</div>`];
}

window.__export_about = window.__export_about || {};
window.__export_about.renderAbout = renderAbout;
} // end page scope

// ===== app.js =====
/**
 * Legislative Services Management System — Prototype
 * app.js — Application bootstrap, hash router, navigation, search, notifications, dark mode.
 *
 * This is the single entry point (loaded as an ES6 module from index.html).
 * It wires together the store, mock data, the UI toolkit, charts, and every
 * page module, then drives a lightweight hash-based SPA router.
 *
 *  — No backend, no build step. Open index.html with Live Server and it runs. —
 */






















/* ---------------------------------------------------------------------------
 * Route registry — each entry maps a hash fragment to a render function plus
 * the metadata used to build the sidebar and the breadcrumb trail.
 * ------------------------------------------------------------------------- */
const ROUTES = [
  { hash: '#/scheduling',   label: 'Session Scheduling Module',                icon: 'calendar-check', render: window.__export_scheduling.renderScheduling,   crumb: 'Session Scheduling Module' },
  { hash: '#/agenda',       label: 'Agenda Preparation Module',                icon: 'calendar-days',  render: window.__export_agenda.renderAgenda,           crumb: 'Agenda Preparation Module' },
  { hash: '#/attendance',   label: 'Attendance and Quorum Monitoring Module',  icon: 'user-check',      render: window.__export_attendance.renderAttendance,   crumb: 'Attendance and Quorum Monitoring Module' },
  { hash: '#/proceedings',  label: 'Session Proceedings Documentation Module', icon: 'file-text',       render: window.__export_proceedings.renderProceedings, crumb: 'Session Proceedings Documentation Module' },
  { hash: '#/minutes',      label: 'Minutes Generation Module',                icon: 'file-signature',  render: window.__export_minutesgen.renderMinutesGen,   crumb: 'Minutes Generation Module' },
  { hash: '#/tracking',     label: 'Real-Time Session Tracking Module',        icon: 'radio',           render: window.__export_tracking.renderTracking,       crumb: 'Real-Time Session Tracking Module' },
];

const DEFAULT_ROUTE = '#/scheduling';
let currentHash = DEFAULT_ROUTE;

/* ---------------------------------------------------------------------------
 * Sidebar navigation — renders the nav list into #ls-nav, with a divider
 * between the core legislative modules and the system/utility routes.
 * ------------------------------------------------------------------------- */
function renderSidebar() {
  const nav = document.getElementById('ls-nav');
  if (!nav) return;

  const link = (r) => {
    const active = currentHash === r.hash;
    return `
      <a href="${r.hash}"
         class="ls-nav-link group flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors ${active
            ? 'bg-primary-600 text-white shadow-sm'
            : 'text-slate-400 hover:text-white hover:bg-slate-800/70'}"
         data-route="${r.hash}">
        <span class="shrink-0 ${active ? 'text-white' : 'text-slate-500 group-hover:text-slate-300'}">${icon(r.icon, 'w-[18px] h-[18px]')}</span>
        <span class="truncate font-medium">${r.label}</span>
      </a>`;
  };

  nav.innerHTML = ROUTES.map(link).join('');

  renderIcons();
}

/* ---------------------------------------------------------------------------
 * Breadcrumb — Home > current page label.
 * ------------------------------------------------------------------------- */
function renderBreadcrumb() {
  const el = document.getElementById('ls-breadcrumb');
  if (!el) return;
  const route = ROUTES.find(r => r.hash === currentHash) || ROUTES[0];
  el.innerHTML = breadcrumbs([
    { label: 'Home', href: '#/scheduling', icon: 'home' },
    { label: route.crumb }
  ]);
  renderIcons();
}

/* ---------------------------------------------------------------------------
 * Topbar title + mobile menu + notifications badge.
 * ------------------------------------------------------------------------- */
function renderTopbar() {
  // notification dot visibility
  const unread = getAll('notifications').filter(n => !n.read).length;
  const dot = document.getElementById('ls-notif-dot');
  if (dot) dot.style.display = unread ? 'block' : 'none';
}

/* ---------------------------------------------------------------------------
 * Router — resolves the current hash, tears down charts, mounts the page.
 * ------------------------------------------------------------------------- */
function router() {
  const hash = window.location.hash || DEFAULT_ROUTE;
  currentHash = hash;
  const route = ROUTES.find(r => r.hash === hash) || ROUTES[0];
  const main = document.getElementById('ls-main');

  // Tear down any charts from the previous page to avoid canvas reuse errors.
  destroyAll();

  if (!main) { console.warn('[router] #ls-main not found'); return; }

  // Smooth page transition: fade out, swap, fade in.
  main.classList.add('opacity-0');
  const mount = () => {
    try {
      route.render(main, route);
      // Keep main scroll at top on navigation.
      main.parentElement && (main.parentElement.scrollTop = 0);
      window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
    } catch (err) {
      console.error('[router] page render failed:', err);
      main.innerHTML = `<div class="p-10 text-center text-red-500">Failed to render <b>${route.label}</b>. See console for details.</div>`;
    }
    renderIcons();
    main.classList.remove('opacity-0');
  };

  if (window.requestAnimationFrame) {
    window.requestAnimationFrame(mount);
  } else {
    mount();
  }

  renderSidebar();
  renderBreadcrumb();
  renderTopbar();

  // Close the mobile sidebar after navigation.
  closeMobileSidebar();
}

/* ---------------------------------------------------------------------------
 * Mobile sidebar toggle.
 * ------------------------------------------------------------------------- */
function openMobileSidebar() {
  const sb = document.getElementById('ls-sidebar');
  const bd = document.getElementById('ls-mobile-backdrop');
  if (sb) sb.classList.remove('-translate-x-full');
  if (bd) bd.classList.remove('hidden');
}
function closeMobileSidebar() {
  const sb = document.getElementById('ls-sidebar');
  const bd = document.getElementById('ls-mobile-backdrop');
  // Only collapse on small screens (avoid affecting desktop sticky layout).
  if (window.matchMedia('(max-width: 1023px)').matches) {
    if (sb) sb.classList.add('-translate-x-full');
  }
  if (bd) bd.classList.add('hidden');
}

/* ---------------------------------------------------------------------------
 * Notifications panel.
 * ------------------------------------------------------------------------- */
function renderNotificationsPanel() {
  const panel = document.getElementById('ls-notif-panel');
  if (!panel) return;
  const items = getAll('notifications').slice().sort((a, b) => new Date(b.date) - new Date(a.date));
  const unread = items.filter(n => !n.read).length;

  panel.innerHTML = `
    <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200 dark:border-slate-700">
      <h3 class="font-semibold text-sm text-slate-800 dark:text-slate-100">Notifications</h3>
      ${unread
        ? `<button id="ls-notif-markread" class="text-xs font-medium text-primary-600 hover:text-primary-700">Mark all read</button>`
        : `<span class="text-xs text-slate-400">All caught up</span>`}
    </div>
    <div class="max-h-96 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
      ${items.length ? items.map(n => `
        <div class="px-4 py-3 ${n.read ? '' : 'bg-primary-50/50 dark:bg-primary-900/10'}">
          <div class="flex items-start gap-2">
            <span class="mt-0.5 shrink-0 ${n.read ? 'text-slate-400' : 'text-primary-600'}">${icon(n.icon || 'bell', 'w-4 h-4')}</span>
            <div class="min-w-0">
              <p class="text-sm font-medium text-slate-800 dark:text-slate-100 leading-snug">${n.title}</p>
              ${n.body ? `<p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 leading-snug">${n.body}</p>` : ''}
              <p class="text-[10px] text-slate-400 mt-1">${n.date}</p>
            </div>
          </div>
        </div>
      `).join('') : `<div class="px-4 py-10 text-center text-sm text-slate-400">No notifications</div>`}
    </div>`;

  const markReadBtn = document.getElementById('ls-notif-markread');
  if (markReadBtn) {
    markReadBtn.onclick = () => {
      markAllRead();
      renderNotificationsPanel();
      renderTopbar();
      toast('All notifications marked as read', 'success');
    };
  }
  renderIcons();
}

function toggleNotifications(force) {
  const panel = document.getElementById('ls-notif-panel');
  if (!panel) return;
  const willOpen = force != null ? force : panel.classList.contains('hidden');
  if (willOpen) {
    renderNotificationsPanel();
    panel.classList.remove('hidden');
  } else {
    panel.classList.add('hidden');
  }
}

/* ---------------------------------------------------------------------------
 * Global search — searches sessions, agenda items, council members, and
 * proceedings log entries, then shows a dropdown.
 * ------------------------------------------------------------------------- */
const SEARCH_SOURCES = [
  { collection: 'sessions',      label: 'Session',     title: r => `${r.id} — ${r.title || r.type}`, sub: r => `${r.date} · ${r.status}`, route: '#/scheduling' },
  { collection: 'agenda',        label: 'Agenda Item',  title: r => r.title,        sub: r => `${r.category} · ${r.status}`,          route: '#/agenda' },
  { collection: 'councilMembers', label: 'Member',      title: r => r.name,         sub: r => `${r.position || ''}`,                  route: '#/attendance' },
  { collection: 'proceedings',   label: 'Proceedings',  title: r => r.note,         sub: r => `${r.author} · ${r.time ? new Date(r.time).toLocaleString() : ''}`, route: '#/proceedings' },
];

function performGlobalSearch(q) {
  const box = document.getElementById('ls-search-results');
  if (!box) return;
  q = (q || '').trim().toLowerCase();
  if (!q) { box.classList.add('hidden'); box.innerHTML = ''; return; }

  const hits = [];
  for (const src of SEARCH_SOURCES) {
    const items = getAll(src.collection) || [];
    for (const r of items) {
      const title = (src.title(r) || '').toString().toLowerCase();
      const sub   = (src.sub(r) || '').toString().toLowerCase();
      if (title.includes(q) || sub.includes(q) || (r.id || '').toLowerCase().includes(q)) {
        hits.push({ src, record: r });
        if (hits.length >= 12) break;
      }
    }
    if (hits.length >= 12) break;
  }

  if (!hits.length) {
    box.innerHTML = `<div class="p-4 text-sm text-slate-500 text-center">No matches for "<b>${q}</b>"</div>`;
  } else {
    box.innerHTML = hits.map(h => `
      <a href="${h.src.route}" class="flex items-center gap-3 px-3 py-2.5 hover:bg-slate-50 dark:hover:bg-slate-700/50 border-b border-slate-100 dark:border-slate-700 last:border-0">
        <span class="shrink-0 w-7 h-7 rounded-lg bg-primary-100 dark:bg-primary-900/40 text-primary-600 flex items-center justify-center">${icon('search', 'w-3.5 h-3.5')}</span>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">${h.src.title(h.record)}</p>
          <p class="text-xs text-slate-400 truncate">${h.src.sub(h.record)}</p>
        </div>
        <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-slate-400 bg-slate-100 dark:bg-slate-700 px-1.5 py-0.5 rounded">${h.src.label}</span>
      </a>`).join('') + `<div class="p-2 text-center"><span class="text-[11px] text-slate-400">Press <kbd class="px-1 py-0.5 bg-slate-100 dark:bg-slate-700 rounded text-[10px]">Enter</kbd> to go to ${hits[0].src.route.replace('#/','')}</span></div>`;
  }
  box.classList.remove('hidden');
  renderIcons();
}

/* ---------------------------------------------------------------------------
 * Dark mode — persisted in settings, toggles the 'dark' class on <html>.
 * ------------------------------------------------------------------------- */
function applyDarkMode() {
  const dark = getSettings().darkMode === true;
  document.documentElement.classList.toggle('dark', dark);
  const btn = document.getElementById('ls-dark-toggle');
  if (btn) {
    btn.innerHTML = icon(dark ? 'sun' : 'moon', 'w-5 h-5');
    renderIcons();
  }
}

function toggleDarkMode() {
  const dark = !document.documentElement.classList.contains('dark');
  document.documentElement.classList.toggle('dark', dark);
  saveSettings({ darkMode: dark });
  applyDarkMode();
  toast(dark ? 'Dark mode enabled' : 'Light mode enabled', 'info');
}

/* ---------------------------------------------------------------------------
 * Live auto-refresh — when any collection changes, re-render the Real-Time
 * Session Tracking module if it is the active route, refresh the
 * notification dot, and bump the notifications panel if open.
 * ------------------------------------------------------------------------- */
function wireLiveUpdates() {
  onAny((collection) => {
    // Notification badge should always reflect fresh state.
    renderTopbar();
    if (!document.getElementById('ls-notif-panel')?.classList.contains('hidden')) {
      renderNotificationsPanel();
    }
    // Re-render Real-Time Session Tracking live when it is the active view.
    if (currentHash === '#/tracking') {
      const main = document.getElementById('ls-main');
      destroyAll();
      try { window.__export_tracking.renderTracking(main, ROUTES[0]); renderIcons(); }
      catch (e) { console.error('[live] tracking refresh failed', e); }
    }
  });
}

/* ---------------------------------------------------------------------------
 * Keyboard shortcuts.
 * ------------------------------------------------------------------------- */
function wireKeyboard() {
  document.addEventListener('keydown', (e) => {
    // "/" focuses global search (unless already typing in a field).
    if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) {
      e.preventDefault();
      const s = document.getElementById('ls-global-search');
      if (s) s.focus();
    }
    // Escape closes search dropdown / notification panel / modals.
    if (e.key === 'Escape') {
      const box = document.getElementById('ls-search-results'); if (box) box.classList.add('hidden');
      const panel = document.getElementById('ls-notif-panel'); if (panel) panel.classList.add('hidden');
      const root = document.getElementById('ls-modal-root'); if (root) root.innerHTML = '';
    }
  });

  // Click-away handlers for dropdown-style overlays.
  document.addEventListener('click', (e) => {
    const searchWrap = document.getElementById('ls-global-search')?.parentElement;
    const searchBox  = document.getElementById('ls-search-results');
    if (searchBox && !searchBox.classList.contains('hidden') && searchWrap && !searchWrap.contains(e.target)) {
      searchBox.classList.add('hidden');
    }
    const notifBtn   = document.getElementById('ls-notif-btn');
    const notifPanel = document.getElementById('ls-notif-panel');
    if (notifPanel && !notifPanel.classList.contains('hidden') && notifBtn && !notifBtn.contains(e.target) && !notifPanel.contains(e.target)) {
      notifPanel.classList.add('hidden');
    }
  });
}

/* ---------------------------------------------------------------------------
 * Topbar wiring — search input, dark toggle, notif button, mobile menu.
 * ------------------------------------------------------------------------- */
function wireTopbar() {
  const search = document.getElementById('ls-global-search');
  if (search) {
    let t;
    search.addEventListener('input', (e) => {
      clearTimeout(t);
      t = setTimeout(() => performGlobalSearch(e.target.value), 120);
    });
    search.addEventListener('focus', (e) => { if (e.target.value) performGlobalSearch(e.target.value); });
    search.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        const first = document.querySelector('#ls-search-results a');
        if (first) { window.location.hash = first.getAttribute('href'); search.value = ''; document.getElementById('ls-search-results').classList.add('hidden'); }
      }
    });
  }

  const darkBtn = document.getElementById('ls-dark-toggle');
  if (darkBtn) darkBtn.addEventListener('click', toggleDarkMode);

  const notifBtn = document.getElementById('ls-notif-btn');
  if (notifBtn) notifBtn.addEventListener('click', () => toggleNotifications());

  const menuBtn = document.getElementById('ls-menu-btn');
  if (menuBtn) menuBtn.addEventListener('click', openMobileSidebar);

  const backdrop = document.getElementById('ls-mobile-backdrop');
  if (backdrop) backdrop.addEventListener('click', closeMobileSidebar);
}

/* ---------------------------------------------------------------------------
 * Boot.
 * ------------------------------------------------------------------------- */
function boot() {
  // 1. Initialize the store (seeds mock data on first run).
  initStore(seedData);

  // 2. Apply persisted theme before first paint of content.
  applyDarkMode();

  // 3. Build the sidebar + topbar wiring once.
  renderSidebar();
  wireTopbar();

  // 4. Wire live updates + keyboard shortcuts.
  wireLiveUpdates();
  wireKeyboard();

  // 5. Routing.
  window.addEventListener('hashchange', router);
  if (!window.location.hash) window.location.hash = DEFAULT_ROUTE;
  router();

  // 6. Hide the loading screen once the first page is mounted.
  if (window.__lsLoaderInterval) clearInterval(window.__lsLoaderInterval);
  const loader = document.getElementById('ls-loading-screen');
  if (loader && loader.style.display !== 'none') {
    loader.style.transition = 'opacity 0.4s ease';
    loader.style.opacity = '0';
    setTimeout(() => { loader.style.display = 'none'; }, 420);
  }

  // 7. Friendly welcome toast (only on first ever load).
  if (!sessionStorage.getItem('lsms_welcomed')) {
    sessionStorage.setItem('lsms_welcomed', '1');
    setTimeout(() => toast('Welcome to the Legislative Services Management System prototype', 'success', 4000), 700);
  }

  // 8. Simulate a live notification nudge after a short delay (demo feel).
  setTimeout(() => {
    const n = getAll('notifications');
    if (n.length && !n.some(x => x.title?.includes('Live demo'))) {
      pushNotification({
        icon: 'sparkles',
        title: 'Live demo active',
        body: 'Interact with any module — changes persist in your browser via Local Storage.',
        date: new Date().toISOString().slice(0, 16).replace('T', ' '),
        read: false
      });
      renderTopbar();
    }
  }, 6000);

  renderIcons();
}

/* Run when the DOM is ready. */
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}


})();
