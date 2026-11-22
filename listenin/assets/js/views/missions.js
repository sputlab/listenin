// Missioni di recupero: frequenze contestate, da verificare o mai verificate nella provincia o regione scelta.
import { get } from '../api.js?v=0.7.1';
import { $, h, clear, field, select } from '../dom.js?v=0.7.1';
import { date, STATUS, RELIABILITY } from '../format.js?v=0.7.1';
import { state } from '../state.js?v=0.7.1';
import { quickCheckin } from '../checkin.js?v=0.7.1';
import { relBadge } from './search.js?v=0.7.1';

export function showMissions() {
  const view = $('#view');
  const t = state.meta.taxonomy;
  const params = new URLSearchParams(location.hash.split('?')[1] || '');
  let region = params.get('region') || '', province = params.get('province') || '', page = 1;
  const provOptions = (reg) => [{ value: '', label: 'Tutte' }, ...(reg ? (t.provinces[reg] || []) : Object.values(t.provinces).flat().sort((a, b) => a.name.localeCompare(b.name, 'it'))).map((p) => ({ value: p.code, label: `${p.name} (${p.code})` }))];
  const regionSel = select('region', [{ value: '', label: 'Tutta Italia' }, ...t.regions.map((r) => ({ value: r.slug, label: r.name }))], region);
  const provSel = select('province', provOptions(region), province);
  const list = h('div');
  const go = (e) => {
    region = regionSel.value; province = provSel.value; page = 1;
    if (e?.target === regionSel) { clear(provSel, ...provOptions(region).map((o) => h('option', { value: o.value }, o.label))); province = ''; provSel.value = ''; }
    load();
  };
  regionSel.addEventListener('change', go);
  provSel.addEventListener('change', go);

  async function load() {
    clear(list, h('p', { class: 'loading' }, 'Ricerca delle frequenze da recuperare…'));
    const q = new URLSearchParams({ missione: '1', sort: 'heard', dir: 'asc', per_page: '30', page: String(page) });
    if (region) q.set('region', region);
    if (province) q.set('province', province);
    try {
      const r = await get('/frequencies?' + q.toString());
      const where = province ? `provincia di ${province}` : region ? t.regions.find((x) => x.slug === region)?.name : 'tutta Italia';
      const rows = h('ul', { class: 'list' }, r.items.map((it) => {
        const li = h('li', {},
          h('div', { class: 'head' },
            h('a', { href: '#/f/' + it.id }, h('strong', { class: 'num' }, it.freq_mhz + ' MHz')),
            h('span', {}, it.label), h('span', { class: 'status ' + it.status }, STATUS[it.status]), relBadge(it)),
          h('div', { class: 'small muted' }, [it.service.name, [it.municipality, it.province].filter(Boolean).join(' '), it.last_heard_at ? 'ultimo ascolto ' + date(it.last_heard_at) : 'mai verificata'].filter(Boolean).join(' · ')),
          h('div', { class: 'actions' }, h('button', { type: 'button', class: 'btn small primary', onclick: async (e) => {
            e.currentTarget.disabled = true;
            const res = await quickCheckin(it.id);
            if (res) { li.remove(); } else { e.currentTarget.disabled = false; }
          } }, 'L’ho ascoltata: confermo'), h('a', { class: 'btn small', href: '#/f/' + it.id }, 'Apri la scheda')));
        return li;
      }));
      clear(list,
        h('p', { class: 'count', role: 'status' }, h('strong', {}, r.total.toLocaleString('it-IT')), ` frequenze da recuperare (${where}). Le più vecchie in cima.`),
        r.items.length ? rows : h('div', { class: 'empty-state' }, h('p', {}, 'Nessuna missione disponibile qui: tutte le frequenze sono state verificate di recente.')),
        r.pages > 1 ? h('nav', { class: 'pager' },
          h('button', { type: 'button', class: 'btn small', disabled: page <= 1, onclick: () => { page--; load(); } }, 'Precedenti'),
          h('span', { class: 'muted small' }, `Pagina ${page} di ${r.pages}`),
          h('button', { type: 'button', class: 'btn small', disabled: page >= r.pages, onclick: () => { page++; load(); } }, 'Successive')) : null);
    } catch (e) { clear(list, h('p', { class: 'form-error' }, e.message)); }
  }

  clear(view,
    h('h1', { tabindex: '-1' }, 'Missioni di recupero'),
    h('p', { class: 'muted' }, 'Elenco automatico delle frequenze contestate, da verificare o mai verificate. Se ne ricevi una, conferma l’ascolto: aggiorni l’affidabilità, guadagni punti e sblocchi badge.'),
    !state.user ? h('p', { class: 'notice' }, h('a', { href: '#/accedi' }, 'Accedi'), ' per confermare gli ascolti e partecipare alle classifiche.') : null,
    h('div', { class: 'grid-2' }, field('Regione', regionSel), field('Provincia', provSel)),
    list);
  load();
}
