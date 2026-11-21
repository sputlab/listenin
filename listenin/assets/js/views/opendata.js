// Archivio open data, feed e formati per gli apparati.
import { get, post, apiUrl } from '../api.js?v=0.7.0';
import { $, h, clear, toast } from '../dom.js?v=0.7.0';
import { date } from '../format.js?v=0.7.0';
import { isAdmin } from '../state.js?v=0.7.0';

const size = (n) => (n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB');

export async function showOpenData() {
  const view = $('#view');
  clear(view, h('p', { class: 'loading' }, 'Caricamento…'));
  let r;
  try { r = await get('/opendata'); } catch (e) { clear(view, h('p', { class: 'form-error' }, e.message)); return; }
  const filesBox = h('div');
  const paint = (files) => clear(filesBox, files.length
    ? h('ul', { class: 'list' }, files.map((f) => h('li', {}, h('div', { class: 'head' },
      h('a', { href: apiUrl('/opendata/download?name=' + encodeURIComponent(f.name)), download: '' }, h('strong', {}, f.name)),
      h('span', { class: 'tag' }, f.format), h('span', { class: 'muted small' }, `${date(f.date)} · ${size(f.size)}`)))))
    : h('p', { class: 'muted' }, 'Non è ancora stata generata nessuna copia completa.'));
  paint(r.files);
  clear(view,
    h('h1', { tabindex: '-1' }, 'Open data'),
    h('p', {}, 'Copie complete e periodiche del database armonizzato, scaricabili liberamente in CSV e JSON.'),
    h('div', { class: 'card' }, h('h3', {}, 'Licenza'),
      h('p', {}, 'I dati sono pubblicati con licenza ', h('a', { href: r.license.url, target: '_blank', rel: 'noopener' }, r.license.name), '. Citare la fonte: ListenIn e i suoi contributori.')),
    h('div', { class: 'card' }, h('h3', {}, 'Copie disponibili'), filesBox,
      isAdmin() ? h('div', { class: 'actions' }, h('button', { type: 'button', class: 'btn', onclick: async (e) => {
        e.currentTarget.disabled = true;
        try { const g = await post('/admin/opendata/generate', {}); paint(g.files); toast(g.message); } catch (err) { toast(err.message, 'error'); }
        e.currentTarget.disabled = false;
      } }, 'Genera ora una nuova copia')) : null),
    h('div', { class: 'card' }, h('h3', {}, 'Feed per rimanere aggiornato'),
      h('p', {}, 'Nuove frequenze e frequenze recuperate: ',
        h('a', { href: apiUrl('/feed?format=rss') }, 'feed RSS'), ' · ', h('a', { href: apiUrl('/feed?format=atom') }, 'feed Atom'),
        '. Si possono filtrare per regione, provincia o banda aggiungendo ', h('code', {}, '&regione='), ', ', h('code', {}, '&provincia='), ' o ', h('code', {}, '&banda='), ' all’indirizzo, e scegliere solo le novità con ', h('code', {}, '&evento=nuove'), ' oppure ', h('code', {}, '&evento=recuperi'), '.')),
    h('div', { class: 'card' }, h('h3', {}, 'Per programmare gli apparati'),
      h('p', {}, 'Dalla pagina di ricerca, menu ', h('strong', {}, 'Esporta'), ', puoi scaricare qualsiasi vista filtrata nei formati CHIRP e OpenGD77.'),
      h('p', { class: 'notice' }, 'Questi tracciati sono ricostruiti dalla documentazione dei programmi e non sono stati provati con ogni versione del software: importa prima pochi canali per verificare il risultato. Si esportano solo i modi supportati (CHIRP: analogici e D-STAR; OpenGD77: FM e DMR).')));
}
