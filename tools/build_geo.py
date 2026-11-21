#!/usr/bin/env python3
"""
Prepara i dati geografici di ListenIn a partire dai confini amministrativi ISTAT (1 gennaio 2026),
ridistribuiti con licenza CC BY 4.0 dal repository openpolis/guglielmo "geojson-italy".

Produce:
  listenin/assets/data/italia-confini.dat   cartina SVG: regioni e province già proiettate e semplificate
  listenin/app/data/geo_province.json         baricentro di ogni provincia (posizione approssimata dei record)
  listenin/app/data/comuni_centroidi.csv      baricentro di ogni comune (posizione dei record senza coordinate)

Uso:  python3 tools/build_geo.py <cartella con limits_IT_regions/provinces/municipalities.geojson>
Richiede: shapely.
"""
import csv, json, math, sys, pathlib
from shapely.geometry import shape, mapping
from shapely.ops import unary_union

src = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else '/tmp/geo')
root = pathlib.Path(__file__).resolve().parent.parent / 'listenin'

LON0, LAT_TOP, LAT0, K = 6.55, 47.12, 41.9, 62.0
COS = math.cos(math.radians(LAT0))
proj = lambda lon, lat: ((lon - LON0) * COS * K, (LAT_TOP - lat) * K)
W, H = 560, 730

REGION_SLUG = {"Trentino-Alto Adige/Südtirol": 'trentino-alto-adige', "Valle d'Aosta/Vallée d'Aoste": 'valle-d-aosta'}
def slug(name):
    if name in REGION_SLUG: return REGION_SLUG[name]
    import unicodedata, re
    s = unicodedata.normalize('NFKD', name).encode('ascii', 'ignore').decode().lower()
    return re.sub(r'[^a-z0-9]+', '-', s).strip('-')

def path(geom, tol):
    g = geom.simplify(tol, preserve_topology=True)
    polys = [g] if g.geom_type == 'Polygon' else list(g.geoms)
    out, xs, ys = [], [], []
    for p in polys:
        if p.area * K * K * COS < 0.4:      # isolotti minuscoli: invisibili a questa scala
            continue
        for ring in [p.exterior, *p.interiors]:
            pts = [proj(x, y) for x, y in ring.coords[:-1]]
            if len(pts) < 3: continue
            xs += [q[0] for q in pts]; ys += [q[1] for q in pts]
            seg = [f'M{pts[0][0]:.1f} {pts[0][1]:.1f}']
            px, py = round(pts[0][0], 1), round(pts[0][1], 1)
            rel = []
            for x, y in pts[1:]:
                x, y = round(x, 1), round(y, 1)
                dx, dy = round(x - px, 1), round(y - py, 1)
                if dx or dy: rel.append(f'{dx:g} {dy:g}')
                px, py = x, y
            seg.append('l' + ' '.join(rel) + 'z')
            out.append(''.join(seg))
    bbox = [round(min(xs), 1), round(min(ys), 1), round(max(xs) - min(xs), 1), round(max(ys) - min(ys), 1)]
    return ''.join(out), bbox

provs = json.load(open(src / 'limits_IT_provinces.geojson', encoding='utf-8'))['features']
by_reg, province_out, centroids = {}, [], []
for f in provs:
    p = f['properties']; g = shape(f['geometry'])
    d, bbox = path(g, 0.012)
    rp = g.representative_point()
    province_out.append({'code': p['prov_acr'], 'name': p['prov_name'], 'region': slug(p['reg_name']), 'd': d, 'bbox': bbox})
    centroids.append({'code': p['prov_acr'], 'lat': round(rp.y, 5), 'lon': round(rp.x, 5)})
    by_reg.setdefault(p['reg_name'], []).append(g)
regions_out = []
for name, geoms in sorted(by_reg.items()):
    d, bbox = path(unary_union(geoms), 0.012)
    regions_out.append({'slug': slug(name), 'name': name.split('/')[0], 'd': d, 'bbox': bbox})
province_out.sort(key=lambda x: x['code'])

(root / 'assets' / 'data').mkdir(parents=True, exist_ok=True)
json.dump({'viewBox': [0, 0, W, H], 'regions': regions_out, 'provinces': province_out,
           'source': 'Confini amministrativi ISTAT al 1° gennaio 2026 (CC BY 4.0), via openpolis/guglielmo geojson-italy; semplificati per la visualizzazione.'},
          open(root / 'assets' / 'data' / 'italia-confini.dat', 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
json.dump(sorted(centroids, key=lambda x: x['code']), open(root / 'app' / 'data' / 'geo_province.json', 'w'), separators=(',', ':'))

com = json.load(open(src / 'limits_IT_municipalities.geojson', encoding='utf-8'))['features']
rows = []
for f in com:
    p = f['properties']; g = shape(f['geometry'])
    c = g.centroid
    if not g.contains(c): c = g.representative_point()
    rows.append([p['com_istat_code'], p['com_catasto_code'], p['name'], p['prov_acr'], f'{c.y:.5f}', f'{c.x:.5f}'])
with open(root / 'app' / 'data' / 'comuni_centroidi.csv', 'w', encoding='utf-8', newline='') as fh:
    w = csv.writer(fh, delimiter=';'); w.writerow(['istat', 'catasto', 'nome', 'provincia', 'lat', 'lon']); w.writerows(rows)
print(f'regioni {len(regions_out)}, province {len(province_out)}, comuni {len(rows)}')
