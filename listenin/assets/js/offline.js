// Uso senza copertura dati: ricerche salvate in IndexedDB e province preferite (localStorage).
// Si salvano solo dati pubblici delle frequenze; nulla di personale.

const DB_NAME = 'rrf-offline', STORE = 'searches';
const open = () => new Promise((resolve, reject) => {
  if (!('indexedDB' in window)) { reject(new Error('Il browser non supporta il salvataggio locale.')); return; }
  const rq = indexedDB.open(DB_NAME, 1);
  rq.onupgradeneeded = () => rq.result.createObjectStore(STORE, { keyPath: 'key' });
  rq.onsuccess = () => resolve(rq.result);
  rq.onerror = () => reject(rq.error);
});
const tx = async (mode, fn) => {
  const db = await open();
  return new Promise((resolve, reject) => {
    const t = db.transaction(STORE, mode), store = t.objectStore(STORE), out = fn(store);
    t.oncomplete = () => { db.close(); resolve(out?.result); };
    t.onerror = () => { db.close(); reject(t.error); };
  });
};

export const saveSearch = (rec) => tx('readwrite', (s) => s.put(rec));
export const getSearch = (key) => tx('readonly', (s) => s.get(key));
export const listSearches = async () => (await tx('readonly', (s) => s.getAll()) || []).sort((a, b) => b.savedAt.localeCompare(a.savedAt));
export const deleteSearch = (key) => tx('readwrite', (s) => s.delete(key));

// ---- province preferite ----
const FAV = 'rrf-fav-provinces';
export function favoriteProvinces() {
  try { const v = JSON.parse(localStorage.getItem(FAV) || '[]'); return Array.isArray(v) ? v.filter((x) => /^[A-Z]{2}$/.test(x)) : []; } catch { return []; }
}
export function setFavoriteProvince(code, on) {
  const set = new Set(favoriteProvinces());
  if (on) set.add(code); else set.delete(code);
  try { localStorage.setItem(FAV, JSON.stringify([...set])); } catch { /* storage non disponibile */ }
}

/** Chiave stabile di una ricerca: i soli filtri, ordinati. */
export const searchKey = (filters) => Object.entries(filters).filter(([k, v]) => v !== '' && v != null && !(k === 'region' && filters.province)).sort(([a], [b]) => a.localeCompare(b)).map(([k, v]) => `${k}=${v}`).join('&');
