// Cartina d'Italia in SVG puro: regioni e province, mappa coropletica, zoom sulla regione e filtro al clic.
// Confini ISTAT al 1° gennaio 2026 (CC BY 4.0), semplificati; i colori derivano dai conteggi del database.
import { get, BASE } from '../api.js?v=0.9.0';
import { h, clear } from '../dom.js?v=0.9.0';
import { state } from '../state.js?v=0.9.0';

const NS = 'http://www.w3.org/2000/svg';
const svgEl = (tag, attrs = {}) => {
  const e = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
  return e;
};
const fmt = (n) => n.toLocaleString('it-IT');

let geoPromise = null;
function loadGeo() {
  geoPromise ??= Promise.all([
    fetch(BASE + 'assets/data/italia-confini.dat', { credentials: 'same-origin' }).then((r) => { if (!r.ok) throw new Error('Cartina non disponibile.'); return r.json(); }),
    get('/geo/summary'),
  ]).catch((e) => { geoPromise = null; throw e; });
  return geoPromise;
}

const METRICS = [['count', 'Numero di frequenze'], ['verified', 'Quota con affidabilità alta']];

/** Crea il pannello della cartina. onChange() viene chiamato quando il clic modifica i filtri di regione o provincia. */
export function createMapPanel({ onChange }) {
  let data = null, svg = null, metric = 'count';
  let level = 'italia', current = [0, 0, 560, 730], anim = 0;
  const regionEls = new Map(), provEls = new Map();

  const status = h('p', { class: 'map-status', role: 'status', 'aria-live': 'polite' }, 'Passa il mouse o seleziona una regione.');
  const back = h('button', { type: 'button', class: 'btn small', hidden: true, onclick: () => choose(null, null) }, '← Tutta Italia');
  const metricSel = h('select', { 'aria-label': 'Colora la cartina secondo' }, METRICS.map(([v, l]) => h('option', { value: v }, l)));
  metricSel.addEventListener('change', () => { metric = metricSel.value; paint(); });
  const legend = h('div', { class: 'map-legend', 'aria-hidden': 'true' });
  const note = h('p', { class: 'small muted' });
  const holder = h('div', { class: 'map-holder' }, h('p', { class: 'loading' }, 'Caricamento della cartina…'));
  const el = h('details', { class: 'map-panel' },
    h('summary', {}, 'Cartina d’Italia'),
    h('div', { class: 'map-body' }, holder,
      h('div', { class: 'map-side' }, h('label', { class: 'small' }, 'Colora secondo', metricSel), back, status, legend, note)));
  if (window.matchMedia('(min-width: 961px)').matches) el.setAttribute('open', '');

  const valueOf = (stat) => (!stat ? null : metric === 'count' ? stat.count : stat.verified_percent);
  const levelOf = (stat, max) => {
    const v = valueOf(stat);
    if (!stat || !stat.count) return 0;
    if (!max) return 1;
    return Math.min(4, 1 + Math.floor(3.999 * Math.sqrt(Math.max(0, v) / max)));
  };

  function label(name, stat) {
    if (!stat || !stat.count) return `${name}: nessuna frequenza`;
    return `${name}: ${fmt(stat.count)} frequenze, ${String(stat.verified_percent).replace('.', ',')}% con affidabilità alta`;
  }

  function paint() {
    if (!data) return;
    const [geo, sum] = data;
    const maxR = Math.max(0, ...Object.values(sum.regions).map(valueOf));
    const inRegion = state.filters.region;
    const provs = geo.provinces.filter((p) => !inRegion || p.region === inRegion);
    const maxP = Math.max(0, ...provs.map((p) => valueOf(sum.provinces[p.code]) ?? 0));
    for (const r of geo.regions) {
      const e = regionEls.get(r.slug), stat = sum.regions[r.slug];
      e.setAttribute('class', `reg lv${levelOf(stat, maxR)}${inRegion === r.slug ? ' selected' : ''}${inRegion && inRegion !== r.slug ? ' dim' : ''}`);
      e.setAttribute('aria-label', label(r.name, stat));
      e.querySelector('title').textContent = label(r.name, stat);
      e.setAttribute('tabindex', level === 'italia' ? '0' : '-1');
    }
    for (const p of geo.provinces) {
      const e = provEls.get(p.code), stat = sum.provinces[p.code], mine = p.region === inRegion;
      e.setAttribute('class', `prov${mine ? ` in-region lv${levelOf(stat, maxP)}` : ''}${state.filters.province === p.code ? ' selected' : ''}`);
      e.setAttribute('aria-label', label(p.name, stat));
      e.querySelector('title').textContent = label(p.name, stat);
      e.setAttribute('tabindex', mine ? '0' : '-1');
    }
    svg.setAttribute('class', 'map-svg level-' + level);
    // legenda
    const top = level === 'italia' ? maxR : maxP;
    const unit = metric === 'count' ? '' : '%';
    clear(legend, h('span', {}, '0'), ...[0, 1, 2, 3, 4].map((i) => h('span', { class: 'sw lv' + i })), h('span', {}, `${fmt(Math.round(top * 10) / 10)}${unit}`));
    back.hidden = level === 'italia';
    const su = sum.provinces.SU;
    const showSu = su && su.count && (level === 'italia' || state.filters.region === 'sardegna');
    note.textContent = (showSu ? `${fmt(su.count)} ${su.count === 1 ? 'frequenza è ancora assegnata' : 'frequenze sono ancora assegnate'} a «Sud Sardegna» (provincia soppressa nel 2026): ${su.count === 1 ? 'conta' : 'contano'} nella regione ma non compare sulla cartina. ` : '')
      + `Confini: ISTAT 2026 (CC BY 4.0), semplificati.${sum.unlocated ? ` ${fmt(sum.unlocated)} frequenze nazionali non hanno una localizzazione.` : ''}`;
  }

  function animate(to, instant) {
    cancelAnimationFrame(anim);
    const from = current.slice();
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (instant || reduce) { current = to; svg.setAttribute('viewBox', to.join(' ')); return; }
    const t0 = performance.now(), D = 320;
    const step = (t) => {
      const k = Math.min(1, (t - t0) / D), e = 1 - (1 - k) ** 3;
      current = from.map((v, i) => v + (to[i] - v) * e);
      svg.setAttribute('viewBox', current.join(' '));
      if (k < 1) anim = requestAnimationFrame(step);
    };
    anim = requestAnimationFrame(step);
  }

  function boxFor(region) {
    const [geo] = data;
    if (!region) return geo.viewBox;
    const r = geo.regions.find((x) => x.slug === region);
    if (!r) return geo.viewBox;
    const [x, y, w, hh] = r.bbox, pad = Math.max(w, hh) * 0.12;
    // proporzioni del contenitore: la cartina si adatta senza deformarsi
    const ratio = 560 / 730;
    let W = w + pad * 2, H = hh + pad * 2;
    if (W / H > ratio) H = W / ratio; else W = H * ratio;
    return [x + w / 2 - W / 2, y + hh / 2 - H / 2, W, H];
  }

  function choose(region, province) {
    state.filters = { ...state.filters };
    if (region) state.filters.region = region; else delete state.filters.region;
    if (province) state.filters.province = province; else delete state.filters.province;
    state.page = 1;
    sync(false);
    onChange();
  }

  /** Allinea la cartina ai filtri correnti (regione/provincia). */
  function sync(instant) {
    if (!data) return;
    let region = state.filters.region || null;
    if (!region && state.filters.province) {
      for (const [r, list] of Object.entries(state.meta.taxonomy.provinces)) if (list.some((p) => p.code === state.filters.province)) region = r;
    }
    const next = region ? 'regione' : 'italia';
    if (region && !state.filters.region) state.filters = { ...state.filters, region };
    level = next;
    animate(boxFor(region), instant);
    paint();
  }

  function build() {
    const [geo] = data;
    svg = svgEl('svg', { viewBox: geo.viewBox.join(' '), role: 'group', 'aria-label': 'Cartina interattiva dell’Italia', class: 'map-svg level-italia', preserveAspectRatio: 'xMidYMid meet' });
    current = geo.viewBox.slice();
    const gR = svgEl('g', { class: 'layer-regions' }), gP = svgEl('g', { class: 'layer-provinces' });
    const wire = (e, name, activate, stat) => {
      e.setAttribute('role', 'button');
      e.append(svgEl('title'));
      e.addEventListener('click', activate);
      e.addEventListener('keydown', (ev) => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); activate(); } });
      const say = () => { status.textContent = e.getAttribute('aria-label'); };
      e.addEventListener('mouseenter', say); e.addEventListener('focus', say);
    };
    for (const r of geo.regions) {
      const e = svgEl('path', { d: r.d, class: 'reg lv0', 'data-slug': r.slug, 'fill-rule': 'evenodd' });
      wire(e, r.name, () => { if (level === 'italia') choose(r.slug, null); });
      regionEls.set(r.slug, e); gR.append(e);
    }
    for (const p of geo.provinces) {
      const e = svgEl('path', { d: p.d, class: 'prov', 'data-code': p.code, 'fill-rule': 'evenodd' });
      wire(e, p.name, () => { if (p.region === state.filters.region) choose(p.region, state.filters.province === p.code ? null : p.code); });
      provEls.set(p.code, e); gP.append(e);
    }
    svg.append(gR, gP);
    clear(holder, svg);
  }

  let started = false;
  async function start() {
    if (started) { sync(true); return; }
    started = true;
    try {
      data = await loadGeo();
      build();
      sync(true);
    } catch (e) {
      started = false;
      clear(holder, h('p', { class: 'muted small' }, e.message || 'Cartina non disponibile.'));
    }
  }
  el.addEventListener('toggle', () => { if (el.open) start(); });
  return { el, refresh: () => { if (el.open) start(); }, start };
}
