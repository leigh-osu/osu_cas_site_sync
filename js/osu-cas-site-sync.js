/**
 * @file
 * Prod site sync: keeps a named browser window on the production
 * counterpart of the page being viewed locally.
 *
 * The "Sync with Prod" checkbox state persists in localStorage (scoped to
 * this site's origin), so it survives navigation. While enabled, every page
 * load re-targets the named window at the new page's prod URL. Browsers only
 * guarantee window.open() outside a user gesture when pop-ups are allowed
 * for the site; when blocked, a hint is shown instead.
 */
(function (Drupal) {
  'use strict';

  const STORAGE_KEY = 'osuCasSiteSync.enabled';
  const WINDOW_NAME = 'osuCasProdSync';

  /**
   * Window features for the right half of the current screen, so the prod
   * window sits side-by-side with the local one.
   *
   * screen.availLeft/availTop handle multi-monitor setups where the primary
   * screen is not at (0,0).
   */
  function sideBySideFeatures() {
    const screenLeft = (typeof screen.availLeft === 'number') ? screen.availLeft : 0;
    const screenTop = (typeof screen.availTop === 'number') ? screen.availTop : 0;
    const screenWidth = screen.availWidth || window.screen.width;
    const screenHeight = screen.availHeight || window.screen.height;
    const width = Math.floor(screenWidth / 2);

    return [
      'width=' + width,
      'height=' + screenHeight,
      'left=' + (screenLeft + width),
      'top=' + screenTop,
      // NOTE: do NOT add 'noopener' or 'noreferrer' here. Per the HTML spec,
      // with noopener any non-empty target name other than _top/_self/_parent
      // behaves like _blank, which defeats reusing the named window. The prod
      // site is same-org and trusted.
      'popup=yes',
      'resizable=yes',
      'scrollbars=yes'
    ].join(',');
  }

  function openSyncWindow(url) {
    return window.open(url, WINDOW_NAME, sideBySideFeatures());
  }

  Drupal.behaviors.osuCasSiteSync = {
    attach: function (context) {
      once('osu-cas-site-sync', '.osu-cas-site-sync', context).forEach(function (el) {
        const url = el.dataset.siteSyncUrl;
        const checkbox = el.querySelector('.osu-cas-site-sync__checkbox');
        const hint = el.querySelector('.osu-cas-site-sync__hint');
        const link = el.querySelector('.osu-cas-site-sync__link');

        checkbox.checked = localStorage.getItem(STORAGE_KEY) === '1';

        // Sync is on: re-target the prod window at this page's counterpart.
        if (checkbox.checked) {
          const w = openSyncWindow(url);
          if (!w) {
            hint.hidden = false;
          }
        }

        checkbox.addEventListener('change', function () {
          if (checkbox.checked) {
            localStorage.setItem(STORAGE_KEY, '1');
            // User gesture: pop-up blockers allow this open.
            const w = openSyncWindow(url);
            if (w) {
              hint.hidden = true;
              try { w.focus(); } catch (e) { /* noop */ }
            }
          }
          else {
            localStorage.removeItem(STORAGE_KEY);
            // Best-effort close of the sync window. An empty URL returns the
            // existing named window without navigating it; if none existed,
            // this opens-and-closes a blank one within the same gesture.
            try {
              const w = window.open('', WINDOW_NAME);
              if (w) {
                w.close();
              }
            }
            catch (e) { /* noop */ }
          }
        });

        // Plain click sends the prod page to the same side-by-side window.
        link.addEventListener('click', function (event) {
          // Honor modifier keys: let the user do their own thing
          // (cmd/ctrl-click for new tab, shift-click, middle-click, etc.)
          if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) {
            return;
          }
          const w = openSyncWindow(this.href);
          if (w) {
            // Prevent the default target="_blank" from also opening a tab.
            event.preventDefault();
            try { w.focus(); } catch (e) { /* noop */ }
          }
          // Pop-up blocked: fall through to the default target="_blank".
        });
      });
    }
  };

})(Drupal);
