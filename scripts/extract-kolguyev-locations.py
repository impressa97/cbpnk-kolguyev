"""Extract Kolguyev (Cain) named locations from a local Arma Reforger install.

Reads the game's .pak archives directly (no Workbench needed):
  * Prefabs/World/Locations/Cain/**.et  — one prefab per map label, with its base type
  * worlds/Cain/Cain.ent                — binary world (EBIN); instances give position
  * Language/localization.{en_us,ru_ru}.conf — display names

Writes resources/js/lib/generated/kolguyev-locations.ts.

    python -I scripts/reforger/extract-kolguyev-locations.py "A:/Steam/steamapps/common/Arma Reforger"

PAK: IFF FORM/PAC1 with HEAD, DATA, FILE chunks. FILE is a tree of entries:
  u8 type (0 dir, 1 file), u8 name length, name, then dir: u32 child count,
  file: u32 offset, u32 stored size, u32 size, u32, u16, u16 compression, u32 time.
Resource GUIDs in resourceDatabase.rdb follow the path + NUL + 6 bytes, as u64 LE.

EBIN: "EBIN", u32 version, u16 keyword count, u16 resource count, keywords (u16 len + bytes),
resources (u64 GUID each), then tagged data. 0x0d u16 = keyword token; the ':' keyword
followed by 0x05 u16 is the parent prefab (index into resources); 'coords' + 0x08 is vec3.
"""

import json
import os
import re
import struct
import sys
import zlib

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, 'resources', 'js', 'lib', 'generated', 'kolguyev-locations.ts')
LOCATIONS = 'Prefabs/World/Locations/Cain/'


def pak_index(data_dir):
    files = {}

    def walk(buf, pos, prefix, pak):
        kind, length = buf[pos], buf[pos + 1]
        name = buf[pos + 2:pos + 2 + length].decode('utf-8', 'replace')
        pos += 2 + length
        if kind == 0:
            count = struct.unpack_from('<I', buf, pos)[0]
            pos += 4
            path = prefix + name + '/' if name else prefix
            for _ in range(count):
                pos = walk(buf, pos, path, pak)
        else:
            offset, stored, size = struct.unpack_from('<III', buf, pos)
            pos += 24
            files[prefix + name] = (pak, offset, stored, size)
        return pos

    for pak in sorted(f for f in os.listdir(data_dir) if f.endswith('.pak')):
        path = os.path.join(data_dir, pak)
        with open(path, 'rb') as fh:
            fh.seek(0x30)
            while True:
                head = fh.read(8)
                if len(head) < 8:
                    break
                tag, size = head[:4], struct.unpack('>I', head[4:])[0]
                if tag == b'FILE':
                    walk(fh.read(size), 0, '', path)
                    break
                fh.seek(size, 1)
    return files


def read(files, name):
    pak, offset, stored, size = files[name]
    with open(pak, 'rb') as fh:
        fh.seek(offset)
        raw = fh.read(stored)
    if stored == size:
        return raw
    try:
        return zlib.decompress(raw)
    except zlib.error:
        return zlib.decompressobj(-15).decompress(raw)


def string_table(text):
    blocks = dict(re.findall(r'^ (\w+) \{\n(.*?)\n \}', text, re.S | re.M))
    ids = re.findall(r'^  "(.*)"$', blocks['Ids'], re.M)
    texts = re.findall(r'^  "(.*)"$', blocks['Texts'], re.M)
    return dict(zip(ids, texts))


def guid_of(rdb, path):
    key = path.encode() + b'\x00'
    at = rdb.find(key)
    if at < 0:
        return None
    return struct.unpack('<Q', rdb[at + len(key) + 6:at + len(key) + 14])[0]


def kind_of(base, prefab):
    if 'Military' in prefab:
        return 'military'
    if 'Harbor' in prefab:
        return 'harbour'
    if 'Sawmill' in prefab:
        return 'sawmill'
    if prefab.startswith('Mine_'):
        return 'mine'
    return {
        'LocationUrbanCity': 'city',
        'LocationUrbanTown': 'town',
        'LocationUrbanVillage': 'village',
        'LocationUrbanGeneric': 'settlement',
        'LocationNaturalHill': 'hill',
        'LocationNaturalMajor': 'ridge',
        'LocationNaturalIsland': 'island',
        'LocationNaturalGeneric': 'landmark',
        'LocationAquaticFreshMinor': 'lake',
        'LocationAquaticSeaMinor': 'bay',
    }.get(base, 'landmark')


def title(text):
    return text.title() if text.isupper() else text


def main(game):
    data = os.path.join(game, 'addons', 'data')
    files = pak_index(data)
    rdb = open(os.path.join(data, 'resourceDatabase.rdb'), 'rb').read()
    en = string_table(read(files, 'Language/localization.en_us.conf').decode('utf-8'))
    ru = string_table(read(files, 'Language/localization.ru_ru.conf').decode('utf-8'))
    world = read(files, 'worlds/Cain/Cain.ent')

    keywords, resources = struct.unpack_from('<HH', world, 8)
    pos = 12
    names = []
    for _ in range(keywords):
        length = struct.unpack_from('<H', world, pos)[0]
        names.append(world[pos + 2:pos + 2 + length].decode())
        pos += 2 + length
    index = {struct.unpack_from('<Q', world, pos + 8 * i)[0]: i for i in range(resources)}
    parent = struct.pack('<H', names.index(':'))
    coords = struct.pack('<H', names.index('coords'))

    rows = []
    by_prefab = {}
    for path in sorted(p for p in files if p.startswith(LOCATIONS) and p.endswith('.et')):
        prefab = path.rsplit('/', 1)[1][:-3]
        text = read(files, path).decode('utf-8')
        base = re.search(r'Locations/Common/\w+/(\w+)\.et', text).group(1)
        key = re.search(r'DisplayName "#([^"]+)"', text).group(1)
        guid = guid_of(rdb, path)
        if guid not in index:
            continue
        marker = b'\x0d' + parent + b'\x05' + struct.pack('<H', index[guid])
        hit = world.find(marker)
        at = world.find(b'\x0d' + coords + b'\x08', hit, hit + 600) if hit >= 0 else -1
        if at < 0:
            continue
        x, y, z = struct.unpack_from('<fff', world, at + 4)
        row = {
            'id': re.sub(r'_01$', '', prefab).lower().replace('_', '-'),
            'prefab': prefab,
            'kind': kind_of(base, prefab),
            'name': title(en.get(key, prefab)),
            'name_ru': ru.get(key, ''),
            'x': round(x),
            'z': round(z),
            'elevation': round(y),
        }
        by_prefab[prefab] = row
        rows.append(row)

    # Generic labels ("military site", "harbor") read better with the place they belong to.
    named = {r['prefab'].split('_')[0]: r for r in rows if r['kind'] not in ('military', 'harbour', 'sawmill')}
    named.setdefault('GubaVilla', by_prefab.get('GubaVilla_01'))
    for row in rows:
        if row['kind'] in ('military', 'harbour', 'sawmill'):
            owner = named.get(row['prefab'].split('_')[0])
            if row['prefab'].startswith('East_'):
                prefix, prefix_ru = 'East', 'Восточный'
            elif owner:
                prefix, prefix_ru = owner['name'], owner['name_ru']
            else:
                prefix, prefix_ru = row['prefab'].split('_')[0], ''
            row['name'] = f"{prefix} {row['name'].lower()}".strip()
            row['name_ru'] = f"{row['name_ru'].capitalize()} ({prefix_ru})" if prefix_ru else row['name_ru']

    rows.sort(key=lambda r: (r['kind'], r['name']))
    body = json.dumps(rows, ensure_ascii=False, indent=4)
    with open(OUT, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write('// Generated by scripts/reforger/extract-kolguyev-locations.py from the Arma Reforger game data.\n')
        fh.write('// Do not edit by hand. World metres, X east, Z north; elevation in metres above sea level.\n\n')
        fh.write("export type KolguyevLocationKind =\n    | 'city'\n    | 'town'\n    | 'village'\n    | 'settlement'\n"
                 "    | 'military'\n    | 'harbour'\n    | 'sawmill'\n    | 'mine'\n    | 'hill'\n    | 'ridge'\n"
                 "    | 'island'\n    | 'landmark'\n    | 'lake'\n    | 'bay';\n\n")
        fh.write('export type KolguyevLocation = {\n    id: string;\n    prefab: string;\n    kind: KolguyevLocationKind;\n'
                 '    name: string;\n    name_ru: string;\n    x: number;\n    z: number;\n    elevation: number;\n};\n\n')
        fh.write(f'export const KOLGUYEV_LOCATIONS: KolguyevLocation[] = {body};\n')
    print(f'{len(rows)} locations -> {OUT}')


if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else r'C:\Program Files (x86)\Steam\steamapps\common\Arma Reforger')
