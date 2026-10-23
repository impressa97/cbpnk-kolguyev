"""Read files straight out of an Arma Reforger install's .pak archives (no Workbench needed).

PAK: IFF FORM/PAC1 with HEAD, DATA, FILE chunks. FILE is a tree of entries:
  u8 type (0 dir, 1 file), u8 name length, name; dir: u32 child count;
  file: u32 offset, u32 stored size, u32 size, u32, u16, u16 compression, u32 time.
"""

import os
import struct
import zlib


def pak_index(data_dir):
    """Map every packed path to (pak file, offset, stored size, size)."""
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
