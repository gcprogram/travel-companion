/**
 * Client-side EXIF GPS + capture-time extraction from a JPEG File, for
 * track-folder-scan.js: building a track from photos that are explicitly
 * NOT being uploaded to the server (PhotoProcessHandler's server-side
 * exif_read_data() extraction only ever sees already-uploaded originals).
 *
 * Hand-rolled JPEG/TIFF/EXIF-IFD walker in the same spirit as
 * video-geotag.js's ISOBMFF box walker: any parsing hiccup means null,
 * never a thrown error.
 *
 * Exposed as window.PhotoGeotag = { extract }.
 */
(function () {
  /**
   * Walks JPEG markers looking for the APP1 "Exif\0\0" segment.
   * @returns {{start: number, end: number}|null} byte range of the TIFF
   *   header (right after "Exif\0\0"), absolute offsets into the file.
   */
  function findExifSegment(view) {
    if (view.byteLength < 4 || view.getUint16(0) !== 0xffd8) {
      return null; // Not a JPEG (no SOI marker).
    }

    let pos = 2;
    while (pos + 4 <= view.byteLength) {
      const marker = view.getUint16(pos);
      if ((marker & 0xff00) !== 0xff00) {
        break; // Not a marker — malformed/unexpected structure.
      }
      if (marker === 0xffd8 || marker === 0xffd9 || (marker >= 0xffd0 && marker <= 0xffd7)) {
        pos += 2; // Markers with no length field.
        continue;
      }
      if (marker === 0xffda) {
        break; // Start of scan — no more metadata markers follow.
      }

      const length = view.getUint16(pos + 2);
      if (marker === 0xffe1 && pos + 4 + 6 <= view.byteLength) {
        const tag = String.fromCharCode(
          view.getUint8(pos + 4), view.getUint8(pos + 5), view.getUint8(pos + 6), view.getUint8(pos + 7),
        );
        if (tag === 'Exif') {
          return { start: pos + 4 + 6, end: pos + 2 + length };
        }
      }
      pos += 2 + length;
    }
    return null;
  }

  function parseTiffHeader(view, tiffStart) {
    const byteOrderMark = view.getUint16(tiffStart);
    const little = byteOrderMark === 0x4949;
    if (!little && byteOrderMark !== 0x4d4d) {
      return null;
    }
    if (view.getUint16(tiffStart + 2, little) !== 0x002a) {
      return null;
    }
    const ifd0Offset = view.getUint32(tiffStart + 4, little);
    return { little: little, ifd0Offset: tiffStart + ifd0Offset };
  }

  /**
   * @returns {Object<number, {type: number, count: number, valueOffset: number}>}
   *   Keyed by tag id; valueOffset points at the 4-byte value/offset field
   *   of the entry (absolute offset into the buffer).
   */
  function readIfd(view, ifdOffset, little) {
    const entries = {};
    if (ifdOffset < 0 || ifdOffset + 2 > view.byteLength) {
      return entries;
    }
    const count = view.getUint16(ifdOffset, little);
    let pos = ifdOffset + 2;
    for (let i = 0; i < count; i++) {
      if (pos + 12 > view.byteLength) {
        break;
      }
      entries[view.getUint16(pos, little)] = {
        type: view.getUint16(pos + 2, little),
        count: view.getUint32(pos + 4, little),
        valueOffset: pos + 8,
      };
      pos += 12;
    }
    return entries;
  }

  function typeSize(type) {
    switch (type) {
      case 1: case 2: case 6: case 7: return 1; // BYTE, ASCII, SBYTE, UNDEFINED
      case 3: case 8: return 2; // SHORT, SSHORT
      case 4: case 9: return 4; // LONG, SLONG
      case 5: case 10: return 8; // RATIONAL, SRATIONAL
      default: return 1;
    }
  }

  function entryAbsoluteOffset(view, tiffStart, entry, little) {
    const size = typeSize(entry.type) * entry.count;
    if (size <= 4) {
      return entry.valueOffset; // Value stored inline within the 4-byte field.
    }
    return tiffStart + view.getUint32(entry.valueOffset, little);
  }

  function readRational(view, offset, little) {
    const numerator = view.getUint32(offset, little);
    const denominator = view.getUint32(offset + 4, little);
    return denominator === 0 ? 0 : numerator / denominator;
  }

  function readAsciiString(view, tiffStart, entry, little) {
    if (!entry) {
      return null;
    }
    const offset = entryAbsoluteOffset(view, tiffStart, entry, little);
    let str = '';
    for (let i = 0; i < entry.count - 1; i++) { // Exclude the trailing NUL.
      const code = view.getUint8(offset + i);
      if (code === 0) {
        break;
      }
      str += String.fromCharCode(code);
    }
    return str || null;
  }

  function readGpsCoordinate(view, tiffStart, gpsIfd, refTag, valueTag, little) {
    const refEntry = gpsIfd[refTag];
    const valueEntry = gpsIfd[valueTag];
    if (!refEntry || !valueEntry || valueEntry.count < 3) {
      return null;
    }
    const ref = String.fromCharCode(view.getUint8(refEntry.valueOffset));
    const offset = entryAbsoluteOffset(view, tiffStart, valueEntry, little);
    const degrees = readRational(view, offset, little);
    const minutes = readRational(view, offset + 8, little);
    const seconds = readRational(view, offset + 16, little);
    let value = degrees + minutes / 60 + seconds / 3600;
    if (ref === 'S' || ref === 'W') {
      value = -value;
    }
    return value;
  }

  // EXIF DateTimeOriginal/DateTime format: "YYYY:MM:DD HH:MM:SS", no
  // timezone of its own. When an OffsetTimeOriginal/OffsetTime tag
  // ("+02:00" style, EXIF 2.31+) is present, it's used to convert to true
  // UTC — same fix as the server-side extraction (PhotoProcessHandler::
  // parseExifDateTime(), see the 2026-08-14 track-mixing bugfix). Without
  // one, treated as UTC for lack of better info, same as before.
  function parseExifDate(str, offset) {
    if (!str) {
      return null;
    }
    const m = str.match(/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})$/);
    if (!m) {
      return null;
    }
    let suffix = 'Z';
    if (offset) {
      const om = String(offset).trim().match(/^([+-])(\d{2}):?(\d{2})$/);
      if (om) {
        suffix = om[1] + om[2] + ':' + om[3];
      }
    }
    const date = new Date(m[1] + '-' + m[2] + '-' + m[3] + 'T' + m[4] + ':' + m[5] + ':' + m[6] + suffix);
    return isNaN(date.getTime()) ? null : date.toISOString();
  }

  /**
   * @param {File} file
   * @returns {Promise<{lat: number, lng: number, recordedAt: string|null}|null>}
   */
  async function extract(file) {
    try {
      const buffer = await file.arrayBuffer();
      const view = new DataView(buffer);

      const segment = findExifSegment(view);
      if (!segment) {
        return null;
      }

      const tiff = parseTiffHeader(view, segment.start);
      if (!tiff) {
        return null;
      }

      const ifd0 = readIfd(view, tiff.ifd0Offset, tiff.little);

      let recordedAt = null;
      const exifIfdPointer = ifd0[0x8769];
      if (exifIfdPointer) {
        const exifIfdOffset = segment.start + view.getUint32(exifIfdPointer.valueOffset, tiff.little);
        const exifIfd = readIfd(view, exifIfdOffset, tiff.little);
        const offset = readAsciiString(view, segment.start, exifIfd[0x9011], tiff.little)
          || readAsciiString(view, segment.start, exifIfd[0x9010], tiff.little);
        recordedAt = parseExifDate(readAsciiString(view, segment.start, exifIfd[0x9003], tiff.little), offset);
      }
      if (!recordedAt) {
        recordedAt = parseExifDate(readAsciiString(view, segment.start, ifd0[0x0132], tiff.little));
      }

      const gpsIfdPointer = ifd0[0x8825];
      if (!gpsIfdPointer) {
        return recordedAt ? { lat: null, lng: null, recordedAt: recordedAt } : null;
      }
      const gpsIfdOffset = segment.start + view.getUint32(gpsIfdPointer.valueOffset, tiff.little);
      const gpsIfd = readIfd(view, gpsIfdOffset, tiff.little);

      const lat = readGpsCoordinate(view, segment.start, gpsIfd, 0x0001, 0x0002, tiff.little);
      const lng = readGpsCoordinate(view, segment.start, gpsIfd, 0x0003, 0x0004, tiff.little);

      if (lat === null || lng === null || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
        return recordedAt ? { lat: null, lng: null, recordedAt: recordedAt } : null;
      }
      return { lat: lat, lng: lng, recordedAt: recordedAt };
    } catch (e) {
      return null;
    }
  }

  window.PhotoGeotag = { extract };
})();
