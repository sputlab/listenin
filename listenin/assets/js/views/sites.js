// Postazioni e monti: elenco, scheda con mappa e vista aggregata di tutti gli impianti installati nello stesso sito.
import { get, put } from '../api.js?v=0.7.0';
import { $, h, clear, field, select, toast, showErrors } from '../dom.js?v=0.7.0';
import { date, STATUS } from '../format.js?v=0.7.0';
import { state, isModerator } from '../state.js?v=0.7.0';
import { createMap, estimateCoverageKm } from '../leaflet-loader.js?v=0.7.0';
import { relBadge } from './search.js?v=0.7.0';

export function showSites() {
  const view = $('#view');
  const t = state.meta.taxonomy;
  const params = new URLSearchParams(location.hash.split('?')[1] || '');
  let q = params.get('q') || '', region = params.get('region') || '', province = params.get('province') || '', page = 1;
  const qInput = h('input', { type: 'search', name: 'q', value: q, placeholder: 'es. Monte Sabotino', 'aria-label': 'Cerca una postazione' });
  const regionSel = select('region', [{ value: '', label: 'Tutta Italia' }, ...t.regions.map((r) => ({ value: r.slug, label: r.name }))], region);
  const provOptions = (reg) => [{ value: '', label: 'Tutte' }, ...(reg ? (t.provinces[reg] || []) : []).map((p) => ({ value: p.code, label: `${p.name} (${p.code})` }))];
  const provSel = select('province', provOptions(region), province);
  const list = h('div');
  let timer = null;
  const go = () => { q = qInput.value.trim(); region = regionSel.value; province = provSel.value; page = 1; load(); };
  qInput.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(go, 300); });
  regionSel.addEventListener('change', () => { clear(provSel, ...provOptions(regionSel.value).map((o) => h('option', { value: o.value }, o.label))); provSel.value = ''; go(); });
  provSel.addEventListener('change', go);

  async function load() {
    clear(list, h('p', { class: 'loading' }, 'Caricamento…'));
    const qs = new URLSearchParams({ page: String(page) });
    if (q) qs.set('q', q);
    if (region) qs.set('region', region);
    if (province) qs.set('province', province);
    try {
      const r = await get('/sites?' + qs.toString());
      clear(list,
        h('p', { class: 'count', role: 'status' }, h('strong', {}, r.total.toLocaleString('it-IT')), r.total === 1 ? ' postazione' : ' postazioni'),
        r.items.length ? h('ul', { class: 'cards-list' }, r.items.map((s) => h('li', { class: 'site-card' },
          h('a', { href: '#/postazione/' + s.slug }, h('strong', {}, s.name)),
          h('span', { class: 'small muted' }, [s.municipality, s.province_name ? `${s.province_name} (${s.province})` : null, s.altitude_m != null ? `${s.altitude_m.toLocaleString('it-IT')} m s.l.m.` : null].filter(Boolean).join(' · ')),
          h('span', {}, `${s.count} ${s.count === 1 ? 'impianto' : 'impianti'}`),
          h('span', { class: 'chips-inline' }, s.services.slice(0, 4).map((x) => h('span', { class: 'tag' }, `${x.name} · ${x.count}`)))))) 
          : h('div', { class: 'empty-state' }, h('p', {}, 'Nessuna postazione trovata.'),
            h('p', { class: 'muted small' }, 'Le postazioni nascono quando, in una frequenza, si indica il campo «Postazione o monte».')),
        r.pages > 1 ? h('nav', { class: 'pager' },
          h('button', { type: 'button', class: 'btn small', disabled: page <= 1, onclick: () => { page--; load(); } }, 'Precedenti'),
          h('span', { class: 'muted small' }, `Pagina ${page} di ${r.pages}`),
          h('button', { type: 'button', class: 'btn small', disabled: page >= r.pages, onclick: () => { page++; load(); } }, 'Successive')) : null);
    } catch (e) { clear(list, h('p', { class: 'form-error' }, e.message)); }
  }

  clear(view,
    h('h1', { tabindex: '-1' }, 'Postazioni e monti'),
    h('p', { class: 'muted' }, 'Ogni postazione raggruppa i ripetitori e i servizi installati nello stesso sito.'),
    h('div', { class: 'grid-3' }, field('Cerca', qInput), field('Regione', regionSel), field('Provincia', provSel)),
    list);
  load();
}

export async function showSite(slug) {
  const view = $('#view');
  clear(view, h('p', { class: 'loading' }, 'Caricamento…'));
  let site, freqs;
  try {
    [site, freqs] = await Promise.all([get('/sites/show?slug=' + encodeURIComponent(slug)), get('/frequencies?site=' + encodeURIComponent(slug) + '&per_page=100&sort=freq')]);
  } catch (e) { clear(view, h('div', { class: 'empty-state' }, h('p', {}, e.message), h('a', { class: 'btn', href: '#/postazioni' }, 'Tutte le postazioni'))); return; }

  const mapEl = site.latitude != null ? h('div', { class: 'detail-map', role: 'img', 'aria-label': 'Mappa della postazione ' + site.name }) : null;
  const coverage = estimateCoverageKm(site.altitude_m);
  const groups = new Map();
  for (const it of freqs.items) {
    const k = it.service.root_name || it.service.name;
    if (!groups.has(k)) groups.set(k, []);
    groups.get(k).push(it);
  }
  const editBox = isModerator() ? editForm(site) : null;
  clear(view,
    h('p', {}, h('a', { href: '#/postazioni' }, '← Tutte le postazioni')),
    h('h1', { tabindex: '-1' }, site.name),
    h('p', { class: 'muted' }, [site.municipality, site.province_name ? `${site.province_name} (${site.province})` : null, site.altitude_m != null ? `quota ${site.altitude_m.toLocaleString('it-IT')} m s.l.m.` : null,
      site.locator ? 'locatore ' + site.locator : null].filter(Boolean).join(' · ') || 'Posizione non ancora indicata'),
    site.notes ? h('p', {}, site.notes) : null,
    mapEl ? h('section', {}, mapEl, h('p', { class: 'small muted' }, coverage ? `Cerchio: copertura stimata dall’orizzonte radio, circa ${coverage} km (indicativa).` : 'Indicando la quota della postazione si ottiene una stima della copertura.')) : null,
    h('h2', {}, `${freqs.total} ${freqs.total === 1 ? 'impianto' : 'impianti'} installati`),
    ...[...groups].map(([name, items]) => h('section', { class: 'card' }, h('h3', {}, name),
      h('ul', { class: 'list' }, items.map((it) => h('li', {},
        h('div', { class: 'head' }, h('a', { href: '#/f/' + it.id }, h('strong', { class: 'num' }, it.freq_mhz + ' MHz')), h('span', {}, it.label),
          it.mode !== 'ND' ? h('span', { class: 'mode' }, it.mode) : null, h('span', { class: 'status ' + it.status }, STATUS[it.status]), relBadge(it)),
        h('div', { class: 'small muted' }, [it.tone, it.last_heard_at ? 'ultimo ascolto ' + date(it.last_heard_at) : 'mai verificata'].filter(Boolean).join(' · '))))))),
    h('p', {}, h('a', { class: 'btn', href: `?site=${encodeURIComponent(slug)}#/` }, 'Apri nella ricerca (con esportazione)')),
    editBox);
  if (mapEl) {
    try {
      const { L, map } = await createMap(mapEl, state.meta.taxonomy.map, [site.latitude, site.longitude], 12);
      L.marker([site.latitude, site.longitude]).addTo(map).bindPopup(site.name);
      if (coverage) L.circle([site.latitude, site.longitude], { radius: coverage * 1000, color: '#1D5C96', weight: 1.5, fillOpacity: 0.07 }).addTo(map);
      setTimeout(() => map.invalidateSize(), 250);
    } catch (e) { clear(mapEl, h('p', { class: 'muted small' }, e.message)); }
  }
}

function editForm(site) {
  const form = h('form', { class: 'form', novalidate: true },
    field('Nome', h('input', { type: 'text', name: 'name', value: site.name, maxlength: 100 }), { name: 'name' }),
    h('div', { class: 'grid-2' },
      field('Latitudine', h('input', { type: 'text', name: 'latitude', inputmode: 'decimal', value: site.latitude ?? '' }), { name: 'latitude' }),
      field('Longitudine', h('input', { type: 'text', name: 'longitude', inputmode: 'decimal', value: site.longitude ?? '' }), { name: 'longitude' })),
    h('div', { class: 'grid-2' },
      field('Altitudine (m s.l.m.)', h('input', { type: 'text', name: 'altitude_m', inputmode: 'numeric', value: site.altitude_m ?? '' }), { name: 'altitude_m' }),
      field('Locatore', h('input', { type: 'text', name: 'locator', maxlength: 8, value: site.locator ?? '' }), { name: 'locator' })),
    field('Note', h('textarea', { name: 'notes', maxlength: 500, rows: 2 }, site.notes ?? ''), { name: 'notes' }),
    h('div', { class: 'actions' }, h('button', { type: 'submit', class: 'btn primary' }, 'Salva la postazione')));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form), body = {};
    for (const k of ['name', 'latitude', 'longitude', 'altitude_m', 'locator', 'notes']) {
      const v = (fd.get(k) || '').toString().trim();
      body[k] = v === '' && k !== 'name' ? null : v;
    }
    try {
      await put('/sites/' + site.id, body);
      toast('Postazione aggiornata: le posizioni delle frequenze sono state ricalcolate.');
      showSite(site.slug);
    } catch (err) { showErrors(form, err); }
  });
  return h('details', { class: 'card' }, h('summary', {}, 'Modifica la postazione (moderatori)'), form);
}
