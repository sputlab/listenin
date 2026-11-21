// Statistiche aggregate e classifiche.
import { get } from '../api.js?v=0.7.0';
import { $, h, clear } from '../dom.js?v=0.7.0';

const MONTHS = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
const monthLabel = (ym) => MONTHS[Number(ym.slice(5, 7)) - 1] + ' ' + ym.slice(2, 4);

function bars(items, { max = null, valueKey = 'count', suffix = '', link = null } = {}) {
  const top = max ?? Math.max(1, ...items.map((i) => i[valueKey]));
  return h('ul', { class: 'bars' }, items.map((i) => h('li', {},
    link ? h('a', { href: link(i) }, i.label) : h('span', {}, i.label),
    h('span', { class: 'track', 'aria-hidden': 'true' }, h('span', { class: 'fill', style: { width: Math.round(100 * i[valueKey] / top) + '%' } })),
    h('span', { class: 'num' }, i[valueKey].toLocaleString('it-IT') + suffix))));
}

function columns(series, title) {
  const top = Math.max(1, ...series.map((s) => s.value));
  return h('div', { role: 'img', 'aria-label': `${title}: ` + series.map((s) => `${monthLabel(s.month)} ${s.value}`).join(', ') },
    h('div', { class: 'cols', 'aria-hidden': 'true' }, series.map((s) => h('div', {}, h('span', {}, s.value || ''), h('span', { class: 'bar', style: { height: Math.round(100 * s.value / top) * 0.78 + '%' } }), h('span', {}, monthLabel(s.month).split(' ')[0])))));
}

export async function showStats() {
  const view = $('#view');
  clear(view, h('p', { class: 'loading' }, 'Calcolo delle statistiche…'));
  let s;
  try { s = await get('/stats'); } catch (e) { clear(view, h('p', { class: 'form-error' }, e.message)); return; }
  const t = s.totals;
  const rankBox = h('div');
  const tabs = [['punti', 'Punti reputazione'], ['punti_mese', 'Punti del mese'], ['recuperi_mese', 'Recuperi del mese'], ['recuperi', 'Recuperi totali']];
  const loadRank = async (period, btns) => {
    btns.forEach(([p, b]) => b.setAttribute('aria-selected', p === period ? 'true' : 'false'));
    clear(rankBox, h('p', { class: 'loading' }, 'Caricamento…'));
    try {
      const r = await get('/leaderboard?period=' + period);
      clear(rankBox, r.items.length
        ? h('ol', { class: 'rank' }, r.items.map((i) => h('li', {}, i.nickname, h('span', { class: 'v' }, i.value.toLocaleString('it-IT') + (period.startsWith('punti') ? ' pt' : '')))))
        : h('p', { class: 'muted' }, 'Ancora nessun punteggio: confermare un ascolto è il modo più veloce per entrare in classifica.'));
    } catch (e) { clear(rankBox, h('p', { class: 'form-error' }, e.message)); }
  };
  const btns = tabs.map(([p, label]) => [p, h('button', { type: 'button', role: 'tab', 'aria-selected': 'false', onclick: () => loadRank(p, btns) }, label)]);

  const relBar = h('div', { class: 'stackbar', role: 'img', 'aria-label': 'Affidabilità: ' + s.reliability.map((r) => `${r.label} ${r.percent}%`).join(', ') },
    s.reliability.filter((r) => r.count).map((r) => h('span', { style: { width: r.percent + '%', '--rel': `var(--rel-${r.level})` }, title: `${r.label}: ${r.count}` })));

  clear(view,
    h('h1', { tabindex: '-1' }, 'Statistiche'),
    h('p', { class: 'muted' }, 'Calcolate sui dati del database e aggiornate ogni pochi minuti.'),
    h('div', { class: 'cards' },
      ...[[t.frequencies, 'frequenze'], [t.recoveries, 'recuperi totali'], [t.recoveries_month, 'recuperi del mese'], [t.checkins, 'ascolti confermati'], [t.new_month, 'nuove nel mese'], [t.users, 'utenti attivi']]
        .map(([n, l]) => h('div', { class: 'stat' }, h('strong', {}, n.toLocaleString('it-IT')), h('span', {}, l)))),
    h('div', { class: 'card' }, h('h3', {}, 'Affidabilità'), relBar,
      h('ul', { class: 'legend' }, s.reliability.map((r) => h('li', { class: 'rel rel-' + r.level }, `${r.label}: ${r.count.toLocaleString('it-IT')} (${String(r.percent).replace('.', ',')}%)`))),
      h('p', { class: 'small muted' }, h('a', { href: '#/missioni' }, 'Aiuta a recuperare le frequenze da verificare'))),
    h('div', { class: 'card' }, h('h3', {}, 'Recuperi negli ultimi 12 mesi'), columns(s.recoveries_by_month, 'Recuperi per mese')),
    h('div', { class: 'card' }, h('h3', {}, 'Ascolti confermati negli ultimi 12 mesi'), columns(s.checkins_by_month, 'Ascolti per mese')),
    h('div', { class: 'card' }, h('h3', {}, 'Per banda'), bars(s.by_band)),
    h('div', { class: 'card' }, h('h3', {}, 'Per modo operativo'), bars(s.by_mode)),
    h('div', { class: 'card' }, h('h3', {}, 'Per servizio'), bars(s.by_service)),
    h('div', { class: 'card' }, h('h3', {}, 'Per regione'),
      h('p', { class: 'small muted' }, 'Tra parentesi la quota di frequenze con affidabilità alta.'),
      h('ul', { class: 'bars' }, s.by_region.map((r) => h('li', {},
        r.slug ? h('a', { href: `?region=${r.slug}#/` }, r.label) : h('span', {}, r.label),
        h('span', { class: 'track', 'aria-hidden': 'true' }, h('span', { class: 'fill', style: { width: Math.round(100 * r.count / Math.max(1, s.by_region[0].count)) + '%' } })),
        h('span', { class: 'num' }, `${r.count.toLocaleString('it-IT')} (${String(r.verified_percent).replace('.', ',')}%)`))))),
    h('div', { class: 'card' }, h('h3', {}, 'Classifiche'), h('div', { class: 'tabs', role: 'tablist' }, btns.map(([, b]) => b)), rankBox));
  loadRank('punti', btns);
}
