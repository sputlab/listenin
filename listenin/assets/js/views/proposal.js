// Modulo di proposta: nuova frequenza o modifica di un record esistente.
import { get, post, put } from '../api.js?v=0.8.0';
import { $, h, clear, field, select, checkbox, showErrors, busy, toast } from '../dom.js?v=0.8.0';
import { mhz, parseFreq, locator } from '../format.js?v=0.8.0';
import { state, isModerator } from '../state.js?v=0.8.0';
import { docLink } from '../content.js?v=0.8.0';

const ALIASES = { freq_hz: 'freq', input_freq_hz: 'input_freq', offset_hz: 'offset_khz', bandwidth_hz: 'bandwidth_khz' };

export async function showProposal(id = null) {
  const view = $('#view');
  if (!state.user) {
    clear(view, h('div', { class: 'page-narrow' }, h('h1', {}, 'Accedi per contribuire'),
      h('p', {}, 'Per proporre nuove frequenze o modifiche serve un account con email verificata. La consultazione resta libera.'),
      h('div', { class: 'actions' }, h('a', { class: 'btn primary', href: '#/accedi' }, 'Accedi'), h('a', { class: 'btn', href: '#/registrati' }, 'Crea un account'))));
    return;
  }
  let current = null;
  let record = null;
  if (id) {
    clear(view, h('p', { class: 'loading' }, 'Caricamento del record…'));
    try {
      record = await get('/frequencies/' + id);
      current = record.logical;
    } catch (e) {
      clear(view, h('p', { class: 'form-error' }, e.message));
      return;
    }
  }
  renderForm(view, id, current, record);
}

function renderForm(view, id, cur, record) {
  const t = state.meta.taxonomy;
  const v = (k) => (cur && cur[k] !== null && cur[k] !== undefined ? String(cur[k]) : '');
  const text = (name, attrs = {}) => h('input', { type: 'text', name, value: attrs.value ?? v(name), ...attrs });

  // ---- Parametri RF ----
  const modeSel = select('mode', t.modes.map((m) => ({ value: m.code, label: `${m.code}: ${m.name}` })), v('mode') || 'NFM');
  const toneType = v('tone_type') || 'none';
  const toneRadios = h('div', { class: 'check-row' }, ['none', 'ctcss', 'dcs'].map((tt) =>
    h('label', {}, h('input', { type: 'radio', name: 'tone_type', value: tt, checked: tt === toneType }), { none: 'Nessuno', ctcss: 'CTCSS', dcs: 'DCS' }[tt])));
  const ctcss = field('Tono CTCSS', select('ctcss_hz', [{ value: '', label: 'Scegli…' }, ...t.ctcss.map((x) => ({ value: x, label: x + ' Hz' }))], v('ctcss_hz')), { name: 'ctcss_hz' });
  const dcs = field('Codice DCS', text('dcs_code', { maxlength: 4, placeholder: 'es. 023N' }), { name: 'dcs_code' });
  const dmr = h('div', { class: 'grid-2' },
    field('Color Code', h('input', { type: 'number', name: 'color_code', min: 0, max: 15, value: v('color_code') }), { name: 'color_code' }),
    field('Timeslot', select('dmr_timeslot', [{ value: '', label: '—' }, { value: '1', label: 'TS1' }, { value: '2', label: 'TS2' }], v('dmr_timeslot')), { name: 'dmr_timeslot' }));
  const ran = field('RAN', h('input', { type: 'number', name: 'ran', min: 0, max: 63, value: v('ran') }), { name: 'ran' });
  const nac = field('NAC', text('nac', { maxlength: 3, placeholder: 'es. 293' }), { name: 'nac' });
  const tg = field('Talkgroup', text('talkgroup', { maxlength: 32 }), { name: 'talkgroup' });

  const freqInput = text('freq', { value: cur ? mhz(cur.freq_hz) : '', inputmode: 'decimal', placeholder: 'es. 145.6000', required: true, autocomplete: 'off' });
  const offsetInput = text('offset_khz', { value: cur?.offset_hz != null ? String(cur.offset_hz / 1000) : '', inputmode: 'decimal', placeholder: 'es. -600' });
  const inputFreq = text('input_freq', { value: '', inputmode: 'decimal', placeholder: cur?.input_freq_hz ? mhz(cur.input_freq_hz) : 'es. 145.0000' });
  const shiftHint = h('div', { class: 'hint', 'aria-live': 'polite' });
  const shiftChips = h('div', { class: 'actions', 'aria-live': 'polite' });
  const dupBox = h('div', { 'aria-live': 'polite' });
  const updateShiftHint = () => {
    const f = parseFreq(freqInput.value);
    const o = offsetInput.value.trim() ? Math.round(parseFloat(offsetInput.value.replace(',', '.')) * 1000) : null;
    shiftHint.textContent = f && o !== null && !isNaN(o) ? `Ingresso calcolato: ${mhz(f + o)} MHz` : 'Indica lo shift oppure la frequenza di ingresso: l’altro valore viene calcolato.';
  };
  [freqInput, offsetInput].forEach((el) => el.addEventListener('input', updateShiftHint));
  updateShiftHint();

  const rf = h('fieldset', {}, h('legend', {}, 'Parametri RF'),
    h('div', { class: 'grid-2' },
      field('Frequenza (MHz)', freqInput, { name: 'freq', hint: 'Virgola o punto, fino a 6 decimali.' }),
      field('Modo', modeSel, { name: 'mode' }),
      field('Shift (kHz)', offsetInput, { name: 'offset_khz' }),
      field('Frequenza di ingresso (MHz)', inputFreq, { name: 'input_freq' }),
      field('Larghezza di banda (kHz)', text('bandwidth_khz', { value: cur?.bandwidth_hz ? String(cur.bandwidth_hz / 1000) : '', inputmode: 'decimal', placeholder: 'tipica del modo' }), { name: 'bandwidth_khz' })),
    shiftHint, shiftChips, dupBox,
    h('div', { class: 'field', dataset: { field: 'tone_type' } }, h('span', { class: 'label' }, 'Tono subaudio'), toneRadios),
    h('div', { class: 'grid-2' }, ctcss, dcs),
    dmr, h('div', { class: 'grid-2' }, ran, nac, tg));

  const syncRf = () => {
    const mode = t.modes.find((m) => m.code === modeSel.value);
    const tone = rf.querySelector('[name=tone_type]:checked')?.value;
    ctcss.hidden = tone !== 'ctcss';
    dcs.hidden = tone !== 'dcs';
    dmr.hidden = mode?.access_code !== 'color_code';
    ran.hidden = mode?.access_code !== 'ran';
    nac.hidden = mode?.access_code !== 'nac';
    tg.hidden = mode?.family === 'analogico';
  };
  rf.addEventListener('change', syncRf);
  syncRf();

  // ---- Classificazione ----
  const svcOptions = t.services.map((s) => ({ group: s.name, options: [{ value: s.slug, label: s.name + ' (generico)' }, ...s.children.map((c) => ({ value: c.slug, label: c.name }))] }));
  const cls = h('fieldset', {}, h('legend', {}, 'Servizio'),
    h('div', { class: 'grid-2' },
      field('Ambito e servizio', select('service', svcOptions, v('service') || 'utility-altro'), { name: 'service' }),
      field('Canale', text('channel', { maxlength: 20, placeholder: 'es. R7a, RU5, CH16' }), { name: 'channel' })),
    field('Descrizione d’uso', text('label', { maxlength: 160, required: true, placeholder: 'es. Vigili del Fuoco, canale provinciale' }), { name: 'label' }));

  // ---- Geografia ----
  const regionSel = select('region', [{ value: '', label: 'Nessuna / nazionale' }, ...t.regions.map((r) => ({ value: r.slug, label: r.name }))], v('region'));
  const provinceSel = select('province', [], '');
  const fillProvinces = (keep) => {
    const list = regionSel.value ? (t.provinces[regionSel.value] || []) : [];
    clear(provinceSel, h('option', { value: '' }, list.length ? 'Nessuna' : 'Scegli prima la regione'), ...list.map((p) => h('option', { value: p.code }, `${p.name} (${p.code})`)));
    provinceSel.value = keep || '';
  };
  regionSel.addEventListener('change', () => fillProvinces(''));
  fillProvinces(v('province'));
  // Postazione o monte, con suggerimenti dalle postazioni già note
  const siteSuggestions = new Map();
  const siteList = h('datalist', { id: 'site-list' });
  const altIn = h('input', { type: 'number', name: 'altitude_m', min: -430, max: 9000, value: v('altitude_m') });
  const siteIn = text('site', { maxlength: 100, placeholder: 'es. Monte Sabotino', list: 'site-list', autocomplete: 'off' });
  let siteTimer = null;
  siteIn.addEventListener('input', () => {
    clearTimeout(siteTimer);
    const known = siteSuggestions.get(siteIn.value.trim().toLowerCase());
    if (known && known.altitude_m != null && !altIn.value) altIn.value = String(known.altitude_m);
    siteTimer = setTimeout(async () => {
      if (siteIn.value.trim().length < 2) return;
      try {
        const r = await get('/sites/suggest?q=' + encodeURIComponent(siteIn.value.trim()));
        siteSuggestions.clear();
        for (const x of r.items) siteSuggestions.set(x.name.toLowerCase(), x);
        clear(siteList, ...r.items.map((x) => h('option', { value: x.name }, `${x.province || ''}${x.altitude_m != null ? ' · ' + x.altitude_m + ' m' : ''}`)));
      } catch { /* i suggerimenti sono facoltativi */ }
    }, 250);
  });
  const latIn = text('latitude', { inputmode: 'decimal', placeholder: 'es. 45.940000' });
  const lonIn = text('longitude', { inputmode: 'decimal', placeholder: 'es. 13.620000' });
  const locIn = text('locator', { maxlength: 8, placeholder: 'es. JN65tw' });
  const calcLoc = () => {
    const la = parseFloat(latIn.value.replace(',', '.')), lo = parseFloat(lonIn.value.replace(',', '.'));
    if (!isNaN(la) && !isNaN(lo) && Math.abs(la) <= 90 && Math.abs(lo) <= 180) locIn.value = locator(la, lo);
  };
  const coverageOthers = cur?.coverage ? cur.coverage.split(',').filter((c) => c !== cur.province).join(', ') : '';
  const geo = h('fieldset', {}, h('legend', {}, 'Geografia'),
    h('div', { class: 'grid-2' },
      field('Nazione', select('country', [{ value: '', label: '—' }, ...t.countries.map((c) => ({ value: c.iso2, label: c.name }))], v('country') || 'IT'), { name: 'country' }),
      field('Regione', regionSel, { name: 'region' }),
      field('Provincia principale', provinceSel, { name: 'province' }),
      field('Altre province coperte', text('coverage', { value: coverageOthers, placeholder: 'sigle, es. AL, AT' }), { name: 'coverage' }),
      field('Comune', text('municipality', { maxlength: 100 }), { name: 'municipality' }),
      field('Area', text('area_note', { maxlength: 120, placeholder: 'es. Val di Susa, tratta A4' }), { name: 'area_note' }),
      field('Latitudine', latIn, { name: 'latitude' }),
      field('Longitudine', lonIn, { name: 'longitude' }),
      field('Locatore QTH', locIn, { name: 'locator' }),
      field('Postazione o monte', h('div', {}, siteIn, siteList), { name: 'site', hint: 'Dove è installato l’impianto: raggruppa i ripetitori dello stesso sito.' }),
      field('Altitudine (m s.l.m.)', altIn, { name: 'altitude_m' }),
      field('Raggio di copertura (km)', h('input', { type: 'number', name: 'coverage_km', min: 0, max: 3000, value: v('coverage_km') }), { name: 'coverage_km' })),
    h('div', { class: 'actions' },
      h('button', { type: 'button', class: 'btn small', onclick: calcLoc }, 'Calcola il locatore dalle coordinate'),
      'geolocation' in navigator ? h('button', { type: 'button', class: 'btn small', onclick: () => navigator.geolocation.getCurrentPosition(
        (p) => { latIn.value = p.coords.latitude.toFixed(6); lonIn.value = p.coords.longitude.toFixed(6); calcLoc(); },
        () => toast('Posizione non disponibile.', 'error'), { enableHighAccuracy: false, timeout: 10000 }) }, 'Usa la mia posizione') : null));

  // ---- Shift automatico dal piano di banda e controllo dei doppioni in tempo reale ----
  let autoShift = false;     // vero finché lo shift in campo è quello suggerito e non è stato toccato dall'utente
  const rules = t.bandplan_rules || [];
  const applyOffset = (hzOffset) => { offsetInput.value = String(hzOffset / 1000); inputFreq.value = ''; updateShiftHint(); };
  const updateShift = () => {
    const hz = parseFreq(freqInput.value);
    const rule = hz ? rules.find((r) => hz >= r.min_hz && hz <= r.max_hz) : null;
    clear(shiftChips);
    if (!rule) {
      if (autoShift) { offsetInput.value = ''; updateShiftHint(); autoShift = false; }
      return;
    }
    shiftChips.append(h('span', { class: 'hint' }, `Piano di banda (${rule.name}), shift suggerito: `),
      ...rule.offsets.map((o) => h('button', { type: 'button', class: 'btn small', onclick: () => { applyOffset(o.hz); autoShift = true; } }, o.label)),
      h('span', { class: 'hint' }, ' Il valore resta modificabile.'));
    const free = !offsetInput.value.trim() && !inputFreq.value.trim();
    if (autoShift || (free && !(id && cur?.offset_hz != null))) { applyOffset(rule.offsets[0].hz); autoShift = true; }
  };
  [offsetInput, inputFreq].forEach((el) => el.addEventListener('input', () => { autoShift = false; }));
  freqInput.addEventListener('input', updateShift);

  let dupTimer = null, dupSeq = 0;
  const checkDup = () => {
    clearTimeout(dupTimer);
    dupTimer = setTimeout(async () => {
      const hz = parseFreq(freqInput.value);
      if (!hz) { clear(dupBox); return; }
      const prov = provinceSel.value;
      const seq = ++dupSeq;
      try {
        const r = await get('/frequencies/check?freq=' + encodeURIComponent(mhz(hz)) + (prov ? '&province=' + prov : '') + (id ? '&exclude=' + id : ''));
        if (seq !== dupSeq) return;
        const link = (x) => h('a', { href: '#/f/' + x.id, target: '_blank', rel: 'noopener' }, `${x.freq_mhz} MHz, ${x.label}${x.province ? ' (' + x.province + ')' : ''}`);
        const lines = [];
        if (r.identical.length) lines.push(h('p', { class: 'notice danger', role: 'alert' }, r.identical.length === 1 ? 'Esiste già un record con questa frequenza: ' : `Esistono già ${r.identical.length} record con questa frequenza: `,
          ...r.identical.slice(0, 3).flatMap((x, i) => [i ? '; ' : '', link(x)]), '. Controlla di non inserire un doppione.'));
        if (r.within_5khz.length) lines.push(h('p', { class: 'notice' }, 'Frequenza molto vicina (entro 5 kHz) nella stessa provincia: ',
          ...r.within_5khz.slice(0, 3).flatMap((x, i) => [i ? '; ' : '', link(x), ` a ${x.delta_khz} kHz`]), '.'));
        if (r.within_12_5khz.length) lines.push(h('p', { class: 'notice' }, 'Frequenza vicina (entro 12,5 kHz) nella stessa provincia: ',
          ...r.within_12_5khz.slice(0, 3).flatMap((x, i) => [i ? '; ' : '', link(x), ` a ${x.delta_khz} kHz`]), '.'));
        if (!lines.length) lines.push(h('p', { class: 'hint' }, r.scoped_to_province
          ? 'Nessun doppione né frequenza vicina (±12,5 kHz) in questa provincia.'
          : 'Nessuna frequenza identica in Italia. Indica la provincia per controllare anche quelle vicine (±5 kHz e ±12,5 kHz).'));
        clear(dupBox, ...lines);
      } catch { clear(dupBox); }
    }, 400);
  };
  freqInput.addEventListener('input', checkDup);
  provinceSel.addEventListener('change', checkDup);
  regionSel.addEventListener('change', checkDup);

  // ---- Identificativo e stato ----
  const ident = h('fieldset', {}, h('legend', {}, 'Identificativo e stato'),
    h('div', { class: 'grid-2' },
      field('Nominativo', text('callsign', { maxlength: 20, autocapitalize: 'characters', placeholder: 'es. IR3UGO' }), { name: 'callsign' }),
      field('Ente o gestore', text('entity', { maxlength: 120 }), { name: 'entity' }),
      field('Stato operativo', select('status', t.statuses.map((s) => ({ value: s.value, label: s.label })), v('status') || 'da_verificare'), { name: 'status' }),
      field('Ultimo riscontro', h('input', { type: 'date', name: 'last_heard_at', value: v('last_heard_at'), max: new Date().toISOString().slice(0, 10) }), { name: 'last_heard_at' })),
    field('Note', h('textarea', { name: 'notes', maxlength: 2000, rows: 3, value: v('notes') }),
      { name: 'notes', hint: 'Informazioni tecniche utili. Non riportare il contenuto delle comunicazioni ascoltate.' }));

  const needsAck = !state.user.disclaimer_current;
  const mod = isModerator();
  const submit = h('button', { type: 'submit', class: 'btn primary' }, id ? (mod ? 'Salva le modifiche' : 'Invia la proposta di modifica') : (mod ? 'Salva la frequenza' : 'Invia la proposta'));
  const form = h('form', { class: 'form', novalidate: true },
    rf, cls, geo, ident,
    h('fieldset', {}, h('legend', {}, 'Invio'),
      field('Commento per i moderatori', h('textarea', { name: 'comment', maxlength: 500, rows: 2 }), { name: 'comment', hint: 'Fonte del dato o motivo della modifica.' }),
      mod ? checkbox('apply', 'Pubblica subito senza revisione', true) : h('p', { class: 'muted small' }, 'La proposta sarà visibile dopo l’approvazione di un moderatore. Puoi seguirne l’esito dal tuo account.'),
      needsAck ? checkbox('disclaimer_ack', ['Ho preso visione del ', docLink('disclaimer', 'disclaimer'), '.']) : null,
      h('div', { class: 'actions' }, submit, h('a', { class: 'btn', href: id ? `#/f/${id}` : '#/' }, 'Annulla'))));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = collect(form);
    if (data.error) { showErrors(form, { fields: data.error }, ALIASES); return; }
    await busy(submit, async () => {
      try {
        const body = { data: data.payload, comment: form.comment.value.trim() || null };
        if (mod) body.apply = form.apply.checked;
        if (needsAck) body.disclaimer_ack = form.disclaimer_ack.checked;
        const r = id ? await put('/frequencies/' + id, body) : await post('/frequencies', body);
        if (needsAck) state.user.disclaimer_current = true;
        toast(r.message);
        if (r.duplicates?.length) toast(`Attenzione: esistono già ${r.duplicates.length} record sulla stessa frequenza nella zona.`, 'error');
        location.hash = r.status === 'approved' && r.frequency_id ? `#/f/${r.frequency_id}` : '#/account/contributi';
      } catch (err) {
        showErrors(form, err, ALIASES);
      }
    });
  });

  clear(view,
    h('h1', {}, id ? `Modifica ${record.freq_mhz} MHz` : 'Aggiungi una frequenza'),
    id ? h('p', { class: 'muted' }, record.label) : h('p', { class: 'muted' }, 'Compila almeno frequenza, modo, servizio, descrizione e stato. Gli altri campi rendono il dato più utile.'),
    form);
  view.querySelector('h1').setAttribute('tabindex', '-1');
  view.querySelector('h1').focus();
}

/** Converte i campi del modulo nel formato dell'API (Hz, codici, null per i campi vuoti). */
function collect(form) {
  const val = (n) => (form.elements[n]?.value ?? '').trim();
  const nul = (n) => val(n) || null;
  const error = {};
  const p = {
    freq: val('freq'),
    mode: val('mode'),
    service: val('service'),
    label: val('label'),
    channel: nul('channel'),
    status: val('status'),
    tone_type: form.querySelector('[name=tone_type]:checked')?.value || 'none',
    ctcss_hz: nul('ctcss_hz'),
    dcs_code: nul('dcs_code'),
    talkgroup: nul('talkgroup'),
    nac: nul('nac'),
    country: nul('country'),
    region: nul('region'),
    province: nul('province'),
    municipality: nul('municipality'),
    area_note: nul('area_note'),
    locator: nul('locator'),
    latitude: nul('latitude'),
    longitude: nul('longitude'),
    callsign: nul('callsign'),
    entity: nul('entity'),
    last_heard_at: nul('last_heard_at'),
    notes: nul('notes'),
  };
  for (const n of ['color_code', 'dmr_timeslot', 'ran', 'coverage_km']) p[n] = val(n) === '' ? null : Number(val(n));
  const extra = val('coverage').split(/[\s,;]+/).filter(Boolean).map((c) => c.toUpperCase());
  p.coverage = p.province ? [p.province, ...extra] : extra;
  const bw = val('bandwidth_khz');
  p.bandwidth_hz = bw ? Math.round(parseFloat(bw.replace(',', '.')) * 1000) : null;
  if (bw && isNaN(p.bandwidth_hz)) error.bandwidth_hz = 'Valore non valido.';
  const off = val('offset_khz');
  const inp = val('input_freq');
  if (off) {
    p.offset_hz = Math.round(parseFloat(off.replace(',', '.')) * 1000);
    if (isNaN(p.offset_hz)) error.offset_hz = 'Shift non valido (es. -600).';
  }
  if (inp) p.input_freq = inp;
  if (!off && !inp) p.offset_hz = null;
  return Object.keys(error).length ? { error } : { payload: p };
}
