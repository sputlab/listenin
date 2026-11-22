// Accesso, registrazione, verifica email, recupero password.
import { post } from '../api.js?v=0.7.1';
import { $, h, clear, field, checkbox, showErrors, formData, busy, toast } from '../dom.js?v=0.7.1';
import { state } from '../state.js?v=0.7.1';
import { docLink } from '../content.js?v=0.7.1';

let onAuthChange = () => {};
export function bindAuth(cb) { onAuthChange = cb; }

function page(title, intro, form, after = null) {
  const view = $('#view');
  const h1 = h('h1', { tabindex: '-1' }, title);
  clear(view, h('div', { class: 'page-narrow' }, h1, intro ? h('p', { class: 'muted' }, intro) : null, form, after));
  h1.focus();
}

function pwInput(name, autocomplete) {
  return h('input', { type: 'password', name, autocomplete, required: true, minlength: state.meta.app.password_min_length, maxlength: 4096 });
}

export function showLogin() {
  if (state.user) { location.hash = '#/account'; return; }
  const submit = h('button', { type: 'submit', class: 'btn primary' }, 'Accedi');
  const form = h('form', { class: 'form', novalidate: true },
    field('Email', h('input', { type: 'email', name: 'email', autocomplete: 'email', required: true }), { name: 'email' }),
    field('Password', pwInput('password', 'current-password'), { name: 'password' }),
    h('div', { class: 'actions' }, submit, h('a', { href: '#/password-dimenticata' }, 'Password dimenticata?')));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await busy(submit, async () => {
      try {
        const r = await post('/auth/login', formData(form));
        state.user = r.user;
        onAuthChange();
        toast('Bentornato, ' + r.user.nickname + '.');
        location.hash = '#/';
      } catch (err) {
        showErrors(form, err);
        if (err.code === 'email_unverified') {
          form.append(h('p', {}, h('button', { type: 'button', class: 'link', onclick: () => resend(form.email.value) }, 'Invia di nuovo l’email di conferma')));
        }
      }
    });
  });
  page('Accedi', 'Consultare il database non richiede un account: serve solo per proporre frequenze e seguire le tue segnalazioni.', form,
    h('p', { class: 'muted' }, 'Non hai un account? ', h('a', { href: '#/registrati' }, 'Registrati')));
}

async function resend(email) {
  try { toast((await post('/auth/resend', { email })).message); } catch (e) { toast(e.message, 'error'); }
}

export function showRegister() {
  if (state.user) { location.hash = '#/account'; return; }
  const min = state.meta.app.password_min_length;
  const submit = h('button', { type: 'submit', class: 'btn primary' }, 'Crea l’account');
  const form = h('form', { class: 'form', novalidate: true },
    field('Email', h('input', { type: 'email', name: 'email', autocomplete: 'email', required: true }), { name: 'email', hint: 'Riceverai un link per confermarla. Non viene mai mostrata pubblicamente.' }),
    field('Nickname pubblico', h('input', { type: 'text', name: 'nickname', autocomplete: 'nickname', required: true, minlength: 3, maxlength: 32 }), { name: 'nickname', hint: 'Compare accanto ai tuoi contributi. Va bene anche il nominativo.' }),
    field('Password', pwInput('password', 'new-password'), { name: 'password', hint: `Almeno ${min} caratteri. Una frase lunga è più sicura di una parola complicata.` }),
    field('Ripeti la password', pwInput('password_confirm', 'new-password'), { name: 'password_confirm' }),
    h('input', { type: 'text', name: 'website', class: 'hp', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }),
    checkbox('accept_terms', ['Accetto i ', docLink('termini', 'termini di utilizzo'), ' e ho letto l’', docLink('privacy', 'informativa privacy'), '.']),
    checkbox('accept_disclaimer', ['Ho preso visione del ', docLink('disclaimer', 'disclaimer'), ' e delle avvertenze sull’uso delle informazioni.']),
    h('div', { class: 'actions' }, submit));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await busy(submit, async () => {
      try {
        const r = await post('/auth/register', formData(form));
        page('Controlla la posta', null, h('p', {}, r.message + ' Il link vale 24 ore.'));
      } catch (err) { showErrors(form, err); }
    });
  });
  page('Crea un account', 'Con un account puoi proporre nuove frequenze, suggerire modifiche e seguire l’esito delle tue segnalazioni.', form,
    h('p', { class: 'muted' }, 'Hai già un account? ', h('a', { href: '#/accedi' }, 'Accedi')));
}

export async function showVerify(token, kind = 'verify') {
  page(kind === 'verify' ? 'Conferma dell’indirizzo' : 'Conferma del nuovo indirizzo', null, h('p', { class: 'loading' }, 'Verifica in corso…'));
  try {
    const r = await post(kind === 'verify' ? '/auth/verify' : '/account/email/confirm', { token });
    page('Fatto', null, h('p', {}, r.message), h('p', {}, h('a', { class: 'btn primary', href: state.user ? '#/account' : '#/accedi' }, state.user ? 'Vai al tuo account' : 'Accedi')));
    if (kind !== 'verify') onAuthChange(true);
  } catch (e) {
    page('Link non valido', null, h('p', {}, e.message),
      kind === 'verify' ? h('p', {}, 'Puoi chiedere un nuovo link dalla pagina di accesso.') : null);
  }
}

export function showForgot() {
  const submit = h('button', { type: 'submit', class: 'btn primary' }, 'Invia il link');
  const form = h('form', { class: 'form', novalidate: true },
    field('Email dell’account', h('input', { type: 'email', name: 'email', autocomplete: 'email', required: true }), { name: 'email' }),
    h('div', { class: 'actions' }, submit));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await busy(submit, async () => {
      try { page('Controlla la posta', null, h('p', {}, (await post('/auth/password/forgot', formData(form))).message)); }
      catch (err) { showErrors(form, err); }
    });
  });
  page('Password dimenticata', 'Ti invieremo un link per sceglierne una nuova. Il link vale un’ora.', form);
}

export function showReset(token) {
  const submit = h('button', { type: 'submit', class: 'btn primary' }, 'Salva la nuova password');
  const form = h('form', { class: 'form', novalidate: true },
    field('Nuova password', pwInput('password', 'new-password'), { name: 'password', hint: `Almeno ${state.meta.app.password_min_length} caratteri.` }),
    field('Ripeti la password', pwInput('password_confirm', 'new-password'), { name: 'password_confirm' }),
    h('div', { class: 'actions' }, submit));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    await busy(submit, async () => {
      try {
        const r = await post('/auth/password/reset', { ...formData(form), token });
        page('Password aggiornata', null, h('p', {}, r.message), h('a', { class: 'btn primary', href: '#/accedi' }, 'Accedi'));
      } catch (err) { showErrors(form, err); }
    });
  });
  page('Scegli una nuova password', null, form);
}

export async function logout() {
  try {
    await post('/auth/logout', {});
    state.user = null;
    onAuthChange();
    toast('Sei uscito dal tuo account.');
    location.hash = '#/';
  } catch (e) { toast(e.message, 'error'); }
}
