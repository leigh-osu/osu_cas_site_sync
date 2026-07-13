<?php

namespace Drupal\osu_cas_site_sync\Controller;

use Drupal\Core\Controller\ControllerBase;
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
    $from = (int) $request->query->get('from', 0);

    $nid = $this->queryStep($dir, $type, $from);
    if (!$nid && $from) {
      // Past either end of the list: wrap around.
      $nid = $this->queryStep($dir, $type, 0);
    }

    if (!$nid) {
      $this->messenger()->addWarning($this->t('No nodes found for the selected slideshow type.'));
      return new RedirectResponse($request->headers->get('referer') ?: '/');
    }

    $url = $this->entityTypeManager()->getStorage('node')->load($nid)
      ->toUrl()->toString();
    return new RedirectResponse($url);
  }

  /**
   * Finds the adjacent node id in nid order.
   *
   * @param string $dir
   *   Direction, "next" or "prev".
   * @param string $type
   *   A node type machine name, or "all".
   * @param int $from
   *   The nid to step from; 0 selects the first/last of the whole list.
   *
   * @return int|null
   *   The adjacent nid, or NULL when there is none.
   */
  protected function queryStep(string $dir, string $type, int $from) {
    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->range(0, 1);

    if ($type !== 'all') {
      $query->condition('type', $type);
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
