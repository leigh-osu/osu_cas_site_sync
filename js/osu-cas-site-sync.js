/**
 * @file
 * Prod site sync: keeps a named browser window on the production
 * counterpart of the page being viewed locally.
 *
 * The sync checkbox state and the slideshow selections persist in a cookie
 * scoped to the registrable domain (e.g. .oregonstate.edu), so they survive
 * navigation across the domain subdomains. While enabled, every page
 * load re-targets the named window at the new page's prod URL. Browsers only
 * guarantee window.open() outside a user gesture when pop-ups are allowed
 * for the site; when blocked, a hint is shown instead.
 */
(function (Drupal) {
  'use strict';

  const STORAGE_KEY = 'osuCasSiteSync.enabled';
  const TYPE_KEY = 'osuCasSiteSync.slideshowType';
  const SITE_KEY = 'osuCasSiteSync.slideshowSite';
  const SYNCED_KEY = 'osuCasSiteSync.lastSynced';
  const WINDOW_NAME = 'osuCasProdSync';

  /**
   * Preference storage (sync-enabled, slideshow type/site) via a cookie scoped
   * to the registrable domain (e.g. .oregonstate.edu) instead of localStorage.
   * Jumping or stepping to a node on another domain crosses origins, where
   * localStorage would be empty; a cookie on the shared parent domain keeps the
   * selections across all the domain subdomains.
   */
  function prefCookieDomain() {
    const parts = window.location.hostname.split('.');
    return parts.length >= 2 ? '.' + parts.slice(-2).join('.') : window.location.hostname;
  }

  function getPref(key) {
    const name = key.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1');
    const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
  }

  function setPref(key, value) {
    document.cookie = key + '=' + encodeURIComponent(value) +
      ';path=/;domain=' + prefCookieDomain() + ';max-age=31536000;samesite=lax';
  }

  function delPref(key) {
    document.cookie = key + '=;path=/;domain=' + prefCookieDomain() + ';max-age=0;samesite=lax';
  }

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

        checkbox.checked = getPref(STORAGE_KEY) === '1';

        // Sync is on: re-target the prod window at this page's counterpart.
        // Skip when the slideshow already pointed the window here within the
        // triggering click (see step below) — and note that without a user
        // gesture this open only succeeds when pop-ups are allowed.
        if (checkbox.checked) {
          if (sessionStorage.getItem(SYNCED_KEY) === url) {
            sessionStorage.removeItem(SYNCED_KEY);
          }
          else {
            const w = openSyncWindow(url);
            if (!w) {
              hint.hidden = false;
            }
          }
        }

        checkbox.addEventListener('change', function () {
          if (checkbox.checked) {
            setPref(STORAGE_KEY, '1');
            // User gesture: pop-up blockers allow this open.
            const w = openSyncWindow(url);
            if (w) {
              hint.hidden = true;
              try { w.focus(); } catch (e) { /* noop */ }
            }
          }
          else {
            delPref(STORAGE_KEY);
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

        // NID chip: click copies the node id to the clipboard.
        const nidCopy = el.querySelector('.osu-cas-site-sync__nid-copy');
        if (nidCopy) {
          nidCopy.addEventListener('click', function (event) {
            event.preventDefault();
            const nid = el.dataset.siteSyncNid;
            const done = function () {
              nidCopy.classList.add('is-copied');
              setTimeout(function () { nidCopy.classList.remove('is-copied'); }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
              navigator.clipboard.writeText(nid).then(done, function () { /* noop */ });
            }
            else {
              // Insecure-context fallback.
              const tmp = document.createElement('textarea');
              tmp.value = nid;
              document.body.appendChild(tmp);
              tmp.select();
              try { document.execCommand('copy'); done(); } catch (e) { /* noop */ }
              tmp.remove();
            }
          });
        }

        // Slideshow: step to the adjacent node of the selected type. The
        // server resolves the next/previous nid and redirects to its alias;
        // with sync enabled the new page then re-targets the prod window.
        const typeSelect = el.querySelector('.osu-cas-site-sync__slideshow-type');
        const siteSelect = el.querySelector('.osu-cas-site-sync__slideshow-site');
        if (typeSelect) {
          const savedType = getPref(TYPE_KEY);
          if (savedType && typeSelect.querySelector('option[value="' + CSS.escape(savedType) + '"]')) {
            typeSelect.value = savedType;
          }
          typeSelect.addEventListener('change', function () {
            setPref(TYPE_KEY, typeSelect.value);
          });
          if (siteSelect) {
            const savedSite = getPref(SITE_KEY);
            if (savedSite && siteSelect.querySelector('option[value="' + CSS.escape(savedSite) + '"]')) {
              siteSelect.value = savedSite;
            }
            siteSelect.addEventListener('change', function () {
              setPref(SITE_KEY, siteSelect.value);
            });
          }

          const prevBtn = el.querySelector('.osu-cas-site-sync__slideshow-prev');
          const nextBtn = el.querySelector('.osu-cas-site-sync__slideshow-next');
          const randomBtn = el.querySelector('.osu-cas-site-sync__slideshow-random');

          // Grey the arrows when the current selection matches no nodes at
          // all (stepping wraps, so any non-empty selection is navigable).
          const updateArrows = function () {
            const params = new URLSearchParams({
              dir: 'next',
              type: typeSelect.value,
              site: siteSelect ? siteSelect.value : 'all',
              from: '',
              respond: 'json'
            });
            fetch(el.dataset.siteSyncStep + '?' + params.toString(), { credentials: 'same-origin' })
              .then(function (response) { return response.json(); })
              .then(function (data) {
                prevBtn.disabled = nextBtn.disabled = randomBtn.disabled = !data.url;
              })
              .catch(function () { /* leave the arrows as they are */ });
          };
          updateArrows();
          typeSelect.addEventListener('change', updateArrows);
          if (siteSelect) {
            siteSelect.addEventListener('change', updateArrows);
          }

          // Resolve the destination first, then point the prod window at it
          // while still inside the click's user gesture (a page-load
          // window.open would be pop-up blocked), then navigate locally.
          const step = function (dir) {
            const params = new URLSearchParams({
              dir: dir,
              type: typeSelect.value,
              site: siteSelect ? siteSelect.value : 'all',
              from: el.dataset.siteSyncNid || '',
              respond: 'json'
            });
            fetch(el.dataset.siteSyncStep + '?' + params.toString(), { credentials: 'same-origin' })
              .then(function (response) { return response.json(); })
              .then(function (data) {
                if (!data.url) {
                  return;
                }
                // When filtering by a domain, step onto that domain's host so
                // the URI changes to the selected domain (content is
                // domain-specific). data.url is a root-relative alias.
                const opt = siteSelect ? siteSelect.options[siteSelect.selectedIndex] : null;
                const domainUrl = opt && opt.dataset ? opt.dataset.domainUrl : '';
                const dest = domainUrl ? (new URL(domainUrl).origin + data.url) : data.url;
                if (checkbox.checked) {
                  const prodUrl = new URL(el.dataset.siteSyncUrl).origin + data.url;
                  if (openSyncWindow(prodUrl)) {
                    // Tell the destination page's load handler this URL is
                    // already synced so it does not re-open the window.
                    sessionStorage.setItem(SYNCED_KEY, prodUrl);
                  }
                }
                window.location.assign(dest);
              })
              .catch(function () { /* leave the page as-is on failure */ });
          };
          prevBtn.addEventListener('click', function () { step('prev'); });
          nextBtn.addEventListener('click', function () { step('next'); });
          randomBtn.addEventListener('click', function () { step('random'); });
        }

        // Jump box: press Enter to navigate to the entered node id. Drupal
        // redirects /node/<nid> to the node's alias; with sync enabled the
        // destination page's load handler then re-targets the prod window.
        const jumpInput = el.querySelector('.osu-cas-site-sync__jump-input');
        if (jumpInput) {
          jumpInput.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') {
              return;
            }
            event.preventDefault();
            const nid = (jumpInput.value || '').trim();
            if (/^\d+$/.test(nid) && parseInt(nid, 10) > 0) {
              window.location.assign('/node/' + nid);
            }
          });
        }

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
