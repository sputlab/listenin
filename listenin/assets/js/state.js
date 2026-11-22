// Stato condiviso dell'applicazione e sincronizzazione dei filtri con l'URL.

import { locatorToLatLon } from './format.js?v=0.8.0';
import { BASE } from './api.js?v=0.8.0';

export const state = {
  meta: null,
  user: null,
  filters: {},
  page: 1,
  sort: 'freq',
  dir: 'asc',
  anonAck: false,      // presa visione del disclaimer per le segnalazioni anonime (sessione corrente)
  services: new Map(), // slug → { name, color, root, rootName }
};

export const FILTER_KEYS = ['q', 'service', 'mode', 'band', 'plan', 'country', 'region', 'province', 'status',
  'fmin', 'fmax', 'locator', 'callsign', 'digital', 'heard_since', 'affidabilita', 'missione', 'near', 'km', 'approx', 'site'];

export function indexServices(meta) {
  for (const root of meta.taxonomy.services) {
    state.services.set(root.slug, { name: root.name, color: root.color, root: root.slug, rootName: root.name, isRoot: true });
    for (const c of root.children) state.services.set(c.slug, { name: c.name, color: root.color, root: root.slug, rootName: root.name });
  }
}

// ---------------- URL parlanti: /frequenze/{regione}/{provincia}/{modo o servizio} ----------------
const slugify = (x) => String(x).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
const relPath = () => (location.pathname.startsWith(BASE) ? location.pathname.slice(BASE.length) : '');
const PRETTY = ['region', 'province', 'mode', 'service'];

function provinceInfo(code) {
  for (const [region, list] of Object.entries(state.meta.taxonomy.provinces)) {
    const p = list.find((x) => x.code === code);
    if (p) return { region, slug: p.slug };
  }
  return null;
}

/** Filtri espressi dal percorso della pagina (vuoto se non è un URL di ricerca). */
function filtersFromPath() {
  const m = relPath().match(/^frequenze(?:\/([a-z0-9-]+))?(?:\/([a-z0-9-]+))?(?:\/([a-z0-9-]+))?\/?$/);
  if (!m || !state.meta) return {};
  const t = state.meta.taxonomy, out = {};
  const [, reg, prov, seg] = m;
  if (reg && reg !== 'italia' && t.regions.some((r) => r.slug === reg)) out.region = reg;
  if (prov && prov !== 'tutte') {
    for (const [region, list] of Object.entries(t.provinces)) {
      const p = list.find((x) => x.slug === prov);
      if (p) { out.province = p.code; out.region = region; }
    }
  }
  if (seg) {
    const mode = t.modes.find((x) => slugify(x.code) === seg);
    if (mode) out.mode = mode.code; else if (state.services.has(seg)) out.service = seg;
  }
  return out;
}

/** Percorso parlante per i filtri correnti, oppure null se non sono esprimibili. Restituisce anche i filtri rimasti nella query. */
function prettyPath(f) {
  if (!state.meta || !PRETTY.some((k) => f[k])) return null;
  const rest = { ...f };
  let region = f.region || null, prov = null;
  if (f.province) {
    const info = provinceInfo(f.province);
    if (!info || (region && region !== info.region)) return null;
    region = info.region; prov = info.slug; delete rest.province;
  }
  if (region) delete rest.region;
  let seg = null;
  if (f.mode && !f.mode.includes(',') && state.meta.taxonomy.modes.some((x) => x.code === f.mode)) { seg = slugify(f.mode); delete rest.mode; }
  else if (f.service && !f.mode && state.services.has(f.service)) { seg = f.service; delete rest.service; }
  const parts = [region || 'italia', prov || 'tutte'];
  if (seg) parts.push(seg); else if (!prov) parts.pop();
  if (!region && !prov && !seg) return null;
  return { path: 'frequenze/' + parts.join('/'), rest };
}

export function readUrl() {
  const p = new URLSearchParams(location.search);
  state.filters = filtersFromPath();
  for (const k of FILTER_KEYS) if (p.get(k)) state.filters[k] = p.get(k);
  state.page = Math.max(1, parseInt(p.get('page') || '1', 10) || 1);
  state.sort = p.get('sort') || (state.filters.near ? 'distance' : 'freq');
  state.dir = p.get('dir') === 'desc' ? 'desc' : 'asc';
}

export function queryString(extra = {}, { paging = true, filters = state.filters } = {}) {
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(filters)) if (v !== '' && v != null) p.set(k, v);
  if (paging) {
    if (state.page > 1) p.set('page', state.page);
    if (state.sort !== 'freq' && !(state.sort === 'distance' && state.filters.near)) p.set('sort', state.sort);
    if (state.dir !== 'asc') p.set('dir', state.dir);
  }
  for (const [k, v] of Object.entries(extra)) p.set(k, v);
  return p.toString();
}

/** Aggiorna la barra degli indirizzi con i filtri correnti: percorso parlante quando possibile, il resto in query. */
export function writeUrl() {
  const pretty = prettyPath(state.filters);
  const qs = queryString({}, { filters: pretty ? pretty.rest : state.filters });
  history.replaceState(null, '', BASE + (pretty ? pretty.path : '') + (qs ? '?' + qs : '') + location.hash);
}

/** Indirizzo condivisibile dei filtri correnti (senza pagina). */
export function shareUrl() {
  const pretty = prettyPath(state.filters);
  const qs = queryString({}, { paging: false, filters: pretty ? pretty.rest : state.filters });
  return location.origin + BASE + (pretty ? pretty.path : '') + (qs ? '?' + qs : '');
}

// ---------------- QTH dell'utente (per distanza e azimut) ----------------
// Il QTH temporaneo (da geolocalizzazione o digitato) resta solo nella sessione del browser; per gli utenti
// registrati vale in alternativa il locatore predefinito del profilo.
export function currentQth() {
  let temp = null;
  try { temp = JSON.parse(sessionStorage.getItem('rrf-qth') || 'null'); } catch { /* storage non disponibile */ }
  if (temp && Number.isFinite(temp.lat)) return temp;
  const loc = state.user?.default_locator;
  const c = loc ? locatorToLatLon(loc) : null;
  return c ? { ...c, locator: loc, source: 'profilo' } : null;
}

export function setTempQth(q) {
  try {
    if (q) sessionStorage.setItem('rrf-qth', JSON.stringify(q)); else sessionStorage.removeItem('rrf-qth');
  } catch { /* storage non disponibile */ }
}

export const isModerator = () => !!state.user && ['moderator', 'admin'].includes(state.user.role);
export const isAdmin = () => state.user?.role === 'admin';
