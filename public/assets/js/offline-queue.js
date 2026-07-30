/**
 * Offline upload queue for photos/videos: when a chunked upload fails
 * because there's no connection, the already-processed file (for video:
 * already compressed, for photo: the original) gets stored in IndexedDB
 * instead of being lost, and is retried automatically once online —
 * respecting a WiFi-only preference by default, since this is aimed at
 * travelers who may be paying for roaming/limited mobile data.
 *
 * Text-only day-entry drafts are handled separately (see day-entry-form.js)
 * with a much simpler localStorage autosave, not this queue: replicating
 * the server's validation/redirect flow through fetch would be substantial
 * extra risk for content that's a few KB and cheap to retry manually.
 *
 * Exposed as window.OfflineQueue.
 */
window.OfflineQueue = (function () {
  const DB_NAME = 'travel-companion-offline';
  const DB_VERSION = 1;
  const STORE_NAME = 'queue';
  const WIFI_ONLY_KEY = 'tc_sync_wifi_only';

  let dbPromise = null;

  function openDb() {
    if (dbPromise) {
      return dbPromise;
    }
    dbPromise = new Promise(function (resolve, reject) {
      const request = indexedDB.open(DB_NAME, DB_VERSION);
      request.onupgradeneeded = function () {
        const db = request.result;
        if (!db.objectStoreNames.contains(STORE_NAME)) {
          db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
        }
      };
      request.onsuccess = function () { resolve(request.result); };
      request.onerror = function () { reject(request.error); };
    });
    return dbPromise;
  }

  function withStore(mode, callback) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        const tx = db.transaction(STORE_NAME, mode);
        const store = tx.objectStore(STORE_NAME);
        const result = callback(store);
        tx.oncomplete = function () { resolve(result); };
        tx.onerror = function () { reject(tx.error); };
      });
    });
  }

  /**
   * @param {{type: 'photo'|'video', entryId: number, uploadUrl: string, filename: string, blob: Blob, extraFields?: Record<string,string>}} item
   * @returns {Promise<number>} the local queue id
   */
  function add(item) {
    return withStore('readwrite', function (store) {
      const record = Object.assign({}, item, {
        createdAt: Date.now(),
        status: 'pending',
        lastError: null,
      });
      const request = store.add(record);
      return new Promise(function (resolve, reject) {
        request.onsuccess = function () { resolve(request.result); };
        request.onerror = function () { reject(request.error); };
      });
    }).then(function (p) { return p; });
  }

  function getAll() {
    return withStore('readonly', function (store) {
      return new Promise(function (resolve, reject) {
        const request = store.getAll();
        request.onsuccess = function () { resolve(request.result); };
        request.onerror = function () { reject(request.error); };
      });
    }).then(function (p) { return p; });
  }

  function remove(id) {
    return withStore('readwrite', function (store) {
      store.delete(id);
    });
  }

  function updateStatus(id, status, lastError) {
    return withStore('readwrite', function (store) {
      const getReq = store.get(id);
      getReq.onsuccess = function () {
        const record = getReq.result;
        if (record) {
          record.status = status;
          record.lastError = lastError || null;
          store.put(record);
        }
      };
    });
  }

  function isWifiOnly() {
    const stored = localStorage.getItem(WIFI_ONLY_KEY);
    return stored === null ? true : stored === 'true'; // default: WiFi-only, safe for roaming
  }

  function setWifiOnly(value) {
    localStorage.setItem(WIFI_ONLY_KEY, value ? 'true' : 'false');
  }

  /**
   * Best-effort connection type. The Network Information API is
   * Chromium/Android-only (not on iOS Safari or Firefox) — when it's
   * unavailable we deliberately return 'unknown' rather than guessing, so
   * callers can treat "don't know" the same as "assume cellular."
   *
   * @returns {'wifi'|'cellular'|'unknown'}
   */
  function connectionType() {
    const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (conn && typeof conn.type === 'string') {
      if (conn.type === 'wifi' || conn.type === 'ethernet') {
        return 'wifi';
      }
      if (conn.type === 'cellular') {
        return 'cellular';
      }
    }
    return 'unknown';
  }

  /**
   * Whether an automatic (unprompted) sync is currently allowed.
   */
  function canAutoSync() {
    if (!navigator.onLine) {
      return false;
    }
    if (!isWifiOnly()) {
      return true;
    }
    return connectionType() === 'wifi';
  }

  function notifyChange() {
    window.dispatchEvent(new CustomEvent('offlinequeue:change'));
  }

  /**
   * Attempts to upload every queued item via the shared ChunkedUpload
   * helper. Items that fail are left in the queue for the next attempt;
   * items that succeed are removed.
   *
   * @param {{csrfToken: string, force?: boolean}} options force=true bypasses the WiFi-only check (explicit user action)
   * @returns {Promise<{synced: number, failed: number, skipped: boolean}>}
   */
  function sync(options) {
    const csrfToken = options.csrfToken;
    const force = !!options.force;

    if (!navigator.onLine) {
      return Promise.resolve({ synced: 0, failed: 0, skipped: true });
    }
    if (!force && !canAutoSync()) {
      return Promise.resolve({ synced: 0, failed: 0, skipped: true });
    }
    if (!window.ChunkedUpload || !csrfToken) {
      return Promise.resolve({ synced: 0, failed: 0, skipped: true });
    }

    return getAll().then(function (items) {
      const pending = items.filter(function (i) { return i.status !== 'syncing'; });
      let synced = 0;
      let failed = 0;

      return pending.reduce(function (chain, item) {
        return chain.then(function () {
          return updateStatus(item.id, 'syncing').then(function () {
            return ChunkedUpload.upload(item.blob, item.filename, item.uploadUrl, csrfToken, {
              extraFields: item.extraFields || {},
            });
          }).then(function () {
            synced++;
            return remove(item.id);
          }).catch(function (err) {
            failed++;
            return updateStatus(item.id, 'pending', err && err.message);
          });
        });
      }, Promise.resolve()).then(function () {
        notifyChange();
        return { synced: synced, failed: failed, skipped: false };
      });
    });
  }

  window.addEventListener('online', notifyChange);
  window.addEventListener('offline', notifyChange);

  return {
    add: add,
    getAll: getAll,
    remove: remove,
    isWifiOnly: isWifiOnly,
    setWifiOnly: setWifiOnly,
    connectionType: connectionType,
    canAutoSync: canAutoSync,
    sync: sync,
  };
})();
