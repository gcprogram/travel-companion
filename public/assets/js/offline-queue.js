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
  // Serializes actual upload attempts so two sync() calls never race each
  // other - e.g. a page-load resume and a user picking new files, or the
  // 'online' handler and a visibilitychange-triggered resume, firing
  // around the same moment. A later call waits for the earlier one to
  // finish rather than being silently skipped (which would have left
  // files queued during that window sitting untouched until some other
  // trigger came along) - once its turn comes it re-reads the queue fresh,
  // so anything added in the meantime still gets picked up.
  let syncTail = Promise.resolve();

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
   * items that succeed are removed. Stops the whole batch as soon as one
   * item reports authRequired (session expired) - retrying the rest one by
   * one against a session that's already gone would just fail identically
   * and burn through everyone's remaining quota of confusing error states.
   *
   * @param {{csrfToken: string, force?: boolean, onProgress?: (doneCount: number, total: number, fraction: number) => void}} options
   *        force=true bypasses the WiFi-only check (explicit user action);
   *        onProgress reports which item of how many is currently going up,
   *        plus that item's own chunk progress, so callers can show a
   *        "photo 2 of 5" / percentage indicator.
   * @returns {Promise<{synced: number, failed: number, skipped: boolean, authRequired?: boolean}>}
   */
  function sync(options) {
    const csrfToken = options.csrfToken;
    const force = !!options.force;
    const onProgress = options.onProgress || function () {};

    if (!navigator.onLine) {
      return Promise.resolve({ synced: 0, failed: 0, skipped: true });
    }
    if (!force && !canAutoSync()) {
      return Promise.resolve({ synced: 0, failed: 0, skipped: true });
    }
    if (!window.ChunkedUpload || !csrfToken) {
      return Promise.resolve({ synced: 0, failed: 0, skipped: true });
    }

    const run = syncTail.then(function () { return runSync(csrfToken, onProgress); });
    // The tail must stay resolvable even if this run rejects, so a later
    // call still gets its turn instead of chaining behind a permanently
    // rejected promise - runSync() itself already catches per-item errors,
    // so a rejection here would only ever come from something unexpected
    // (e.g. getAll() itself failing).
    syncTail = run.catch(function () {});
    return run;
  }

  function runSync(csrfToken, onProgress) {
    return getAll().then(function (items) {
      const pending = items.filter(function (i) { return i.status !== 'syncing'; });
      let synced = 0;
      let failed = 0;
      let authRequired = false;

      return pending.reduce(function (chain, item, index) {
        return chain.then(function () {
          if (authRequired) {
            return null; // Already know the session's gone; stop trying the rest.
          }
          onProgress(index, pending.length, 0);
          return updateStatus(item.id, 'syncing').then(function () {
            return ChunkedUpload.upload(item.blob, item.filename, item.uploadUrl, csrfToken, {
              extraFields: item.extraFields || {},
              onProgress: function (fraction) { onProgress(index, pending.length, fraction); },
            });
          }).then(function () {
            synced++;
            return remove(item.id);
          }).catch(function (err) {
            if (err && err.authRequired) {
              authRequired = true;
              return updateStatus(item.id, 'pending', 'auth_required');
            }
            failed++;
            // A real, permanent rejection (delete something or ask an admin
            // for more quota) - not a network hiccup, so it's tagged
            // distinctly rather than left looking like an ordinary transient
            // failure that a plain retry would fix.
            var isQuotaExceeded = err && typeof err.body === 'string' && err.body.indexOf('quota_exceeded') !== -1;
            return updateStatus(item.id, 'pending', isQuotaExceeded ? 'quota_exceeded' : (err && err.message));
          });
        });
      }, Promise.resolve()).then(function () {
        notifyChange();
        return { synced: synced, failed: failed, skipped: false, authRequired: authRequired };
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
