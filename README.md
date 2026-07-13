# OSU CAS Site Sync

Development helper for the CAS Drupal 7 → 10 migration. Places a block that
links the page being viewed on the local/dev site to its counterpart on the
production site, and can keep a dedicated browser window in sync while you
navigate.

## What it does

- **Prod link** — the block shows a link to the current page on production.
  The hostname is domain-aware: the active domain's raw `domain.record`
  config supplies the production hostname (local hostname overrides, e.g.
  DDEV's, are ignored), and the current request URI (path alias + query
  string) is appended. On node pages the node id is shown for reference.
- **Sync with Prod** — a checkbox in the block. While checked, every page
  you visit re-targets a single named browser window (`osuCasProdSync`) at
  that page's production counterpart, opened on the right half of the screen
  for a side-by-side compare. The state persists per site in localStorage.
- Clicking the link sends the prod page to the same side-by-side window.
- **Slideshow** — two dropdowns ("All Sites" / "All Affiliates" / a domain / a group, and
  "All Nodes" / a node type) with back/forward buttons. Each press navigates
  the local site to the previous or next node id in the selection
  (published, access-checked, wrapping at the ends). Domain selections
  filter strictly by domain assignment (all-affiliates content only appears
  under "All Sites"); group selections filter by group membership. The
  arrows grey out when the selection matches no nodes. The destination is
  resolved before navigating so, with sync enabled, the prod window is
  re-targeted inside the click gesture — no pop-up permission needed for
  stepping.
- **NID chip** — on node pages the line starts with the node id; clicking
  it copies the id to the clipboard.

Browsers only allow `window.open()` without a user gesture when pop-ups are
permitted for the site — allow pop-ups on the local site for hands-free
syncing; the block shows a hint when the open is blocked.

## Setup

1. Enable the module: `drush en osu_cas_site_sync`
2. Place the **Prod site sync** block (category "OSU CAS") in any region.
3. Grant the *Use the prod site sync block* permission to the roles that
   should see it.

The block's only settings are the link text and a fallback base URL used
when no active domain can be resolved (e.g. the domain module is not
installed).
