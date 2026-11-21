// Pannello di moderazione: proposte, segnalazioni, utenti (solo amministratori).
import { get, post, patch } from '../api.js?v=0.6.0';
import { $, h, clear, field, select, openModal, closeModal, toast } from '../dom.js?v=0.6.0';
import { dateTime, REV_STATUS, REV_ACTION, REPORT_TYPE, REPORT_STATUS } from '../format.js?v=0.6.0';
import { state, isModerator, isAdmin } from '../state.js?v=0.6.0';
import { diffTable } from './detail.js?v=0.6.0';
import { showImport } from './import.js?v=0.6.0';
import { newsAdmin } from './news.js?v=0.6.0';

let refreshNav = () => {};
export function bindModeration(cb) { refreshNav = cb; }

export async function showModeration(tab = 'proposte') {
  const view = $('#view');
  if (!isModerator()) {
    clear(view, h('p', { class: 'form-error' }, 'Area riservata a moderatori e amministratori.'));
    return;
  }
  const tabsDef = [['proposte', 'Proposte'], ['segnalazioni', 'Segnalazioni'], ['archivio', 'Archivio revisioni']];
  if (isAdmin()) tabsDef.push(['importazione', 'Importazione'], ['notizie', 'Notizie'], ['utenti', 'Utenti'], ['sistema', 'Sistema']);
  const stats = h('div', { class: 'stats' });
  const panel = h('div', { role: 'tabpanel' });
  const h1 = h('h1', { tabindex: '-1' }, 'Moderazione');
  clear(view, h1, stats,
    h('div', { class: 'tabs', role: 'tablist' }, tabsDef.map(([id, label]) =>
      h('button', { type: 'button', role: 'tab', 'aria-selected': id === tab ? 'true' : 'false',
        onclick: () => { history.replaceState(null, '', location.pathname + location.search + '#/moderazione/' + id); showModeration(id); } }, label))),
    panel);
  loadStats(stats);
  ({ proposte: () => revisions(panel, 'pending'), segnalazioni: () => reports(panel), archivio: () => archive(panel), importazione: () => showImport(panel), notizie: () => newsAdmin(panel), utenti: () => users(panel), sistema: () => system(panel) }[tab] || (() => revisions(panel, 'pending')))();
}

async function loadStats(box) {
  try {
    const s = await get('/moderation/stats');
    box.parentElement?.querySelector('.empty-db')?.remove();
    if (isAdmin() && s.frequencies === 0) {
      box.after(h('p', { class: 'notice empty-db' }, 'Il database è ancora vuoto. ',
        h('a', { href: '#/moderazione/importazione' }, 'Importa i dati iniziali'), ' (il manuale incluso nel pacchetto e, se vuoi, il file di inascolto.org).'));
    }
    clear(box,
      h('div', {}, h('strong', {}, s.pending_revisions), 'proposte in attesa'),
      h('div', {}, h('strong', {}, s.open_reports), 'segnalazioni aperte'),
      h('div', {}, h('strong', {}, s.frequencies.toLocaleString('it-IT')), 'frequenze pubblicate'),
      h('div', {}, h('strong', {}, s.to_verify.toLocaleString('it-IT')), 'da verificare'));
  } catch { /* le statistiche sono accessorie */ }
}

// ---------------- Proposte ----------------
async function revisions(panel, status, page = 1) {
  clear(panel, h('p', { class: 'loading' }, 'Caricamento…'));
  try {
    const r = await get(`/moderation/revisions?status=${status}&page=${page}`);
    if (!r.items.length) { clear(panel, h('p', { class: 'muted' }, status === 'pending' ? 'Nessuna proposta in attesa.' : 'Nessuna revisione.')); return; }
    clear(panel, h('ul', { class: 'list' }, r.items.map((x) => h('li', {},
      h('div', { class: 'head' },
        h('strong', { class: 'num' }, (x.freq_mhz || '—') + ' MHz'),
        h('span', {}, REV_ACTION[x.action]),
        status !== 'pending' ? h('span', { class: 'tag ' + status }, REV_STATUS[status]) : null,
        x.outdated && status === 'pending' ? h('span', { class: 'tag pending' }, 'record cambiato nel frattempo') : null,
        h('span', { class: 'muted small' }, `${x.author}, ${dateTime(x.created_at)}`)),
      h('div', {}, x.label || ''),
      x.comment ? h('p', { class: 'small muted' }, '“', x.comment, '”') : null,
      h('button', { type: 'button', class: 'btn small', onclick: () => revisionDetail(x.id, () => revisions(panel, status, page)) },
        status === 'pending' ? `Esamina ${x.changes} campi` : 'Dettagli')))),
    pagerNav(r, (p) => revisions(panel, status, p)));
  } catch (e) { clear(panel, h('p', { class: 'form-error' }, e.message)); }
}

async function revisionDetail(id, done) {
  const body = h('div', {}, h('p', { class: 'loading' }, 'Caricamento…'));
  openModal('Revisione n. ' + id, body, { wide: true });
  let rev;
  try { rev = await get('/moderation/revisions/' + id); } catch (e) { clear(body, h('p', { class: 'form-error' }, e.message)); return; }
  const note = h('textarea', { name: 'note', rows: 2, maxlength: 500 });
  const msg = h('div');
  const act = async (path, payload, okMsg) => {
    try {
      const r = await post(path, payload);
      toast(r.message || okMsg);
      closeModal(); done(); refreshNav();
    } catch (e) {
      clear(msg, h('p', { class: 'form-error', role: 'alert' }, e.message));
      if (e.code === 'conflict') {
        msg.append(h('p', {}, 'Campi cambiati dopo la proposta: ', (e.details.conflicts || []).join(', '), '.'),
          h('button', { type: 'button', class: 'btn danger', onclick: () => act(`/moderation/revisions/${id}/approve`, { note: note.value, force: true }) }, 'Approva comunque sovrascrivendo'));
      }
    }
  };
  clear(body,
    h('p', {}, h('strong', {}, REV_ACTION[rev.action]), ` proposta da ${rev.author} il ${dateTime(rev.created_at)}`,
      rev.frequency_id ? [' su ', h('a', { href: '#/f/' + rev.frequency_id, onclick: () => closeModal() }, 'record n. ' + rev.frequency_id)] : null),
    rev.author_comment ? h('p', { class: 'notice' }, 'Commento dell’autore: ', rev.author_comment) : null,
    rev.outdated ? h('p', { class: 'notice' }, 'Il record è stato modificato dopo questa proposta: le righe evidenziate non corrispondono più al valore di partenza.') : null,
    rev.duplicates?.length ? h('p', { class: 'notice danger' }, 'Possibili duplicati: ', rev.duplicates.map((d) => `n. ${d.id} (${d.freq_mhz} MHz, ${d.label})`).join('; ')) : null,
    rev.changes.length ? diffTable(rev.changes, rev.action === 'create', rev.action === 'update') : h('p', { class: 'muted' }, 'Nessun campo da mostrare.'),
    rev.review_note ? h('p', { class: 'notice' }, `Nota di ${rev.reviewer || 'moderazione'}: ${rev.review_note}`) : null,
    rev.status === 'pending' ? h('div', { class: 'form' },
      field('Nota per l’autore', note, { hint: 'Obbligatoria in caso di rifiuto: l’autore la vede nel suo storico.' }),
      msg,
      h('div', { class: 'actions' },
        h('button', { type: 'button', class: 'btn primary', onclick: () => act(`/moderation/revisions/${id}/approve`, { note: note.value }) }, 'Approva e pubblica'),
        h('button', { type: 'button', class: 'btn danger', onclick: () => act(`/moderation/revisions/${id}/reject`, { note: note.value }) }, 'Rifiuta'))) : null);
}

async function archive(panel) {
  const sel = select('status', [{ value: 'approved', label: 'Approvate' }, { value: 'rejected', label: 'Rifiutate' }, { value: 'superseded', label: 'Sostituite' }], 'approved');
  const box = h('div');
  sel.addEventListener('change', () => revisions(box, sel.value));
  clear(panel, h('div', { class: 'toolbar' }, field('Mostra', sel)), box);
  revisions(box, 'approved');
}

// ---------------- Segnalazioni ----------------
async function reports(panel, status = '', page = 1) {
  const sel = select('status', [{ value: '', label: 'Da gestire' }, ...Object.entries(REPORT_STATUS).map(([value, label]) => ({ value, label }))], status);
  const list = h('div', {}, h('p', { class: 'loading' }, 'Caricamento…'));
  sel.addEventListener('change', () => reports(panel, sel.value));
  clear(panel, h('div', { class: 'toolbar' }, field('Stato', sel)), list);
  try {
    const r = await get(`/moderation/reports?page=${page}` + (status ? '&status=' + status : ''));
    if (!r.items.length) { clear(list, h('p', { class: 'muted' }, 'Nessuna segnalazione.')); return; }
    clear(list, h('ul', { class: 'list' }, r.items.map((x) => reportItem(x, () => reports(panel, status, page)))), pagerNav(r, (p) => reports(panel, status, p)));
  } catch (e) { clear(list, h('p', { class: 'form-error' }, e.message)); }
}

function reportItem(x, reload) {
  const open = ['aperta', 'in_revisione'].includes(x.status);
  const note = h('input', { type: 'text', name: 'note', maxlength: 500, placeholder: 'Nota di esito (facoltativa)' });
  const value = h('input', { type: 'text', name: 'value', maxlength: 255, value: x.suggested_value || '' });
  const err = h('div');
  const run = async (path, payload) => {
    try {
      const r = await post(path, payload);
      toast(r.message); reload(); refreshNav();
    } catch (e) { clear(err, h('p', { class: 'form-error', role: 'alert' }, e.message + (e.fields ? ' ' + Object.values(e.fields).join(' ') : ''))); }
  };
  const canApply = x.type !== 'rimozione' || !x.freq_deleted;
  return h('li', {},
    h('div', { class: 'head' },
      h('strong', { class: 'num' }, x.freq_mhz + ' MHz'),
      h('span', {}, REPORT_TYPE[x.type]),
      h('span', { class: 'tag ' + x.status }, REPORT_STATUS[x.status]),
      h('span', { class: 'muted small' }, `${x.reporter || 'utente eliminato'}, ${dateTime(x.created_at)}`)),
    h('div', {}, h('a', { href: '#/f/' + x.frequency_id }, x.freq_label)),
    x.field ? h('div', { class: 'small' }, h('strong', {}, x.field_label), `: ${x.current_value ?? '—'} → ${x.suggested_value}`) : null,
    x.removal_reason ? h('div', { class: 'small' }, h('strong', {}, x.removal_reason_label),
      x.duplicate_of_id ? [' del record ', h('a', { href: '#/f/' + x.duplicate_of_id }, 'n. ' + x.duplicate_of_id)] : null) : null,
    x.message ? h('p', { class: 'small muted' }, '“', x.message, '”') : null,
    x.resolution_note ? h('p', { class: 'small' }, `Esito (${x.handler || 'moderazione'}): ${x.resolution_note}`) : null,
    open ? h('div', { class: 'form' },
      x.field ? field('Valore da applicare', value, { hint: 'Puoi correggerlo prima di applicarlo.' }) : null,
      note, err,
      h('div', { class: 'actions' },
        x.status === 'aperta' ? h('button', { type: 'button', class: 'btn small', onclick: () => run(`/moderation/reports/${x.id}/claim`, {}) }, 'Prendi in carico') : null,
        canApply ? h('button', { type: 'button', class: 'btn small primary', onclick: () => run(`/moderation/reports/${x.id}/resolve`, { apply: true, value: x.field ? value.value : null, note: note.value }) },
          x.type === 'rimozione' ? (x.removal_reason === 'duplicato' ? 'Conferma il duplicato e rimuovi' : 'Rimuovi il record e risolvi') : 'Applica e risolvi') : null,
        h('button', { type: 'button', class: 'btn small', onclick: () => run(`/moderation/reports/${x.id}/resolve`, { apply: false, note: note.value }) },
          x.origin === 'import' ? 'Non è un duplicato' : 'Risolvi senza modifiche'),
        h('button', { type: 'button', class: 'btn small danger', onclick: () => run(`/moderation/reports/${x.id}/archive`, { note: note.value }) }, 'Archivia'))) : null);
}

// ---------------- Utenti ----------------
async function users(panel, q = '') {
  const search = h('input', { type: 'search', name: 'q', value: q, placeholder: 'Nickname o email' });
  const list = h('div', {}, h('p', { class: 'loading' }, 'Caricamento…'));
  const form = h('form', { class: 'toolbar', onsubmit: (e) => { e.preventDefault(); users(panel, search.value.trim()); } },
    field('Cerca utente', search), h('button', { type: 'submit', class: 'btn' }, 'Cerca'));
  clear(panel, form, list);
  try {
    const r = await get('/admin/users' + (q ? '?q=' + encodeURIComponent(q) : ''));
    clear(list, h('ul', { class: 'list' }, r.items.map((u) => {
      const role = select('role', [{ value: 'user', label: 'Utente' }, { value: 'moderator', label: 'Moderatore' }, { value: 'admin', label: 'Amministratore' }], u.role, { 'aria-label': 'Ruolo di ' + u.nickname });
      const self = u.id === state.user.id;
      role.disabled = self || u.status === 'pending';
      role.addEventListener('change', async () => {
        try { toast((await patch('/admin/users/' + u.id, { role: role.value })).message); } catch (e) { toast(e.message, 'error'); role.value = u.role; }
      });
      return h('li', {},
        h('div', { class: 'head' }, h('strong', {}, u.nickname), h('span', { class: 'muted small' }, u.email),
          h('span', { class: 'tag' }, { active: 'attivo', pending: 'da verificare', suspended: 'sospeso' }[u.status])),
        h('div', { class: 'small muted' }, `Iscritto ${dateTime(u.created_at)}, ${u.approved} contributi approvati`),
        h('div', { class: 'actions' }, role,
          !self && u.status !== 'pending' ? h('button', { type: 'button', class: 'btn small' + (u.status === 'active' ? ' danger' : ''), onclick: async () => {
            try { toast((await patch('/admin/users/' + u.id, { status: u.status === 'active' ? 'suspended' : 'active' })).message); users(panel, q); } catch (e) { toast(e.message, 'error'); }
          } }, u.status === 'active' ? 'Sospendi' : 'Riattiva') : null));
    })));
  } catch (e) { clear(list, h('p', { class: 'form-error' }, e.message)); }
}

// ---------------- Sistema ----------------
async function webhooksCard() {
  let items = [];
  try { items = (await get('/admin/webhooks')).items; } catch { return h('div'); }
  const active = items.filter((w) => w.active).length;
  return h('div', { class: 'card' }, h('h3', {}, 'Webhook degli utenti'),
    h('p', {}, `${items.length} configurati, ${active} attivi.`),
    items.length ? h('ul', { class: 'small' }, items.slice(0, 15).map((w) => h('li', {}, `${w.user} → ${w.host} (${w.active ? 'attivo' : 'disattivato'}${w.failures ? ', errori: ' + w.failures : ''}) `,
      w.active ? h('button', { type: 'button', class: 'btn small', onclick: async () => { const reason = prompt('Motivo della disattivazione (visibile al proprietario):', 'Uso improprio'); if (reason) { await post(`/admin/webhooks/${w.id}/disable`, { reason }); toast('Webhook disattivato.'); system(document.querySelector('[role=tabpanel]')); } } }, 'Disattiva') : null))) : null);
}

async function system(panel) {
  clear(panel, h('p', { class: 'loading' }, 'Caricamento…'));
  try {
    const r = await get('/admin/system');
    const msg = h('p', { class: 'small', 'aria-live': 'polite' });
    clear(panel,
      h('div', { class: 'card' }, h('h3', {}, 'Versione'), h('p', {}, `ListenIn ${r.version} · PHP ${r.php}`)),
      h('div', { class: 'card' }, h('h3', {}, 'Database'),
        h('p', {}, 'Dopo aver caricato una nuova versione dei file, il database si aggiorna da solo alla prima visita. Se preferisci, puoi verificarlo o forzarlo da qui. Prima di un aggiornamento conviene sempre un backup del database.'),
        h('ul', { class: 'small' }, r.migrations.applied.map((m) => h('li', {}, '✓ ' + m)), r.migrations.pending.map((m) => h('li', {}, '… in attesa: ' + m))),
        h('div', { class: 'actions' }, h('button', { type: 'button', class: 'btn primary', onclick: async () => {
          try { const x = await post('/admin/system/migrate', {}); msg.textContent = x.message; toast(x.message); system(panel); } catch (e) { toast(e.message, 'error'); }
        } }, r.migrations.pending.length ? 'Aggiorna il database' : 'Verifica il database')), msg),
      h('div', { class: 'card' }, h('h3', {}, 'Dati geografici'),
        h('p', {}, `Comuni con coordinate: ${r.geo.municipalities_located.toLocaleString('it-IT')} su ${r.geo.municipalities.toLocaleString('it-IT')}. Posizione dei record: ${Object.entries(r.geo.by_source).map(([k, n]) => `${k} ${n.toLocaleString('it-IT')}`).join(', ')}.`),
        h('p', { class: 'small muted' }, 'I baricentri dei comuni (dati ISTAT, CC BY 4.0, inclusi nel pacchetto) permettono la ricerca per prossimità e le distanze anche per le frequenze senza coordinate. L’operazione è sicura da ripetere e sposta i comuni sardi nelle nuove province.'),
        (() => {
          const out = h('p', { class: 'small', 'aria-live': 'polite' });
          const btn = h('button', { type: 'button', class: 'btn' + (r.geo.municipalities_located < 7000 ? ' primary' : ''), onclick: async () => {
            btn.disabled = true;
            let offset = 0, total = { updated: 0, created: 0, skipped: 0 }, last;
            try {
              for (;;) {
                last = await post('/admin/geo/comuni', { offset });
                for (const k of Object.keys(total)) total[k] += last[k];
                offset = last.offset;
                out.textContent = `Comuni elaborati: ${offset.toLocaleString('it-IT')}…`;
                if (last.done) break;
              }
              out.textContent = `Completato: ${total.updated.toLocaleString('it-IT')} aggiornati, ${total.created.toLocaleString('it-IT')} creati, ${total.skipped} saltati; ${last.upgraded.toLocaleString('it-IT')} frequenze con posizione più precisa.`;
              toast('Coordinate dei comuni importate.');
            } catch (e) { toast(e.message, 'error'); }
            btn.disabled = false;
          } }, 'Importa i baricentri dei comuni');
          return h('div', {}, h('div', { class: 'actions' }, btn), out);
        })()),
      await webhooksCard(),
      h('div', { class: 'card' }, h('h3', {}, 'Copie open data'),
        h('p', { class: 'small muted' }, r.opendata.length ? `${r.opendata.length} file disponibili.` : 'Nessuna copia generata.'),
        h('div', { class: 'actions' }, h('button', { type: 'button', class: 'btn', onclick: async () => {
          try { const x = await post('/admin/opendata/generate', {}); toast(x.message); system(panel); } catch (e) { toast(e.message, 'error'); }
        } }, 'Genera ora una copia'), h('a', { class: 'btn', href: '#/opendata' }, 'Apri la pagina pubblica'))));
  } catch (e) { clear(panel, h('p', { class: 'form-error' }, e.message)); }
}

function pagerNav(r, go) {
  if (r.pages <= 1) return null;
  return h('nav', { class: 'pager' },
    h('button', { type: 'button', class: 'btn small', disabled: r.page <= 1, onclick: () => go(r.page - 1) }, 'Precedenti'),
    h('span', { class: 'muted small' }, `Pagina ${r.page} di ${r.pages}`),
    h('button', { type: 'button', class: 'btn small', disabled: r.page >= r.pages, onclick: () => go(r.page + 1) }, 'Successive'));
}
