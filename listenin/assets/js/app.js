// ListenIn — avvio dell'applicazione e instradamento (hash routing).
import { get, setCsrf, BASE } from './api.js?v=0.6.0';
import { $, h, clear, initModal, toast, closeModal, openModal } from './dom.js?v=0.6.0';
import { state, indexServices, isModerator, isAdmin } from './state.js?v=0.6.0';
import { openDoc } from './content.js?v=0.6.0';
import { showSearch, renderFilters, refresh, resetFilters, bindActions, invalidate } from './views/search.js?v=0.6.0';
import { showDetail, bindDetail } from './views/detail.js?v=0.6.0';
import { showReport } from './views/report.js?v=0.6.0';
import { showProposal } from './views/proposal.js?v=0.6.0';
import { showLogin, showRegister, showVerify, showForgot, showReset, logout, bindAuth } from './views/auth.js?v=0.6.0';
import { showAccount, bindAccount } from './views/account.js?v=0.6.0';
import { showModeration, bindModeration } from './views/moderation.js?v=0.6.0';
import { showStats } from './views/stats.js?v=0.6.0';
import { showMissions } from './views/missions.js?v=0.6.0';
import { showOpenData } from './views/opendata.js?v=0.6.0';
import { showSites, showSite } from './views/sites.js?v=0.6.0';
import { showSaved } from './views/saved.js?v=0.6.0';
import { showNews, showNewsItem } from './views/news.js?v=0.6.0';
import { bindCheckin } from './checkin.js?v=0.6.0';
import { cycleTheme, currentTheme, themeLabel } from './theme.js?v=0.6.0';

const ROUTES = [
  [/^\/?$/, () => search()],
  [/^\/f\/(\d+)$/, (id) => { search(); showDetail(Number(id)); }, 'search'],
  [/^\/f\/(\d+)\/modifica$/, (id) => showProposal(Number(id)), 'page'],
  [/^\/proponi$/, () => showProposal(), 'page'],
  [/^\/accedi$/, () => showLogin(), 'page'],
  [/^\/registrati$/, () => showRegister(), 'page'],
  [/^\/password-dimenticata$/, () => showForgot(), 'page'],
  [/^\/reimposta-password\/([\w-]+)$/, (t) => showReset(t), 'page'],
  [/^\/verifica\/([\w-]+)$/, (t) => showVerify(t, 'verify'), 'page'],
  [/^\/conferma-email\/([\w-]+)$/, (t) => showVerify(t, 'email'), 'page'],
  [/^\/account(?:\/(\w+))?$/, (tab) => showAccount(tab || 'profilo'), 'page'],
  [/^\/moderazione(?:\/(\w+))?$/, (tab) => showModeration(tab || 'proposte'), 'page'],
  [/^\/importazione$/, () => showModeration('importazione'), 'page'],
  [/^\/statistiche$/, () => showStats(), 'page'],
  [/^\/missioni(?:\?.*)?$/, () => showMissions(), 'page'],
  [/^\/opendata$/, () => showOpenData(), 'page'],
  [/^\/postazioni(?:\?.*)?$/, () => showSites(), 'page'],
  [/^\/postazione\/([a-z0-9-]+)$/, (slug) => showSite(slug), 'page'],
  [/^\/offline$/, () => showSaved(), 'page'],
  [/^\/notizie(?:\?.*)?$/, () => showNews(), 'page'],
  [/^\/notizia\/([a-z0-9-]+)$/, (slug) => showNewsItem(slug), 'page'],
  [/^\/doc\/(disclaimer|termini|privacy)$/, (d) => { search(); openDoc(d); }, 'search'],
];

let searchActive = false;
function search() {
  document.body.dataset.route = 'search';
  showSearch({ force: !searchActive });
  searchActive = true;
}

function markSubnav(path) {
  const key = path.startsWith('/notizi') ? '#/notizie' : path.startsWith('/postazion') ? '#/postazioni' : path.startsWith('/offline') ? '#/offline' : path.startsWith('/missioni') ? '#/missioni' : path.startsWith('/statistiche') ? '#/statistiche' : path.startsWith('/opendata') ? '#/opendata' : '#/';
  document.querySelectorAll('.subnav a').forEach((a) => {
    if (a.getAttribute('href') === key) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
  });
}

function route() {
  const path = decodeURIComponent(location.hash.replace(/^#/, '')) || '/';
  document.body.classList.remove('filters-open');
  markSubnav(path);
  for (const [re, fn, kind] of ROUTES) {
    const m = path.match(re);
    if (!m) continue;
    if (kind === 'page') {
      closeModal();
      document.body.dataset.route = 'page';
      searchActive = false;
      invalidate();
      window.scrollTo({ top: 0 });
    }
    fn(...m.slice(1));
    return;
  }
  location.hash = '#/';
}

async function showShortcuts() {
  const keys = [['/', 'Vai alla ricerca'], ['↓ ↑', 'Scorri i risultati (dalla ricerca o da una riga)'], ['Invio', 'Apri la scheda della riga selezionata'],
    ['Esc', 'Chiudi la finestra aperta o esci dalla ricerca'], ['?', 'Mostra questo elenco']];
  openModal('Scorciatoie da tastiera', h('div', { class: 'help-keys' }, keys.flatMap(([k, d]) => [h('kbd', {}, k), h('span', {}, d)])));
}

function renderNav() {
  const nav = $('#usernav');
  const items = [h('a', { href: '#/proponi', class: 'cta' }, h('span', { class: 'long' }, 'Aggiungi frequenza'), h('span', { class: 'short', 'aria-hidden': 'true' }, '+ Frequenza'), h('span', { class: 'visually-hidden short' }, 'Aggiungi frequenza'))];
  if (state.user) {
    if (isModerator()) {
      const link = h('a', { href: '#/moderazione' }, 'Moderazione');
      items.push(link);
      if (isAdmin()) items.push(h('a', { href: '#/moderazione/importazione' }, 'Importazione'));
      get('/moderation/stats').then((s) => {
        const n = s.pending_revisions + s.open_reports;
        if (n) link.append(h('span', { class: 'badge', 'aria-label': `${n} elementi da gestire` }, n > 99 ? '99+' : String(n)));
      }).catch(() => {});
    }
    items.push(h('a', { href: '#/account/badge', title: 'Punti reputazione' }, state.user.nickname, h('span', { class: 'pts' }, `${state.user.points ?? 0} pt`)),
      h('button', { type: 'button', onclick: logout }, 'Esci'));
  } else {
    items.push(h('a', { href: '#/accedi' }, 'Accedi'));
  }
  const themeText = h('span', { class: 'long' }, 'Tema: ' + themeLabel(currentTheme()));
  const themeBtn = h('button', { type: 'button', class: 'theme-btn', onclick: () => {
    const t = cycleTheme();
    themeText.textContent = 'Tema: ' + themeLabel(t);
    themeBtn.setAttribute('aria-label', `Cambia tema (attuale: ${themeLabel(t)})`);
  }, 'aria-label': `Cambia tema (attuale: ${themeLabel(currentTheme())})`, title: 'Alterna tra tema automatico, chiaro, scuro e notte' }, themeText, h('span', { class: 'short', 'aria-hidden': 'true' }, '◐'));
  items.push(themeBtn);
  clear(nav, ...items);
}

async function onAuthChange(reload = false) {
  if (reload) {
    try { const me = await get('/auth/me'); state.user = me.user; } catch { /* resta lo stato attuale */ }
  }
  renderNav();
}

async function boot() {
  initModal();
  try {
    state.meta = await get('/meta');
  } catch (e) {
    clear($('#view'), h('div', { class: 'empty-state' }, h('h1', {}, 'Servizio non raggiungibile'), h('p', {}, e.message)));
    return;
  }
  setCsrf(state.meta.csrf);
  state.user = state.meta.user;
  indexServices(state.meta);

  const actions = {
    openDetail: (id) => { location.hash = '#/f/' + id; },
    openReport: (id, item) => showReport(id, item),
    refresh: () => { invalidate(); refresh(); },
  };
  bindActions(actions);
  bindDetail(actions);
  bindAuth(onAuthChange);
  bindAccount(onAuthChange);
  bindModeration(renderNav);
  bindCheckin(renderNav);

  // Ricerca libera dalla testata (con breve attesa durante la digitazione)
  const q = $('#q');
  let timer = null;
  const submitQuery = () => {
    const v = q.value.trim();
    if (v) state.filters.q = v; else delete state.filters.q;
    state.page = 1;
    if (document.body.dataset.route !== 'search') { location.hash = '#/'; return; }
    refresh();
  };
  $('#search-form').addEventListener('submit', (e) => { e.preventDefault(); clearTimeout(timer); submitQuery(); });
  q.addEventListener('input', () => {
    clearTimeout(timer);
    if (document.body.dataset.route === 'search') timer = setTimeout(submitQuery, 350);
  });

  $('#filters-reset').addEventListener('click', resetFilters);
  $('#filters-close').addEventListener('click', () => document.body.classList.remove('filters-open'));
  document.querySelectorAll('.sitefoot a[data-doc]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault();
    openDoc(a.dataset.doc);
  }));
  document.addEventListener('keydown', (e) => {
    const tag = document.activeElement?.tagName;
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || document.activeElement?.isContentEditable;
    const modalOpen = $('#modal').open;
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key === '/' && !typing && !modalOpen) {
      e.preventDefault();
      q.focus();
    } else if (e.key === '?' && !typing && !modalOpen) {
      e.preventDefault();
      showShortcuts();
    } else if (e.key === 'Escape' && document.activeElement === q) {
      q.blur();
    } else if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !modalOpen && document.body.dataset.route === 'search') {
      const rows = [...document.querySelectorAll('.freq-table tbody tr')];
      if (!rows.length) return;
      const i = rows.indexOf(document.activeElement.closest?.('tr') ?? null);
      const fromSearch = document.activeElement === q && e.key === 'ArrowDown';
      if (i === -1 && !fromSearch) return;                       // le frecce non devono rubare lo scorrimento altrove
      e.preventDefault();
      const next = fromSearch ? 0 : Math.max(0, Math.min(rows.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)));
      rows[next].focus();
    }
  });

  renderNav();
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || ['localhost', '127.0.0.1'].includes(location.hostname))) {
    navigator.serviceWorker.register(BASE + 'sw.js', { scope: BASE }).catch(() => { /* senza service worker l'app funziona comunque, solo non offline */ });
  }
  window.addEventListener('hashchange', route);
  route();
}

boot().catch((e) => toast(e.message || 'Errore imprevisto.', 'error'));
