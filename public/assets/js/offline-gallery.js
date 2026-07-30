/**
 * Renders OfflineQueue items (photos/videos queued because there was no
 * connection at upload time) into the gallery as extra tiles, drives the
 * sync-status widget (pending count, manual "sync now", WiFi-only toggle),
 * and triggers sync on page load / reconnect.
 */
document.addEventListener('DOMContentLoaded', function () {
  if (!window.OfflineQueue) {
    return;
  }

  var photoGallery = document.querySelector('[data-photo-gallery]');
  var videoGallery = document.querySelector('[data-video-gallery]');
  var syncStatus = document.querySelector('[data-sync-status]');
  var syncCount = document.querySelector('[data-sync-count]');
  var syncNowBtn = document.querySelector('[data-sync-now]');
  var wifiToggle = document.querySelector('[data-wifi-only-toggle]');
  var csrfField = document.querySelector('input[name="_csrf"]');

  var entryId = photoGallery
    ? parseInt(photoGallery.dataset.entryId, 10)
    : (videoGallery ? parseInt(videoGallery.dataset.entryId, 10) : NaN);

  if (wifiToggle) {
    wifiToggle.checked = OfflineQueue.isWifiOnly();
    wifiToggle.addEventListener('change', function () {
      OfflineQueue.setWifiOnly(wifiToggle.checked);
      trySync(false);
    });
  }

  if (syncNowBtn) {
    syncNowBtn.addEventListener('click', function () {
      trySync(true);
    });
  }

  window.addEventListener('online', function () { trySync(false); });
  window.addEventListener('offlinequeue:change', render);

  render();
  trySync(false);

  function trySync(force) {
    if (!csrfField) {
      return;
    }
    OfflineQueue.sync({ csrfToken: csrfField.value, force: force }).then(function (result) {
      if (result.synced > 0) {
        window.location.reload();
      } else {
        render();
      }
    });
  }

  function render() {
    OfflineQueue.getAll().then(function (items) {
      renderGalleryQueue(photoGallery, items.filter(function (i) { return i.type === 'photo' && i.entryId === entryId; }));
      renderGalleryQueue(videoGallery, items.filter(function (i) { return i.type === 'video' && i.entryId === entryId; }));
      renderSyncWidget(items);
    });
  }

  function renderGalleryQueue(gallery, items) {
    if (!gallery) {
      return;
    }
    Array.prototype.forEach.call(gallery.querySelectorAll('[data-queue-item]'), function (el) {
      el.remove();
    });

    items.forEach(function (item) {
      var li = document.createElement('li');
      li.className = 'photo-gallery__item';
      li.setAttribute('data-queue-item', '');

      var placeholder = document.createElement('div');
      placeholder.className = 'photo-gallery__placeholder';
      placeholder.textContent = gallery.dataset.msgQueued || '';
      li.appendChild(placeholder);

      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'btn btn-ghost photo-gallery__remove';
      removeBtn.textContent = gallery.dataset.msgRemove || '';
      removeBtn.addEventListener('click', function () {
        if (!window.confirm(gallery.dataset.msgRemoveConfirm || '')) {
          return;
        }
        OfflineQueue.remove(item.id).then(render);
      });
      li.appendChild(removeBtn);

      gallery.appendChild(li);
    });
  }

  function renderSyncWidget(allItems) {
    if (!syncStatus || !syncCount) {
      return;
    }
    var count = allItems.length;
    if (count === 0) {
      syncStatus.hidden = true;
      return;
    }
    syncStatus.hidden = false;

    var label = count === 1
      ? syncStatus.dataset.msgPendingOne
      : (syncStatus.dataset.msgPendingMany || '').replace(':count', String(count));

    if (!OfflineQueue.canAutoSync() && navigator.onLine) {
      label += ' — ' + syncStatus.dataset.msgWaitingWifi;
    }
    syncCount.textContent = label;
  }
});
