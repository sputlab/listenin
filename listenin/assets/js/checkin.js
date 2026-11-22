// Conferma di ascolto (check-in) a un tocco, condivisa da ricerca, missioni e scheda.
import { post } from './api.js?v=0.9.0';
import { h, openModal, closeModal, toast, checkbox } from './dom.js?v=0.9.0';
import { state } from './state.js?v=0.9.0';
import { docLink } from './content.js?v=0.9.0';

let onUserChange = () => {};
export function bindCheckin(cb) { onUserChange = cb; }

/** Chiede la presa visione del disclaimer al primo invio (obbligatoria per contribuire). */
function askDisclaimer() {
  return new Promise((resolve) => {
    const cb = checkbox('ack', ['Ho preso visione del ', docLink('disclaimer', 'disclaimer'), ' e delle avvertenze sull’uso delle informazioni.']);
    const ok = h('button', { type: 'button', class: 'btn primary', onclick: () => {
      if (!cb.querySelector('input').checked) { cb.append(h('div', { class: 'error', role: 'alert' }, 'Spunta la casella per proseguire.')); return; }
      resolve(true); closeModal();
    } }, 'Conferma e prosegui');
    let done = false;
    openModal('Prima di contribuire', h('div', { class: 'form' }, cb, h('div', { class: 'actions' }, ok,
      h('button', { type: 'button', class: 'btn', onclick: () => closeModal() }, 'Annulla'))),
    { onClose: () => { if (!done) { done = true; resolve(false); } } });
  });
}

/**
 * Registra l'ascolto di oggi. Restituisce la risposta dell'API oppure null se l'utente non è
 * autenticato (viene portato all'accesso), annulla o la richiesta non va a buon fine.
 */
export async function quickCheckin(id, extra = {}) {
  if (!state.user) { location.hash = '#/accedi'; return null; }
  const body = { ...extra };
  if (!state.user.disclaimer_current) {
    if (!(await askDisclaimer())) return null;
    body.disclaimer_ack = true;
  }
  try {
    const r = await post(`/frequencies/${id}/checkins`, body);
    state.user = r.user;
    onUserChange();
    toast(r.message + (r.badges?.length ? ' Nuovo badge: ' + r.badges.map((b) => b.name).join(', ') + '.' : ''));
    return r;
  } catch (e) {
    toast(e.fields ? Object.values(e.fields).join(' ') : e.message, 'error');
    return null;
  }
}
