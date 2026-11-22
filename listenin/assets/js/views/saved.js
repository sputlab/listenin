// Ricerche salvate per l'uso senza copertura dati.
import { $, h, clear, toast, openModal, scrollTable } from '../dom.js?v=0.9.0';
import { date, STATUS, RELIABILITY } from '../format.js?v=0.9.0';
import { listSearches, deleteSearch } from '../offline.js?v=0.9.0';
import { saveOfflineFor } from './search.js?v=0.9.0';
import { relBadge } from './search.js?v=0.9.0';

function itemModal(it) {
  const rows = [['Frequenza', it.freq_mhz + ' MHz' + (it.input_freq_mhz ? ' (ingresso ' + it.input_freq_mhz + ')' : '')], ['Modo', it.mode], ['Servizio', it.service.name],
    ['Descrizione', it.label], ['Nominativo', it.callsign], ['Località', [it.municipality, it.province, it.region].filter(Boolean).join(', ') || it.area_note],
    ['Tono', it.tone], ['Canale', it.channel], ['Stato', STATUS[it.status]], ['Affidabilità', RELIABILITY[it.reliability]],
    ['Ultimo riscontro', it.last_heard_at ? date(it.last_heard_at) : 'mai']].filter(([, v]) => v);
  openModal(`${it.freq_mhz} MHz`, h('div', {}, h('p', { class: 'notice' }, 'Copia salvata sul dispositivo: la scheda completa, lo storico e le conferme di ascolto richiedono la connessione.'),
    h('dl', { class: 'facts' }, rows.flatMap(([k, v]) => [h('dt', {}, k), h('dd', {}, String(v))]))));
}

function viewer(rec) {
  const box = h('div');
  const q = h('input', { type: 'search', placeholder: 'Filtra tra le frequenze salvate', 'aria-label': 'Filtra tra le frequenze salvate' });
  let page = 1;
  const per = 100;
  const draw = () => {
    const needle = q.value.trim().toLowerCase();
    const items = needle ? rec.items.filter((it) => `${it.freq_mhz} ${it.label} ${it.callsign || ''} ${it.municipality || ''} ${it.province || ''} ${it.mode}`.toLowerCase().includes(needle)) : rec.items;
    const pages = Math.max(1, Math.ceil(items.length / per));
    page = Math.min(page, pages);
    clear(box,
      h('p', { class: 'count', role: 'status' }, h('strong', {}, items.length.toLocaleString('it-IT')), ' frequenze'),
      scrollTable(h('table', { class: 'freq-table' }, h('thead', {}, h('tr', {}, ['Frequenza', 'Modo', 'Descrizione', 'Località', 'Stato'].map((x) => h('th', { scope: 'col' }, x)))),
        h('tbody', {}, items.slice((page - 1) * per, page * per).map((it) => {
          const tr = h('tr', { tabindex: '0' },
            h('td', { class: 'f' }, h('div', { class: 'freq-value' }, it.freq_mhz, h('span', { class: 'unit' }, 'MHz'))), h('td', { class: 'm' }, h('span', { class: 'mode' }, it.mode)),
            h('td', { class: 'd' }, h('div', { class: 'label-main' }, it.label)), h('td', { class: 'loc' }, [it.municipality, it.province].filter(Boolean).join(' ')),
            h('td', { class: 'st' }, h('span', { class: 'status ' + it.status }, STATUS[it.status]), relBadge(it)));
          tr.addEventListener('click', () => itemModal(it));
          tr.addEventListener('keydown', (e) => { if (e.key === 'Enter') itemModal(it); });
          return tr;
        })))),
      pages > 1 ? h('nav', { class: 'pager' }, h('button', { type: 'button', class: 'btn small', disabled: page <= 1, onclick: () => { page--; draw(); } }, 'Precedenti'),
        h('span', { class: 'muted small' }, `Pagina ${page} di ${pages}`), h('button', { type: 'button', class: 'btn small', disabled: page >= pages, onclick: () => { page++; draw(); } }, 'Successive')) : null);
  };
  q.addEventListener('input', () => { page = 1; draw(); });
  draw();
  return h('div', {}, h('div', { class: 'field' }, q), box);
}

export async function showSaved() {
  const view = $('#view');
  const online = navigator.onLine;
  let list = [];
  try { list = await listSearches(); } catch (e) { clear(view, h('p', { class: 'form-error' }, e.message)); return; }
  const detail = h('div');
  const paint = async () => {
    list = await listSearches();
    clear(listBox, list.length ? h('ul', { class: 'list' }, list.map((rec) => h('li', {},
      h('div', { class: 'head' }, h('strong', {}, rec.name), h('span', { class: 'muted small' }, `${rec.items.length.toLocaleString('it-IT')}${rec.total > rec.items.length ? ' di ' + rec.total.toLocaleString('it-IT') : ''} frequenze · salvata il ${date(rec.savedAt.slice(0, 10))}`)),
      h('div', { class: 'actions' },
        h('button', { type: 'button', class: 'btn small primary', onclick: () => { clear(detail, h('h2', {}, rec.name), viewer(rec)); detail.scrollIntoView({ behavior: 'smooth' }); } }, 'Apri'),
        h('button', { type: 'button', class: 'btn small', disabled: !navigator.onLine, onclick: async (e) => {
          e.currentTarget.disabled = true;
          try { await saveOfflineFor(rec.filters, rec.name); toast('Ricerca aggiornata.'); await paint(); } catch (err) { toast(err.message, 'error'); }
        } }, 'Aggiorna'),
        h('button', { type: 'button', class: 'btn small danger', onclick: async () => { await deleteSearch(rec.key); clear(detail); paint(); } }, 'Elimina')))))
      : h('div', { class: 'empty-state' }, h('p', {}, 'Non hai ancora salvato nessuna ricerca.'),
        h('p', { class: 'muted small' }, 'Nella pagina delle frequenze usa «Salva offline» per conservare la ricerca corrente, oppure aggiungi una provincia ai preferiti.'),
        h('a', { class: 'btn primary', href: '#/' }, 'Vai alle frequenze')));
  };
  const listBox = h('div');
  clear(view,
    h('h1', { tabindex: '-1' }, 'Uso senza copertura dati'),
    h('p', { class: online ? 'muted' : 'notice', role: 'status' }, online ? 'Sei online. Le ricerche salvate restano disponibili anche quando sarai senza rete.' : 'Sei offline: puoi consultare le ricerche salvate qui sotto.'),
    h('p', { class: 'small muted' }, 'Le ricerche si conservano solo su questo dispositivo e contengono soltanto dati pubblici delle frequenze. Installando l’app (menu del browser → «Installa» o «Aggiungi a Home») si apre anche senza connessione.'),
    listBox, detail);
  await paint();
  window.addEventListener('online', () => showSaved(), { once: true });
}
