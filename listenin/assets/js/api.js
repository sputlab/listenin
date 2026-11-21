/** Percorso base dell'applicazione (dalla pagina): le ricerche possono avere URL profondi come /frequenze/lombardia/milano. */
export const BASE = document.querySelector('meta[name="rrf-base"]')?.content || './';
// Client dell'API: JSON, token CSRF su ogni richiesta mutativa, gestione uniforme degli errori.

let csrf = null;
export const setCsrf = (t) => { if (t) csrf = t; };

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.error?.message || 'Errore di rete.');
    this.status = status;
    this.code = body?.error?.code || 'network';
    this.fields = body?.error?.fields || null;
    this.details = body?.error || {};
  }
}

/**
 * URL dell'API relativo alla pagina: funziona in qualsiasi sottocartella e senza mod_rewrite.
 * '/frequencies?q=x' → 'api/index.php?route=/frequencies&q=x'
 */
export function apiUrl(path) {
  const [route, qs] = path.split('?');
  return BASE + 'api/index.php?route=' + encodeURIComponent(route) + (qs ? '&' + qs : '');
}

export async function api(method, path, body = undefined, retried = false) {
  const opts = { method, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
  if (method !== 'GET') {
    opts.headers['Content-Type'] = 'application/json';
    opts.headers['X-CSRF-Token'] = csrf || '';
    opts.body = JSON.stringify(body ?? {});
  }
  let res;
  try {
    res = await fetch(apiUrl(path), opts);
  } catch {
    throw new ApiError(0, { error: { message: 'Connessione non disponibile. Riprova.' } });
  }
  let data = null;
  try { data = await res.json(); } catch { /* corpo vuoto */ }
  if (data?.csrf) setCsrf(data.csrf);
  if (res.status === 419 && !retried) {
    const me = await api('GET', '/auth/me');
    setCsrf(me.csrf);
    return api(method, path, body, true);
  }
  if (!res.ok) throw new ApiError(res.status, data);
  return data;
}

/** Caricamento di file (multipart): niente Content-Type manuale, lo imposta il browser. */
export async function upload(path, formData) {
  let res;
  try {
    res = await fetch(apiUrl(path), { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf || '' }, body: formData });
  } catch {
    throw new ApiError(0, { error: { message: 'Connessione non disponibile. Riprova.' } });
  }
  let data = null;
  try { data = await res.json(); } catch { /* corpo non JSON (es. limite di caricamento superato) */ }
  if (data?.csrf) setCsrf(data.csrf);
  if (!res.ok) {
    throw new ApiError(res.status, data || { error: { message: res.status === 413 ? 'Il file supera il limite di caricamento del server.' : 'Caricamento non riuscito.' } });
  }
  return data;
}

export const get = (p) => api('GET', p);
export const post = (p, b) => api('POST', p, b);
export const put = (p, b) => api('PUT', p, b);
export const patch = (p, b) => api('PATCH', p, b);
export const del = (p, b) => api('DELETE', p, b);
