// Segnalazione rapida su una frequenza: errore, aggiornamento, rimozione.
import { post } from '../api.js?v=0.9.0';
import { h, clear, openModal, closeModal, field, select, checkbox, showErrors, formData, busy, toast } from '../dom.js?v=0.9.0';
import { state } from '../state.js?v=0.9.0';
import { docLink } from '../content.js?v=0.9.0';

const TYPES = [
  { value: 'errore', title: 'Errore nei dati', desc: 'Un campo è sbagliato: indica quale e il valore corretto.' },
  { value: 'aggiornamento', title: 'Aggiornamento', desc: 'È cambiato qualcosa: tono, stato operativo, QTH.' },
  { value: 'rimozione', title: 'Rimozione', desc: 'Record duplicato, frequenza dismessa o non pertinente.' },
];

export function showReport(id, item) {
  const meta = state.meta;
  if (!state.user && !meta.app.anonymous_reports) {
    toast('Accedi per inviare segnalazioni.', 'error');
    location.hash = '#/accedi';
    return;
  }
  const logical = item?.logical || null;
  const labels = meta.taxonomy.fields;
  const dynamic = h('div', { class: 'grid-2' });

  const typeCards = h('fieldset', { class: 'field', dataset: { field: 'type' } },
    h('legend', { class: 'label' }, 'Che cosa vuoi segnalare?'),
    h('div', { class: 'choice-cards' }, TYPES.map((t, i) => h('label', {},
      h('input', { type: 'radio', name: 'type', value: t.value, checked: i === 0 }),
      h('span', { class: 't' }, t.title), h('span', { class: 'd' }, t.desc)))));

  const currentValue = (fieldName) => {
    if (!logical) return null;
    const v = logical[fieldName];
    return v === null || v === undefined ? 'vuoto' : String(v);
  };

  const renderDynamic = (type) => {
    if (type === 'rimozione') {
      const reason = select('removal_reason', Object.entries(meta.removal_reasons).map(([value, label]) => ({ value, label })), 'duplicato');
      const dupField = field('Id del record da conservare', h('input', { type: 'number', name: 'duplicate_of_id', min: 1, inputmode: 'numeric' }),
        { hint: 'Il numero si trova in fondo alla scheda della frequenza.', name: 'duplicate_of_id' });
      reason.addEventListener('change', () => { dupField.hidden = reason.value !== 'duplicato'; });
      clear(dynamic, field('Motivo', reason, { name: 'removal_reason' }), dupField);
      return;
    }
    const names = type === 'errore' ? Object.keys(labels) : meta.update_fields;
    const fieldSel = select('field', names.map((n) => ({ value: n, label: labels[n] || n })), type === 'aggiornamento' ? 'ctcss_hz' : 'label');
    const valueBox = h('div');
    const currentHint = h('div', { class: 'hint' });
    const renderValue = () => {
      const n = fieldSel.value;
      let ctl;
      if (n === 'status') ctl = select('suggested_value', meta.taxonomy.statuses.map((s) => ({ value: s.value, label: s.label })));
      else if (n === 'ctcss_hz') ctl = select('suggested_value', meta.taxonomy.ctcss.map((t) => ({ value: t, label: t + ' Hz' })), '88.5');
      else if (n === 'mode') ctl = select('suggested_value', meta.taxonomy.modes.map((m) => ({ value: m.code, label: m.code })));
      else if (n === 'last_heard_at') ctl = h('input', { type: 'date', name: 'suggested_value', max: new Date().toISOString().slice(0, 10) });
      else ctl = h('input', { type: 'text', name: 'suggested_value', maxlength: 255 });
      clear(valueBox, field('Valore corretto', ctl, { name: 'suggested_value' }));
      currentHint.textContent = logical ? 'Valore attuale: ' + currentValue(n) : '';
    };
    fieldSel.addEventListener('change', renderValue);
    const f = field(type === 'errore' ? 'Campo errato' : 'Cosa aggiornare', fieldSel, { name: 'field' });
    f.append(currentHint);
    renderValue();
    clear(dynamic, f, valueBox);
  };

  const needsAck = state.user ? !state.user.disclaimer_current : !state.anonAck;
  const form = h('form', { class: 'form', novalidate: true },
    item ? h('p', { class: 'muted' }, `${item.freq_mhz} MHz, ${item.label}`) : null,
    typeCards,
    dynamic,
    field('Dettagli (facoltativo)', h('textarea', { name: 'message', maxlength: 1000, rows: 3 }),
      { hint: 'Fonte, data dell’ascolto o altro utile ai moderatori. Non riportare il contenuto delle comunicazioni.', name: 'message' }),
    h('input', { type: 'text', name: 'website', class: 'hp', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }),
    needsAck ? checkbox('disclaimer_ack', ['Ho preso visione del ', docLink('disclaimer', 'disclaimer'), ' e delle avvertenze sull’uso dei dati.']) : null,
    !state.user ? h('p', { class: 'muted small' }, 'Stai segnalando senza account. ', h('a', { href: '#/accedi' }, 'Accedi'), ' per seguire l’esito nel tuo storico.') : null,
    h('div', { class: 'actions' },
      h('button', { type: 'submit', class: 'btn primary' }, 'Invia la segnalazione'),
      h('button', { type: 'button', class: 'btn', onclick: closeModal }, 'Annulla')));

  typeCards.addEventListener('change', (e) => { if (e.target.name === 'type') renderDynamic(e.target.value); });
  renderDynamic('errore');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const data = formData(form);
    const payload = { type: data.type, message: data.message || null, website: data.website };
    if (data.type === 'rimozione') {
      payload.removal_reason = data.removal_reason;
      if (data.removal_reason === 'duplicato') payload.duplicate_of_id = data.duplicate_of_id ? Number(data.duplicate_of_id) : null;
    } else {
      payload.field = data.field;
      payload.suggested_value = data.suggested_value;
    }
    if (needsAck) payload.disclaimer_ack = !!data.disclaimer_ack;
    await busy(form.querySelector('[type=submit]'), async () => {
      try {
        const r = await post(`/frequencies/${id}/reports`, payload);
        if (!state.user) state.anonAck = true;
        else state.user.disclaimer_current = true;
        closeModal();
        toast(r.message);
      } catch (err) {
        showErrors(form, err);
      }
    });
  });

  openModal('Segnala un problema', form);
}
