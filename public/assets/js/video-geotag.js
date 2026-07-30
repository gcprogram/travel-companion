/**
 * Best-effort GPS extraction from an MP4/MOV file's container metadata,
 * run client-side BEFORE compression (video-compress.js decodes to raw
 * pixels/audio and never sees the container again, so this is the only
 * point at which the location can be recovered).
 *
 * Looks for moov > udta > ©xyz, the ISO 6709 location box written by both
 * iOS QuickTime recordings and Android's MediaMuxer (MediaRecorder.setLocation()) -
 * the same convention on both major platforms since Android's writer follows
 * the same ISOBMFF/QuickTime user-data convention. Not universal (many
 * Android camera apps don't embed location in the video container even when
 * geotagging is turned on for photos), so this is deliberately lenient:
 * any parsing hiccup just means no coordinates, never a thrown error.
 *
 * Exposed as window.VideoGeotag = { extract }.
 */
(function () {
  /**
   * Finds a box by following a path of 4-character type codes, e.g.
   * findBox(buffer, ['moov', 'udta']). Returns {start, end} of the payload
   * of the deepest matched box (absolute offsets into `buffer`), or null.
   *
   * @param {ArrayBuffer} buffer
   * @param {string[]} path
   * @returns {{start: number, end: number}|null}
   */
  function findBox(buffer, path) {
    const view = new DataView(buffer);
    let searchStart = 0;
    let searchEnd = buffer.byteLength;

    for (const targetType of path) {
      let found = null;
      let pos = searchStart;

      while (pos + 8 <= searchEnd) {
        let size = view.getUint32(pos);
        const type = String.fromCharCode(
          view.getUint8(pos + 4), view.getUint8(pos + 5), view.getUint8(pos + 6), view.getUint8(pos + 7)
        );
        let headerSize = 8;

        if (size === 1) {
          if (pos + 16 > searchEnd) {
            break;
          }
          const hi = view.getUint32(pos + 8);
          const lo = view.getUint32(pos + 12);
          size = hi * 2 ** 32 + lo;
          headerSize = 16;
        } else if (size === 0) {
          size = searchEnd - pos;
        }

        if (size < headerSize || pos + size > searchEnd) {
          break; // Malformed/truncated box — stop rather than loop forever.
        }

        if (type === targetType) {
          found = { start: pos + headerSize, end: pos + size };
          break;
        }

        pos += size;
      }

      if (!found) {
        return null;
      }
      searchStart = found.start;
      searchEnd = found.end;
    }

    return { start: searchStart, end: searchEnd };
  }

  /**
   * Decodes a byte range as text and looks for an ISO 6709 coordinate pair,
   * e.g. "+52.5200+013.4050/" or "+52.5200+013.4050+000.000/". Used instead
   * of parsing the exact sub-structure because the wrapper around the string
   * (plain QuickTime string vs. an iTunes-style "data" atom) varies.
   *
   * @returns {{lat: number, lng: number}|null}
   */
  function parseIso6709(bytes) {
    const text = new TextDecoder('utf-8', { fatal: false }).decode(bytes);
    const match = text.match(/([+-]\d{1,3}(?:\.\d+)?)([+-]\d{1,3}(?:\.\d+)?)/);
    if (!match) {
      return null;
    }

    const lat = parseFloat(match[1]);
    const lng = parseFloat(match[2]);
    if (!isFinite(lat) || !isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
      return null;
    }

    return { lat, lng };
  }

  /**
   * @param {File} file
   * @returns {Promise<{lat: number, lng: number}|null>}
   */
  async function extract(file) {
    try {
      const buffer = await file.arrayBuffer();

      const xyz = findBox(buffer, ['moov', 'udta', '©xyz']);
      if (xyz) {
        const result = parseIso6709(new Uint8Array(buffer, xyz.start, xyz.end - xyz.start));
        if (result) {
          return result;
        }
      }

      // Fallback for encoders that nest location differently (e.g.
      // moov > udta > meta > ilst > ©xyz). Rather than special-casing every
      // wrapper style, search the whole moov box — metadata only, at most a
      // few KB — instead of the exact expected path. Deliberately NOT
      // searching the rest of the file: that's arbitrary media data (often
      // most of the file) where a coincidental pattern match is far likelier.
      const moov = findBox(buffer, ['moov']);
      if (moov) {
        return parseIso6709(new Uint8Array(buffer, moov.start, moov.end - moov.start));
      }

      return null;
    } catch (e) {
      return null;
    }
  }

  window.VideoGeotag = { extract };
})();
