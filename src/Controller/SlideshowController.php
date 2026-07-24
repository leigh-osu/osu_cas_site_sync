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
    $dir = $request->query->get('dir');
    $dir = in_array($dir, ['prev', 'random'], TRUE) ? $dir : 'next';
    $type = $request->query->get('type', 'all');
    $site = $request->query->get('site', 'all');
    $from = (int) $request->query->get('from', 0);

    if ($dir === 'random') {
      $nid = $this->queryRandom($type, $site);
    }
    else {
      $nid = $this->queryStep($dir, $type, $site, $from);
      if (!$nid && $from) {
        // Past either end of the list: wrap around.
        $nid = $this->queryStep($dir, $type, $site, 0);
      }
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

    $node = $this->entityTypeManager()->getStorage('node')->load($nid);
    $url = $node->toUrl()->toString();
    if ($as_json) {
      // The node's own domain, so a selection that spans sites ("All Sites",
      // "All Affiliates") can send the browser to the host the node actually
      // renders on instead of 403ing on the current one.
      return new JsonResponse(['url' => $url, 'nid' => $nid] + $this->nodeDomain($node));
    }
    return new RedirectResponse($url);
  }

  /**
   * Resolves the domain a node belongs to.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return array
   *   "domain_url" (this environment) and "prod_url" (production) for the
   *   node's canonical domain, falling back to the first domain it is
   *   assigned to. Both are NULL when no domain can be resolved.
   */
  protected function nodeDomain($node): array {
    $id = NULL;
    if ($node->hasField('field_domain_source')) {
      $id = $node->get('field_domain_source')->target_id;
    }
    if (!$id && $node->hasField('field_domain_access')) {
      $ids = array_column($node->get('field_domain_access')->getValue(), 'target_id');
      $id = reset($ids) ?: NULL;
    }
    $domains = \Drupal::service('osu_cas_site_sync.environment')->getDomains();
    return [
      'domain_url' => $domains[$id]['url'] ?? NULL,
      'prod_url' => $domains[$id]['production_url'] ?? NULL,
    ];
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
    $query = $this->buildQuery($type, $site);
    if (!$query) {
      return NULL;
    }
    $query->range(0, 1);

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

  /**
   * Picks a random node id within the selection.
   */
  protected function queryRandom(string $type, string $site) {
    $query = $this->buildQuery($type, $site);
    if (!$query) {
      return NULL;
    }
    $count = (int) (clone $query)->count()->execute();
    if (!$count) {
      return NULL;
    }
    $ids = $query->sort('nid', 'ASC')
      ->range(random_int(0, $count - 1), 1)
      ->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

  /**
   * Builds the base node query for the type and site selection.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface|null
   *   The filtered query, or NULL when the selection can match nothing.
   */
  protected function buildQuery(string $type, string $site) {
    // No access check: Domain Access scopes node access to the *active*
    // domain, which would hide every node of every other site and make "All
    // Sites" mean "this site" (only uid 1, which bypasses node access, saw
    // the full list). Stepping is cross-domain by design and sends the
    // browser to the node's own domain, where normal access applies on
    // arrival. Only published nodes are listed, so this exposes nothing that
    // is not already public on the site the node belongs to.
    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1);

    if (str_starts_with($type, 'd7p:')) {
      // Nodes holding blocks migrated from the selected D7 paragraph type.
      $nids = \Drupal::service('osu_cas_site_sync.paragraph_map')
        ->getNids(substr($type, 4));
      if (!$nids) {
        return NULL;
      }
      $query->condition('nid', $nids, 'IN');
    }
    elseif ($type !== 'all') {
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

    return $query;
  }

}
