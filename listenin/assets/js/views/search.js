// Ricerca: filtri combinabili, striscia spettrale, tabella risultati, esportazione.
import { get, apiUrl } from '../api.js?v=0.8.0';
import { $, h, clear, select, field, toast } from '../dom.js?v=0.8.0';
import { mhz, date, plural, STATUS, RELIABILITY, locatorToLatLon, distanceLabel } from '../format.js?v=0.8.0';
import { state, readUrl, writeUrl, queryString, shareUrl, isAdmin, currentQth, setTempQth } from '../state.js?v=0.8.0';
import { createMapPanel } from './map.js?v=0.8.0';
import { saveSearch, getSearch, favoriteProvinces, setFavoriteProvince, searchKey } from '../offline.js?v=0.8.0';
import { quickCheckin } from '../checkin.js?v=0.8.0';

let lastQuery = null;
let lastResult = null;
let reqSeq = 0;
let openDetail = () => {};
let openReport = () => {};
const mapPanel = createMapPanel({ onChange: () => { refresh(); renderFilters(); } });

export function bindActions(actions) {
  openDetail = actions.openDetail;
  openReport = actions.openReport;
}

// ------------------------------------------------------------------
//  Filtri
// ------------------------------------------------------------------
export function renderFilters() {
  const t = state.meta.taxonomy;
  const f = state.filters;
  const form = $('#filter-form');

  const serviceOptions = [{ value: '', label: 'Tutti gli ambiti' }];
  for (const s of t.services) {
    serviceOptions.push({ group: s.name, options: [{ value: s.slug, label: 'Tutto: ' + s.name }, ...s.children.map((c) => ({ value: c.slug, label: c.name }))] });
  }
  const regionSel = select('region', [{ value: '', label: 'Tutte' }, ...t.regions.map((r) => ({ value: r.slug, label: r.name }))], f.region || '');
  const provinceSel = select('province', provinceOptions(f.region), f.province || '');
  regionSel.addEventListener('change', () => {
    clear(provinceSel, ...provinceOptions(regionSel.value).map((o) => h('option', { value: o.value }, o.label)));
  });
  const statuses = (f.status || '').split(',').filter(Boolean);

  clear(form,
    field('Ambito e servizio', select('service', serviceOptions, f.service || '')),
    field('Modo', select('mode', [{ value: '', label: 'Tutti i modi' }, ...t.modes.map((m) => ({ value: m.code, label: m.code === 'ND' ? 'Non determinato' : m.code }))], f.mode || '')),
    h('div', { class: 'field' },
      h('span', { class: 'label' }, 'Intervallo di frequenza (MHz)'),
      h('div', { class: 'range-pair' },
        h('input', { type: 'text', name: 'fmin', inputmode: 'decimal', placeholder: 'da', 'aria-label': 'Frequenza minima in MHz', value: f.fmin || '' }),
        h('input', { type: 'text', name: 'fmax', inputmode: 'decimal', placeholder: 'a', 'aria-label': 'Frequenza massima in MHz', value: f.fmax || '' }))),
    field('Banda ITU', select('band', [{ value: '', label: 'Tutte' }, ...t.itu_bands.map((b) => ({ value: b.code, label: `${b.code} (${b.name})` }))], f.band || '')),
    field('Piano di frequenza', select('plan', [{ value: '', label: 'Tutti' }, ...t.band_plans.map((b) => ({ value: b.slug, label: b.name }))], f.plan || '')),
    field('Regione', regionSel),
    field('Provincia', provinceSel, { hint: 'Include le frequenze che coprono la provincia' }),
    f.province ? (() => {
      const on = favoriteProvinces().includes(f.province);
      return h('button', { type: 'button', class: 'btn small', 'aria-pressed': String(on), onclick: async () => {
        setFavoriteProvince(f.province, !on);
        if (!on) {
          try { await saveOfflineFor({ province: f.province }, 'Provincia ' + f.province); toast('Provincia ' + f.province + ' tra i preferiti e salvata per l’uso offline.'); } catch (e) { toast('Tra i preferiti, ma salvataggio offline non riuscito: ' + e.message, 'error'); }
        }
        renderFilters(); renderResults(lastResult);
      } }, on ? '★ Tra le province preferite' : '☆ Aggiungi ai preferiti (e salva offline)');
    })() : null,
    field('Locatore QTH', h('input', { type: 'text', name: 'locator', maxlength: 8, placeholder: 'es. JN65', value: f.locator || '', autocapitalize: 'characters' })),
    field('Nominativo', h('input', { type: 'text', name: 'callsign', maxlength: 20, placeholder: 'es. IR3UGO', value: f.callsign || '', autocapitalize: 'characters' })),
    h('fieldset', { class: 'field' },
      h('legend', { class: 'label' }, 'Stato operativo'),
      h('div', { class: 'check-row' }, ...t.statuses.map((s) =>
        h('label', {}, h('input', { type: 'checkbox', name: 'status', value: s.value, checked: statuses.includes(s.value) }), s.label)))),
    h('fieldset', { class: 'field' },
      h('legend', { class: 'label' }, 'Affidabilità'),
      h('div', { class: 'check-row' }, ...t.reliability.map((r) =>
        h('label', {}, h('input', { type: 'checkbox', name: 'affidabilita', value: r.value, checked: (f.affidabilita || '').split(',').includes(r.value) }), r.label)))),
    h('div', { class: 'check-row' },
      h('label', {}, h('input', { type: 'checkbox', name: 'digital', value: '1', checked: f.digital === '1' }), 'Solo modi digitali e dati')),
    qthControl(),
    proximityControl(),
    h('div', { class: 'actions' },
      h('button', { type: 'submit', class: 'btn primary' }, 'Applica filtri'),
      h('button', { type: 'button', class: 'btn only-mobile', onclick: () => document.body.classList.remove('filters-open') }, 'Chiudi')),
  );

  form.onsubmit = (e) => { e.preventDefault(); applyForm(form); document.body.classList.remove('filters-open'); };
  form.onchange = (e) => { if (e.target.tagName === 'SELECT' || e.target.type === 'checkbox') applyForm(form); };
}

/** Il mio QTH: serve a calcolare distanza e azimut di ogni frequenza; resta solo nella sessione del browser. */
function qthControl() {
  const q = currentQth();
  const input = h('input', { type: 'text', name: 'qth', maxlength: 8, placeholder: 'es. JN65tw', value: q?.locator || '', autocapitalize: 'characters', 'aria-label': 'Il mio locatore QTH' });
  const msg = h('div', { class: 'hint', 'aria-live': 'polite' }, q ? `QTH attivo (${q.source}): distanza e azimut sono mostrati nei risultati.` : 'Imposta il tuo QTH per vedere distanza e azimut.');
  const apply = () => {
    const c = locatorToLatLon(input.value);
    if (!c) { msg.textContent = 'Locatore non valido (es. JN65tw).'; return; }
    setTempQth({ ...c, locator: input.value.trim(), source: 'manuale' });
    renderResults(lastResult);
    msg.textContent = 'QTH impostato per questa sessione.';
  };
  return h('fieldset', { class: 'field' },
    h('legend', { class: 'label' }, 'Il mio QTH (distanza e azimut)'),
    input,
    h('div', { class: 'actions' },
      h('button', { type: 'button', class: 'btn small', onclick: apply }, 'Imposta'),
      'geolocation' in navigator ? h('button', { type: 'button', class: 'btn small', onclick: () => {
        msg.textContent = 'Richiesta della posizione…';
        navigator.geolocation.getCurrentPosition((p) => {
          setTempQth({ lat: p.coords.latitude, lon: p.coords.longitude, locator: '', source: 'posizione del dispositivo' });
          input.value = '';
          renderResults(lastResult);
          msg.textContent = 'Posizione usata solo in questa sessione, mai inviata al server.';
        }, () => { msg.textContent = 'Posizione non disponibile.'; }, { timeout: 10000 });
      } }, 'Usa la mia posizione') : null,
      q ? h('button', { type: 'button', class: 'btn small', onclick: () => { setTempQth(null); input.value = ''; renderResults(lastResult); msg.textContent = 'QTH temporaneo rimosso.'; } }, 'Rimuovi') : null),
    msg);
}

/** Filtro di prossimità: frequenze entro 10, 30, 50 o 100 km da un comune o dal proprio QTH. */
function proximityControl() {
  const f = state.filters;
  const q = currentQth();
  const input = h('input', { type: 'search', name: 'place_q', placeholder: 'es. Gorizia', autocomplete: 'off', 'aria-label': 'Comune di partenza', 'aria-controls': 'place-list' });
  const list = h('ul', { class: 'place-list', id: 'place-list' });
  const note = h('div', { class: 'hint', 'aria-live': 'polite' }, f.near ? '' : 'Cerca le frequenze ricevibili entro un raggio da un comune o dal tuo QTH.');
  const radius = select('km', ['10', '30', '50', '100'].map((k) => ({ value: k, label: k + ' km' })), f.km || '30', { 'aria-label': 'Raggio di ricerca' });
  const apply = (lat, lon, label) => {
    state.filters = { ...state.filters, near: `${lat.toFixed(5)},${lon.toFixed(5)}`, km: radius.value };
    state.nearLabel = label; state.sort = 'distance'; state.dir = 'asc'; state.page = 1;
    refresh(); renderFilters();
  };
  let timer = null;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      if (input.value.trim().length < 2) { clear(list); return; }
      try {
        const r = await get('/geo/place?q=' + encodeURIComponent(input.value.trim()));
        clear(list, ...(r.items.length ? r.items.map((x) => h('li', {}, h('button', { type: 'button', onclick: () => apply(x.lat, x.lon, `${x.name} (${x.province})`) }, `${x.name} (${x.province})`)))
          : [h('li', { class: 'hint' }, r.has_coordinates ? 'Nessun comune trovato.' : 'Le coordinate dei comuni non sono ancora state caricate: l’amministratore può importarle da Moderazione → Sistema.')]));
      } catch { clear(list); }
    }, 300);
  });
  return h('fieldset', { class: 'field' },
    h('legend', { class: 'label' }, 'Vicino a… (prossimità)'),
    input, list,
    q ? h('button', { type: 'button', class: 'btn small', onclick: () => apply(q.lat, q.lon, q.locator ? 'il mio QTH ' + q.locator : 'la mia posizione') }, 'Dal mio QTH') : null,
    h('div', { class: 'field' }, h('label', {}, 'Raggio ', radius)),
    h('label', { class: 'check' }, h('input', { type: 'checkbox', name: 'approx', value: '1', checked: f.approx === '1' }), 'Includi posizioni approssimate (centro provincia)'),
    note);
}

function provinceOptions(region) {
  const prov = state.meta.taxonomy.provinces;
  const list = region ? (prov[region] || []) : Object.values(prov).flat().sort((a, b) => a.name.localeCompare(b.name, 'it'));
  return [{ value: '', label: 'Tutte' }, ...list.map((p) => ({ value: p.code, label: `${p.name} (${p.code})` }))];
}

function applyForm(form) {
  const keep = state.filters.q ? { q: state.filters.q } : {};
  const fd = new FormData(form);
  const out = { ...keep };
  for (const k of ['service', 'mode', 'band', 'plan', 'region', 'province', 'fmin', 'fmax', 'locator', 'callsign']) {
    const v = (fd.get(k) || '').toString().trim();
    if (v) out[k] = v;
  }
  const st = fd.getAll('status');
  if (st.length) out.status = st.join(',');
  const rl = fd.getAll('affidabilita');
  if (rl.length) out.affidabilita = rl.join(',');
  if (fd.get('digital')) out.digital = '1';
  if (state.filters.missione) out.missione = state.filters.missione;
  if (state.filters.site) out.site = state.filters.site;
  if (state.filters.near) {
    out.near = state.filters.near;
    out.km = fd.get('km') || state.filters.km || '30';
    if (fd.get('approx')) out.approx = '1';
  }
  state.filters = out;
  state.page = 1;
  refresh();
}

export function setFilter(key, value) {
  if (value === null || value === '') delete state.filters[key];
  else state.filters[key] = value;
  state.page = 1;
  renderFilters();
  refresh();
}

export function resetFilters() {
  state.filters = {};
  state.page = 1;
  $('#q').value = '';
  renderFilters();
  refresh();
}

// ------------------------------------------------------------------
//  Risultati
// ------------------------------------------------------------------
export function showSearch({ force = false } = {}) {
  readUrl();
  writeUrl();   // porta i vecchi link (?region=…) alla forma parlante
  $('#q').value = state.filters.q || '';
  const qs = queryString();
  if (!force && qs === lastQuery && lastResult) return;
  renderFilters();
  load();
}

export function refresh() {
  writeUrl();
  load();
}

async function load() {
  const view = $('#view');
  const qs = queryString();
  lastQuery = qs;
  const seq = ++reqSeq;
  if (!lastResult) clear(view, h('p', { class: 'loading' }, 'Caricamento delle frequenze…'));
  view.setAttribute('aria-busy', 'true');
  try {
    const apiQs = qs + (state.sort === 'distance' && state.filters.near && !qs.includes('sort=') ? (qs ? '&' : '') + 'sort=distance' : '');
    const data = await get('/frequencies?' + apiQs + (apiQs ? '&' : '') + 'per_page=50');
    if (seq !== reqSeq) return;
    lastResult = data;
    renderResults(data);
    if (data.spectrum) renderSpectrum(data.spectrum);
  } catch (e) {
    if (seq !== reqSeq) return;
    if (await offlineFallback(e)) return;
    clear(view, h('div', { class: 'empty-state' }, h('p', {}, e.message),
      h('button', { class: 'btn', type: 'button', onclick: () => load() }, 'Riprova')));
  } finally {
    view.removeAttribute('aria-busy');
  }
}

/** Senza rete: se questa ricerca è stata salvata, si mostra la copia locale. */
async function offlineFallback(e) {
  if (e.code !== 'network') return false;
  const saved = await getSearch(searchKey(state.filters)).catch(() => null);
  if (!saved) return false;
  const per = 50, total = saved.items.length, pages = Math.max(1, Math.ceil(total / per)), page = Math.min(state.page, pages);
  lastResult = { items: saved.items.slice((page - 1) * per, page * per), total, page, pages, spectrum: null,
    offline: { savedAt: saved.savedAt, shown: total, total: saved.total } };
  renderResults(lastResult);
  return true;
}

/** Scarica tutte le pagine della ricerca (fino a 2.500 frequenze) e le conserva sul dispositivo. */
export async function saveOfflineFor(filters, name) {
  const MAX_PAGES = 25;
  const qs = queryString({ per_page: '100', sort: 'freq', dir: 'asc' }, { paging: false, filters });
  const items = [];
  let total = 0, pages = 1;
  for (let page = 1; page <= Math.min(pages, MAX_PAGES); page++) {
    const r = await get('/frequencies?' + qs + '&page=' + page);
    total = r.total; pages = r.pages;
    items.push(...r.items);
  }
  await saveSearch({ key: searchKey(filters), name, filters, savedAt: new Date().toISOString(), total, items });
  return { saved: items.length, total };
}

function describeFilters(filters) {
  const t = state.meta.taxonomy;
  const parts = [];
  if (filters.region) parts.push(t.regions.find((r) => r.slug === filters.region)?.name || filters.region);
  if (filters.province) parts.push('provincia ' + filters.province);
  if (filters.mode) parts.push(filters.mode);
  if (filters.service) parts.push(state.services.get(filters.service)?.name || filters.service);
  if (filters.q) parts.push('«' + filters.q + '»');
  return parts.join(' · ') || 'Tutta Italia';
}

async function saveOffline() {
  const name = describeFilters(state.filters);
  toast('Salvataggio di «' + name + '» in corso…');
  try {
    const r = await saveOfflineFor(state.filters, name);
    toast(r.saved < r.total ? `Salvate ${r.saved.toLocaleString('it-IT')} di ${r.total.toLocaleString('it-IT')} frequenze (limite 2.500): restringi la ricerca per averle tutte.` : `Salvate ${r.saved.toLocaleString('it-IT')} frequenze per l’uso senza copertura dati.`);
  } catch (e) { toast(e.message || 'Salvataggio non riuscito.', 'error'); }
}

async function copyLink() {
  const url = shareUrl();
  try { await navigator.clipboard.writeText(url); toast('Link copiato negli appunti.'); } catch { window.prompt('Copia il link:', url); }
}

// Ultima notizia pubblicata negli ultimi 14 giorni, in evidenza sopra i risultati
let latestNews;
async function newsBanner() {
  if (latestNews === undefined) {
    latestNews = null;
    try {
      const r = await get('/news?per_page=1');
      const n = r.items[0];
      if (n && (Date.now() - new Date(n.published_at.replace(' ', 'T')).getTime()) < 14 * 864e5) latestNews = n;
    } catch { /* le notizie sono facoltative */ }
    if (latestNews && $('#view .news-banner') === null && document.body.dataset.route === 'search') {
      $('#view').prepend(h('p', { class: 'notice news-banner' }, h('strong', {}, latestNews.category_label + ': '), h('a', { href: '#/notizia/' + latestNews.slug }, latestNews.title)));
    }
  }
}

function favoritesBar() {
  const favs = favoriteProvinces();
  if (!favs.length) return null;
  return h('div', { class: 'fav-bar' }, h('span', { class: 'small muted' }, 'Province preferite:'),
    ...favs.map((code) => h('button', { type: 'button', class: 'btn small', 'aria-pressed': String(state.filters.province === code), onclick: () => {
      state.filters = { province: code }; state.page = 1; refresh(); renderFilters();
    } }, '★ ' + code)));
}

function exportMenu() {
  const qs = queryString({}, { paging: false });
  const sep = qs ? '&' : '';
  const link = (fmt, title, hint) => h('a', { href: apiUrl('/export?' + qs + sep + 'format=' + fmt), download: '' }, title, h('small', {}, hint));
  const feed = (fmt) => {
    const p = new URLSearchParams({ format: fmt });
    if (state.filters.region) p.set('regione', state.filters.region);
    if (state.filters.province) p.set('provincia', state.filters.province);
    if (state.filters.band) p.set('banda', state.filters.band);
    return apiUrl('/feed?' + p.toString());
  };
  return h('details', { class: 'export-menu' },
    h('summary', { class: 'btn small' }, 'Esporta ▾'),
    h('div', { class: 'menu-list' },
      link('csv', 'CSV', 'tutte le colonne, per fogli di calcolo'),
      link('json', 'JSON', 'dati strutturati'),
      link('chirp', 'CHIRP (.csv)', 'analogici e D-STAR, da provare prima su pochi canali'),
      link('opengd77', 'OpenGD77 (.csv)', 'FM e DMR, da provare prima su pochi canali'),
      h('a', { href: feed('rss') }, 'Feed RSS', h('small', {}, 'novità per la regione, provincia o banda selezionata')),
      h('a', { href: feed('atom') }, 'Feed Atom', h('small', {}, 'stesso contenuto, formato Atom'))));
}

function renderResults(data) {
  const view = $('#view');
  if (!data) return;
  const qth = currentQth();
  const sortSel = select('sort', [
    { value: 'freq:asc', label: 'Frequenza crescente' },
    { value: 'freq:desc', label: 'Frequenza decrescente' },
    { value: 'updated:desc', label: 'Aggiornate di recente' },
    { value: 'heard:desc', label: 'Ascoltate di recente' },
    { value: 'heard:asc', label: 'Ascolto più vecchio' },
    { value: 'region:asc', label: 'Regione' },
    { value: 'service:asc', label: 'Servizio' },
    ...(state.filters.near ? [{ value: 'distance:asc', label: 'Distanza dal punto' }] : []),
  ], `${state.sort}:${state.dir}`, { 'aria-label': 'Ordina i risultati' });
  sortSel.addEventListener('change', () => {
    [state.sort, state.dir] = sortSel.value.split(':');
    state.page = 1;
    refresh();
  });

  const toolbar = h('div', { class: 'toolbar' },
    h('button', { type: 'button', class: 'btn small only-mobile', onclick: () => document.body.classList.add('filters-open') }, 'Filtri'),
    h('p', { class: 'count', role: 'status' }, h('strong', {}, data.total.toLocaleString('it-IT')), data.total === 1 ? ' frequenza' : ' frequenze'),
    h('span', { class: 'spacer' }),
    sortSel,
    h('button', { type: 'button', class: 'btn small', onclick: copyLink, title: 'Copia l’indirizzo di questa ricerca' }, 'Copia link'),
    h('button', { type: 'button', class: 'btn small', onclick: saveOffline, title: 'Conserva questa ricerca sul dispositivo per usarla senza copertura dati' }, 'Salva offline'),
    exportMenu(),
  );
  const banner = data.offline ? h('p', { class: 'notice', role: 'status' }, `Senza connessione: stai consultando la copia salvata il ${date(data.offline.savedAt.slice(0, 10))}`
    + (data.offline.total > data.offline.shown ? ` (prime ${data.offline.shown.toLocaleString('it-IT')} frequenze su ${data.offline.total.toLocaleString('it-IT')}).` : '.')) : null;
  const chips = activeChips();
  if (!data.items.length) {
    clear(view, mapPanel.el, favoritesBar(), banner, toolbar, chips, h('div', { class: 'empty-state' },
      h('h2', {}, 'Nessuna frequenza con questi filtri'),
      h('p', { class: 'muted' }, 'Allarga l’intervallo o togli qualche filtro. Se conosci una frequenza che manca, proponila alla comunità.'),
      h('div', { class: 'actions' },
        h('button', { type: 'button', class: 'btn', onclick: resetFilters }, 'Azzera i filtri'),
        isAdmin() && !Object.keys(state.filters).length ? h('a', { class: 'btn', href: '#/moderazione/importazione' }, 'Importa i dati') : null,
        h('a', { class: 'btn primary', href: '#/proponi' }, 'Aggiungi una frequenza'))));
    mapPanel.refresh();
    return;
  }

  const tbody = h('tbody');
  for (const it of data.items) tbody.append(row(it, qth));
  const table = h('table', { class: 'freq-table' },
    h('caption', { class: 'visually-hidden' }, 'Frequenze trovate'),
    h('thead', {}, h('tr', {},
      h('th', { scope: 'col' }, 'Frequenza'), h('th', { scope: 'col' }, 'Modo'), h('th', { scope: 'col' }, 'Servizio'),
      h('th', { scope: 'col' }, 'Località'), h('th', { scope: 'col' }, state.filters.near ? 'Distanza dal punto' : qth ? 'Distanza e azimut' : 'Tono / codice'), h('th', { scope: 'col' }, 'Stato e affidabilità'),
      h('th', { scope: 'col' }, 'Riscontro'), h('th', { scope: 'col' }, h('span', { class: 'visually-hidden' }, 'Azioni')))),
    tbody);

  const pager = h('nav', { class: 'pager', 'aria-label': 'Pagine dei risultati' },
    h('button', { type: 'button', class: 'btn small', disabled: data.page <= 1, onclick: () => goPage(data.page - 1) }, 'Precedenti'),
    h('span', { class: 'muted small' }, `Pagina ${data.page} di ${data.pages}`),
    h('button', { type: 'button', class: 'btn small', disabled: data.page >= data.pages, onclick: () => goPage(data.page + 1) }, 'Successive'));

  clear(view, mapPanel.el, favoritesBar(), banner, toolbar, chips, table, data.pages > 1 ? pager : null);
  mapPanel.refresh();
  newsBanner();
  if (latestNews && !$('#view .news-banner')) view.prepend(h('p', { class: 'notice news-banner' }, h('strong', {}, latestNews.category_label + ': '), h('a', { href: '#/notizia/' + latestNews.slug }, latestNews.title)));
}

export function relBadge(it) {
  return h('span', { class: 'rel rel-' + it.reliability, title: it.last_heard_at ? 'Ultimo ascolto: ' + date(it.last_heard_at) : 'Nessun ascolto registrato' },
    'Affidabilità ' + RELIABILITY[it.reliability].toLowerCase());
}

function row(it, qth) {
  const loc = it.municipality
    ? `${it.municipality}${it.province ? ` (${it.province})` : ''}`
    : [it.province, it.region].filter(Boolean).join(', ') || it.area_note || '';
  const tone = it.tone || it.channel || '';
  const third = it.distance_km != null
    ? h('span', { class: 'dist' }, it.distance_km.toLocaleString('it-IT') + ' km' + (it.geo?.approx ? ' (circa)' : ''))
    : qth ? h('span', { class: 'dist' }, it.geo ? distanceLabel(qth, it.geo) + (it.geo.approx || it.geo.source === 'locatore' ? ' (circa)' : '') : '—') : tone;
  const tr = h('tr', { tabindex: '0', 'aria-label': `${it.freq_mhz} MHz, ${it.label}` },
    h('td', { class: 'f' },
      h('div', { class: 'freq-value' }, it.freq_mhz, h('span', { class: 'unit' }, 'MHz')),
      it.input_freq_mhz ? h('div', { class: 'freq-sub' }, 'in ' + it.input_freq_mhz) : null),
    h('td', { class: 'm' }, h('span', { class: 'mode' }, it.mode === 'ND' ? '—' : it.mode)),
    h('td', { class: 'd' },
      h('div', { class: 'label-main' }, it.label),
      h('div', { class: 'svc' }, [it.service.name, it.callsign].filter(Boolean).join(', '))),
    h('td', { class: 'loc' }, loc),
    h('td', { class: 'tone num' }, third),
    h('td', { class: 'st' }, h('span', { class: 'status ' + it.status }, STATUS[it.status]), relBadge(it)),
    h('td', { class: 'heard small muted' }, date(it.last_heard_at)),
    h('td', { class: 'row-action' },
      h('button', { type: 'button', class: 'btn small', title: 'Conferma di aver ascoltato questa frequenza oggi', 'aria-label': `Confermo l’ascolto di ${it.freq_mhz} MHz`,
        onclick: async (e) => {
          e.stopPropagation();
          const r = await quickCheckin(it.id);
          if (!r) return;
          Object.assign(it, { reliability: r.reliability, reliability_label: RELIABILITY[r.reliability], status: r.status, last_heard_at: r.last_heard_at, last_heard_days: 0 });
          const fresh = row(it, currentQth());
          tr.replaceWith(fresh);
          fresh.focus();
        } }, 'Ascoltata'),
      h('button', { type: 'button', class: 'btn small', onclick: (e) => { e.stopPropagation(); openReport(it.id, it); } }, 'Segnala')));
  if (it.service.color) tr.style.setProperty('--svc', it.service.color);
  tr.addEventListener('click', () => openDetail(it.id));
  tr.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target === tr) openDetail(it.id); });
  return tr;
}

function goPage(p) {
  state.page = p;
  refresh();
  $('#main').focus();
  window.scrollTo({ top: 0 });
}

function activeChips() {
  const t = state.meta.taxonomy;
  const labels = {
    q: (v) => `“${v}”`,
    service: (v) => state.services.get(v)?.name || v,
    mode: (v) => 'Modo ' + v,
    band: (v) => 'Banda ' + v,
    plan: (v) => t.band_plans.find((b) => b.slug === v)?.name || v,
    region: (v) => t.regions.find((r) => r.slug === v)?.name || v,
    province: (v) => 'Provincia ' + v,
    status: (v) => v.split(',').map((s) => STATUS[s]).join(', '),
    fmin: (v) => 'da ' + v + ' MHz',
    fmax: (v) => 'fino a ' + v + ' MHz',
    locator: (v) => 'QTH ' + v,
    callsign: (v) => v,
    digital: () => 'Solo digitali',
    affidabilita: (v) => 'Affidabilità: ' + v.split(',').map((x) => RELIABILITY[x]).join(', '),
    missione: () => 'Missione di recupero',
    heard_since: (v) => 'sentite dal ' + date(v),
    country: (v) => v,
    near: () => `Entro ${state.filters.km || 30} km da ${state.nearLabel || 'un punto scelto'}`,
    approx: () => 'Con posizioni approssimate',
    site: (v) => 'Postazione: ' + v.replace(/-/g, ' '),
  };
  const entries = Object.entries(state.filters).filter(([k]) => k !== 'km');
  if (!entries.length) return null;
  return h('ul', { class: 'chips', 'aria-label': 'Filtri attivi' }, ...entries.map(([k, v]) =>
    h('li', {}, h('button', { type: 'button', 'aria-label': 'Rimuovi filtro ' + (labels[k]?.(v) ?? v), onclick: () => {
      if (k === 'q') $('#q').value = '';
      if (k === 'near') { state.filters = { ...state.filters }; delete state.filters.km; delete state.filters.approx; if (state.sort === 'distance') state.sort = 'freq'; }
      setFilter(k, null);
    } }, labels[k]?.(v) ?? v))));
}

// ------------------------------------------------------------------
//  Striscia spettrale (scala logaritmica 100 kHz – 3 GHz)
// ------------------------------------------------------------------
function renderSpectrum(sp) {
  const track = $('#spectrum');
  if (!sp) return;
  const lmin = Math.log10(sp.min_hz), lmax = Math.log10(sp.max_hz);
  const pos = (hz) => Math.min(100, Math.max(0, (Math.log10(hz) - lmin) / (lmax - lmin) * 100));
  const max = Math.max(1, ...sp.bins);
  const n = sp.bins.length;

  const bins = h('div', { class: 'sp-bins' });
  sp.bins.forEach((count, i) => {
    const lo = Math.pow(10, lmin + (lmax - lmin) * i / n);
    const hi = Math.pow(10, lmin + (lmax - lmin) * (i + 1) / n);
    const label = `${fmtHz(lo)} – ${fmtHz(hi)}: ${plural(count, 'frequenza', 'frequenze')}`;
    const bar = h('span');
    bar.style.height = count ? Math.max(6, Math.sqrt(count / max) * 100) + '%' : '0';
    const btn = h('button', {
      type: 'button', class: 'sp-bin' + (count ? '' : ' empty'), title: label, 'aria-label': label,
      tabindex: count ? '0' : '-1',
      onclick: () => {
        if (!count) return;
        delete state.filters.band; delete state.filters.plan;
        state.filters.fmin = trimMHz(lo); state.filters.fmax = trimMHz(hi);
        state.page = 1; renderFilters(); refresh();
      },
    }, bar);
    btn.style.left = (i / n * 100) + '%';
    btn.style.width = (100 / n) + '%';
    btn.style.top = '0';
    bins.append(btn);
  });

  const bands = h('div', { class: 'sp-bands' });
  for (const b of state.meta.taxonomy.itu_bands) {
    const l = pos(Math.max(b.min_hz, sp.min_hz)), r = pos(Math.min(b.max_hz, sp.max_hz));
    if (r <= l) continue;
    const active = state.filters.band === b.code;
    const btn = h('button', {
      type: 'button', class: 'sp-band', 'aria-pressed': active ? 'true' : 'false',
      title: `${b.name}: ${fmtHz(b.min_hz)} – ${fmtHz(b.max_hz)}`,
      onclick: () => {
        delete state.filters.fmin; delete state.filters.fmax;
        setFilter('band', active ? null : b.code);
      },
    }, b.code);
    btn.style.left = l + '%';
    btn.style.width = (r - l) + '%';
    bands.append(btn);
  }

  const children = [bins, bands];
  const fmin = state.filters.fmin ? parseFloat(state.filters.fmin.replace(',', '.')) * 1e6 : null;
  const fmax = state.filters.fmax ? parseFloat(state.filters.fmax.replace(',', '.')) * 1e6 : null;
  if (fmin || fmax) {
    const range = h('div', { class: 'sp-range', 'aria-hidden': 'true' });
    const l = pos(fmin || sp.min_hz), r = pos(fmax || sp.max_hz);
    range.style.left = l + '%';
    range.style.width = Math.max(0.4, r - l) + '%';
    children.push(range);
  }
  clear(track, ...children);
  const cap = track.nextElementSibling?.classList.contains('sp-caption') ? track.nextElementSibling : null;
  const caption = h('div', { class: 'sp-caption' }, h('span', {}, '100 kHz'), h('span', {}, 'Tocca una colonna per filtrare l’intervallo, una banda per selezionarla'), h('span', {}, '3 GHz'));
  cap ? cap.replaceWith(caption) : track.after(caption);
}

function fmtHz(hz) {
  if (hz >= 1e9) return (hz / 1e9).toLocaleString('it-IT', { maximumFractionDigits: 2 }) + ' GHz';
  if (hz >= 1e6) return (hz / 1e6).toLocaleString('it-IT', { maximumFractionDigits: 1 }) + ' MHz';
  return (hz / 1e3).toLocaleString('it-IT', { maximumFractionDigits: 0 }) + ' kHz';
}

function trimMHz(hz) {
  const v = hz / 1e6;
  return v >= 100 ? v.toFixed(1) : v >= 1 ? v.toFixed(3) : v.toFixed(4);
}

export function invalidate() { lastResult = null; lastQuery = null; }
export const currentItems = () => lastResult?.items || [];
