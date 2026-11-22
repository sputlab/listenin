// Notizie e aggiornamenti: elenco, lettura e gestione (amministratori).
import { get, post, put, del, apiUrl } from '../api.js?v=0.8.0';
import { $, h, clear, field, select, toast, showErrors } from '../dom.js?v=0.8.0';
import { date } from '../format.js?v=0.8.0';
import { isAdmin } from '../state.js?v=0.8.0';

const CATS = { comunicazione: 'Comunicazione', bandplan: 'Piano di banda', importazione: 'Importazione' };

/** Testo semplice: paragrafi, a capo e indirizzi https cliccabili (costruiti con il DOM, mai come HTML). */
export function renderBody(text) {
  return h('div', { class: 'news-body' }, String(text).split(/\n{2,}/).map((para) => {
    const kids = [];
    para.split('\n').forEach((line, i) => {
      if (i) kids.push(h('br'));
      let last = 0;
      for (const m of line.matchAll(/https?:\/\/[^\s<>"]+/g)) {
        kids.push(line.slice(last, m.index));
        const url = m[0].replace(/[.,;:!?)]+$/, '');
        kids.push(h('a', { href: url, target: '_blank', rel: 'noopener noreferrer' }, url));
        last = m.index + url.length;
      }
      kids.push(line.slice(last));
    });
    return h('p', {}, ...kids);
  }));
}

export async function showNews() {
  const view = $('#view');
  const params = new URLSearchParams(location.hash.split('?')[1] || '');
  let category = params.get('categoria') || '', page = 1;
  const list = h('div');
  const catSel = select('categoria', [{ value: '', label: 'Tutte le categorie' }, ...Object.entries(CATS).map(([v, l]) => ({ value: v, label: l }))], category);
  catSel.addEventListener('change', () => { category = catSel.value; page = 1; load(); });
  async function load() {
    clear(list, h('p', { class: 'loading' }, 'Caricamento…'));
    try {
      const r = await get('/news?page=' + page + (category ? '&category=' + category : ''));
      clear(list, r.items.length ? h('ul', { class: 'news-list' }, r.items.map((n) => h('li', {},
        h('div', { class: 'head' }, h('span', { class: 'tag' }, n.category_label), h('span', { class: 'muted small' }, date(n.published_at.slice(0, 10)))),
        h('h2', {}, h('a', { href: '#/notizia/' + n.slug }, n.title)), h('p', {}, n.excerpt))))
        : h('div', { class: 'empty-state' }, h('p', {}, 'Nessuna notizia pubblicata.')),
      r.pages > 1 ? h('nav', { class: 'pager' },
        h('button', { type: 'button', class: 'btn small', disabled: page <= 1, onclick: () => { page--; load(); } }, 'Precedenti'),
        h('span', { class: 'muted small' }, `Pagina ${page} di ${r.pages}`),
        h('button', { type: 'button', class: 'btn small', disabled: page >= r.pages, onclick: () => { page++; load(); } }, 'Successive')) : null);
    } catch (e) { clear(list, h('p', { class: 'form-error' }, e.message)); }
  }
  clear(view, h('h1', { tabindex: '-1' }, 'Notizie e aggiornamenti'),
    h('p', { class: 'muted' }, 'Comunicazioni, novità sui piani di banda e resoconti delle importazioni. Feed: ',
      h('a', { href: apiUrl('/news/feed?format=rss') }, 'RSS'), ' · ', h('a', { href: apiUrl('/news/feed?format=atom') }, 'Atom'), '.'),
    field('Categoria', catSel), list);
  load();
}

export async function showNewsItem(slug) {
  const view = $('#view');
  clear(view, h('p', { class: 'loading' }, 'Caricamento…'));
  try {
    const n = await get('/news/show?slug=' + encodeURIComponent(slug));
    clear(view, h('p', {}, h('a', { href: '#/notizie' }, '← Tutte le notizie')),
      h('article', { class: 'news-article' }, h('div', { class: 'head' }, h('span', { class: 'tag' }, n.category_label), h('span', { class: 'muted small' }, date(n.published_at.slice(0, 10)) + (n.author ? ' · ' + n.author : ''))),
        h('h1', { tabindex: '-1' }, n.title), renderBody(n.body)));
  } catch (e) { clear(view, h('div', { class: 'empty-state' }, h('p', {}, e.message), h('a', { class: 'btn', href: '#/notizie' }, 'Tutte le notizie'))); }
}

// ---------------- gestione (scheda «Notizie» del pannello di moderazione) ----------------
export async function newsAdmin(panel) {
  if (!isAdmin()) return;
  const formBox = h('div'), listBox = h('div');
  function editor(n = null) {
    const form = h('form', { class: 'form', novalidate: true },
      field('Titolo', h('input', { type: 'text', name: 'title', maxlength: 160, value: n?.title ?? '' }), { name: 'title' }),
      h('div', { class: 'grid-2' },
        field('Categoria', select('category', Object.entries(CATS).map(([v, l]) => ({ value: v, label: l })), n?.category ?? 'comunicazione'), { name: 'category' }),
        field('Stato', select('status', [{ value: 'draft', label: 'Bozza (non visibile)' }, { value: 'published', label: 'Pubblicata' }], n?.status ?? 'draft'), { name: 'status' })),
      field('Testo', h('textarea', { name: 'body', rows: 8, maxlength: 8000 }, n?.body ?? ''), { name: 'body', hint: 'Testo semplice: righe vuote per i paragrafi; gli indirizzi https diventano link.' }),
      h('div', { class: 'actions' }, h('button', { type: 'submit', class: 'btn primary' }, n ? 'Salva le modifiche' : 'Salva la notizia'),
        h('button', { type: 'button', class: 'btn', onclick: () => clear(formBox) }, 'Annulla')));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(form), body = Object.fromEntries(['title', 'category', 'status', 'body'].map((k) => [k, (fd.get(k) || '').toString()]));
      try { n ? await put('/admin/news/' + n.id, body) : await post('/admin/news', body); toast('Notizia salvata.'); clear(formBox); loadList(); } catch (err) { showErrors(form, err); }
    });
    clear(formBox, h('div', { class: 'card' }, h('h3', {}, n ? 'Modifica la notizia' : 'Nuova notizia'), form));
    form.querySelector('input')?.focus();
  }
  async function loadList() {
    clear(listBox, h('p', { class: 'loading' }, 'Caricamento…'));
    try {
      const r = await get('/admin/news');
      clear(listBox, r.items.length ? h('ul', { class: 'list' }, r.items.map((n) => h('li', {},
        h('div', { class: 'head' }, h('strong', {}, n.title), h('span', { class: 'tag ' + (n.status === 'published' ? 'approved' : '') }, n.status === 'published' ? 'Pubblicata' : 'Bozza'), h('span', { class: 'tag' }, n.category_label),
          h('span', { class: 'muted small' }, n.published_at ? date(n.published_at.slice(0, 10)) : 'non pubblicata')),
        h('div', { class: 'actions' },
          h('button', { type: 'button', class: 'btn small', onclick: async () => { editor(await get('/admin/news/show?slug=' + n.slug)); window.scrollTo({ top: 0, behavior: 'smooth' }); } }, 'Modifica'),
          n.status === 'published' ? h('a', { class: 'btn small', href: '#/notizia/' + n.slug }, 'Apri') : h('button', { type: 'button', class: 'btn small primary', onclick: async () => { await put('/admin/news/' + n.id, { status: 'published' }); toast('Notizia pubblicata.'); loadList(); } }, 'Pubblica'),
          h('button', { type: 'button', class: 'btn small danger', onclick: async () => { if (confirm('Eliminare definitivamente questa notizia?')) { await del('/admin/news/' + n.id); loadList(); } } }, 'Elimina')))))
        : h('p', { class: 'muted' }, 'Nessuna notizia: creane una con il pulsante qui sopra.'));
    } catch (e) { clear(listBox, h('p', { class: 'form-error' }, e.message)); }
  }
  clear(panel, h('div', { class: 'actions' }, h('button', { type: 'button', class: 'btn primary', onclick: () => editor() }, 'Nuova notizia'), h('a', { class: 'btn', href: '#/notizie' }, 'Apri la pagina pubblica')), formBox, listBox);
  loadList();
}
