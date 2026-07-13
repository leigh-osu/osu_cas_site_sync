<?php

namespace Drupal\osu_cas_site_sync\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Steps through nodes for the site sync slideshow.
 *
 * Resolves the next/previous node id (in nid order) within the selected
 * node type — or all nodes — and redirects to that node's canonical URL,
 * so the path alias is applied and the sync window follows along. Wraps
 * around at either end of the list.
 */
class SlideshowController extends ControllerBase {

  /**
   * Redirects to the next/previous node of the selected type.
   */
  public function step(Request $request) {
    $dir = $request->query->get('dir') === 'prev' ? 'prev' : 'next';
    $type = $request->query->get('type', 'all');
    $site = $request->query->get('site', 'all');
    $from = (int) $request->query->get('from', 0);

    $nid = $this->queryStep($dir, $type, $site, $from);
    if (!$nid && $from) {
      // Past either end of the list: wrap around.
      $nid = $this->queryStep($dir, $type, $site, 0);
    }

    // The block's JS asks for JSON so it can point the prod sync window at
    // the destination within the button click's user gesture (pop-up
    // blockers deny window.open on the following page load), then navigate.
    $as_json = $request->query->get('respond') === 'json';

    if (!$nid) {
      if ($as_json) {
        return new JsonResponse(['url' => NULL]);
      }
      $this->messenger()->addWarning($this->t('No nodes found for the selected slideshow type.'));
      return new RedirectResponse($request->headers->get('referer') ?: '/');
    }

    $url = $this->entityTypeManager()->getStorage('node')->load($nid)
      ->toUrl()->toString();
    if ($as_json) {
      return new JsonResponse(['url' => $url, 'nid' => $nid]);
    }
    return new RedirectResponse($url);
  }

  /**
   * Finds the adjacent node id in nid order.
   *
   * @param string $dir
   *   Direction, "next" or "prev".
   * @param string $type
   *   A node type machine name, or "all".
   * @param string $site
   *   "all", "domain:<domain id>" or "group:<group id>".
   * @param int $from
   *   The nid to step from; 0 selects the first/last of the whole list.
   *
   * @return int|null
   *   The adjacent nid, or NULL when there is none.
   */
  protected function queryStep(string $dir, string $type, string $site, int $from) {
    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->range(0, 1);

    if ($type !== 'all') {
      $query->condition('type', $type);
    }
    if ($site === 'affiliates') {
      // Content flagged "send to all affiliates" (renders on every site).
      $query->condition('field_domain_all_affiliates', 1);
    }
    elseif (str_starts_with($site, 'domain:')) {
      // Strictly the nodes assigned to the selected domain. "Send to all
      // affiliates" content is deliberately excluded — it renders on every
      // site, so including it would make each domain's list look the same
      // (8k+ nodes carry the flag).
      $query->condition('field_domain_access', substr($site, 7));
    }
    elseif (str_starts_with($site, 'group:')) {
      $nids = \Drupal::database()->query(
        "SELECT entity_id FROM {group_relationship_field_data} WHERE gid = :gid AND plugin_id LIKE 'group_node:%'",
        [':gid' => (int) substr($site, 6)]
      )->fetchCol();
      if (!$nids) {
        return NULL;
      }
      $query->condition('nid', $nids, 'IN');
    }
    if ($dir === 'next') {
      $query->sort('nid', 'ASC');
      if ($from) {
        $query->condition('nid', $from, '>');
      }
    }
    else {
      $query->sort('nid', 'DESC');
      if ($from) {
        $query->condition('nid', $from, '<');
      }
    }

    $ids = $query->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

}
