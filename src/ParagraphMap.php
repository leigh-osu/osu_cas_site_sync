<?php

namespace Drupal\osu_cas_site_sync;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

/**
 * Maps migrated D7 paragraph types to the nodes that now hold them.
 *
 * Each D7 paragraph migration (migrate_map_paragraph_*__to__layout_builder)
 * produced block_content entities that node layouts reference by revision id
 * inside serialized layout_builder sections (inline_block_usage is not
 * populated by the migration). One pass over node__layout_builder__layout
 * extracts every referenced block revision, joins revisions to block ids and
 * block ids to each migration's map table, yielding a per-type node list.
 *
 * Types whose blocks are only nested inside other blocks or were converted
 * to native sections (e.g. paragraph_menu, paragraph_lp_adjustable_columns)
 * resolve to no nodes and render as unavailable in the slideshow.
 */
class ParagraphMap {

  const CID = 'osu_cas_site_sync.paragraph_map';

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  public function __construct(Connection $database, CacheBackendInterface $cache) {
    $this->database = $database;
    $this->cache = $cache;
  }

  /**
   * Returns the map of D7 paragraph type => sorted node id list.
   *
   * @return array[]
   *   Node ids keyed by paragraph migration name (map table name without
   *   the migrate_map_ prefix and __to__layout_builder suffix).
   */
  public function getMap(): array {
    if ($cached = $this->cache->get(self::CID)) {
      return $cached->data;
    }
    $map = $this->build();
    // The map only changes when migrations rerun; an hour is plenty.
    $this->cache->set(self::CID, $map, time() + 3600);
    return $map;
  }

  /**
   * Returns the node ids holding blocks migrated from the given type.
   */
  public function getNids(string $type): array {
    return $this->getMap()[$type] ?? [];
  }

  /**
   * Scans layouts and migrate maps to build the type => nids map.
   */
  protected function build(): array {
    // Every block revision referenced by any node layout, with its nodes.
    $rev_to_nids = [];
    $result = $this->database->query(
      'SELECT entity_id, layout_builder__layout_section AS section FROM {node__layout_builder__layout}'
    );
    foreach ($result as $record) {
      if (preg_match_all('/"block_revision_id";i:(\d+)/', $record->section, $matches)) {
        foreach ($matches[1] as $revision_id) {
          $rev_to_nids[$revision_id][$record->entity_id] = TRUE;
        }
      }
    }

    // Block id => nids, via the revision each layout references.
    $bid_to_nids = [];
    if ($rev_to_nids) {
      $rev_to_bid = $this->database->query(
        'SELECT revision_id, id FROM {block_content}'
      )->fetchAllKeyed();
      foreach ($rev_to_bid as $revision_id => $block_id) {
        if (isset($rev_to_nids[$revision_id])) {
          $bid_to_nids[$block_id] = ($bid_to_nids[$block_id] ?? []) + $rev_to_nids[$revision_id];
        }
      }
    }

    // Some D7 paragraph rows became native sections whose column content
    // comes from field_collection child migrations; resolve those types
    // through their child map instead of their own (block-less) map.
    $aliases = [
      'field_collection_field_lp_adj_column' => 'paragraph_lp_adjustable_columns',
      'field_collection_field_lp_picbox' => 'paragraph_lp_picbox_grid',
    ];

    $map = [];
    // The LIKE stops at "_bu" because MySQL truncates long map table names
    // (e.g. ..._field_lp_adj_column__to__layout_bu) at 64 characters.
    foreach ($this->database->schema()->findTables('migrate_map_%__to__layout_bu%') as $table) {
      $type = preg_replace('/^migrate_map_|__to__layout_bu.*$/', '', $table);
      $type = $aliases[$type] ?? $type;
      $nids = $map[$type] ?? [];
      foreach ($this->database->query('SELECT destid1 FROM {' . $table . '} WHERE destid1 IS NOT NULL')->fetchCol() as $block_id) {
        if (isset($bid_to_nids[$block_id])) {
          $nids += $bid_to_nids[$block_id];
        }
      }
      $map[$type] = $nids;
    }
    foreach ($map as $type => $nids) {
      $nids = array_keys($nids);
      sort($nids, SORT_NUMERIC);
      $map[$type] = $nids;
    }
    ksort($map);
    return $map;
  }

}
