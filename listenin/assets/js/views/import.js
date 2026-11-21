// Importazione dei dati dal pannello (per hosting senza accesso a terminale).
import { get, post, upload, apiUrl } from '../api.js?v=0.7.0';
import { h, clear, toast, checkbox, field, select } from '../dom.js?v=0.7.0';
import { dateTime } from '../format.js?v=0.7.0';

const STAT_LABELS = [
  ['righe_lette', 'Righe lette'], ['righe_scartate', 'Righe scartate'], ['righe_unificate', 'Righe unificate (duplicati certi)'],
  ['record_risultanti', 'Record armonizzati'], ['inseriti', 'Nuovi record'], ['aggiornati', 'Record aggiornati'],
  ['invariati', 'Record invariati'], ['completati', 'Record completati nei campi vuoti'],
  ['protetti', 'Record curati dalla comunità (non sovrascritti)'], ['collegati_a_esistenti', 'Collegati a record già presenti'],
  ['rimossi_dai_moderatori', 'Già rimossi dai moderatori (ignorati)'], ['possibili_duplicati', 'Possibili duplicati segnalati ai moderatori'],
  ['errori', 'Errori di validazione'],
];
const PHASES = { map: 'Lettura e armonizzazione', cluster: 'Ricerca dei duplicati', write: 'Scrittura nel database', finish: 'Chiusura', done: 'Completata' };

function mb(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

export async function showImport(panel) {
  clear(panel, h('p', { class: 'loading' }, 'Caricamento…'));
  let ov;
  try { ov = await get('/admin/import'); } catch (e) { clear(panel, h('p', { class: 'form-error' }, e.message)); return; }

  let files = ov.files;
  const out = h('div', { 'aria-live': 'polite' });
  const fileBox = h('div');
  const opts = { file: null };
  const sourceSel = select('source', [{ value: 'inascolto', label: 'inascolto.org' }, { value: 'altro', label: 'Altra fonte' }], 'inascolto');
  const update = checkbox('update', 'Aggiorna i record già importati se la fonte è cambiata (i record modificati dalla comunità non vengono mai sovrascritti)', true);
  const sourceField = field('Origine dei dati', sourceSel, { hint: 'Serve a riconoscere le stesse righe nelle importazioni successive.' });
  let busy = false;

  const renderFiles = () => {
    clear(fileBox);
    if (!files.length) { fileBox.append(h('p', { class: 'muted' }, 'Nessun file disponibile: carica un file CSV qui sotto.')); return; }
    if (!opts.file || !files.some((f) => f.kind + '/' + f.name === opts.file)) {
      opts.file = (files.find((f) => f.kind === 'incluso') || files[0]).kind + '/' + (files.find((f) => f.kind === 'incluso') || files[0]).name;
    }
    fileBox.append(h('div', { class: 'choice-cards', role: 'radiogroup', 'aria-label': 'File da importare' }, files.map((f) => {
      const key = f.kind + '/' + f.name;
      return h('label', {},
        h('input', { type: 'radio', name: 'import-file', value: key, checked: key === opts.file, onchange: () => { opts.file = key; syncSource(); } }),
        h('span', { class: 't' }, f.name),
        h('span', { class: 'd' }, [f.kind === 'incluso' ? 'Incluso nel pacchetto' : 'Caricato da te', f.rows ? `${f.rows.toLocaleString('it-IT')} righe` : null, mb(f.size)].filter(Boolean).join(' · ')));
    })));
    syncSource();
  };
  const syncSource = () => {
    const f = files.find((x) => x.kind + '/' + x.name === opts.file);
    sourceField.hidden = !f || f.kind === 'incluso';
  };

  const setButtons = (disabled) => { busy = disabled; panel.querySelectorAll('[data-run]').forEach((b) => { b.disabled = disabled; }); };

  async function start(dry) {
    if (busy || !opts.file) return;
    const [kind, ...rest] = opts.file.split('/');
    const name = rest.join('/');
    if (!dry && !confirm('Avviare l\'importazione vera? I dati verranno scritti nel database.\n\nSe è la prima volta, ti consigliamo di fare prima una simulazione.')) return;
    setButtons(true);
    const f = files.find((x) => x.kind === kind && x.name === name);
    const source = kind === 'incluso' ? 'manuale-v2' : sourceSel.value;
    try {
      const job = await post('/admin/import/start', { kind, name, source, dry_run: dry, update: panel.querySelector('[name=update]').checked });
      await run(job, dry, f);
    } catch (e) {
      clear(out, h('p', { class: 'form-error', role: 'alert' }, e.message));
    } finally { setButtons(false); }
  }

  async function run(job, dry, f) {
    const bar = h('div', { class: 'bar' }, h('span'));
    const barFill = bar.firstChild;
    const phase = h('p', { class: 'muted small' });
    const cancel = h('button', { type: 'button', class: 'btn small', onclick: async () => { cancelled = true; try { await post('/admin/import/cancel', { job: job.id }); } catch { /* già concluso */ } } }, 'Annulla');
    let cancelled = false;
    clear(out, h('div', { class: 'card' },
      h('h3', {}, (dry ? 'Simulazione' : 'Importazione') + ' in corso: ' + f.name),
      h('div', { role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-label': 'Avanzamento' }, bar),
      phase,
      h('p', { class: 'muted small' }, 'Tieni aperta questa pagina finché non termina. Se la chiudi, potrai riprendere dall\'elenco delle importazioni.'),
      cancel));
    const paint = (st) => {
      barFill.style.width = Math.round(st.progress * 100) + '%';
      bar.parentElement.setAttribute('aria-valuenow', String(Math.round(st.progress * 100)));
      phase.textContent = `${PHASES[st.phase] || st.phase}: ${st.message}`;
    };
    let st = job;
    let retries = 0;
    while (st.status === 'running') {
      try {
        st = await post('/admin/import/step', { job: st.id });
        retries = 0;
        paint(st);
      } catch (e) {
        if (++retries > 4) throw e;
        phase.textContent = 'Connessione interrotta, nuovo tentativo…';
        await new Promise((r) => setTimeout(r, 2000 * retries));
      }
    }
    renderResult(st);
  }

  function renderResult(st) {
    const r = st.report;
    const ok = st.status === 'done';
    const stats = r ? h('table', { class: 'diff' }, h('tbody', {}, STAT_LABELS.filter(([k]) => (r.stats[k] ?? 0) > 0 || ['righe_lette', 'inseriti'].includes(k)).map(([k, l]) =>
      h('tr', {}, h('td', {}, l + (st.dry_run && k === 'possibili_duplicati' ? ' (stima)' : '')), h('td', { class: 'num' }, (r.stats[k] || 0).toLocaleString('it-IT'))))) ) : null;
    const services = r && Object.keys(r.per_servizio || {}).length
      ? h('details', {}, h('summary', {}, 'Nuovi record per servizio'), h('ul', { class: 'small' }, Object.entries(r.per_servizio).map(([k, n]) => h('li', {}, `${k}: ${n.toLocaleString('it-IT')}`))))
      : null;
    const problems = r && (r.errori.length || r.scartate.length)
      ? h('details', {}, h('summary', {}, `Righe con problemi (${r.errori.length + r.scartate.length})`),
        h('ul', { class: 'small' }, [...r.scartate.slice(0, 40), ...r.errori.slice(0, 40)].map((x) => h('li', {}, x))))
      : null;
    const unclassified = r && Object.keys(r.da_classificare || {}).length
      ? h('details', {}, h('summary', {}, 'Descrizioni classificate solo per banda di frequenza'),
        h('ul', { class: 'small' }, Object.entries(r.da_classificare).slice(0, 40).map(([l, n]) => h('li', {}, `${n}× ${l}`))))
      : null;
    clear(out, h('div', { class: 'card' },
      h('h3', {}, ok ? (st.dry_run ? 'Simulazione completata' : 'Importazione completata') : 'Operazione non completata'),
      h('p', { class: ok ? 'notice ok' : 'notice danger' }, st.message),
      stats, services, problems, unclassified,
      h('div', { class: 'actions' },
        st.has_duplicates_csv ? h('a', { class: 'btn small', href: apiUrl('/admin/import/download?id=' + st.id), download: '' }, 'Scarica l\'elenco di unificazioni e duplicati (CSV)') : null,
        ok && st.dry_run ? h('button', { type: 'button', class: 'btn primary small', onclick: () => { start(false); } }, 'Tutto bene: importa davvero') : null,
        ok && !st.dry_run ? h('button', { type: 'button', class: 'btn small', onclick: async (e) => {
          e.currentTarget.disabled = true;
          try { const n = await post('/admin/news/from-import', { job: st.id }); toast(n.message); location.hash = '#/moderazione/notizie'; } catch (err) { toast(err.message, 'error'); e.currentTarget.disabled = false; }
        } }, 'Crea una bozza di notizia con il resoconto') : null,
        ok && !st.dry_run && (r?.stats.possibili_duplicati || 0) > 0
          ? h('a', { class: 'btn small', href: '#/moderazione/segnalazioni' }, 'Rivedi i possibili duplicati') : null)));
    loadJobs();
  }

  // ---- caricamento di un file
  const fileInput = h('input', { type: 'file', name: 'file', accept: '.csv,.xlsx,.json,.html,.htm,.txt,.tsv', 'aria-label': 'File da caricare' });
  const uploadBtn = h('button', { type: 'button', class: 'btn', onclick: async () => {
    if (!fileInput.files.length) { toast('Scegli prima un file.', 'error'); return; }
    const fd = new FormData();
    fd.append('file', fileInput.files[0]);
    uploadBtn.disabled = true;
    try {
      const r = await upload('/admin/import/upload', fd);
      files = r.files;
      opts.file = 'caricato/' + r.name;
      fileInput.value = '';
      renderFiles();
      toast('File caricato: ' + r.name);
    } catch (e) { toast(e.fields?.file || e.message, 'error'); } finally { uploadBtn.disabled = false; }
  } }, 'Carica il file');

  // ---- comuni ISTAT: download diretto oppure file caricato (CSV o Excel)
  const istatUrl = h('input', { type: 'url', name: 'istat_url', value: ov.defaults?.istat_url || '', 'aria-label': 'Indirizzo del file ISTAT' });
  const istatInput = h('input', { type: 'file', accept: '.csv,.xlsx', 'aria-label': 'Elenco comuni ISTAT (CSV o Excel)' });
  const istatMsg = h('p', { class: 'muted small', 'aria-live': 'polite' });
  const istatBtns = [];

  async function importComuniFile(name) {
    let offset = 0, imported = 0, skipped = 0;
    for (;;) {
      const r = await post('/admin/import/comuni', { name, offset });
      if (r.message) throw new Error(r.message);
      offset = r.offset; imported += r.imported; skipped += r.skipped;
      istatMsg.textContent = `Comuni elaborati: ${offset.toLocaleString('it-IT')}…`;
      if (r.done) break;
    }
    try { await post('/admin/import/delete', { name }); } catch { /* il file resta in inbox: non è un problema */ }
    istatMsg.textContent = `Completato: ${imported.toLocaleString('it-IT')} comuni caricati`
      + (skipped ? `, ${skipped.toLocaleString('it-IT')} saltati perché la sigla di provincia non è presente nella tabella delle province.` : '.');
    files = (await get('/admin/import')).files;
    renderFiles();
  }
  const guardIstat = async (fn) => {
    istatBtns.forEach((b) => { b.disabled = true; });
    istatMsg.textContent = '';
    try { await fn(); } catch (e) { istatMsg.textContent = ''; toast(e.fields?.url || e.fields?.file || e.message, 'error'); }
    finally { istatBtns.forEach((b) => { b.disabled = false; }); }
  };
  const istatFetchBtn = h('button', { type: 'button', class: 'btn primary', onclick: () => guardIstat(async () => {
    istatMsg.textContent = 'Download da ISTAT in corso…';
    const r = await post('/admin/import/fetch', { url: istatUrl.value.trim() });
    await importComuniFile(r.name);
  }) }, 'Scarica da ISTAT e importa');
  const istatUploadBtn = h('button', { type: 'button', class: 'btn', onclick: () => guardIstat(async () => {
    if (!istatInput.files.length) throw new Error('Scegli prima il file ISTAT.');
    const fd = new FormData();
    fd.append('file', istatInput.files[0]);
    const up = await upload('/admin/import/upload', fd);
    await importComuniFile(up.name);
  }) }, 'Importa il file scelto');
  istatBtns.push(istatFetchBtn, istatUploadBtn);

  // ---- elenco importazioni
  const jobsBox = h('div');
  async function loadJobs() {
    try {
      const { jobs } = await get('/admin/import');
      clear(jobsBox, jobs.length ? h('ul', { class: 'list' }, jobs.map((j) => h('li', {},
        h('div', { class: 'head' },
          h('strong', {}, j.file),
          h('span', { class: 'tag ' + (j.status === 'done' ? 'approved' : j.status === 'running' ? 'pending' : 'rejected') }, { done: 'completata', running: 'in corso', error: 'errore', cancelled: 'annullata' }[j.status] || j.status),
          j.dry_run ? h('span', { class: 'tag' }, 'simulazione') : null,
          h('span', { class: 'muted small' }, dateTime(j.started_at))),
        h('div', { class: 'small muted' }, j.stats ? `${j.stats.inseriti} nuovi, ${j.stats.aggiornati} aggiornati, ${j.stats.righe_unificate} unificati` : ''),
        j.status === 'running' ? h('button', { type: 'button', class: 'btn small', onclick: () => { run(j, j.dry_run, { name: j.file }).catch((e) => toast(e.message, 'error')); } }, 'Riprendi') : null,
        j.has_duplicates_csv ? h('a', { class: 'small', href: apiUrl('/admin/import/download?id=' + j.id), download: '' }, 'Elenco duplicati (CSV)') : null)))
        : h('p', { class: 'muted' }, 'Nessuna importazione eseguita.'));
    } catch { /* non bloccante */ }
  }

  // ---- manutenzione
  const maintMsg = h('span', { class: 'muted small' }, ov.maintenance_last_run ? 'Ultima esecuzione: ' + ov.maintenance_last_run : 'Non ancora eseguita.');
  const maintBtn = h('button', { type: 'button', class: 'btn small', onclick: async () => {
    try { const r = await post('/admin/maintenance', {}); maintMsg.textContent = 'Ultima esecuzione: ' + r.last_run; toast(r.message); } catch (e) { toast(e.message, 'error'); }
  } }, 'Esegui ora');

  const limitMb = (ov.limits.upload_bytes / 1048576).toFixed(ov.limits.upload_bytes < 10485760 ? 1 : 0);
  clear(panel,
    h('p', { class: 'muted' }, 'Qui importi i dati nel database senza usare il terminale. Ogni importazione armonizza i dati, elimina i duplicati e può essere simulata prima di salvarla.'),
    h('div', { class: 'card' },
      h('h3', {}, '1. Scegli il file'),
      fileBox,
      ov.review_file ? h('p', { class: 'small muted' }, 'Del manuale sono state escluse alcune frequenze che l\'OCR ha letto in modo dubbio: ',
        h('a', { href: apiUrl('/admin/import/download?what=rivedere'), download: '' }, 'scarica l\'elenco da rivedere (CSV)'), '.') : null,
      h('details', {}, h('summary', {}, 'Carica un altro file (es. l\'esportazione di inascolto.org)'),
        h('div', { class: 'form' },
          h('p', { class: 'small muted' }, `Formati: CSV, Excel (XLSX), JSON o tabella HTML. Dimensione massima: ${limitMb} MB. Se il file è più grande, caricalo via FTP nella cartella app/storage/import/inbox/ e ricarica questa pagina.`),
          fileInput, h('div', { class: 'actions' }, uploadBtn)))),
    h('div', { class: 'card' },
      h('h3', {}, '2. Opzioni'),
      h('div', { class: 'form' }, sourceField, update)),
    h('div', { class: 'card' },
      h('h3', {}, '3. Avvia'),
      h('div', { class: 'actions' },
        h('button', { type: 'button', class: 'btn primary', 'data-run': '', onclick: () => start(true) }, 'Simula (non salva nulla)'),
        h('button', { type: 'button', class: 'btn', 'data-run': '', onclick: () => start(false) }, 'Importa')),
      h('p', { class: 'small muted' }, 'La simulazione fa tutto il lavoro tranne il salvataggio e mostra quanti record verrebbero creati e quali duplicati verrebbero uniti.')),
    out,
    h('details', { class: 'card' }, h('summary', {}, 'Comuni ISTAT (facoltativo, consigliato prima dei dati)'),
      h('div', { class: 'form' },
        h('p', { class: 'small muted' }, 'Con l\'elenco ufficiale dei comuni il sistema riconosce meglio le località. Si carica una sola volta. ISTAT lo pubblica come file Excel (.xlsx) all\'indirizzo permanente qui sotto: il pulsante lo scarica e lo importa da solo.'),
        field('Indirizzo del file ISTAT', istatUrl, { hint: 'Sono ammessi solo indirizzi HTTPS di siti autorizzati: ' + (ov.defaults?.fetch_hosts || []).join(', ') + '.' }),
        h('div', { class: 'actions' }, istatFetchBtn),
        h('p', { class: 'small muted' }, 'Se l\'hosting non consente il download diretto, scarica il file dal browser (pagina "Codici delle unità amministrative" su istat.it) e caricalo qui, in CSV o Excel:'),
        istatInput, h('div', { class: 'actions' }, istatUploadBtn), istatMsg)),
    h('div', { class: 'card' }, h('h3', {}, 'Ultime importazioni'), jobsBox),
    h('div', { class: 'card' }, h('h3', {}, 'Manutenzione'),
      h('p', { class: 'small muted' }, 'La pulizia di token scaduti e dati temporanei parte da sola ogni ora: non serve un cron. Puoi anche lanciarla a mano.'),
      h('div', { class: 'actions' }, maintBtn, maintMsg)));
  renderFiles();
  loadJobs();
}
