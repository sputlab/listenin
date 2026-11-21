// Account self-service: profilo, email, password, storico personale, eliminazione.
import { webhooksPanel } from './webhooks.js?v=0.6.0';
import { get, post, patch, apiUrl } from '../api.js?v=0.6.0';
import { $, h, clear, field, showErrors, formData, busy, toast } from '../dom.js?v=0.6.0';
import { dateTime, date, REV_STATUS, REV_ACTION, REPORT_TYPE, REPORT_STATUS, locator as toLocator } from '../format.js?v=0.6.0';
import { state } from '../state.js?v=0.6.0';

let onAuthChange = () => {};
export function bindAccount(cb) { onAuthChange = cb; }

const TABS = [['profilo', 'Profilo e sicurezza'], ['logbook', 'Logbook SWL'], ['badge', 'Badge e punti'], ['webhook', 'Webhook'], ['contributi', 'I miei contributi'], ['segnalazioni', 'Le mie segnalazioni'], ['elimina', 'Elimina account']];

export function showAccount(tab = 'profilo') {
  const view = $('#view');
  if (!state.user) { location.hash = '#/accedi'; return; }
  const panel = h('div', { role: 'tabpanel' });
  const tabs = h('div', { class: 'tabs', role: 'tablist' }, TABS.map(([id, label]) =>
    h('button', { type: 'button', role: 'tab', 'aria-selected': id === tab ? 'true' : 'false',
      onclick: () => { history.replaceState(null, '', location.pathname + location.search + '#/account/' + id); showAccount(id); } }, label)));
  const h1 = h('h1', { tabindex: '-1' }, 'Il mio account');
  clear(view, h1, h('p', { class: 'muted' }, `${state.user.nickname}, iscritto dal ${state.user.member_since.split('-').reverse().join('/')}`), tabs, panel);
  ({ profilo: profile, logbook, badge, webhook: webhooksPanel, contributi: contributions, segnalazioni: reports, elimina: deletion }[tab] || profile)(panel);
}

function miniForm(title, fields, button, onSubmit) {
  const submit = h('button', { type: 'submit', class: 'btn primary' }, button);
  const form = h('form', { class: 'form', novalidate: true }, h('fieldset', {}, h('legend', {}, title), ...fields, h('div', { class: 'actions' }, submit)));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await busy(submit, async () => {
      try { await onSubmit(formData(form), form); } catch (err) { showErrors(form, err); }
    });
  });
  return form;
}

function profile(panel) {
  const pw = (name, auto = 'current-password') => h('input', { type: 'password', name, autocomplete: auto, maxlength: 4096 });
  clear(panel, h('div', { class: 'form' },
    miniForm('Profilo pubblico', [
      field('Nickname', h('input', { type: 'text', name: 'nickname', value: state.user.nickname, maxlength: 32 }), { name: 'nickname' }),
      (() => {
        const loc = h('input', { type: 'text', name: 'default_locator', value: state.user.default_locator || '', maxlength: 8, placeholder: 'es. JN65tw', autocapitalize: 'characters' });
        const wrap = field('Locatore QTH predefinito', loc, { name: 'default_locator', hint: 'Serve a calcolare distanza e azimut di ogni frequenza. Resta nel tuo profilo e non viene mostrato agli altri.' });
        if ('geolocation' in navigator) {
          wrap.append(h('button', { type: 'button', class: 'btn small', onclick: () => navigator.geolocation.getCurrentPosition(
            (p) => { loc.value = toLocator(p.coords.latitude, p.coords.longitude); toast('Locatore calcolato dalla tua posizione: ricorda di salvare.'); },
            () => toast('Posizione non disponibile.', 'error'), { timeout: 10000 }) }, 'Calcola dalla mia posizione'));
        }
        return wrap;
      })(),
    ], 'Salva il profilo', async (d) => {
      const r = await patch('/account', d);
      state.user = r.user; onAuthChange(); toast('Profilo aggiornato.');
    }),
    miniForm('Indirizzo email', [
      h('p', { class: 'muted small' }, 'Attuale: ', state.user.email),
      field('Nuovo indirizzo', h('input', { type: 'email', name: 'new_email', autocomplete: 'email' }), { name: 'new_email', hint: 'Ti invieremo un link di conferma al nuovo indirizzo.' }),
      field('Password attuale', pw('password'), { name: 'password' }),
    ], 'Cambia email', async (d, form) => {
      toast((await post('/account/email', d)).message); form.reset();
    }),
    miniForm('Password', [
      field('Password attuale', pw('current_password'), { name: 'current_password' }),
      field('Nuova password', pw('new_password', 'new-password'), { name: 'new_password', hint: `Almeno ${state.meta.app.password_min_length} caratteri. Le altre sessioni aperte verranno chiuse.` }),
      field('Ripeti la nuova password', pw('new_password_confirm', 'new-password'), { name: 'new_password_confirm' }),
    ], 'Cambia password', async (d, form) => {
      toast((await post('/account/password', d)).message); form.reset();
    })));
}

async function logbook(panel, page = 1) {
  clear(panel, h('p', { class: 'loading' }, 'Caricamento…'));
  try {
    const r = await get('/account/logbook?page=' + page);
    const head = h('div', { class: 'toolbar' },
      h('p', { class: 'count' }, h('strong', {}, r.total.toLocaleString('it-IT')), ' ascolti registrati, di cui ', h('strong', {}, r.recoveries.toLocaleString('it-IT')), ' recuperi'),
      h('span', { class: 'spacer' }),
      h('a', { class: 'btn small', href: apiUrl('/account/logbook/export'), download: '' }, 'Esporta in CSV'));
    if (!r.items.length) {
      clear(panel, head, h('div', { class: 'empty-state' }, h('p', {}, 'Il tuo logbook è vuoto.'), h('p', { class: 'muted' }, 'Ogni volta che confermi di aver ascoltato una frequenza, l’ascolto compare qui.'),
        h('a', { class: 'btn primary', href: '#/missioni' }, 'Scegli una missione di recupero')));
      return;
    }
    clear(panel, head, h('ul', { class: 'list' }, r.items.map((c) => h('li', {},
      h('div', { class: 'head' },
        h('a', { href: '#/f/' + c.frequency_id }, h('strong', { class: 'num' }, c.freq_mhz + ' MHz')),
        h('span', {}, c.label),
        c.recovered ? h('span', { class: 'tag approved' }, 'Recupero') : null,
        h('span', { class: 'muted small' }, date(c.heard_on))),
      h('div', { class: 'small muted' }, [c.rst ? 'Rapporto ' + c.rst : null, c.equipment].filter(Boolean).join(' · ') || 'Nessun dettaglio')))),
    pager(r, (p) => logbook(panel, p)));
  } catch (e) { clear(panel, h('p', { class: 'form-error' }, e.message)); }
}

async function badge(panel) {
  clear(panel, h('p', { class: 'loading' }, 'Caricamento…'));
  try {
    const r = await get('/account/badges');
    const pct = Math.min(100, Math.round(100 * r.trust_score / r.auto_approve_min));
    clear(panel,
      h('div', { class: 'cards' },
        h('div', { class: 'stat' }, h('strong', {}, r.points.toLocaleString('it-IT')), h('span', {}, 'punti reputazione')),
        h('div', { class: 'stat' }, h('strong', {}, r.recoveries.toLocaleString('it-IT')), h('span', {}, 'recuperi')),
        h('div', { class: 'stat' }, h('strong', {}, r.trust_score), h('span', {}, 'Trust Score'))),
      h('div', { class: 'card' }, h('h3', {}, 'Trust Score'),
        h('div', { class: 'progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': String(r.auto_approve_min), 'aria-valuenow': String(Math.min(r.trust_score, r.auto_approve_min)), 'aria-label': 'Trust Score' }, h('span', { style: { width: pct + '%' } })),
        h('p', { class: 'small muted' }, r.trust_score >= r.auto_approve_min
          ? 'Hai raggiunto la soglia: le tue modifiche minori (note, toni, coordinate, ultimo ascolto…) vengono pubblicate subito. Restano sempre nello storico e i moderatori possono annullarle.'
          : `Con ${r.auto_approve_min} punti di Trust Score le tue modifiche minori vengono pubblicate senza attendere la revisione. Cresce con le proposte approvate e cala con quelle respinte.`)),
      h('h3', {}, 'Badge'),
      h('ul', { class: 'badges' }, r.badges.map((b) => h('li', { class: b.earned ? '' : 'locked' },
        h('strong', {}, (b.earned ? '✓ ' : '') + b.name), h('span', { class: 'small muted' }, b.description),
        b.earned ? h('span', { class: 'small' }, 'Ottenuto il ' + date(b.awarded_at)) : h('span', { class: 'small muted' }, 'Non ancora ottenuto')))),
      h('p', {}, h('a', { href: '#/statistiche' }, 'Vedi le classifiche')));
  } catch (e) { clear(panel, h('p', { class: 'form-error' }, e.message)); }
}

async function contributions(panel, page = 1) {
  clear(panel, h('p', { class: 'loading' }, 'Caricamento…'));
  try {
    const r = await get('/account/contributions?page=' + page);
    if (!r.items.length) {
      clear(panel, h('div', { class: 'empty-state' }, h('p', {}, 'Non hai ancora inviato proposte.'), h('a', { class: 'btn primary', href: '#/proponi' }, 'Aggiungi una frequenza')));
      return;
    }
    clear(panel, h('ul', { class: 'list' }, r.items.map((c) => h('li', {},
      h('div', { class: 'head' },
        h('strong', { class: 'num' }, c.freq_mhz ? c.freq_mhz + ' MHz' : '—'),
        h('span', {}, REV_ACTION[c.action]),
        h('span', { class: 'tag ' + c.status }, REV_STATUS[c.status]),
        h('span', { class: 'muted small' }, dateTime(c.created_at))),
      c.label ? h('div', {}, c.label) : null,
      h('div', { class: 'small muted' }, `${c.changes} campi`, c.origin === 'report' ? ', da una tua segnalazione' : ''),
      c.review_note ? h('p', { class: 'notice' + (c.status === 'rejected' ? ' danger' : ' ok') }, 'Nota del moderatore: ', c.review_note) : null,
      c.frequency_id && c.status === 'approved' ? h('a', { href: '#/f/' + c.frequency_id, class: 'small' }, 'Apri la scheda') : null))),
    pager(r, (p) => contributions(panel, p)));
  } catch (e) { clear(panel, h('p', { class: 'form-error' }, e.message)); }
}

async function reports(panel, page = 1) {
  clear(panel, h('p', { class: 'loading' }, 'Caricamento…'));
  try {
    const r = await get('/account/reports?page=' + page);
    if (!r.items.length) { clear(panel, h('p', { class: 'muted' }, 'Non hai inviato segnalazioni.')); return; }
    clear(panel, h('ul', { class: 'list' }, r.items.map((x) => h('li', {},
      h('div', { class: 'head' },
        h('strong', { class: 'num' }, x.freq_mhz + ' MHz'),
        h('span', {}, REPORT_TYPE[x.type]),
        h('span', { class: 'tag ' + x.status }, REPORT_STATUS[x.status]),
        h('span', { class: 'muted small' }, dateTime(x.created_at))),
      h('div', {}, x.freq_label),
      x.field_label ? h('div', { class: 'small' }, `${x.field_label}: ${x.current_value ?? '—'} → ${x.suggested_value}`) : null,
      x.removal_reason_label ? h('div', { class: 'small' }, x.removal_reason_label) : null,
      x.resolution_note ? h('p', { class: 'notice ok' }, 'Esito: ', x.resolution_note) : null))),
    pager(r, (p) => reports(panel, p)));
  } catch (e) { clear(panel, h('p', { class: 'form-error' }, e.message)); }
}

function deletion(panel) {
  const submit = h('button', { type: 'submit', class: 'btn danger solid' }, 'Elimina definitivamente');
  const form = h('form', { class: 'form', novalidate: true },
    h('fieldset', { class: 'danger-zone' }, h('legend', {}, 'Eliminazione dell’account'),
      h('p', {}, 'Verranno cancellati in modo definitivo email, nickname, password, le proposte non ancora approvate e le segnalazioni aperte.'),
      h('p', {}, 'Le frequenze e le modifiche già approvate restano nel database pubblico, attribuite a “utente eliminato”, per non compromettere lo storico.'),
      field('Password', h('input', { type: 'password', name: 'password', autocomplete: 'current-password' }), { name: 'password' }),
      field('Scrivi ELIMINA per confermare', h('input', { type: 'text', name: 'confirm', autocomplete: 'off' }), { name: 'confirm' }),
      h('div', { class: 'actions' }, submit)));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await busy(submit, async () => {
      try {
        const r = await post('/account/delete', formData(form));
        state.user = null; onAuthChange();
        toast(r.message);
        location.hash = '#/';
      } catch (err) { showErrors(form, err); }
    });
  });
  clear(panel, form);
}

function pager(r, go) {
  if (r.pages <= 1) return null;
  return h('nav', { class: 'pager' },
    h('button', { type: 'button', class: 'btn small', disabled: r.page <= 1, onclick: () => go(r.page - 1) }, 'Precedenti'),
    h('span', { class: 'muted small' }, `Pagina ${r.page} di ${r.pages}`),
    h('button', { type: 'button', class: 'btn small', disabled: r.page >= r.pages, onclick: () => go(r.page + 1) }, 'Successive'));
}
