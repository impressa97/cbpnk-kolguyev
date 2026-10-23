"""Build the Kolguyev map tiles for /reforger from a local Arma Reforger install.

The style is schematic, like the in-game map: land and sea from the terrain heights, light
contours, then the game's own vector layers from worlds/Cain/Cain.topo (forests, water,
roads by class, buildings). Nothing is fetched from anywhere; the output is served by the site.

    python -I scripts/reforger/build-kolguyev-map.py "A:/Steam/steamapps/common/Arma Reforger"

Writes public/geodata/kolguyev/:
  {z}/{x}/{y}.webp   raster base (sea, land, contours, forests, water), z 0..5, 2^z tiles
                     across, 256 px, y from the north edge; z5 is 1.5625 m per pixel.
  roads.geojson      road lines with their class, for MapLibre to draw crisp at any zoom
  buildings.geojson  building footprints
The tile pyramid is laid out exactly on the Web Mercator pixel grid (our z0 is MapLibre's
tile 0/0/0), so metres convert to LngLat with lnglat() below and the same in
resources/js/features/reforger-war/map/projection.ts.

Terrain: worlds/Cain/Terrain/Terrain.terr HEAD holds the grid (6401 x 6401, 2 m) and the
height scale (raw * 0.03125 - 204.78). Each of the 50 x 50 .ttile files holds a 129 x 129 u16
HGHT block; neighbours share an edge.
Topo: sections ROAD, PWLN, BULD, AREA, WATR. Coordinates are float (x, z) pairs, unaligned.
ROAD records are <class u8><count u32><count points>; BULD footprints are 4 points; AREA is one
pool of outline points, split where consecutive points jump.
"""

import json
import math
import os
import struct
import sys

import numpy as np
from PIL import Image, ImageDraw

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from reforger_pak import pak_index, read  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, 'public', 'geodata', 'kolguyev')
WORLD = 12800.0
MAX_Z = 5
PX = 256 * 2 ** MAX_Z  # 8192
S = PX / WORLD

PAPER = (238, 235, 224)
SEA_SHALLOW = np.array([196, 218, 228], dtype=np.float32)
SEA_DEEP = np.array([150, 186, 208], dtype=np.float32)
FOREST = (200, 220, 178)
WATER = (120, 170, 214)
CONTOUR = (205, 190, 160)
CONTOUR_MAJOR = (180, 160, 125)


def heights(files):
    head = read(files, 'worlds/Cain/Terrain/Terrain.terr')
    at = head.index(b'HEAD') + 8
    size_x, size_z = struct.unpack_from('<II', head, at)
    cell, scale, offset = struct.unpack_from('<fff', head, at + 16)
    tiles = (size_x - 1) // 128
    grid = np.zeros((size_z, size_x), dtype=np.float32)
    for n in range(tiles * tiles):
        raw = read(files, f'worlds/Cain/Terrain/.Data/Terrain_{n}.ttile')
        h = raw.index(b'HGHT') + 8
        block = np.frombuffer(raw, dtype='<u2', count=129 * 129, offset=h).reshape(129, 129)
        tx, tz = n % tiles, n // tiles
        grid[tz * 128:tz * 128 + 129, tx * 128:tx * 128 + 129] = block
    print(f'terrain {size_x}x{size_z}, {cell} m, {tiles}x{tiles} tiles')
    # Row 0 is the south edge: the game's own location elevations match the grid this way up.
    return grid * scale + offset


def topo_sections(blob):
    sections, p = {}, 0x14
    while p + 20 <= len(blob):
        tag = blob[p:p + 4]
        if not tag.isalpha():
            break
        _, a, b, _ = struct.unpack_from('<IIII', blob, p + 4)
        sections[tag.decode()] = (p + 20, p + 20 + a)
        p = p + 20 + a if blob[p + 20 + a:p + 24 + a].isalpha() else p + 20 + b
    return sections


FOREST_RES = 4.0  # metres per forest-mask cell


def tree_points(blob, sec):
    """AREA is no set of polygons: it is every tree on the island. Head (60 bytes): u32 class
    count (6), per-class zeros, u32 point count at +56; then that many (x, z) float pairs."""
    s, e = sec['AREA']
    count = struct.unpack_from('<I', blob, s + 56)[0]
    assert s + 60 + count * 8 == e, 'AREA is not a plain tree list'
    pts = np.frombuffer(blob, dtype='<f4', count=count * 2, offset=s + 60).reshape(-1, 2).astype(np.float64)
    pts[:, 1] = WORLD - pts[:, 1]  # topo Z grows southward
    return pts


def forest_mask(blob, sec):
    """Woods on a 4 m grid, row 0 south: each tree a crown, stands merged, lone trees dropped."""
    from scipy import ndimage

    n = int(WORLD / FOREST_RES)
    trees = tree_points(blob, sec)
    grid = np.zeros((n, n), dtype=bool)
    ix = np.clip((trees[:, 0] / FOREST_RES).astype(int), 0, n - 1)
    iz = np.clip((trees[:, 1] / FOREST_RES).astype(int), 0, n - 1)
    grid[iz, ix] = True
    disk = lambda r: np.hypot(*np.mgrid[-r:r + 1, -r:r + 1]) <= r  # noqa: E731
    grid = ndimage.binary_dilation(grid, structure=disk(2))   # crowns, ~8 m
    grid = ndimage.binary_closing(grid, structure=disk(3))    # gaps inside a stand
    grid = ndimage.binary_opening(grid, structure=disk(4))    # lone trees and thin rows out
    print(f'trees: {len(trees)}, forest covers {grid.mean():.1%} of the map square')
    return grid


def topo_world(x, z):
    """Cain.topo stores points like image rows, Z growing southward. World Z grows northward:
    checked against the height grid, where every road then lies on dry ground and every named
    place sits at its game elevation."""
    return (x, WORLD - z)


def point_runs(blob, start, end):
    """Runs of consecutive (x, z) float pairs inside the island bounds, in world metres."""
    ok = lambda v: 1.0 < v < 12900.0  # noqa: E731
    i, runs = start, []
    while i < end - 8:
        x, z = struct.unpack_from('<ff', blob, i)
        if ok(x) and ok(z):
            pts, j = [], i
            while j < end - 8:
                x, z = struct.unpack_from('<ff', blob, j)
                if not (ok(x) and ok(z)):
                    break
                pts.append(topo_world(x, z))
                j += 8
            if len(pts) >= 2:
                runs.append((i, pts))
                i = j
                continue
        i += 1
    return runs


def counted(blob, i, pts):
    lines, pos = [], i
    while pts:
        count = struct.unpack_from('<I', blob, pos - 4)[0]
        cls = blob[pos - 5]
        if count < 2 or count > len(pts):
            lines.append((cls, pts))
            break
        lines.append((cls, pts[:count]))
        pts = pts[count:]
        pos += count * 8 + 5
    return lines


def rings(pts, gap=60.0):
    out, cur = [], [pts[0]]
    for a, b in zip(pts, pts[1:]):
        if math.dist(a, b) > gap:
            out.append(cur)
            cur = []
        cur.append(b)
    out.append(cur)
    return [r for r in out if len(r) >= 3]


def xy(pt):
    return (pt[0] * S, (WORLD - pt[1]) * S)


def lnglat(pt):
    """Metres to LngLat on the Web Mercator pixel grid the tiles use."""
    u = pt[0] / WORLD
    v = (WORLD - pt[1]) / WORLD
    return [round(u * 360.0 - 180.0, 4), round(math.degrees(math.atan(math.sinh(math.pi * (1 - 2 * v)))), 4)]


def simplify(pts, tol=1.5):
    """Douglas-Peucker in metres (iterative, numpy per span)."""
    if len(pts) < 3:
        return list(pts)
    arr = np.asarray(pts, dtype=np.float64)
    keep = np.zeros(len(arr), dtype=bool)
    keep[0] = keep[-1] = True
    stack = [(0, len(arr) - 1)]
    while stack:
        i, j = stack.pop()
        if j - i < 2:
            continue
        a, b = arr[i], arr[j]
        d = b - a
        norm = math.hypot(d[0], d[1])
        seg = arr[i + 1:j]
        if norm < 1e-9:
            dist = np.hypot(seg[:, 0] - a[0], seg[:, 1] - a[1])
        else:
            dist = np.abs(d[1] * (seg[:, 0] - a[0]) - d[0] * (seg[:, 1] - a[1])) / norm
        k = int(np.argmax(dist))
        if dist[k] > tol:
            m = i + 1 + k
            keep[m] = True
            stack.append((i, m))
            stack.append((m, j))
    return [tuple(p) for p in arr[keep]]


def base_layer(h):
    """Paper land with a soft hillshade, sea shaded by depth. Contours, coast, forest and water
    are vectors (terrain.geojson, landcover.geojson) so they stay sharp at every zoom."""
    hm = Image.fromarray(np.flipud(h)).resize((PX, PX), Image.BILINEAR)  # image rows run north to south
    z = np.asarray(hm, dtype=np.float32)
    rgb = np.empty((PX, PX, 3), dtype=np.float32)
    rgb[:] = PAPER
    sea = z < 0
    depth = np.clip(-z / 40.0, 0, 1)[..., None]
    rgb[sea] = (SEA_SHALLOW * (1 - depth) + SEA_DEEP * depth)[sea]

    # Hillshade, light from the north-west, gentle: it reads the ground without fighting the symbols.
    px = WORLD / PX
    gy, gx = np.gradient(z, px)
    slope = np.arctan(np.hypot(gx, gy) * 1.5)
    aspect = np.arctan2(-gy, gx)
    az, alt = math.radians(315), math.radians(45)
    shade = np.sin(alt) * np.cos(slope) + np.cos(alt) * np.sin(slope) * np.cos(az - aspect)
    light = np.clip(0.55 + 0.6 * (shade - np.sin(alt)), 0.78, 1.06)[..., None]
    land = ~sea
    rgb[land] = np.clip(rgb * light, 0, 255)[land]
    return Image.fromarray(rgb.astype(np.uint8), 'RGB')


def write_terrain(h):
    """Contours every 10 m (major every 50 m) and the coastline, from the 2 m height grid."""
    import contourpy

    rows, cols = h.shape
    xs = np.arange(cols, dtype=np.float64) * (WORLD / (cols - 1))
    zs = np.arange(rows, dtype=np.float64) * (WORLD / (rows - 1))  # row 0 south
    gen = contourpy.contour_generator(xs, zs, h, line_type='Separate')
    features, top = [], int(np.nanmax(h))
    for level in [0] + list(range(10, top + 1, 10)):
        kind = 'coast' if level == 0 else 'contour'
        tol = 2.0 if level == 0 else 3.0
        for line in gen.lines(level):
            if len(line) < 4:
                continue
            pts = simplify([(float(x), float(zz)) for x, zz in line], tol)
            if len(pts) < 2 or (kind == 'contour' and math.dist(pts[0], pts[-1]) < 1 and len(pts) < 5):
                continue
            features.append({
                'type': 'Feature',
                'properties': {'k': kind, 'e': level, 'major': level % 50 == 0},
                'geometry': {'type': 'LineString', 'coordinates': [lnglat(p) for p in pts]},
            })
    with open(os.path.join(OUT, 'terrain.geojson'), 'w') as fh:
        json.dump({'type': 'FeatureCollection', 'features': features}, fh, separators=(',', ':'))
    print(f'terrain: {len(features)} lines up to {top} m')


def write_landcover(blob):
    """Forests (topo AREA) and water (topo WATR) as polygons and streams."""
    import contourpy
    from scipy import ndimage

    sec = topo_sections(blob)
    features = []

    # Forest edges traced from the tree mask, smoothed a little, with clearings as holes.
    grid = forest_mask(blob, sec)
    n = grid.shape[0]
    soft = ndimage.gaussian_filter(grid.astype(np.float32), 1.0)
    axis = (np.arange(n, dtype=np.float64) + 0.5) * FOREST_RES
    gen = contourpy.contour_generator(axis, axis, soft, fill_type='OuterOffset')
    polys, offsets = gen.filled(0.5, 2.0)
    for pts, offs in zip(polys, offsets):
        rings_out = []
        for a, b in zip(offs[:-1], offs[1:]):
            ring = simplify([(float(x), float(z)) for x, z in pts[a:b]], 2.0)
            if len(ring) >= 4:
                rings_out.append([lnglat(p) for p in ring])
        if rings_out:
            features.append({'type': 'Feature', 'properties': {'k': 'forest'}, 'geometry': {'type': 'Polygon', 'coordinates': rings_out}})

    s, e = sec['WATR']
    for _, pts in point_runs(blob, s, e):
        if len(pts) < 3:
            continue
        pts = simplify(pts, 1.0)
        # WATR records carry heights and a layout not decoded yet; open runs read this way are
        # not real streams (they cut straight across the island), so only closed ponds are kept.
        if math.dist(pts[0], pts[-1]) < 30 and len(pts) >= 3:
            features.append({'type': 'Feature', 'properties': {'k': 'water'},
                             'geometry': {'type': 'Polygon', 'coordinates': [[lnglat(p) for p in pts + pts[:1]]]}})
    with open(os.path.join(OUT, 'landcover.geojson'), 'w') as fh:
        json.dump({'type': 'FeatureCollection', 'features': features}, fh, separators=(',', ':'))
    print(f'landcover: {len(features)} shapes')


def draw_topo(img, blob):
    """Forest tint stays in the raster too, so the overview reads at a glance; sharp edges
    come from landcover.geojson on top."""
    sec = topo_sections(blob)

    woods = forest_mask(blob, sec)
    forest = Image.fromarray(np.flipud(woods).astype(np.uint8) * 110).resize(img.size, Image.BILINEAR)
    img.paste(Image.new('RGB', img.size, FOREST), mask=forest)

    # Roads and buildings stay vector: MapLibre draws them crisp at every zoom.
    s, e = sec['ROAD']
    roads = road_records(blob, s, e)
    s, e = sec['BULD']
    buildings = [pts[k:k + 4] for _, pts in point_runs(blob, s, e) for k in range(0, len(pts) - 3, 4)]
    return roads, buildings


def road_records(blob, start, end):
    """ROAD section, full detail block (a simplified LOD block of the same roads follows it).

    Head (40 bytes): u32 records per class for classes 0..5, two u32 zero, u32 class count (6),
    u32 total records. Record: u8 class, u32 point count, points (a strip of quads: the road
    surface edges), u32 link count, link count * u32 ids of the roads it joins at junctions.
    """
    classes, total = struct.unpack_from('<II', blob, start + 32)
    per_class = struct.unpack_from(f'<{classes}I', blob, start)
    assert sum(per_class) == total, 'road head counts do not add up'
    pos = start + 40
    roads = []
    for index in range(total):
        cls = blob[pos]
        count = struct.unpack_from('<I', blob, pos + 1)[0]
        flat = struct.unpack_from(f'<{count * 2}f', blob, pos + 5)
        pos += 5 + count * 8
        links_count = struct.unpack_from('<I', blob, pos)[0]
        links = list(struct.unpack_from(f'<{links_count}I', blob, pos + 4))
        pos += 4 + links_count * 4
        strip = [topo_world(x, z) for x, z in zip(flat[0::2], flat[1::2])]
        roads.append({'id': index, 'class': cls, 'strip': strip, 'links': links})
    assert pos <= end, 'road records ran past the section'
    print('road classes', dict(enumerate(per_class)))
    return roads


def centreline(strip):
    """A road record is a strip of quads (two edge points, then the next two, shared edges
    repeated). Its centreline is the midpoint of each cross edge; its width is that edge."""
    if len(strip) < 4 or len(strip) % 4:
        return strip, []
    mid = lambda a, b: ((a[0] + b[0]) / 2, (a[1] + b[1]) / 2)  # noqa: E731
    quads = [strip[k:k + 4] for k in range(0, len(strip), 4)]
    line = [mid(quads[0][0], quads[0][1])] + [mid(q[2], q[3]) for q in quads]
    widths = [math.dist(q[0], q[1]) for q in quads] + [math.dist(quads[-1][2], quads[-1][3])]
    return line, widths


def write_vectors(roads, buildings):
    os.makedirs(OUT, exist_ok=True)
    features = []
    for road in roads:
        line, widths = centreline(road['strip'])
        # Width can change along a road: cut it where the surface narrows or widens, so no
        # stretch is drawn wider or narrower than it is in game.
        start = 0
        for k in range(1, len(line) + 1):
            if k < len(line) and abs(widths[k] - widths[start]) < 0.25:
                continue
            part = line[max(0, start - 1 if start else 0):k] if k - start >= 1 else []
            if len(part) >= 2:
                features.append({
                    'type': 'Feature',
                    'properties': {'id': road['id'], 'c': road['class'], 'w': round(widths[start], 2), 'links': road['links']},
                    'geometry': {'type': 'LineString', 'coordinates': [lnglat(p) for p in simplify(part)]},
                })
            start = k
    with open(os.path.join(OUT, 'roads.geojson'), 'w') as fh:
        json.dump({'type': 'FeatureCollection', 'features': features}, fh, separators=(',', ':'))
    features_roads = features
    features = [
        {'type': 'Feature', 'properties': {}, 'geometry': {'type': 'Polygon', 'coordinates': [[lnglat(p) for p in fp + fp[:1]]]}}
        for fp in buildings
    ]
    with open(os.path.join(OUT, 'buildings.geojson'), 'w') as fh:
        json.dump({'type': 'FeatureCollection', 'features': features}, fh, separators=(',', ':'))
    print(f'vectors: {len(roads)} roads in {len(features_roads)} pieces, {len(buildings)} buildings')


def write_tiles(img):
    level = img
    for z in range(MAX_Z, -1, -1):
        across = 2 ** z
        if level.size[0] != across * 256:
            level = img.resize((across * 256, across * 256), Image.LANCZOS)
        for x in range(across):
            os.makedirs(os.path.join(OUT, str(z), str(x)), exist_ok=True)
            for y in range(across):
                tile = level.crop((x * 256, y * 256, x * 256 + 256, y * 256 + 256))
                tile.save(os.path.join(OUT, str(z), str(x), f'{y}.webp'), 'WEBP', quality=72, method=6)
        print(f'z{z}: {across * across} tiles')


def main(game):
    files = pak_index(os.path.join(game, 'addons', 'data'))
    h = heights(files)
    img = base_layer(h)
    topo = read(files, 'worlds/Cain/Cain.topo')
    roads, buildings = draw_topo(img, topo)
    write_vectors(roads, buildings)
    write_terrain(h)
    write_landcover(topo)
    write_tiles(img)
    img.resize((1024, 1024), Image.LANCZOS).save(os.path.join(OUT, 'overview.webp'), 'WEBP', quality=80)
    print('done ->', OUT)


if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else r'C:\Program Files (x86)\Steam\steamapps\common\Arma Reforger')
