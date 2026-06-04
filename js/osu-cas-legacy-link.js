/**
 * @file
 * Opens the legacy site link in a window positioned on the right half
 * of the current screen, for side-by-side comparison.
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.osuCasLegacyLink = {
    attach: function (context) {
      const links = once('osu-cas-legacy-link', '.osu-cas-legacy-link a', context);

      links.forEach(function (link) {
        link.addEventListener('click', function (event) {
          // Honor modifier keys: let the user do their own thing
          // (cmd/ctrl-click for new tab, shift-click, middle-click, etc.)
          if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) {
            return;
          }

          // Right half of the available screen.
          // screen.availLeft/availTop handle multi-monitor setups where
          // the primary screen isn't at (0,0). Fall back to 0 if undefined.
          const screenLeft = (typeof screen.availLeft === 'number') ? screen.availLeft : 0;
          const screenTop = (typeof screen.availTop === 'number') ? screen.availTop : 0;
          const screenWidth = screen.availWidth || window.screen.width;
          const screenHeight = screen.availHeight || window.screen.height;

          const width = Math.floor(screenWidth / 2);
          const height = screenHeight;
          const left = screenLeft + width;
          const top = screenTop;

          const features = [
            'width=' + width,
            'height=' + height,
            'left=' + left,
            'top=' + top,
            // NOTE: do NOT add 'noopener' or 'noreferrer' here. Per the
            // HTML spec / MDN, when noopener is set, any non-empty target
            // name other than _top, _self, _parent is treated like _blank,
            // which defeats the window-reuse behavior we want from the
            // 'osuCasLegacy' name. The legacy site is same-org and trusted,
            // so dropping noopener is acceptable here.
            'popup=yes',
            'resizable=yes',
            'scrollbars=yes'
          ].join(',');

          // Use a named target so repeat clicks reuse the same window
          // instead of stacking new ones.
          const newWindow = window.open(this.href, 'osuCasLegacy', features);

          if (newWindow) {
            // Successfully opened — prevent the default target="_blank" from
            // also firing, which would create a duplicate tab.
            event.preventDefault();
            // Best-effort focus; some browsers ignore this.
            try { newWindow.focus(); } catch (e) { /* noop */ }
          }
          // If newWindow is null (popup blocker), do nothing — the default
          // target="_blank" behavior will open it in a tab as a fallback.
        });
      });
    }
  };

})(Drupal);
