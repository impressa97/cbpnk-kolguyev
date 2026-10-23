"""Decode Enfusion .edds textures (a DDS header, then each mip as a COPY or LZ4 chunk).

After the 148-byte DDS + DX10 header comes a table of (tag, size) pairs, smallest mip first,
then the chunks in the same order. COPY is the mip as is; LZ4 is u32 decompressed size, then
blocks of u32 header (low 24 bits compressed size, top bit set on the last block) chained as
an LZ4 stream (each block may refer back into the 64 KiB decompressed before it).
"""

import io
import struct

import lz4.block
from PIL import Image

DDS_HEADER = 148


def _lz4(chunk):
    size = struct.unpack_from('<I', chunk, 0)[0]
    out = bytearray()
    pos = 4
    while len(out) < size:
        head = struct.unpack_from('<I', chunk, pos)[0]
        pos += 4
        length = head & 0xFFFFFF
        block = chunk[pos:pos + length]
        pos += length
        window = bytes(out[-65536:])
        out += lz4.block.decompress(block, uncompressed_size=65536, dict=window)
        if head & 0x80000000:
            break
    return bytes(out[:size])


def mips(blob):
    """Every mip's raw block data, largest first."""
    pos = DDS_HEADER
    table = []
    while blob[pos:pos + 4] in (b'COPY', b'LZ4 '):
        table.append((blob[pos:pos + 4], struct.unpack_from('<I', blob, pos + 4)[0]))
        pos += 8
    out = []
    for tag, size in table:
        chunk = blob[pos:pos + size]
        pos += size
        out.append(chunk if tag == b'COPY' else _lz4(chunk))
    return out[::-1]


def image(blob):
    """The top mip as a Pillow image (Pillow decodes the block format from a plain DDS)."""
    top = mips(blob)[0]
    header = bytearray(blob[:DDS_HEADER])
    struct.pack_into('<I', header, 28, 1)  # one mip level
    return Image.open(io.BytesIO(bytes(header) + top)).convert('RGB')
