// Webhook personali: avvisi automatici (POST JSON firmato) per nuove frequenze e recuperi.
import { get, post, put, del } from '../api.js?v=0.9.0';
import { h, clear, field, select, toast, showErrors } from '../dom.js?v=0.9.0';
import { dateTime } from '../format.js?v=0.9.0';
import { state } from '../state.js?v=0.9.0';

const SNIPPET = `# Python: verifica della firma
import hmac, hashlib
def valido(segreto, intestazione, corpo):      # corpo = byte grezzi della richiesta
    parti = dict(p.split('=', 1) for p in intestazione.split(','))
    atteso = hmac.new(segreto.encode(), (parti['t'] + '.').encode() + corpo, hashlib.sha256).hexdigest()
    return hmac.compare_digest(atteso, parti['v1'])`;

function secretBox(secret, onDone) {
  const input = h('input', { type: 'text', readonly: true, value: secret, 'aria-label': 'Segreto di firma', class: 'mono' });
  return h('div', { class: 'notice' }, h('strong', {}, 'Segreto di firma (mostrato una sola volta): '), input,
    h('div', { class: 'actions' },
      h('button', { type: 'button', class: 'btn small', onclick: async () => { try { await navigator.clipboard.writeText(secret); toast('Segreto copiato.'); } catch { input.select(); } } }, 'Copia'),
      h('button', { type: 'button', class: 'btn small', onclick: onDone }, 'Ho salvato il segreto')));
}

export async function webhooksPanel(panel) {
  clear(panel, h('p', { class: 'loading' }, 'Caricamento…'));
  let r;
  try { r = await get('/account/webhooks'); } catch (e) { clear(panel, h('p', { class: 'form-error' }, e.message)); return; }
  const t = state.meta.taxonomy;
  const notice = h('div');
  const intro = h('div', { class: 'card' }, h('h3', {}, 'Webhook'),
    h('p', {}, 'Ricevi un avviso automatico sul tuo server quando una frequenza viene aggiunta o recuperata in una regione, provincia o banda a tua scelta. ListenIn invia una richiesta POST con un JSON firmato all’indirizzo indicato.'),
    h('details', {}, h('summary', {}, 'Come verificare la firma e cosa contiene l’avviso'),
      h('p', { class: 'small' }, 'Intestazioni: ', h('code', {}, 'X-ListenIn-Event'), ', ', h('code', {}, 'X-ListenIn-Delivery'), ', ', h('code', {}, 'X-ListenIn-Signature: t=<timestamp>,v1=<HMAC-SHA256>'),
        '. La firma si calcola sul testo «timestamp.corpo» con il tuo segreto: controlla anche che il timestamp sia recente, per evitare repliche.'),
      h('pre', { class: 'code' }, SNIPPET),
      h('p', { class: 'small muted' }, 'Corpo: {"event":"frequency.created" | "frequency.recovered", "occurred_at", "frequency": {id, freq_mhz, mode, label, service, region, province, municipality, status, reliability, last_heard_at, url}}. Rispondi con un codice 2xx entro 8 secondi: altrimenti l’invio si ripete (5 tentativi con attese crescenti) e dopo 10 errori consecutivi il webhook si disattiva.'),
      h('p', { class: 'small muted' }, 'Per sicurezza sono ammessi solo indirizzi https://nome-di-dominio (porta 443) che puntano a reti pubbliche; non si seguono i reindirizzamenti e la risposta non viene conservata.')));
  if (!r.available) { clear(panel, intro, h('p', { class: 'notice danger' }, 'I webhook non sono disponibili su questo server (servono le estensioni PHP cURL e OpenSSL).')); return; }

  const regionSel = select('region', [{ value: '', label: 'Tutta Italia' }, ...t.regions.map((x) => ({ value: x.slug, label: x.name }))], '');
  const provSel = select('province', [{ value: '', label: 'Tutte' }], '');
  regionSel.addEventListener('change', () => clear(provSel, ...[{ value: '', label: 'Tutte' }, ...(t.provinces[regionSel.value] || []).map((p) => ({ value: p.code, label: `${p.name} (${p.code})` }))].map((o) => h('option', { value: o.value }, o.label))));
  const form = h('form', { class: 'form', novalidate: true },
    field('Nome', h('input', { type: 'text', name: 'name', maxlength: 80, placeholder: 'es. Nuove frequenze Friuli' }), { name: 'name' }),
    field('Indirizzo (https)', h('input', { type: 'url', name: 'url', placeholder: 'https://esempio.it/hook' }), { name: 'url' }),
    h('fieldset', { class: 'field' }, h('legend', { class: 'label' }, 'Eventi'),
      h('div', { class: 'check-row' }, ...Object.entries(r.events).map(([k, l]) => h('label', {}, h('input', { type: 'checkbox', name: 'events', value: k, checked: true }), l)))),
    h('div', { class: 'grid-3' }, field('Regione', regionSel), field('Provincia', provSel),
      field('Banda ITU', select('band', [{ value: '', label: 'Tutte' }, ...t.itu_bands.map((b) => ({ value: b.code, label: `${b.code} (${b.name})` }))], ''))),
    h('div', { class: 'actions' }, h('button', { type: 'submit', class: 'btn primary' }, 'Crea il webhook')));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const body = { name: fd.get('name'), url: fd.get('url'), events: fd.getAll('events'), region: fd.get('region'), province: fd.get('province'), band: fd.get('band') };
    try {
      const res = await post('/account/webhooks', body);
      await webhooksPanel(panel);
      panel.prepend(secretBox(res.secret, () => webhooksPanel(panel)));
      toast(res.message);
    } catch (err) { showErrors(form, err); }
  });

  const rows = r.items.map((w) => {
    const log = h('div');
    const filters = [w.region, w.province, w.band].filter(Boolean).join(' · ') || 'tutta Italia';
    return h('li', {},
      h('div', { class: 'head' }, h('strong', {}, w.name), h('span', { class: 'tag ' + (w.active ? 'approved' : 'rejected') }, w.active ? 'Attivo' : 'Disattivato'), h('span', { class: 'muted small' }, w.host)),
      h('div', { class: 'small muted' }, `Eventi: ${w.events.map((x) => r.events[x]).join(', ')} · Filtri: ${filters}`
        + (w.last_attempt_at ? ` · ultimo invio ${dateTime(w.last_attempt_at)} (HTTP ${w.last_status ?? 'errore'})` : '') + (w.failures ? ` · errori consecutivi: ${w.failures}` : '')),
      w.disabled_reason ? h('div', { class: 'small' }, 'Motivo: ' + w.disabled_reason) : null,
      h('div', { class: 'actions' },
        h('button', { type: 'button', class: 'btn small', onclick: async (e) => { e.currentTarget.disabled = true; try { const x = await post(`/account/webhooks/${w.id}/test`, {}); toast(x.message, x.ok ? 'ok' : 'error'); } catch (err) { toast(err.message, 'error'); } webhooksPanel(panel); } }, 'Invia prova'),
        h('button', { type: 'button', class: 'btn small', onclick: async () => { await put(`/account/webhooks/${w.id}`, { active: !w.active }); webhooksPanel(panel); } }, w.active ? 'Disattiva' : 'Riattiva'),
        h('button', { type: 'button', class: 'btn small', onclick: async () => { if (!confirm('Generare un nuovo segreto? Quello attuale smetterà di funzionare.')) return; const x = await post(`/account/webhooks/${w.id}/rotate`, {}); await webhooksPanel(panel); panel.prepend(secretBox(x.secret, () => webhooksPanel(panel))); } }, 'Nuovo segreto'),
        h('button', { type: 'button', class: 'btn small', onclick: async () => {
          const x = await get(`/account/webhooks/${w.id}/deliveries`);
          clear(log, x.items.length ? h('ul', { class: 'small' }, x.items.map((d) => h('li', {}, `${dateTime(d.created_at)} · ${d.event} · ${d.status === 'sent' ? 'consegnato' : d.status === 'failed' ? 'fallito' : 'in coda'} (${d.attempts} ${d.attempts === 1 ? 'tentativo' : 'tentativi'}${d.http_status ? ', HTTP ' + d.http_status : ''}${d.error ? ', ' + d.error : ''})`))) : h('p', { class: 'small muted' }, 'Nessuna consegna ancora.'));
        } }, 'Registro consegne'),
        h('button', { type: 'button', class: 'btn small danger', onclick: async () => { if (confirm('Eliminare il webhook?')) { await del('/account/webhooks/' + w.id); webhooksPanel(panel); } } }, 'Elimina')),
      log);
  });
  clear(panel, notice, intro, r.items.length ? h('ul', { class: 'list' }, rows) : h('p', { class: 'muted' }, 'Non hai ancora creato nessun webhook.'),
    h('div', { class: 'card' }, h('h3', {}, 'Nuovo webhook'), form));
}
