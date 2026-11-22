// Scheda completa di una frequenza, con storico delle revisioni e azioni.
import { get, post, del } from '../api.js?v=0.7.1';
import { h, clear, openModal, closeModal, toast } from '../dom.js?v=0.7.1';
import { khz, bandwidth, date, dateTime, STATUS, REV_ACTION, RELIABILITY, distanceLabel, bearingDeg, compass, distanceKm } from '../format.js?v=0.7.1';
import { state, isModerator, currentQth } from '../state.js?v=0.7.1';
import { relBadge } from './search.js?v=0.7.1';
import { quickCheckin } from '../checkin.js?v=0.7.1';
import { createMap, estimateCoverageKm } from '../leaflet-loader.js?v=0.7.1';

let actions = {};
export function bindDetail(a) { actions = a; }

export async function showDetail(id) {
  const body = h('div', {}, h('p', { class: 'loading' }, 'Caricamento…'));
  openModal('Frequenza', body, { wide: true, onClose: () => { if (location.hash.startsWith('#/f/')) history.replaceState(null, '', location.pathname + location.search + '#/'); } });
  try {
    const f = await get('/frequencies/' + id);
    renderDetail(body, f);
  } catch (e) {
    clear(body, h('p', { class: 'form-error' }, e.message));
  }
}

function kv(pairs) {
  const dl = h('dl', { class: 'kv' });
  for (const [k, v] of pairs) {
    if (v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length)) continue;
    dl.append(h('dt', {}, k), h('dd', {}, v));
  }
  return dl.childNodes.length ? dl : h('p', { class: 'muted small' }, 'Nessun dato');
}

function renderDetail(body, f) {
  const svc = state.services.get(f.service.slug);
  const modeInfo = state.meta.taxonomy.modes.find((m) => m.code === f.mode);
  const hero = h('div', { class: 'detail-hero' },
    h('div', { class: 'freq-value' }, f.freq_mhz, h('span', { class: 'unit' }, 'MHz')),
    h('span', { class: 'mode' }, f.mode === 'ND' ? 'Modo non determinato' : f.mode),
    h('span', { class: 'status ' + f.status }, STATUS[f.status]),
    h('div', { class: 'what' }, f.label));
  if (f.service.color) hero.style.setProperty('--svc', f.service.color);

  const notices = [];
  if (f.deleted) notices.push(h('p', { class: 'notice danger' }, 'Questa frequenza è stata rimossa dal dataset pubblico.'));
  if (f.status === 'da_verificare') notices.push(h('p', { class: 'notice' }, 'Dato non ancora confermato da un ascolto recente. Se lo senti, segnalalo con una richiesta di aggiornamento.'));
  if (f.status === 'contestata') notices.push(h('p', { class: 'notice danger' }, 'Questa frequenza è contestata: i dati sono in discussione. Se la ascolti, la tua conferma aiuta a risolvere la contestazione.'));
  if (f.open_reports) notices.push(h('p', { class: 'notice' }, `Ci sono ${f.open_reports} segnalazioni aperte su questa frequenza.`));

  const rf = [
    ['Frequenza', `${f.freq_mhz} MHz`],
    ['In kHz', `${f.freq_khz} kHz`],
    ['Ingresso', f.input_freq_mhz ? `${f.input_freq_mhz} MHz` : null],
    ['Shift', f.offset_hz !== null ? khz(f.offset_hz, true) : null],
    ['Tono', f.tone],
    ['Color Code', f.color_code],
    ['Timeslot', f.dmr_timeslot],
    ['Talkgroup', f.talkgroup],
    ['RAN', f.ran],
    ['NAC', f.nac],
  ];
  const tech = [
    ['Modo', modeInfo ? modeInfo.name : f.mode],
    ['Larghezza di banda', f.bandwidth_hz ? bandwidth(f.bandwidth_hz) + (f.bandwidth_is_default ? ' (tipica del modo)' : '') : null],
    ['Banda ITU', f.itu_band],
    ['Piano', f.band_plan],
    ['Canale', f.channel],
  ];
  const geo = [
    ['Nazione', state.meta.taxonomy.countries.find((c) => c.iso2 === f.country)?.name],
    ['Regione', f.region],
    ['Provincia', f.province_name ? `${f.province_name} (${f.province})` : null],
    ['Copertura', f.coverage.length > 1 ? f.coverage.join(', ') : null],
    ['Comune', f.municipality],
    ['Area', f.area_note],
    ['Locatore', f.locator],
    ['Coordinate', f.latitude !== null ? `${f.latitude.toFixed(5)}, ${f.longitude.toFixed(5)}` : null],
    ['Copertura stimata', f.coverage_km ? `${f.coverage_km} km` : null],
  ];
  const ident = [
    ['Ambito', svc?.rootName],
    ['Servizio', f.service.name],
    ['Nominativo', f.callsign],
    ['Ente o gestore', f.entity],
    ['Stato', STATUS[f.status]],
    ['Ultimo riscontro', date(f.last_heard_at)],
    ['Aggiornata', dateTime(f.updated_at)],
  ];

  const historyBox = h('div', {}, h('button', { type: 'button', class: 'link', onclick: () => loadHistory(f.id, historyBox) }, 'Mostra lo storico delle modifiche'));

  const buttons = [
    h('button', { type: 'button', class: 'btn', onclick: () => actions.openReport(f.id, f) }, 'Segnala'),
  ];
  if (!f.deleted) {
    buttons.push(state.user
      ? h('a', { class: 'btn primary', href: `#/f/${f.id}/modifica` }, isModerator() ? 'Modifica' : 'Proponi una modifica')
      : h('a', { class: 'btn', href: '#/accedi' }, 'Accedi per proporre modifiche'));
  }
  if (isModerator()) {
    buttons.push(f.deleted
      ? h('button', { type: 'button', class: 'btn', onclick: () => restore(f.id) }, 'Ripristina')
      : h('button', { type: 'button', class: 'btn danger', onclick: () => remove(f.id) }, 'Rimuovi'));
  }

  const days = f.last_heard_days;
  const relSection = h('section', { class: 'detail-section' }, h('h3', {}, 'Affidabilità e ascolti'),
    h('p', {}, relBadge(f), ' ', f.last_heard_at
      ? `Ultimo ascolto: ${date(f.last_heard_at)} (${days === 0 ? 'oggi' : days === 1 ? 'ieri' : days + ' giorni fa'}).`
      : 'Nessun ascolto è mai stato registrato per questa frequenza.'),
    f.checkins?.total ? h('p', { class: 'small muted' }, `${f.checkins.total} conferme di ascolto in totale. Le più recenti:`) : null,
    f.checkins?.recent?.length ? h('ul', { class: 'recent' }, f.checkins.recent.map((c) =>
      h('li', {}, `${date(c.heard_on)} · ${c.by}${c.rst ? ' · rapporto ' + c.rst : ''}`))) : null,
    (() => {
      const q = currentQth();
      if (!q || !f.geo) return q && !f.geo ? h('p', { class: 'small muted' }, 'Per questa frequenza non è nota la posizione: distanza e azimut non disponibili.') : null;
      const az = Math.round(bearingDeg(q, f.geo));
      return h('p', {}, h('strong', {}, 'Dal tuo QTH: '), distanceLabel(q, f.geo), f.geo.source === 'locatore' ? ' (stima dal centro del locatore)' : '',
        h('span', { class: 'visually-hidden' }, ` distanza ${Math.round(distanceKm(q, f.geo))} chilometri, azimut ${az} gradi ${compass(az)}`));
    })());
  const checkinSection = f.deleted ? null : checkinBox(f);
  const mapSection = f.geo ? mapBox(f) : null;
  const sdrSection = sdrBox(f);
  const siteLine = f.site ? h('p', {}, h('strong', {}, 'Postazione: '), h('a', { href: '#/postazione/' + f.site.slug, onclick: () => closeModal() }, f.site.name),
    f.altitude_m != null ? ` (quota ${f.altitude_m.toLocaleString('it-IT')} m s.l.m.)` : '') : null;

  clear(body, hero, notices, relSection, checkinSection, siteLine, mapSection, sdrSection,
    h('div', { class: 'detail-grid' },
      h('section', {}, h('h3', {}, 'Parametri RF'), kv(rf)),
      h('section', {}, h('h3', {}, 'Classificazione tecnica'), kv(tech)),
      h('section', {}, h('h3', {}, 'Geografia'), kv(geo)),
      h('section', {}, h('h3', {}, 'Identificativo e stato'), kv(ident))),
    f.notes ? h('section', { class: 'detail-section' }, h('h3', {}, 'Note'), h('p', { class: 'prose' }, f.notes)) : null,
    h('section', { class: 'detail-section section' }, h('h3', {}, 'Storico'), historyBox),
    h('div', { class: 'actions' }, buttons),
    h('p', { class: 'muted small' }, `Record n. ${f.id}, versione ${f.version}. Fonte: ${{ manuale: 'contributo della comunità', inascolto: 'importazione da fonte esterna', import: 'importazione' }[f.source] || f.source}`
      + (f.sources?.length ? ` (${f.sources.length > 1 ? 'record unificati' : 'record'} ${f.sources.map((s) => s.ref).join(', ')})` : '') + '.'));
}

/** Mappa Leaflet/OpenStreetMap con la posizione, la quota e il cerchio indicativo di copertura. */
function mapBox(f) {
  const g = f.geo;
  const el = h('div', { class: 'detail-map', role: 'img', 'aria-label': `Mappa della posizione di ${f.label}` });
  const coverage = f.coverage_km || estimateCoverageKm(f.altitude_m);
  const origin = { coordinate: 'coordinate del record', sito: `postazione ${f.site?.name || ''}`.trim(), locatore: 'centro del locatore ' + (f.locator || ''),
    comune: 'centro del comune (posizione approssimata)', provincia: 'centro della provincia (posizione molto approssimata)' }[g.source] || g.source;
  const caption = h('p', { class: 'small muted' }, `Posizione: ${origin}.`,
    f.altitude_m != null ? ` Quota ${f.altitude_m.toLocaleString('it-IT')} m s.l.m.` : '',
    coverage ? ` Cerchio di copertura ${f.coverage_km ? 'indicato' : 'stimato dall’orizzonte radio'}: ${coverage} km (indicativo: dipende da potenza, antenna e terreno).` : '');
  const box = h('section', { class: 'detail-section' }, h('h3', {}, 'Mappa'), el, caption);
  // le tessere si caricano solo se la sezione è visibile: evita richieste inutili quando la scheda si apre e si chiude subito
  queueMicrotask(async () => {
    try {
      const { L, map } = await createMap(el, state.meta.taxonomy.map, [g.lat, g.lon], g.approx ? (g.source === 'provincia' ? 9 : 11) : 12);
      const marker = L.marker([g.lat, g.lon]).addTo(map);
      marker.bindPopup(`${f.freq_mhz} MHz · ${f.label}`);
      if (g.approx) L.circle([g.lat, g.lon], { radius: g.source === 'provincia' ? 15000 : 4000, color: '#B3261E', weight: 1, dashArray: '4 4', fillOpacity: 0.05 }).addTo(map);
      else if (coverage) L.circle([g.lat, g.lon], { radius: coverage * 1000, color: '#1D5C96', weight: 1.5, fillOpacity: 0.07 }).addTo(map);
      setTimeout(() => map.invalidateSize(), 250);
    } catch (e) { clear(el, h('p', { class: 'muted small' }, e.message)); }
  });
  return box;
}

// ---------------- Ascolto online su ricevitori WebSDR / KiwiSDR ----------------
const SDR_MODE = { FM: 'fm', NFM: 'fm', WFM: 'fm', AM: 'am', USB: 'usb', LSB: 'lsb', CW: 'cw' };
const fmtKhz = (hz) => String(Math.round(hz / 10) / 100);   // kHz con al massimo due decimali, senza zeri finali

function myReceiver() {
  try { const v = JSON.parse(localStorage.getItem('rrf-sdr') || 'null'); return v && /^[\w.-]+(:\d{2,5})?$/.test(v.host) && ['kiwisdr', 'websdr'].includes(v.kind) ? v : null; } catch { return null; }
}

function sdrBox(f) {
  const mode = SDR_MODE[f.mode];
  if (!mode) return null;
  const hz = f.freq_hz;
  const links = [];
  for (const r of state.meta.taxonomy.sdr || []) {
    if (hz >= r.min_hz && hz <= r.max_hz) links.push([r.name, r.url.replace('{khz}', fmtKhz(hz)).replace('{mode}', mode)]);
  }
  const mine = myReceiver();
  if (mine) {
    const param = mine.kind === 'kiwisdr' ? 'f' : 'tune';
    links.push([`Il mio ricevitore (${mine.host})`, `http://${mine.host}/?${param}=${fmtKhz(hz)}${mode}`]);
  }
  const kind = h('select', { 'aria-label': 'Tipo di ricevitore' }, h('option', { value: 'kiwisdr' }, 'KiwiSDR'), h('option', { value: 'websdr' }, 'WebSDR'));
  const host = h('input', { type: 'text', placeholder: 'indirizzo:porta, es. mio.kiwi.it:8073', maxlength: 80, 'aria-label': 'Indirizzo del ricevitore', value: mine?.host || '' });
  if (mine) kind.value = mine.kind;
  const setup = h('details', { class: 'sdr-setup' }, h('summary', { class: 'small' }, 'Usa un altro ricevitore'),
    h('p', { class: 'small muted' }, 'Indica il tuo ricevitore o uno pubblico (elenchi su kiwisdr.com e websdr.org). Viene ricordato solo in questo browser.'),
    h('div', { class: 'grid-2' }, kind, host),
    h('button', { type: 'button', class: 'btn small', onclick: () => {
      const v = host.value.trim();
      if (!/^[\w.-]+(:\d{2,5})?$/.test(v)) { toast('Indirizzo non valido (es. mio.kiwi.it:8073).', 'error'); return; }
      try { localStorage.setItem('rrf-sdr', JSON.stringify({ kind: kind.value, host: v })); } catch { /* storage non disponibile */ }
      showDetail(f.id);
    } }, 'Salva'));
  if (!links.length && hz > 30_000_000) {
    return h('section', { class: 'detail-section' }, h('h3', {}, 'Ascolta online'),
      h('p', { class: 'small muted' }, 'Questa frequenza è oltre i 30 MHz: i ricevitori pubblici predefiniti coprono solo le onde corte. Se conosci un ricevitore che la copre, indicalo qui sotto.'), setup);
  }
  return h('section', { class: 'detail-section' }, h('h3', {}, 'Ascolta online'),
    h('div', { class: 'actions' }, links.map(([name, url]) => h('a', { class: 'btn small', href: url, target: '_blank', rel: 'noopener noreferrer' }, 'Sintonizza su ' + name))),
    h('p', { class: 'small muted' }, `Apre il ricevitore già sintonizzato su ${f.freq_mhz} MHz (${mode.toUpperCase()}). Il sito esterno ha le sue regole d’uso.`), setup);
}

function checkinBox(f) {
  if (!state.user) {
    return h('p', { class: 'notice' }, 'Hai ascoltato questa frequenza? ', h('a', { href: '#/accedi', onclick: () => closeModal() }, 'Accedi'),
      ' per confermarlo: aggiorni l’affidabilità e guadagni punti.');
  }
  const rst = h('input', { type: 'text', name: 'rst', maxlength: 3, inputmode: 'numeric', placeholder: 'es. 59', 'aria-describedby': 'rst-hint' });
  const eq = h('input', { type: 'text', name: 'equipment', maxlength: 80, placeholder: 'es. RTL-SDR e dipolo' });
  const btn = h('button', { type: 'button', class: 'btn primary', onclick: async () => {
    btn.disabled = true;
    const r = await quickCheckin(f.id, { rst: rst.value.trim() || undefined, equipment: eq.value.trim() || undefined });
    btn.disabled = false;
    if (r) { actions.refresh?.(); showDetail(f.id); }
  } }, 'Confermo l’ascolto di oggi');
  return h('div', { class: 'checkin-box' },
    h('strong', {}, 'Conferma ascolto'),
    h('div', { class: 'grid-2' },
      h('div', { class: 'field' }, h('label', { for: 'ck-rst' }, 'Rapporto RS/RST (facoltativo)'), Object.assign(rst, { id: 'ck-rst' }), h('div', { class: 'hint', id: 'rst-hint' }, 'Es. 59 o 599.')),
      h('div', { class: 'field' }, h('label', { for: 'ck-eq' }, 'Antenna o apparato (facoltativo)'), Object.assign(eq, { id: 'ck-eq' }))),
    btn);
}

async function loadHistory(id, box) {
  clear(box, h('p', { class: 'loading' }, 'Caricamento dello storico…'));
  try {
    const { items } = await get(`/frequencies/${id}/history`);
    if (!items.length) { clear(box, h('p', { class: 'muted' }, 'Nessuna revisione registrata.')); return; }
    clear(box, h('ol', { class: 'history' }, items.map((r) => h('li', {},
      h('div', {}, h('strong', {}, REV_ACTION[r.action]), ' di ', r.author),
      h('div', { class: 'when' }, dateTime(r.date)),
      r.changes.length ? diffTable(r.changes, r.action === 'create') : null))));
  } catch (e) {
    clear(box, h('p', { class: 'form-error' }, e.message));
  }
}

const TEXT_FIELDS = new Set(['label', 'notes', 'area_note', 'municipality', 'entity']);

/** Confronto parola per parola (LCS): restituisce i nodi per le colonne "prima" e "dopo". */
function wordDiff(a, b) {
  const x = a.split(/(\s+)/), y = b.split(/(\s+)/);
  if (x.length * y.length > 40000) return null;
  const dp = Array.from({ length: x.length + 1 }, () => new Array(y.length + 1).fill(0));
  for (let i = x.length - 1; i >= 0; i--) for (let j = y.length - 1; j >= 0; j--) dp[i][j] = x[i] === y[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
  const left = [], right = [];
  let i = 0, j = 0;
  while (i < x.length && j < y.length) {
    if (x[i] === y[j]) { left.push(x[i]); right.push(y[j]); i++; j++; }
    else if (dp[i + 1][j] >= dp[i][j + 1]) left.push(h('del', {}, x[i++]));
    else right.push(h('ins', {}, y[j++]));
  }
  while (i < x.length) left.push(h('del', {}, x[i++]));
  while (j < y.length) right.push(h('ins', {}, y[j++]));
  return [left, right];
}

/** Tabella "Prima / Dopo": campi aggiunti in verde, rimossi in rosso, modificati con le parole variate evidenziate. */
export function diffTable(changes, isCreate = false, withCurrent = false) {
  return h('table', { class: 'diff' },
    h('thead', {}, h('tr', {}, h('th', {}, 'Campo'), isCreate ? null : h('th', {}, 'Prima'), h('th', {}, 'Dopo'), withCurrent ? h('th', {}, 'Valore attuale') : null)),
    h('tbody', {}, changes.map((c) => {
      const kind = isCreate || c.old == null ? 'add' : (c.new == null ? 'del' : 'chg');
      const mark = { add: ['+', 'aggiunto'], del: ['−', 'rimosso'], chg: ['↔', 'modificato'] }[kind];
      let oldN = c.old ?? '—', newN = c.new ?? '—';
      if (kind === 'chg' && TEXT_FIELDS.has(c.field) && typeof c.old === 'string' && typeof c.new === 'string') {
        const d = wordDiff(c.old, c.new);
        if (d) [oldN, newN] = d;
      }
      return h('tr', { class: kind + (c.stale ? ' stale' : '') },
        h('td', {}, h('span', { class: 'mark', 'aria-hidden': 'true' }, mark[0]), h('span', { class: 'visually-hidden' }, mark[1] + ': '), c.label),
        isCreate ? null : h('td', { class: 'old' }, oldN),
        h('td', { class: 'new' }, newN),
        withCurrent ? h('td', {}, (c.current ?? '—') + (c.stale ? ' (cambiato)' : '')) : null);
    })));
}

async function remove(id) {
  const note = prompt('Motivo della rimozione (visibile nello storico):');
  if (note === null) return;
  try {
    const r = await del('/frequencies/' + id, { comment: note });
    toast(r.message);
    closeModal();
    actions.refresh();
  } catch (e) { toast(e.message, 'error'); }
}

async function restore(id) {
  try {
    const r = await post(`/frequencies/${id}/restore`, {});
    toast(r.message);
    showDetail(id);
    actions.refresh();
  } catch (e) { toast(e.message, 'error'); }
}
