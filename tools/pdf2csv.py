#!/usr/bin/env python3
"""
Conversione del manuale "Tutte le frequenze segrete VHF UHF" (v. 2.0) in un CSV importabile in ListenIn.

Uso:  pdftotext -layout manuale.pdf manuale.txt  &&  python3 pdf2csv.py manuale.txt out_dir

Il testo del PDF deriva da una scansione con OCR e contiene errori (separatori mancanti o sbagliati,
cifre confuse). Ogni frequenza viene quindi normalizzata e controllata:
  - separatori: "73,000", "78 275", "172825", "74,337.5", "162. 037.5" → MHz;
  - banda: deve cadere nell'intervallo del servizio (es. Vigili del Fuoco 73-74,5 MHz);
  - canalizzazione: deve essere multipla di 2,5 kHz o 6,25 kHz.
I valori che non superano i controlli NON vengono importati: finiscono in frequenze_da_rivedere.csv.
"""
import csv, re, sys, os, unicodedata
from collections import OrderedDict, defaultdict

SRC = sys.argv[1]
OUT = sys.argv[2]
SEED = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'listenin', 'app', 'database', 'seed.sql')
os.makedirs(OUT, exist_ok=True)

TEXT = open(SRC, encoding='utf-8').read()
PAGES = [None] + [p.split('\n') for p in TEXT.split('\f')]


def norm(s):
    s = unicodedata.normalize('NFKD', s.lower())
    s = ''.join(c for c in s if not unicodedata.combining(c))
    return re.sub(r'[^a-z0-9]+', ' ', s).strip()


# ---------------------------------------------------------------- province
PROV = {}       # nome normalizzato → sigla
PROV_NAME = {}  # sigla → nome
for m in re.finditer(r"\(\d+,'([A-Z]{2})','((?:[^']|'')+)'\)", open(SEED, encoding='utf-8').read()):
    code, name = m.group(1), m.group(2).replace("''", "'")
    PROV[norm(name)] = code
    PROV_NAME[code] = name
PROV.update({
    'massa carrara': 'MS', 'massa': 'MS', 'pesaro': 'PU', 'pesaro urbino': 'PU', 'forli': 'FC', 'forli cesena': 'FC',
    'cesena': 'FC', 'verbania': 'VB', 'verbano cusio ossola': 'VB', 'le spezia': 'SP', 'spezia': 'SP', 'la spezia': 'SP',
    'reggio c': 'RC', 'reggio calabria': 'RC', 'reggio emilia': 'RE', 'reggio e': 'RE', 'aquila': 'AQ', 'l aquila': 'AQ',
    'monza': 'MB', 'monza e brianza': 'MB', 'bolzano': 'BZ', 'bozen': 'BZ', 'barletta': 'BT', 'ascoli': 'AP',
    'olbia': 'SS', 'iglesias': 'SU', 'carbonia': 'SU', 'carbonia iglesias': 'SU', 'medio campidano': 'SU', 'ogliastra': 'NU',
    'vibo valentia': 'VV', 'forlì': 'FC',
})
PROV_LEGACY = {'FO': 'FC', 'PS': 'PU', 'OT': 'SS', 'OG': 'NU', 'CI': 'SU', 'VS': 'SU', 'ML': 'MI', 'MI': 'MI'}


def prov_code(name):
    """Sigla della provincia se il nome (senza note tra parentesi) è un capoluogo/provincia noto."""
    base = re.sub(r'\(.*?\)', '', name)
    base = re.sub(r'\b(e prov\.?|prov\.?|provincia)\b', '', base, flags=re.I).strip(' .-')
    return PROV.get(norm(base))


def code_from_token(tok):
    t = tok.strip().upper().replace('{', '').replace('}', '')
    if t == 'ML':
        return 'MI'
    t = PROV_LEGACY.get(t, t)
    return t if t in PROV_NAME else None


# ---------------------------------------------------------------- frequenze
FREQ_RE = re.compile(r'''
  (?<![\d.,])
  (?:
     (?P<a>\d{2,3})\s?[.,]\s?(?P<b>\d{3})(?:[.,]\s?(?P<c>\d{1,2})(?!\d))?(?![\d])   # 73.500  74,337.5  172.712,5  162. 037.5
   | (?P<a2>\d{2,3})\s?[.,]\s?(?P<b2>\d{4,5})(?!\d)                                  # 173.10625
   | (?P<a3>\d{2,3})\s(?P<b3>\d{3})(?:[.,]\s?(?P<c3>\d{1,2})(?!\d))?(?!\d)                 # 78 275   43 350,0
   | (?P<d>\d{6})(?:,(?P<e>\d))?(?!\d)                                               # 172825  155687,5
  )''', re.X)


def to_hz(m):
    if m.group('a'):
        s = m.group('a') + '.' + m.group('b') + (m.group('c') or '')
    elif m.group('a2'):
        s = m.group('a2') + '.' + m.group('b2')
    elif m.group('a3'):
        s = m.group('a3') + '.' + m.group('b3') + (m.group('c3') or '')
    else:
        d = m.group('d')
        s = d[:3] + '.' + d[3:] + (m.group('e') or '')
    ip, fp = s.split('.')
    return int(ip) * 1_000_000 + int((fp + '000000')[:6])


def raster_ok(hz):
    return hz % 2500 == 0 or hz % 6250 == 0


def mhz_str(hz):
    ip, fp = divmod(hz, 1_000_000)
    fp = str(fp).zfill(6).rstrip('0').ljust(4, '0')
    return f'{ip}.{fp}'


def mask_parens(s):
    """Sostituisce il contenuto delle parentesi con spazi (stessa lunghezza) e restituisce [(inizio, testo)]."""
    notes = []
    out = list(s)
    depth = 0
    start = None
    for i, ch in enumerate(s):
        if ch == '(':
            if depth == 0:
                start = i
            depth += 1
        elif ch == ')' and depth:
            depth -= 1
            if depth == 0:
                notes.append((start, s[start + 1:i]))
                for j in range(start, i + 1):
                    out[j] = ' '
    if depth:  # parentesi non chiusa (OCR)
        notes.append((start, s[start + 1:]))
        for j in range(start, len(s)):
            out[j] = ' '
    return ''.join(out), notes


class Tok:
    def __init__(self, hz, start, end, raw):
        self.hz, self.start, self.end, self.raw = hz, start, end, raw
        self.notes = []
        self.pair = False
        self.partner = None


def find_freqs(s):
    """Frequenze di una riga: lista di Tok con le note tra parentesi attribuite alla frequenza che le precede."""
    masked, pnotes = mask_parens(s)
    toks = [Tok(to_hz(m), m.start(), m.end(), m.group(0)) for m in FREQ_RE.finditer(masked)]
    # coppie "parla-ascolta": 73.550<>73.575
    for i, t in enumerate(toks):
        if i + 1 < len(toks) and masked[t.end:toks[i + 1].start].strip() == '<>':
            t.pair = toks[i + 1].pair = True
            t.partner, toks[i + 1].partner = toks[i + 1].hz, t.hz
    for pos, txt in pnotes:
        prev = [t for t in toks if t.end <= pos]
        txt = txt.strip()
        if re.search(r'entramb[ei]|tutte', txt, re.I) and re.search(r'verific', txt, re.I):
            for t in toks:
                t.notes.append('da verificare')
        elif re.fullmatch(r'parla\W*ascolta|parla\W*$|ascolta\)?', txt, re.I):
            pass
        elif prev:
            prev[-1].notes.append(txt)   # le parentesi prima della prima frequenza fanno parte del nome della località
    # numeri con separatori che il riconoscitore non ha letto (es. due frequenze attaccate): da rivedere
    left = list(masked)
    for t in toks:
        for j in range(t.start, t.end):
            left[j] = ' '
    for m in re.finditer(r'(?<![\d.,:/])\d{2,3}[.,]\d{3,}(?![\d]*[:/])', ''.join(left)):
        REVIEW.append([CTX['page'], CTX['section'], m.group(0), re.sub(r'\s+', ' ', s.strip())[:60], '', 'non letta (formato non riconosciuto)'])
        STATS['non_lette'] += 1
    # alternative "160.500 o 161.225"
    for i, t in enumerate(toks[:-1]):
        if re.fullmatch(r'\s*o\s*', masked[t.end:toks[i + 1].start]):
            t.notes.append('alternativa a ' + mhz_str(toks[i + 1].hz) + ' MHz')
    return toks, masked


def clean_line(l):
    l = l.replace('’', "'").replace('‘', "'").replace('“', '"').replace('”', '"').replace('\u00a0', ' ')
    l = l.replace('—', '-').replace('–', '-').replace('\t', '    ')
    return l.rstrip()


# ---------------------------------------------------------------- uscita
RECORDS = []
REVIEW = []
STATS = defaultdict(int)
SEQ = defaultdict(int)
MANUAL = 'Tutte le frequenze segrete VHF UHF v. 2.0'


def fix_ocr_text(t):
    t = re.sub(r'\bdeI\b', 'del', t)
    t = re.sub(r'\bdeIl', 'dell', t)
    t = re.sub(r'\bAir Franca\b', 'Air France', t)
    t = re.sub(r'\b([A-Z])([A-Z])([a-zà-ÿ]{2,})', lambda m: m.group(1) + m.group(2).lower() + m.group(3), t)   # CIttà → Città
    t = re.sub(r'(?<=[a-zà-ÿ])([A-Z])(?=[a-zà-ÿ])', lambda m: m.group(1).lower(), t)                          # MiLanese → Milanese
    return re.sub(r'\s+', ' ', t).strip()


def emit(page, hz, band, label, svc, mode='FM', area='', region='', place='', note=None, entity='', channel='',
         callsign='', inp=None, status='Da verificare', extra=None, section=''):
    """Registra un record se la frequenza supera i controlli; altrimenti la mette tra quelle da rivedere."""
    label, place = fix_ocr_text(label), fix_ocr_text(place)
    bands = band if isinstance(band, list) else [band]
    reason = None
    if not any(lo <= hz <= hi for lo, hi in bands):
        reason = 'fuori banda (' + ', '.join(f'{mhz_str(lo)}-{mhz_str(hi)}' for lo, hi in bands) + ' MHz)'
    elif not raster_ok(hz):
        reason = 'non coerente con la canalizzazione'
    if reason:
        REVIEW.append([page, section, mhz_str(hz), label, area or place, reason])
        STATS['scartate'] += 1
        return False
    SEQ[page] += 1
    notes = []
    if extra:
        notes.extend(extra if isinstance(extra, list) else [extra])
    if note:
        notes.extend(note if isinstance(note, list) else [note])
    notes.append(f'Fonte: manuale «{MANUAL}», pag. {page}')
    RECORDS.append(OrderedDict([
        ('ID', f'man2-{page:03d}-{SEQ[page]:03d}'),
        ('Frequenza', mhz_str(hz)),
        ('Modo', mode),
        ('Descrizione', label),
        ('Servizio ListenIn', svc),
        ('Regione', region),
        ('Provincia / area', area),
        ('Località', place),
        ('Stato', status),
        ('Ente', entity),
        ('Canale', channel),
        ('Nominativo', callsign),
        ('Ingresso', mhz_str(inp) if inp else ''),
        ('Note', '\n'.join(n for n in notes if n)),
    ]))
    STATS[svc] += 1
    return True


SKIP_LINE = re.compile(r'^\s*(INDICE|\d{1,3})\s*$')
CTX = {'page': 0, 'section': ''}


def page_lines(p, start_after=None):
    CTX['page'] = p
    lines = [clean_line(l) for l in PAGES[p]]
    if start_after:
        for i, l in enumerate(lines):
            if re.search(start_after, l):
                lines = lines[i + 1:]
                break
    return [l for l in lines if not SKIP_LINE.match(l)]


def pair_note(t):
    return [f'Coppia parla-ascolta con {mhz_str(t.partner)} MHz'] if t.pair and t.partner else []


def note_texts(tok):
    out = []
    for n in tok.notes:
        n = re.sub(r'\s+', ' ', n).strip()
        if n:
            out.append(n[0].upper() + n[1:])
    return out


# ---------------------------------------------------------------- sezioni a blocchi per provincia
def province_blocks(pages, band, svc, label, mode='FM', start_marker=r'PROVINCIE E LOCALIT|^\s*LOCALIT', region_lists=False,
                    section='', stop=None, loc_noun='Distaccamenti'):
    """VV.F., Carabinieri, Polizia Stradale, AIB: riga con provincia e frequenze, poi le località dipendenti."""
    cur = None   # {'name','code','freqs':[Tok],'locs':[..],'page':n}
    done_header = False
    out = []

    def flush():
        nonlocal cur
        if not cur:
            return
        extra = [f'{loc_noun}: ' + ', '.join(cur['locs'])] if cur['locs'] else []
        for t in cur['freqs']:
            emit(cur['page'], t.hz, band, f"{label} - {cur['name']}", svc, mode, area=cur['code'] or '', place='',
                 note=note_texts(t) + pair_note(t), extra=extra, section=section)
        cur = None

    for p in pages:
        lines = page_lines(p, None if done_header else start_marker)
        if not done_header and len(lines) < len(page_lines(p)):
            done_header = True
        elif not done_header:
            done_header = True
        for l in lines:
            if stop and re.search(stop, l):
                flush()
                return
            s = l.strip()
            if not s or re.match(r'^[-—_ ]+$', s) or re.search(r'DISTACCAMENTI E STAZIONI NON|VENGONO RIPORTATE|EVENTUALE VERIFICA|PROVINCIE E LOCALIT', s):
                continue
            toks, masked = find_freqs(l)
            if toks:
                name = masked[:toks[0].start].strip(' .')
                name_raw = l[:toks[0].start].strip(' .')
                if not name:      # continuazione delle frequenze della riga precedente
                    if cur:
                        cur['freqs'].extend(toks)
                    continue
                code = prov_code(name_raw) if '(' not in name_raw else None
                is_header = code is not None and (cur is None or norm(name_raw) != norm(cur['name']))
                if is_header:
                    flush()
                    cur = {'name': re.sub(r'\s+', ' ', name_raw), 'code': code, 'freqs': list(toks), 'locs': [], 'page': p}
                else:   # località con frequenza propria
                    if cur:
                        base = re.sub(r'\(.*?\)', '', name_raw).strip()
                        # "Roma (aeroporto Fiumicino)": il qualificatore distingue il record da quello della provincia
                        place = name_raw if prov_code(base) is not None else base
                        for t in toks:
                            emit(p, t.hz, band, f"{label} - {name_raw}", svc, mode, area=cur['code'] or '', place=place,
                                 note=note_texts(t) + [f'Dipende da {cur["name"]}'], section=section)
                continue
            # nessuna frequenza: località dipendente (o nuova provincia senza frequenze, ignorata)
            if cur and re.match(r"^[A-Za-zÀ-ÿ0-9'’.,\-/ ()]+$", s) and not s.isupper():
                cur['locs'].append(re.sub(r'\s+', ' ', s))
    flush()


# ================================================================ SEZIONI
B_VVF = [(73_000_000, 74_600_000)]
B_POLSTATO = [(76_000_000, 79_500_000)]
B_CC = [(424_000_000, 429_100_000)]
B_URBANI = [(150_000_000, 175_500_000), (440_000_000, 470_000_000)]
MARINE_OK = [(156_000_000, 157_500_000), (160_600_000, 162_050_000)]
MARINE_CH = {6: 156_300_000, 8: 156_400_000, 9: 156_450_000, 10: 156_500_000, 11: 156_550_000, 12: 156_600_000,
             13: 156_650_000, 14: 156_700_000, 15: 156_750_000, 16: 156_800_000, 17: 156_850_000, 18: 161_500_000,
             19: 161_550_000, 20: 161_600_000, 21: 161_650_000, 22: 161_700_000, 25: 161_850_000, 26: 161_900_000,
             27: 161_950_000, 28: 162_000_000, 68: 156_425_000, 79: 161_575_000, 80: 161_625_000, 70: 156_525_000}


def digits_differ(a, b):
    sa, sb = str(a), str(b)
    return len(sa) == len(sb) and sum(x != y for x, y in zip(sa, sb)) <= 1


def marine_fix(hz, ch):
    """Il canale VHF marittimo fissa la frequenza (piano ITU): corregge un solo errore di cifra dell'OCR."""
    if ch in MARINE_CH and hz != MARINE_CH[ch] and digits_differ(hz, MARINE_CH[ch]):
        return MARINE_CH[ch], f'Valore corretto in base al canale {ch} (letto {mhz_str(hz)} MHz)'
    return hz, None


def parts_of(pre):
    return [x for x in re.split(r'\s{2,}', pre.strip()) if x]


def prov_from(loc):
    """(nome, sigla) da 'Acqui Terme (AL)', 'Viareggio (LU}', 'Bergamo e prov.'"""
    m = re.match(r'^(.*?)\s*[({]\s*([A-Za-z]{2})\s*[)}]?\s*$', loc.strip())
    if m and code_from_token(m.group(2)):
        return m.group(1).strip(), code_from_token(m.group(2))
    return re.sub(r'\s+', ' ', loc.strip(' .')), prov_code(loc)


def first_marker(p, marker):
    return marker


# ---------- Vigili del Fuoco
def s_vvf():
    province_blocks(range(4, 16), B_VVF, 'emergenza-vvf', 'Vigili del Fuoco', section='VV.F.')
    CTX['section'] = 'VV.F. canali regionali'
    for l in page_lines(16):
        if re.search(r'CANALIZZAZIONE APPARATI UHF', l):
            break
        toks, masked = find_freqs(l)
        if not toks:
            continue
        name = masked[:toks[0].start].strip()
        for t in toks:
            reg = name if any(name.lower().startswith(k) for k in ('veneto', 'liguria', 'emilia', 'toscana', 'marche', 'umbria', 'lazio', 'abruzzo',
                                                                  'molise', 'puglia', 'campania', 'basilicata', 'calabria', 'sicilia', 'sardegna',
                                                                  'piemonte', 'lombardia', 'valle')) else ''
            emit(16, t.hz, B_VVF, f'Vigili del Fuoco - {name}', 'emergenza-vvf', region=reg.replace(' – ', '-').replace('–', '-'), place='',
                 section='VV.F. regionali', note=note_texts(t) + ['Canale regionale o di interconnessione tra ponti radio'])
    for l in page_lines(16, r'CANALIZZAZIONE APPARATI UHF'):
        toks, _ = find_freqs(l)
        for t in toks:
            emit(16, t.hz, [(434_000_000, 443_000_000)], 'Vigili del Fuoco - canalizzazione apparati UHF', 'emergenza-vvf',
                 note=['Canali UHF in uso ai Vigili del Fuoco'], section='VV.F. UHF')


# ---------- tabelle LOCALITA' / PROV / FREQUENZE
def loc_prov_table(pages, band, svc, label, section, marker=r"LOCALIT", mode='FM', entity='', skip_pages=()):
    last = None
    for i, p in enumerate(pages):
        if p in skip_pages:
            continue
        for l in page_lines(p, marker if i == 0 else None):
            toks, masked = find_freqs(l)
            if not toks:
                continue
            pre = l[:toks[0].start]
            parts = parts_of(pre)
            if not parts:
                if not last:
                    continue
                loc, prov = last
            else:
                loc = re.sub(r'\s+', ' ', parts[0].strip(' .'))
                prov = code_from_token(parts[1]) if len(parts) > 1 else None
                if prov is None:
                    prov = prov_code(loc)
                last = (loc, prov)
            for t in toks:
                emit(p, t.hz, band, f'{label} - {loc}', svc, mode, area=prov or '', place=re.sub(r'\(.*?\)', '', loc).strip(),
                     entity=entity, note=note_texts(t) + pair_note(t), section=section)


def s_polstato():
    loc_prov_table(range(17, 24), B_POLSTATO, 'utility-forze-ordine', 'Polizia di Stato', 'Polizia di Stato', entity='Polizia di Stato')


def s_gdf():
    CTX['section'] = 'Guardia di Finanza'
    seen = set()
    for l in page_lines(24, r'PER OGNI BACINO'):
        for t in find_freqs(l)[0]:
            if t.hz not in seen:
                seen.add(t.hz)
                emit(24, t.hz, [(152_000_000, 154_600_000)], 'Guardia di Finanza - canale di lavoro', 'utility-forze-ordine',
                     entity='Guardia di Finanza', note=['Assegnazione regionale per bacino di utenza'], section='Guardia di Finanza')


def s_carabinieri():
    province_blocks(range(25, 36), B_CC, 'utility-forze-ordine', 'Arma dei Carabinieri', section='Carabinieri',
                    loc_noun='Località dipendenti', stop=r'Sono inoltre frequenze nazionali')
    CTX['section'] = 'Carabinieri nazionali'
    for l in page_lines(35):
        if re.search(r'Sono inoltre frequenze nazionali', l):
            for t in find_freqs(l)[0]:
                emit(35, t.hz, B_CC, 'Arma dei Carabinieri - frequenza nazionale comune a tutte le province', 'utility-forze-ordine',
                     entity='Arma dei Carabinieri', note=['Frequenza nazionale comune a tutte le province'], section='Carabinieri nazionali')
        elif re.search(r'IMIPIEGO AERONAUTICO|IMPIEGO AERONAUTICO', l):
            for t in find_freqs(l)[0]:
                emit(35, t.hz, [(118_000_000, 137_000_000), (225_000_000, 400_000_000)], 'Arma dei Carabinieri - impiego aeronautico',
                     'aeronautico', 'AM', entity='Arma dei Carabinieri',
                     note=['Civile VHF' if t.hz < 200_000_000 else 'Militare UHF'], section='Carabinieri nazionali')


def s_vigilanza():
    CTX['section'] = 'Vigilanza'
    for l in page_lines(36, r'ASSEGNAZIONE'):
        toks, masked = find_freqs(l)
        if not toks:
            continue
        pre = masked[:toks[0].start].strip()
        for t in toks:
            if 169_000_000 <= t.hz <= 170_000_000:
                emit(36, t.hz, [(169_000_000, 170_000_000)], f'Vigilanza privata - assegnazione {pre.lower() or "nazionale"}',
                     'utility-vigilanza', note=note_texts(t), section='Vigilanza')


def s_polstradale():
    province_blocks(range(37, 46), B_POLSTATO, 'utility-forze-ordine', 'Polizia Stradale', section='Polizia Stradale',
                    loc_noun='Distaccamenti')


def s_autostrade_pol():
    code = ''
    for p in (46, 47):
        for l in page_lines(p, r'AUTOSTRADE\s+FREQUENZE'):
            toks, masked = find_freqs(l)
            if not toks:
                continue
            pre = masked[:toks[0].start].strip()
            if pre:
                code = re.sub(r'\s+', ' ', pre)
            tratta = re.sub(r'\s+', ' ', l[toks[-1].end:].strip(' -'))
            for t in toks:
                emit(p, t.hz, [(76_000_000, 79_500_000), (172_000_000, 173_500_000)], f'Polizia Stradale - {code} {tratta}'.strip(),
                     'utility-forze-ordine', place=tratta, entity='Polizia Stradale',
                     note=note_texts(t) + [f'Autostrada {code}'], section='Autostrade Polizia Stradale')


def s_autostrade_caselli():
    CTX['section'] = 'Autostrade caselli'
    code = ''
    for l in page_lines(48, r'ASSEGNAZIONE'):
        toks, masked = find_freqs(l)
        if not toks:
            continue
        parts = parts_of(l[:toks[0].start])
        if len(parts) >= 2 and parts[0].lower() == 'nazionale':
            for t in toks:
                emit(48, t.hz, [(160_000_000, 173_500_000)], 'Autostrade - caselli e soccorso stradale (assegnazione nazionale)', 'utility-autostrade',
                     note=['Ambito territoriale regionale'] + note_texts(t), section='Autostrade caselli')
        elif len(parts) >= 2:
            a, tratta = parts[0], parts[1]
            for t in toks:
                emit(48, t.hz, [(160_000_000, 173_500_000)], f'Autostrade - {a} {tratta}', 'utility-autostrade', place=tratta,
                     note=note_texts(t) + [f'Tratta fuori dall\'assegnazione nazionale: {a}'], section='Autostrade caselli')
    for l in page_lines(49, r'COPERTURA'):
        toks, masked = find_freqs(l)
        if not toks:
            continue
        cover = re.sub(r'\s+', ' ', l[toks[-1].end:].strip(' -'))
        for t in toks:
            emit(49, t.hz, [(87_500_000, 108_000_000)], f'Informazioni viabilità - {cover}', 'broadcast-fm', 'WFM',
                 place='', note=['Radiodiffusione FM con informazioni sulla viabilità'], section='Informazioni viabilità')


def s_polmunicipale():
    loc_prov_table(range(50, 59), B_URBANI, 'utility-polizia-locale', 'Polizia Locale', 'Polizia Municipale', entity='Polizia Locale')


def s_aib():
    province_blocks(range(60, 77), [(150_000_000, 175_000_000), (430_000_000, 470_000_000)], 'emergenza-aib',
                    'Antincendio boschivo e Corpo Forestale', section='AIB e Forestale', loc_noun='Distaccamenti e stazioni')


# ---------- sanità
def s_ambulanze():
    CTX['section'] = 'Ambulanze'
    group = 'Croce Rossa Italiana'
    for l in page_lines(78, r'ASSEGNAZIONE'):
        if re.search(r'PUBBLICHE ASSISTENZE', l):
            group = 'Cooperative pubbliche assistenze'
        toks, masked = find_freqs(l)
        if not toks:
            continue
        parts = parts_of(l[:toks[0].start])
        if len(parts) < 2:
            continue
        ch = re.sub(r'\(.*?\)', '', parts[1]).strip()
        for t in toks:
            emit(78, t.hz, [(171_000_000, 174_000_000)], f'{group} - canale {ch}', 'emergenza-volontariato',
                 entity=group, channel=ch, note=[f'Assegnazione {parts[0].lower()}'] + note_texts(t), section='Ambulanze')


COLORS = {'bianca', 'rossa', 'verde', 'azzurra', 'gialla', 'blu', 'rosa', 'argento', 'celeste', 'ambrosiana', 'd\'oro'}


def three_col(pages, svc, label_fn, section, band, marker, entity_fn=None, mode='FM'):
    for i, p in enumerate(pages):
        for l in page_lines(p, marker if i == 0 else None):
            toks, masked = find_freqs(l)
            if not toks:
                continue
            parts = parts_of(l[:toks[0].start])
            if len(parts) < 2:
                continue
            loc, prov = prov_from(parts[0])
            ente_full = parts[1]
            ente_note = re.findall(r'\((.*?)\)', ente_full)
            ente = re.sub(r'\(.*?\)', '', ente_full).strip(' .')
            for t in toks:
                emit(p, t.hz, band, label_fn(loc, ente), svc, mode, area=prov or '', place=re.sub(r'\(.*?\)', '', loc).strip(),
                     entity=entity_fn(ente) if entity_fn else '', note=ente_note + note_texts(t) + pair_note(t), section=section)


def s_croci():
    def lab(loc, ente):
        e = ente.strip()
        if not e or 'non disponibile' in e.lower():
            return f'Pubblica assistenza - {loc}'
        return f'Croce {e} - {loc}' if e.lower() in COLORS else f'{e} - {loc}'
    three_col(range(79, 83), 'emergenza-volontariato', lab, 'Croci private e pubbliche assistenze',
              B_URBANI, r'LOCALIT', lambda e: '' if 'non disp' in e.lower() else e)


def s_sanitaria():
    def lab(loc, ente):
        e = ente.strip()
        if not e or 'non disponibile' in e.lower():
            return f'Emergenza sanitaria - {loc}'
        return f'{e} - {loc}'
    three_col(range(83, 86), 'emergenza-118', lab, 'Emergenza sanitaria',
              B_URBANI, r'LOCALITA\s+ENTE', None)


def s_elisoccorso():
    last = None
    for i, p in enumerate((86, 87)):
        for l in page_lines(p, r"LOCALITA" if i == 0 else None):
            toks, masked = find_freqs(l)
            if not toks:
                continue
            parts = parts_of(l[:toks[0].start])
            if parts:
                loc, prov = prov_from(parts[0])
                last = (loc, prov)
            elif last:
                loc, prov = last
            else:
                continue
            for t in toks:
                nt = note_texts(t)
                suffix = f' ({nt[0]})' if nt else ''
                emit(p, t.hz, B_URBANI, f'Elisoccorso - {loc}{suffix}', 'emergenza-elisoccorso',
                     area=prov or '', place=loc, note=nt + pair_note(t), section='Elisoccorso')


# ---------- soccorso alpino, rifugi, funivie
def s_alpino():
    CTX['section'] = 'Soccorso alpino'
    mode = 0
    last_entry = None
    for l in page_lines(88):
        if re.search(r"LOCALITA.*EMERGENZA E SOCCORSO", l):
            mode = 2
            continue
        m = re.match(r'^\s{0,3}(\S.*?)\s{2,}(\S.*)$', l)
        if not m:
            if last_entry and l.strip() and not re.search(r'BANDA|SERVIZI', l):
                last_entry['note'].append(l.strip())
            continue
        toks, masked = find_freqs(m.group(1))
        if len(toks) != 1 or masked[:toks[0].start].strip():
            continue
        t, desc = toks[0], m.group(2).strip()
        name, prov = prov_from(re.sub(r'\s+', ' ', desc)) if mode == 2 else (desc, None)
        if mode == 2:
            last_entry = {'note': []}
            emit(88, t.hz, [(68_000_000, 175_000_000)], f'Soccorso alpino - {name}', 'emergenza-soccorso-alpino',
                 area=prov or '', place=re.sub(r'\(.*?\)', '', name).strip(), note=['Località con frequenza operativa per emergenza e soccorso'],
                 section='Soccorso alpino')
        else:
            # buffer: il testo continua sulla riga seguente
            last_entry = {'t': t, 'desc': desc, 'note': []}
            PENDING.append(last_entry)
    for e in PENDING:
        d = ' '.join([e['desc']] + e['note'])
        if d.startswith('CAI'):
            label, ent = 'Soccorso alpino - frequenza CAI', 'CAI'
        elif 'Savigliano' in d:
            label, ent = 'Soccorso alpino - Savigliano (CN)', ''
        else:
            label, ent = 'Soccorso alpino - frequenza di soccorso', ''
        emit(88, e['t'].hz, [(68_000_000, 175_000_000)], label, 'emergenza-soccorso-alpino', area='CN' if 'Savigliano' in d else '',
             place='Savigliano' if 'Savigliano' in d else '', entity=ent, note=[d], section='Soccorso alpino')


PENDING = []

REGION_KEYS = [('piem', 'Piemonte'), ('lomb', 'Lombardia'), ('ligu', 'Liguria'), ('vall', "Valle d'Aosta"), ('vene', 'Veneto'),
               ('tren', 'Trentino-Alto Adige'), ('friu', 'Friuli-Venezia Giulia'), ('emil', 'Emilia-Romagna'), ('tosc', 'Toscana')]


def s_rifugi():
    CTX['section'] = 'Rifugi alpini'
    for p in (89, 90):
        for l in page_lines(p, r'RIFUGIO\s+REGIONE' if p == 89 else None):
            if re.search(r'GUIDE ALPINE', l):
                break
            toks, masked = find_freqs(l)
            if not toks:
                continue
            parts = parts_of(l[:toks[0].start])
            if len(parts) == 2 and parts[1].lower().startswith('non ident'):
                parts.append('')
            if len(parts) < 3:
                continue
            name, reg, pv = parts[0], parts[1], parts[2]
            region = next((v for k, v in REGION_KEYS if reg.lower().startswith(k)), '')
            codes = [c for c in re.findall(r'[A-Z]{2}', pv.upper()) if code_from_token(c)]
            ent = 'CAI' if re.search(r'\bCAI\b', name) else ''
            nm = re.sub(r'\s+CAI\b', '', name).strip()
            for t in toks:
                emit(p, t.hz, [(140_000_000, 450_000_000)], f'Rifugio {nm}', 'emergenza-soccorso-alpino', region=region,
                     area=' '.join(codes), place=nm, entity=ent, note=['Rifugio con radiotelefono di emergenza'] + note_texts(t), section='Rifugi alpini')


def s_guide():
    CTX['section'] = 'Guide alpine'
    for l in page_lines(90, r'GUIDE ALPINE'):
        toks, masked = find_freqs(l)
        if not toks:
            continue
        parts = parts_of(l[:toks[0].start])
        if not parts:
            continue
        loc = parts[0]
        prov = code_from_token(parts[1]) if len(parts) > 1 else None
        for t in toks:
            emit(90, t.hz, [(140_000_000, 175_000_000)], f'Guide alpine - {loc}', 'emergenza-soccorso-alpino', area=prov or '', place=loc,
                 note=['Frequenze non soggette ad assegnazione ministeriale'] + note_texts(t), section='Guide alpine')


def s_funivie():
    CTX['section'] = 'Funivie'
    for l in page_lines(91, r'LOCALITA'):
        toks, masked = find_freqs(l)
        if toks:
            loc = masked[:toks[0].start].strip()
            for t in toks:
                emit(91, t.hz, [(150_000_000, 175_000_000)], f'Funivie - {loc}', 'utility-trasporti', place=loc,
                     note=['Frequenza non soggetta ad assegnazione ministeriale'], section='Funivie')


# ---------- elenchi di canali
def freq_list(page, band, svc, label, skip_until=None, stop=None, section='', entity='', note=None, mode='FM', area='', start=None):
    CTX['section'] = section
    seen = set()
    for l in page_lines(page, skip_until):
        if stop and re.search(stop, l):
            break
        for t in find_freqs(l)[0]:
            if t.hz in seen:
                continue
            seen.add(t.hz)
            emit(page, t.hz, band, label, svc, mode, entity=entity, note=(note or []) + note_texts(t), section=section, area=area)


def s_pool():
    freq_list(92, [(465_000_000, 470_000_000)], 'utility-autostrade', 'ANAS - canale di lavoro', skip_until=r'PER OGNI BACINO',
              stop=r'NELLA REGIONE LOMBARDIA', section='ANAS', entity='ANAS', note=['Assegnazione provinciale per bacino d\'utenza'])
    for l in page_lines(92, r'NELLA REGIONE LOMBARDIA'):
        toks, masked = find_freqs(l)
        if toks:
            name = masked[:toks[0].start].strip()
            for t in toks:
                emit(92, t.hz, [(430_000_000, 470_000_000)], f'ANAS - {name}', 'utility-autostrade', area=prov_code(name) or '', place=name,
                     entity='ANAS', note=['Frequenze ANAS in Lombardia'], section='ANAS Lombardia')
    freq_list(93, [(440_000_000, 445_000_000)], 'utility-trasporti', 'Ferrovie dello Stato - coordinamento movimento', skip_until=r'UHF',
              section='Ferrovie', entity='Ferrovie dello Stato', note=['Coordinazione movimento, composizione convogli, smistamento carrozze (assegnazione nazionale)'])
    freq_list(94, [(163_600_000, 164_700_000)], 'utility-aziende', 'ENEL - canale di lavoro', skip_until=r'Porre il range',
              section='ENEL', entity='ENEL', note=['Assegnazione provinciale per bacino di utenza'])
    CTX['section'] = 'AGIP-SNAM'
    for l in page_lines(95, r'CANALI\s+FREQUENZE'):
        toks, masked = find_freqs(l)
        if toks:
            ch = masked[:toks[0].start].strip()
            ch = ch.zfill(2) if ch.isdigit() else ch
            emit(95, toks[0].hz, [(172_000_000, 172_700_000)], f'AGIP-SNAM - canale {ch}', 'utility-aziende', entity='ENI', channel=ch,
                 note=['Assegnazione locale'], section='AGIP-SNAM')
    CTX['section'] = 'RAI'
    for l in page_lines(96, r'RIPRESE SQUADRE'):
        toks, masked = find_freqs(l)
        for i, t in enumerate(toks):
            end = toks[i + 1].start if i + 1 < len(toks) else len(l)
            m = re.search(r'canale\s+(\w+)', l[t.end:end], re.I)
            ch = m.group(1).upper() if m else ''
            vhf = t.hz < 300_000_000
            emit(96, t.hz, [(223_000_000, 232_000_000), (469_000_000, 470_100_000)],
                 f'RAI - {"riprese squadre esterne" if vhf else "radiomicrofono"}, canale {ch}'.strip(', '), 'broadcast-regia',
                 entity='RAI', channel=ch, section='RAI')
    freq_list(97, [(154_000_000, 163_000_000)], 'utility-eventi', 'Federazione Ciclistica Italiana - radio corsa', skip_until=r'RADIO CORSA',
              stop=r'Link Master', section='Ciclismo', entity='Federazione Ciclistica Italiana',
              note=['Servizi di corse professionistiche (partenza, traguardo, motociclisti)'])
    for l in page_lines(97):
        m = re.search(r'Link Master UHF\s+(\d{3}[.,]\d{3})', l)
        if m:
            hz = find_freqs(m.group(1))[0][0].hz
            emit(97, hz, [(431_000_000, 433_000_000)], 'Federazione Ciclistica Italiana - link master UHF', 'utility-eventi',
                 entity='Federazione Ciclistica Italiana', note=[f'Link UHF abbinato alla frequenza VHF {l.split()[0]} MHz'], section='Ciclismo')


def s_civili():
    CTX['section'] = 'Civili 43 MHz'
    punto = ''
    desc = {}
    cur = None
    for p in (98, 99):
        for l in page_lines(p):
            m = re.search(r'PUNTO\s+(\d)', l)
            if m:
                punto, cur = m.group(1), m.group(1)
                desc[cur] = ''
                continue
            m = re.search(r'canale\s+(\d{2})\s+(.+)$', l)
            if m:
                toks = find_freqs(m.group(2))[0]
                if toks:
                    emit(p, toks[0].hz, [(43_300_000, 43_600_000)], f'Civili 43 MHz - canale {m.group(1)}', 'civili-43', channel=m.group(1),
                         note=[f'Punto {punto}: ' + desc.get(punto, '').strip()] if punto else [], section='Civili 43 MHz')
                cur = None
            elif cur and l.strip() and not re.search(r'BANDA|SERVIZI|L.ASSEGNAZIONE|PER OGNI', l):
                desc[cur] += ' ' + l.strip()


def s_lpd():
    CTX['section'] = 'LPD'
    seen = set()
    for l in page_lines(100, r'CANALE\s+FREQUENZA'):
        for t in find_freqs(l)[0]:
            if t.hz in seen or not (433_075_000 <= t.hz <= 434_775_000):
                continue
            seen.add(t.hz)
            ch = (t.hz - 433_075_000) // 25_000 + 1
            emit(100, t.hz, [(433_075_000, 434_775_000)], f'LPD 433 MHz - canale {ch:02d}', 'lpd', 'NFM', channel=f'{ch:02d}',
                 note=['Uso libero a breve distanza'], section='LPD')


def s_satelliti():
    CTX['section'] = 'Satelliti'
    name = ''
    nation = ''
    for l in page_lines(101, r'SATELLITE\s+FREQUENZE'):
        toks, masked = find_freqs(l)
        if not toks:
            continue
        pre = masked[:toks[0].start].strip()
        if pre:
            name = re.sub(r'(?i)^nooa', 'NOAA', pre)
        nat = l[toks[-1].end:].strip()
        if nat:
            nation = nat
        for t in toks:
            emit(101, t.hz, [(137_000_000, 138_500_000)], f'Satellite meteorologico {name}', 'utility-telemetria', 'ND',
                 note=[f'Nazione: {nation}'] if nation else [], section='Satelliti')


def s_sanmarino():
    CTX['section'] = 'San Marino'
    SM = {'emergenza sanitaria': 'emergenza-118', 'gendarmeria': 'utility-forze-ordine', 'ospedale': 'emergenza-118',
          'polizia municipale': 'utility-polizia-locale', 'pronto soccorso': 'emergenza-118'}
    for l in page_lines(102, r'SERVIZIO\s+FREQUENZA'):
        toks, masked = find_freqs(l)
        if toks:
            name = masked[:toks[0].start].strip()
            svc = next((v for k, v in SM.items() if name.lower().startswith(k)), 'utility-pa')
            for t in toks:
                emit(102, t.hz, [(150_000_000, 470_000_000)], f'San Marino - {name}', svc, area='Repubblica di San Marino',
                     note=['Servizi disponibili al momento dell\'impaginazione del manuale'], section='San Marino')


# ---------- aeronautica
SVC_KEYS = r'Torre|Radio|Radar|Controllo|Acars|Soccorso|Informazion|Centro|Inserimento|societ|Eliporto|Scuola|Alitalia|Meridiana|Geasar|Air |Atis|Aviazione|Klm|Iberia|Sabena|Lufthansa|Fiat|Alenia|Catania direttore|Altre compagnie|Ente|Volmet'


def aero_class(text, company):
    t = text.lower()
    if 'acars' in t:
        return 'aero-servizi', 'ACARS'
    if t.startswith('torre') or 'controllo di terra' in t:
        return 'aero-torre', 'AM'
    if 'avvicinamento' in t or t.startswith('radar') or 'inserimento radar' in t or 'direttore' in t:
        return 'aero-avvicinamento', 'AM'
    if 'informazioni di volo' in t or 'informazioni' in t or t.startswith('atis') or 'volmet' in t:
        return 'aero-informazioni', 'AM'
    if t.startswith('radio') or 'scuola' in t:
        return 'aero-aviazione-generale', 'AM'
    return 'aero-servizi', 'AM'


def split_loc_svc(text):
    m = re.match(rf'^(?P<loc>[A-ZÀ-Ý][\w\'’ ]*?)\s+(?P<svc>(?:{SVC_KEYS}).*)$', text)
    if m and not re.match(rf'^(?:{SVC_KEYS})', text):
        return m.group('loc'), m.group('svc')
    return None, text


def s_aero_header():
    CTX['section'] = 'Aeronautica civile'
    ctx = None
    for l in page_lines(105):
        if re.search(r'VOLMET', l):
            ctx = 'volmet'
        elif re.search(r'F\.I\.S\.', l):
            ctx = 'fis'
        elif re.search(r'ENTI REGIONALI', l):
            ctx = 'erc'
        elif re.search(r'S\.A\.R\.', l):
            ctx = 'sar'
        elif re.search(r'AIR to AIR', l, re.I):
            ctx = 'a2a'
        toks, masked = find_freqs(l)
        if not toks:
            continue
        prev_end, name = 0, ''
        for t in toks:
            nm = masked[prev_end:t.start].strip(' -,')
            if nm:
                name = nm
            prev_end = t.end
            if ctx == 'volmet':
                emit(105, t.hz, [(118_000_000, 137_000_000)], f'VOLMET - {name}', 'aero-informazioni', 'AM', place=name, note=['Servizi meteorologici di volo'], section='Aeronautica civile')
            elif ctx == 'fis':
                emit(105, t.hz, [(118_000_000, 137_000_000)], f'Servizio informazioni di volo (FIS) - {name}', 'aero-informazioni', 'AM', place=name, section='Aeronautica civile')
            elif ctx == 'erc':
                emit(105, t.hz, [(118_000_000, 137_000_000)], f'Ente regionale controllo traffico aereo - {name}', 'aero-avvicinamento', 'AM', place=name, section='Aeronautica civile')
            elif ctx == 'sar':
                emit(105, t.hz, [(118_000_000, 137_000_000)], f'Soccorso aereo (SAR) - {name}', 'aero-servizi', 'AM', place=name,
                     note=['Per scopi di sicurezza con stazioni mobili marittime'], section='Aeronautica civile')
            elif ctx == 'a2a':
                lab = 'Aerei Ministero degli Interni' if 'INTERNI' in l else ('Emergenza internazionale' if 'EMERGENZA' in l else 'Air to air')
                emit(105, t.hz, [(118_000_000, 137_000_000)], lab, 'aeronautico', 'AM', section='Aeronautica civile')


def s_aeroporti():
    CTX['section'] = 'Aeroporti'
    loc, company = '', False
    for p in range(106, 114):
        lines = page_lines(p, r'LOCALIT.*SERVIZIO' if p == 106 else None)
        withf = [l for l in lines if find_freqs(l)[0]]
        base = min((len(l) - len(l.lstrip()) for l in withf), default=0)
        for l in lines:
            ind = len(l) - len(l.lstrip())
            toks, masked = find_freqs(l)
            pre = l[:toks[0].start] if toks else l
            parts = parts_of(pre)
            if re.search(r'COMPAGNIE AEREE', l):
                company = True
                continue
            if not toks:
                if ind <= base + 3 and len(parts) >= 2 and parts[0][:1].isupper():
                    loc, company = parts[0], False
                continue
            svc_text = None
            if len(parts) >= 2 and (ind <= base + 3 or len(parts[0]) < 28):
                loc, svc_text = parts[0], parts[1]
                company = False
            elif len(parts) == 1:
                l2, s2 = split_loc_svc(parts[0])
                if l2:
                    loc, svc_text, company = l2, s2, False
                else:
                    svc_text = parts[0]
            elif len(parts) >= 3:
                svc_text = parts[-1]
            if not svc_text:
                continue
            note = re.findall(r'\((.*?)\)', svc_text)
            svc_clean = re.sub(r'\(.*?\)', '', svc_text).replace('"', '').replace('“', '').replace('”', '').strip(" .’'")
            slug, mode = aero_class(svc_clean, company)
            if company and slug == 'aero-servizi':
                slug = 'aero-servizi'
            place = re.sub(r'\(.*?\)', '', loc).strip()
            for t in toks:
                emit(p, t.hz, [(108_000_000, 143_000_000)], f'{svc_clean} - {place}', slug, mode, place=place, area=prov_code(place) or '',
                     entity=svc_clean if company else '', note=note + note_texts(t) + (['Compagnia aerea'] if company else []), section='Aeroporti')


def s_vor():
    CTX['section'] = 'VOR'
    for p in (114, 115):
        for l in page_lines(p, r'LOCALITA.*NOMINATIVO' if p == 114 else None):
            toks, masked = find_freqs(l)
            if not toks:
                continue
            parts = parts_of(l[:toks[0].start])
            if len(parts) < 2:
                continue
            loc, ident = parts[0], parts[1]
            emit(p, toks[0].hz, [(108_000_000, 118_000_000)], f'VOR {loc}', 'aero-radioassistenze', 'AM', place=loc, area=prov_code(loc) or '',
                 callsign=ident.upper(), note=['Radiofaro omnidirezionale VHF (VOR)'], section='VOR')


def s_aeromil():
    CTX['section'] = 'Aeronautica militare'
    loc, band = '', ''
    for p in (116, 117, 118):
        lines = page_lines(p, r'AEROPORTI\s+BANDA\s+FREQUENZE' if p == 116 else None)
        group = 'Aeronautica Militare'
        for l in lines:
            if re.search(r'AERONAUTICA LEGGERA ESERCITO', l):
                group = 'Aeronautica Leggera dell\'Esercito'
                loc = ''
                continue
            if re.search(r'SERVIZI DI TERRA', l):
                break
            toks, masked = find_freqs(l)
            if not toks:
                continue
            pre = l[:toks[0].start]
            parts = parts_of(pre)
            if group.startswith('Aeronautica Leggera') or p == 118:
                if len(parts) >= 2:
                    loc, band = parts[0], parts[1]
                    for t in toks:
                        emit(p, t.hz, [(30_000_000, 400_000_000)], f'{group} - {loc}', 'aero-militare', 'AM', place='',
                             note=note_texts(t), section='Aeronautica militare')
                continue
            if len(parts) >= 2:
                loc, band = parts[0], parts[1]
            elif len(parts) == 1 and parts[0] in ('UHF', 'VHF'):
                band = parts[0]
            elif parts:
                continue
            nato = loc.lower().startswith('tutti')
            for t in toks:
                emit(p, t.hz, [(118_000_000, 400_000_000)], 'Traffico NATO' if nato else f'Aeronautica Militare - {loc} ({band})', 'aero-militare', 'AM',
                     place='' if nato else loc, area=('' if nato else prov_code(loc) or ''),
                     note=['Frequenze del traffico internazionale NATO'] if nato else [f'Banda {band}'] + note_texts(t), section='Aeronautica militare')


# ---------- marina
def s_marina_civile():
    CTX['section'] = 'Marina civile'
    for l in page_lines(119, r'FREQUENZE ADIBITE'):
        m = re.match(r'^\s*(\d{3}[.,]\d{3})\s*\(ch\.?\s*(\d+)\)\s*-?\s*(.*)$', l)
        if m:
            hz = find_freqs(m.group(1))[0][0].hz
            emit(119, hz, MARINE_OK, f'Canale nautico {int(m.group(2))}', 'mare-canali', channel=m.group(2), note=[m.group(3)] if m.group(3) else [], section='Marina civile')
    for l in page_lines(119, r'CAPITANERIE DI PORTO'):
        pass
    group = ('Capitaneria di Porto', 'mare-guardia-costiera')
    for p in (119, 120, 121):
        for l in page_lines(p, r'CAPITANERIE DI PORTO' if p == 119 else None):
            if re.search(r'UFFICI.*LOCAMARE|LOCAMARE', l):
                group = ('Ufficio circondariale marittimo', 'mare-guardia-costiera')
                continue
            if re.search(r'DELEGAZIONI.*DELEMARE|DELEMARE', l):
                group = ('Delegazione di spiaggia', 'mare-guardia-costiera')
                continue
            if re.search(r'CAPITANERIE|COMPAMARE', l):
                group = ('Capitaneria di Porto', 'mare-guardia-costiera')
                continue
            toks, masked = find_freqs(l)
            if not toks:
                continue
            parts = parts_of(l[:toks[0].start])
            ch_txt = parts[-1] if parts else ''
            if len(parts) >= 2:
                global LASTLOC
                LASTLOC = parts[0]
            loc = LASTLOC
            orario = re.sub(r'\s+', ' ', l[toks[-1].end:].strip(' -'))
            chm = re.match(r'^(\d{1,2})$', ch_txt)
            for t in toks:
                hz, fixnote = t.hz, None
                if chm:
                    hz, fixnote = marine_fix(hz, int(chm.group(1)))
                emit(p, hz, MARINE_OK, f'{group[0]} - {loc}', group[1], place=loc, area=prov_code(loc) or '', channel=ch_txt if chm else '',
                     note=[n for n in [fixnote, f'Ascolto: {orario}' if orario else ''] if n] + ([f'Canali {ch_txt}'] if ch_txt and not chm else []),
                     section='Marina civile')


LASTLOC = ''


def s_meteomar():
    global LASTLOC
    CTX['section'] = 'Meteomar'
    for l in page_lines(122, r'NOMINATIVI STAZIONI'):
        toks, masked = find_freqs(l)
        if not toks:
            continue
        parts = parts_of(l[:toks[0].start])
        if len(parts) >= 2:
            LASTLOC = re.sub(r'\s*\.\s*$', '', parts[0])
        ch = parts[-1] if parts else ''
        chm = re.match(r'^(\d{1,2})$', ch)
        t = toks[0]
        hz, fixnote = t.hz, None
        if chm:
            hz, fixnote = marine_fix(hz, int(chm.group(1)))
        orari = ' '.join(re.findall(r'\d\d[.:]\d\d', l[t.end:]))
        emit(122, hz, [(161_800_000, 162_050_000)], f'Meteomar - {LASTLOC}', 'mare-meteo', place=LASTLOC, area=prov_code(LASTLOC) or '',
             channel=ch if chm else '', note=[n for n in [fixnote, f'Orari di diffusione (UTC): {orari}' if orari else '',
                                                           'Avvisi ai naviganti, avvisi di tempesta e bollettino Meteomar'] if n], section='Meteomar')


def s_piloti():
    global LASTLOC
    CTX['section'] = 'Piloti'
    for l in page_lines(123, r'\(ch\.?18\)'):
        pass
    for l in page_lines(123):
        for m in re.finditer(r'\(ch\.?\s*(\d+)\)\s*(\d{3}[.,]\d{3})', l):
            t = find_freqs(m.group(2))[0][0]
            emit(123, t.hz, MARINE_OK, f'Capitanerie di porto - controllo traffico portuale, canale {m.group(1)}', 'mare-porti',
                 channel=m.group(1), section='Piloti')
    for p in (123, 124):
        for l in page_lines(p, r'NOMINATIVI STAZIONI' if p == 123 else None):
            toks, masked = find_freqs(l)
            if not toks or re.search(r'\(ch', l):
                continue
            parts = parts_of(l[:toks[0].start])
            if len(parts) >= 2:
                LASTLOC = parts[0]
            ch = parts[-1] if parts else ''
            for i, t in enumerate(toks[:2]):
                kind = 'piloti' if i == 0 else 'avvisatore'
                emit(p, t.hz, MARINE_OK, f'Corporazione piloti - {LASTLOC}' + (' (avvisatore)' if i else ''), 'mare-porti', place=LASTLOC,
                     area=prov_code(LASTLOC) or '', channel=ch if i == 0 else '', note=[f'Canali {ch}', f'Frequenza {kind}'], section='Piloti')


def s_marina_militare():
    CTX['section'] = 'Marina militare'
    cols = None
    for l in page_lines(125, r'ALFA\s+CHARLIE'):
        pass
    lines = [clean_line(x) for x in PAGES[125]]
    for l in lines:
        if re.search(r'ALFA\s+CHARLIE\s+PORTUALI', l):
            cols = [(m.group(0), m.start()) for m in re.finditer(r'ALFA|CHARLIE|PORTUALI', l)]
            continue
        if not cols:
            continue
        for t in find_freqs(l)[0]:
            name = min(cols, key=lambda c: abs(c[1] - t.start))[0]
            emit(125, t.hz, [(155_000_000, 158_000_000)], f'Marina Militare - gruppo {name.capitalize()}', 'utility-militare',
                 note=['Uso previa chiamata sul canale 16 (156.800 MHz)'], section='Marina militare')


SECTIONS = [s_vvf, s_polstato, s_gdf, s_carabinieri, s_vigilanza, s_polstradale, s_autostrade_pol, s_autostrade_caselli,
            s_polmunicipale, s_aib, s_ambulanze, s_croci, s_sanitaria, s_elisoccorso, s_alpino, s_rifugi, s_guide, s_funivie, s_pool,
            s_civili, s_lpd, s_satelliti, s_sanmarino, s_aero_header, s_aeroporti, s_vor, s_aeromil, s_marina_civile,
            s_meteomar, s_piloti, s_marina_militare]


def write_outputs():
    cols = list(RECORDS[0].keys())
    with open(os.path.join(OUT, 'manuale_frequenze_v2.csv'), 'w', newline='', encoding='utf-8-sig') as f:
        w = csv.DictWriter(f, fieldnames=cols, delimiter=';', quoting=csv.QUOTE_MINIMAL)
        w.writeheader()
        w.writerows(RECORDS)
    with open(os.path.join(OUT, 'frequenze_da_rivedere.csv'), 'w', newline='', encoding='utf-8-sig') as f:
        w = csv.writer(f, delimiter=';')
        w.writerow(['pagina', 'sezione', 'valore_letto', 'descrizione', 'area', 'motivo'])
        w.writerows(REVIEW)


if __name__ == '__main__':
    for fn in SECTIONS:
        n0, r0 = len(RECORDS), len(REVIEW)
        try:
            fn()
        except Exception as e:  # una sezione non deve fermare le altre
            print('ERRORE in', fn.__name__, repr(e))
            import traceback; traceback.print_exc()
        print(f'{fn.__name__:26} record {len(RECORDS) - n0:5}   da rivedere {len(REVIEW) - r0:4}')
    write_outputs()
    print('TOTALE record', len(RECORDS), ' da rivedere', len(REVIEW))
