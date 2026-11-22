// Costruzione del DOM senza innerHTML: ogni dato passa da textContent (difesa XSS).

export function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else if (k === 'style') for (const [p, val] of Object.entries(v)) el.style.setProperty(p, val);
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else if (k === 'value' && (tag === 'input' || tag === 'textarea' || tag === 'select')) el.value = v;
    else if (v === true) el.setAttribute(k, '');
    else el.setAttribute(k, String(v));
  }
  append(el, children);
  return el;
}

function append(el, children) {
  for (const c of children.flat(Infinity)) {
    if (c === null || c === undefined || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
}

export function clear(el, ...children) {
  el.replaceChildren();
  append(el, children);
  return el;
}

/** Avvolge una tabella in un contenitore con scorrimento orizzontale, raggiungibile anche da tastiera. */
export function scrollTable(table, label = 'Tabella') {
  return h('div', { class: 'table-scroll', role: 'region', tabindex: '0', 'aria-label': label + ' (scorrevole in orizzontale)' }, table);
}

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

// ---------------- Notifiche ----------------
export function toast(message, type = 'info') {
  const box = $('#toasts');
  const t = h('div', { class: 'toast' + (type === 'error' ? ' error' : '') }, message);
  box.append(t);
  setTimeout(() => t.remove(), type === 'error' ? 7000 : 4500);
}

// ---------------- Modale (elemento <dialog>) ----------------
let lastFocus = null;
let onCloseCb = null;

export function openModal(title, body, { wide = false, onClose = null } = {}) {
  const dlg = $('#modal');
  lastFocus = document.activeElement;
  onCloseCb = onClose;
  dlg.classList.toggle('wide', wide);
  const titleEl = h('h2', { id: 'modal-title' }, title);
  clear($('#modal-body'),
    h('div', { class: 'modal-head' }, titleEl,
      h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Chiudi', onclick: () => closeModal() }, '×')),
    body);
  if (!dlg.open) dlg.showModal();
  titleEl.setAttribute('tabindex', '-1');
  titleEl.focus();
  return dlg;
}

export function closeModal() {
  const dlg = $('#modal');
  if (dlg.open) dlg.close();
}

export function initModal() {
  const dlg = $('#modal');
  dlg.addEventListener('close', () => {
    const cb = onCloseCb;
    onCloseCb = null;
    if (cb) cb();
    if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
  });
  dlg.addEventListener('click', (e) => { if (e.target === dlg) closeModal(); });
}

// ---------------- Moduli ----------------
export function field(label, control, { hint = null, name = null } = {}) {
  const id = control.id || ('f-' + (name || control.name || Math.random().toString(36).slice(2)));
  control.id = id;
  const wrap = h('div', { class: 'field', dataset: { field: name || control.name || '' } },
    h('label', { for: id }, label), control);
  if (hint) {
    const hid = id + '-hint';
    control.setAttribute('aria-describedby', hid);
    wrap.append(h('div', { class: 'hint', id: hid }, hint));
  }
  return wrap;
}

export function select(name, options, value = '', attrs = {}) {
  const sel = h('select', { name, ...attrs });
  for (const o of options) {
    if (o.group) {
      const g = h('optgroup', { label: o.group });
      for (const c of o.options) g.append(h('option', { value: c.value }, c.label));
      sel.append(g);
    } else {
      sel.append(h('option', { value: o.value }, o.label));
    }
  }
  sel.value = value ?? '';
  return sel;
}

export function checkbox(name, labelNodes, checked = false) {
  const id = 'c-' + name + '-' + Math.random().toString(36).slice(2, 7);
  return h('div', { class: 'check', dataset: { field: name } },
    h('input', { type: 'checkbox', id, name, checked }),
    h('label', { for: id }, labelNodes));
}

/** Mostra gli errori di validazione restituiti dall'API accanto ai campi. */
export function showErrors(form, err, aliases = {}) {
  form.querySelectorAll('.error, .form-error').forEach((e) => e.remove());
  form.querySelectorAll('[aria-invalid]').forEach((e) => e.removeAttribute('aria-invalid'));
  const fields = err?.fields || {};
  let first = null;
  for (const [name, msg] of Object.entries(fields)) {
    const key = aliases[name] || name;
    const wrap = form.querySelector(`[data-field="${CSS.escape(key)}"]`);
    if (!wrap) continue;
    const ctl = wrap.querySelector('input, select, textarea');
    if (ctl) { ctl.setAttribute('aria-invalid', 'true'); first ??= ctl; }
    wrap.append(h('div', { class: 'error', role: 'alert' }, msg));
  }
  const unplaced = Object.keys(fields).filter((n) => !form.querySelector(`[data-field="${CSS.escape(aliases[n] || n)}"]`));
  if (!Object.keys(fields).length || unplaced.length) {
    const msg = unplaced.length ? unplaced.map((n) => fields[n]).join(' ') : (err?.message || 'Operazione non riuscita.');
    form.prepend(h('p', { class: 'form-error', role: 'alert' }, msg));
  }
  first?.focus();
}

export function formData(form) {
  const out = {};
  for (const el of form.elements) {
    if (!el.name || el.disabled) continue;
    if (el.type === 'checkbox') out[el.name] = el.checked;
    else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; }
    else out[el.name] = el.value.trim();
  }
  return out;
}

/** Disattiva il pulsante di invio durante la richiesta. */
export async function busy(button, fn) {
  const label = button.textContent;
  button.disabled = true;
  button.textContent = 'Invio in corso…';
  try { return await fn(); } finally { button.disabled = false; button.textContent = label; }
}
