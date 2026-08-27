# OSU CAS Site Sync

Development helper for the CAS Drupal 7 → 10 migration. Places a block that
links the page being viewed on the local/dev site to its counterpart on the
D7 site, and can keep a dedicated browser window in sync while you
navigate.

Since the domain cutover the per-domain production hostnames serve the D10
site; the D7 site sits behind the single `agsci.prod.oregonstate.edu`
preview hostname. Every sync link therefore targets that one host (the
block's *D7 sync base URL* setting), regardless of the active D10 domain.

## What it does

- **D7 link** — the block shows a link to the current page on the D7 site.
  Node pages link as `/node/NID`, never by alias: the same alias can exist
  on several domains, and on the single D7 site it would resolve to
  whichever node happens to own it. Profile nodes link to the D7 user page
  (`/user/UID`, via the migrate map); other pages append the request URI.
  On node pages the node id is shown for reference.
- **Sync with D7** — a checkbox in the block. While checked, every page
  you visit re-targets a single named browser window (`osuCasProdSync`) at
  that page's D7 counterpart, opened on the right half of the screen
  for a side-by-side compare. The state persists per site in localStorage.
- Clicking the link sends the D7 page to the same side-by-side window.
- **Slideshow** — two dropdowns ("All Sites" / "All Affiliates" / a domain / a group, and
  "All Nodes" / a node type) with back/forward buttons. Each press navigates
  the local site to the previous or next node id in the selection
  (published, wrapping at the ends). Domain selections filter strictly by
  domain assignment (all-affiliates content only appears under "All Sites");
  group selections filter by group membership and step onto the group's
  canonical domain. "All Sites" and "All Affiliates" span domains and step
  onto each node's own domain, so content that only exists on another site
  is still reachable. The
  arrows grey out when the selection matches no nodes. The destination is
  resolved before navigating so, with sync enabled, the prod window is
  re-targeted inside the click gesture — no pop-up permission needed for
  stepping.
- **NID chip** — on node pages the line starts with the node id; clicking
  it copies the id to the clipboard.
- **D7 Paragraphs filter** — the type dropdown also lists every D7 paragraph
  migration (with a node count), stepping through the nodes that hold the
  migrated blocks. A cached one-pass scan of the serialized layout sections
  resolves block revisions back through each migrate map; container types
  that became native sections (adjustable columns, picbox grids) resolve
  through their field-collection child maps. Types with no reachable nodes
  are omitted.
- **Random** — a "?" button after the arrows jumps to a random node within
  the current selection.

## Environments

Domain records store production hostnames, but each environment serves the
same sites under a derived hostname (see `docroot/sites/sites.php`):

| production            | DDEV                       | Acquia dev                | Acquia stage                |
|-----------------------|----------------------------|---------------------------|-----------------------------|
| `bee.oregonstate.edu` | `ddev.bee.oregonstate.edu` | `bee.dev.oregonstate.edu` | `bee.stage.oregonstate.edu` |
| `barleyworld.org`     | `ddev.barleyworld.org`     | `dev.barleyworld.org`     | `stage.barleyworld.org`     |

`SiteSyncEnvironment` (service `osu_cas_site_sync.environment`) derives those
hostnames, so the two directions stay separate everywhere:

- **Site links stay in the current environment.** Picking a domain in the
  slideshow dropdown, and stepping onto a node of another domain, go to that
  domain's hostname *in this environment* — never to production. A group
  selection uses the group's canonical domain (`field_domain_source`), or the
  default domain (agsci) when the group has none.
- **The compare link always goes to the D7 host.** Its origin is the
  block's configured D7 sync base URL — the same for every domain — and
  node pages are addressed there as `/node/NID`, so stepping across domains
  keeps the sync window on the one D7 site without alias conflicts.

The environment is detected from `IS_DDEV_PROJECT` and Acquia's
`AH_SITE_ENVIRONMENT` (Acquia's `test` is this project's *stage*). Anything
unrecognised is treated as production, i.e. hostnames are left untouched.
Force it with `$settings['osu_cas_site_sync_environment'] = 'ddev';` (or
`dev`, `stage`, `prod`).

Stepping deliberately runs its node query without an access check. Domain
Access scopes node access to the *active* domain, so an access-checked query
hid every node belonging to another site — "All Sites" silently meant "this
site" for everyone except uid 1, which bypasses node access. Only published
nodes are listed and the browser lands on the node's own domain, where normal
access applies, so nothing is exposed that is not already public there.

Resolving the active domain goes through the same mapping rather than the
domain negotiator, which on Acquia dev and stage cannot match the request
hostname against a production `domain.record` and falls back to the default
domain. Where an environment overrides the records itself — DDEV does, in
`settings.local.php` — that override wins, since it is what negotiation
matches.

Browsers only allow `window.open()` without a user gesture when pop-ups are
permitted for the site — allow pop-ups on the local site for hands-free
syncing; the block shows a hint when the open is blocked.

## Setup

1. Enable the module: `drush en osu_cas_site_sync`
2. Place the **Prod site sync** block (category "OSU CAS") in any region.
3. Grant the *Use the prod site sync block* permission to the roles that
   should see it.

The block's only settings are the link text and the D7 sync base URL
(default `https://agsci.prod.oregonstate.edu`) that every sync link
targets.
