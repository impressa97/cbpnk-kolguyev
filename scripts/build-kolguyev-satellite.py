"""Build the Kolguyev satellite layer for /reforger from a local Arma Reforger install.

The terrain's own colour map: every 256 m terrain tile has a supertexture (what the game
draws for the ground from afar), worlds/Cain/Terrain/.Data/Terrain_<n>_supertexture.edds,
256 x 256 px BC7, numbered like the height tiles (n = tz * 50 + tx, row 0 south). Each image
runs south to north, so it is flipped to stand north up. The sea there is bare seabed: it is
tinted with water by depth from the height grid. That colour map is 1 m a pixel and has no
trees (the game draws those as objects), so every tree of the topo (worlds/Cain/Cain.topo)
goes on as a crown with its shadow. Nothing is fetched from anywhere.

    python -I scripts/reforger/build-kolguyev-satellite.py "A:/Steam/steamapps/common/Arma Reforger"

Needs Pillow, numpy and lz4. Writes public/geodata/kolguyev-sat/{z}/{x}/{y}.webp, z 0..6, on
the same tile grid as the schematic map (build-kolguyev-map.py); z6 is 0.78 m a pixel.
"""

import importlib.util
import os
import sys

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from reforger_edds import image  # noqa: E402
from reforger_pak import pak_index, read  # noqa: E402

_spec = importlib.util.spec_from_file_location('kolmap', os.path.join(HERE, 'build-kolguyev-map.py'))
kolmap = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(kolmap)

OUT = os.path.join(os.path.dirname(os.path.dirname(HERE)), 'public', 'geodata', 'kolguyev-sat')
TILES = 50
TILE_PX = 256
MAX_Z = 6
PX = 256 * 2 ** MAX_Z  # 16384
S = PX / kolmap.WORLD  # pixels per metre
SEA = np.array([62, 96, 116], dtype=np.float32)
CROWN_M = 2.6  # crown radius
SHADOW_M = (1.6, 1.4)  # the sun from the north-west: shadows fall south-east


def mosaic(files):
    full = Image.new('RGB', (TILES * TILE_PX, TILES * TILE_PX))
    for n in range(TILES * TILES):
        tx, tz = n % TILES, n // TILES
        tile = image(read(files, f'worlds/Cain/Terrain/.Data/Terrain_{n}_supertexture.edds'))
        full.paste(tile.transpose(Image.FLIP_TOP_BOTTOM), (tx * TILE_PX, (TILES - 1 - tz) * TILE_PX))
    print('supertextures: 2500 tiles')
    big = full.resize((PX, PX), Image.LANCZOS)
    return big.filter(ImageFilter.UnsharpMask(radius=1.6, percent=55, threshold=2))


def water(rgb, h):
    """The seabed under water: more water colour the deeper it is."""
    hm = Image.fromarray(np.flipud(h)).resize((PX, PX), Image.BILINEAR)
    z = np.asarray(hm, dtype=np.float32)
    alpha = (np.clip(0.55 + (-z) / 30.0, 0, 0.92) * (z < 0))[..., None]
    rgb *= 1 - alpha
    rgb += SEA * alpha


def trees(rgb, files):
    """Every tree: a soft shadow to the south-east, then a dark crown over the ground."""
    blob = read(files, 'worlds/Cain/Cain.topo')
    pts = kolmap.tree_points(blob, kolmap.topo_sections(blob))
    shadow = Image.new('L', (PX, PX), 0)
    crown = Image.new('L', (PX, PX), 0)
    ds, dc = ImageDraw.Draw(shadow), ImageDraw.Draw(crown)
    rng = np.random.default_rng(7)
    sizes = CROWN_M * rng.uniform(0.75, 1.3, len(pts)) * S
    for (x, z), r in zip(pts, sizes):
        px, py = x * S, (kolmap.WORLD - z) * S
        sx, sy = px + SHADOW_M[0] * S, py + SHADOW_M[1] * S
        ds.ellipse((sx - r, sy - r, sx + r, sy + r), fill=255)
        dc.ellipse((px - r, py - r, px + r, py + r), fill=255)
    shadow = np.asarray(shadow.filter(ImageFilter.GaussianBlur(1.6)), dtype=np.float32)[..., None] / 255
    crown = np.asarray(crown.filter(ImageFilter.GaussianBlur(0.8)), dtype=np.float32)[..., None] / 255
    rgb *= 1 - 0.45 * shadow
    leaves = rgb * 0.42 + np.array([18, 30, 10], dtype=np.float32)
    rgb *= 1 - 0.9 * crown
    rgb += leaves * 0.9 * crown
    print(f'trees: {len(pts)}')


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
                tile.save(os.path.join(OUT, str(z), str(x), f'{y}.webp'), 'WEBP', quality=82, method=6)
        print(f'z{z}: {across * across} tiles')


def main(game):
    files = pak_index(os.path.join(game, 'addons', 'data'))
    rgb = np.asarray(mosaic(files), dtype=np.float32)
    water(rgb, kolmap.heights(files))
    trees(rgb, files)
    write_tiles(Image.fromarray(np.clip(rgb, 0, 255).astype(np.uint8), 'RGB'))
    print('done ->', OUT)


if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else r'C:\Program Files (x86)\Steam\steamapps\common\Arma Reforger')
