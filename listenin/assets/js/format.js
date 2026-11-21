// Formattazione coerente con il backend (frequenze in Hz interi).

export function mhz(hz) {
  if (hz === null || hz === undefined) return '';
  const n = BigInt(Math.round(hz));
  const int = n / 1000000n;
  let frac = (n % 1000000n).toString().padStart(6, '0').replace(/0+$/, '');
  return int.toString() + '.' + frac.padEnd(4, '0');
}

/** Converte un testo in Hz: accetta virgola o punto e l'unità (MHz di default). */
export function parseFreq(text, unit = 'MHz') {
  if (text === null || text === undefined) return null;
  let s = String(text).trim().toLowerCase().replace(/\s+/g, '');
  if (!s) return null;
  const m = s.match(/(ghz|mhz|khz|hz)$/);
  if (m) { unit = m[1]; s = s.slice(0, -m[1].length); }
  s = s.replace(',', '.');
  if (!/^\d{1,12}(\.\d{1,12})?$/.test(s)) return null;
  const exp = { hz: 0, khz: 3, mhz: 6, ghz: 9 }[unit.toLowerCase()] ?? 6;
  const [i, f = ''] = s.split('.');
  return Number(i + f.padEnd(exp, '0').slice(0, exp));
}

export function khz(hz, signed = false) {
  if (hz === null || hz === undefined) return '';
  const v = (hz / 1000).toLocaleString('it-IT', { maximumFractionDigits: 3 });
  return (signed && hz > 0 ? '+' : '') + v + ' kHz';
}

export function bandwidth(hz) {
  if (!hz) return '';
  return hz >= 1e6 ? (hz / 1e6).toLocaleString('it-IT') + ' MHz' : (hz / 1000).toLocaleString('it-IT', { maximumFractionDigits: 2 }) + ' kHz';
}

export function date(iso) {
  if (!iso) return '';
  const d = new Date(iso.length === 10 ? iso + 'T12:00:00' : iso.replace(' ', 'T'));
  return isNaN(d) ? iso : d.toLocaleDateString('it-IT', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function dateTime(iso) {
  if (!iso) return '';
  const d = new Date(iso.replace(' ', 'T'));
  return isNaN(d) ? iso : d.toLocaleString('it-IT', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export function locator(lat, lon) {
  lon += 180; lat += 90;
  let s = String.fromCharCode(65 + Math.floor(lon / 20)) + String.fromCharCode(65 + Math.floor(lat / 10));
  lon %= 20; lat %= 10;
  s += Math.floor(lon / 2) + '' + Math.floor(lat);
  lon %= 2; lat %= 1;
  s += String.fromCharCode(97 + Math.floor(lon * 12)) + String.fromCharCode(97 + Math.floor(lat * 24));
  return s;
}

export const STATUS = {
  attivo: 'Attivo', inattivo: 'Inattivo', sperimentale: 'Sperimentale', da_verificare: 'Da verificare', contestata: 'Contestata',
};
export const RELIABILITY = { alta: 'Alta', media: 'Media', da_verificare: 'Da verificare', mai: 'Mai verificata' };

// ---------------- geografia: locatore Maidenhead, distanza e azimut ----------------
/** Centro del quadrato indicato da un locatore (4, 6 o 8 caratteri) → { lat, lon } oppure null. */
export function locatorToLatLon(loc) {
  const m = String(loc || '').trim().match(/^([A-R]{2})(\d{2})(?:([A-X]{2})(?:(\d{2}))?)?$/i);
  if (!m) return null;
  const A = 'A'.charCodeAt(0);
  let lon = (m[1][0].toUpperCase().charCodeAt(0) - A) * 20 - 180 + Number(m[2][0]) * 2;
  let lat = (m[1][1].toUpperCase().charCodeAt(0) - A) * 10 - 90 + Number(m[2][1]);
  let w = 2, h = 1;
  if (m[3]) {
    lon += (m[3][0].toUpperCase().charCodeAt(0) - A) * (2 / 24);
    lat += (m[3][1].toUpperCase().charCodeAt(0) - A) * (1 / 24);
    w = 2 / 24; h = 1 / 24;
    if (m[4]) { lon += Number(m[4][0]) * (2 / 240); lat += Number(m[4][1]) * (1 / 240); w = 2 / 240; h = 1 / 240; }
  }
  return { lat: lat + h / 2, lon: lon + w / 2 };
}

const rad = (d) => (d * Math.PI) / 180;

/** Distanza in km lungo il cerchio massimo (formula di Haversine). */
export function distanceKm(a, b) {
  const dLat = rad(b.lat - a.lat), dLon = rad(b.lon - a.lon);
  const x = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLon / 2) ** 2;
  return 2 * 6371.0088 * Math.asin(Math.min(1, Math.sqrt(x)));
}

/** Azimut iniziale in gradi (0 = nord, senso orario) da a verso b. */
export function bearingDeg(a, b) {
  const y = Math.sin(rad(b.lon - a.lon)) * Math.cos(rad(b.lat));
  const x = Math.cos(rad(a.lat)) * Math.sin(rad(b.lat)) - Math.sin(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.cos(rad(b.lon - a.lon));
  return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
}

const COMPASS = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSO', 'SO', 'OSO', 'O', 'ONO', 'NO', 'NNO'];
export const compass = (deg) => COMPASS[Math.round(deg / 22.5) % 16];

/** "23 km · 245° OSO" tra un QTH e la posizione di un record ({lat, lon}). */
export function distanceLabel(from, to) {
  if (!from || !to) return '';
  const km = distanceKm(from, to), az = bearingDeg(from, to);
  return `${km < 10 ? km.toFixed(1).replace('.', ',') : Math.round(km).toLocaleString('it-IT')} km · ${Math.round(az)}° ${compass(az)}`;
}
export const REV_STATUS = { pending: 'In attesa', approved: 'Approvata', rejected: 'Rifiutata', superseded: 'Sostituita' };
export const REV_ACTION = { create: 'Nuova frequenza', update: 'Modifica', delete: 'Rimozione', restore: 'Ripristino' };
export const REPORT_TYPE = { errore: 'Errore nei dati', aggiornamento: 'Richiesta di aggiornamento', rimozione: 'Richiesta di rimozione' };
export const REPORT_STATUS = { aperta: 'Aperta', in_revisione: 'In revisione', risolta: 'Risolta', archiviata: 'Archiviata' };

export function plural(n, one, many) { return `${n.toLocaleString('it-IT')} ${n === 1 ? one : many}`; }
