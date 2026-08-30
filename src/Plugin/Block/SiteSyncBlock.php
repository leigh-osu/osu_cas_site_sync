<?php

namespace Drupal\osu_cas_site_sync\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\osu_cas_site_sync\ParagraphMap;
use Drupal\osu_cas_site_sync\SiteSyncEnvironment;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Links the current page to its counterpart on the D7 site.
 *
 * Since the domain cutover the per-domain production hostnames serve this
 * D10 site, so the compare target is the D7 preview host
 * (agsci.prod.oregonstate.edu) — one host for every domain, because D7 was
 * a single site. Node pages are addressed there as /node/NID rather than by
 * alias: the same alias can exist on several domains, and on the single D7
 * site it would resolve to whichever node happens to own it.
 *
 * The site dropdown, by contrast, stays inside the current environment
 * (DDEV, Acquia dev or Acquia stage), so switching sites does not leave it.
 *
 * @Block(
 *   id = "osu_cas_site_sync",
 *   admin_label = @Translation("Prod site sync"),
 *   category = @Translation("OSU CAS")
 * )
 */
class SiteSyncBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The MMI domain id: its content syncs against the mmi D7 site.
   */
  const MMI_DOMAIN_ID = 'mmi_oregonstate_edu';

  /**
   * The mmi D7 site's base URL (its production hostname until cutover).
   */
  const MMI_D7_BASE_URL = 'https://mmi.oregonstate.edu';

  /**
   * The MMI migration's nid offset (MmiNidOffset::OFFSET, without the
   * module dependency).
   */
  const MMI_NID_OFFSET = 400000;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The environment/hostname resolver.
   *
   * @var \Drupal\osu_cas_site_sync\SiteSyncEnvironment
   */
  protected $environment;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The paragraph-to-node map.
   *
   * @var \Drupal\osu_cas_site_sync\ParagraphMap
   */
  protected $paragraphMap;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new SiteSyncBlock.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, RequestStack $request_stack, SiteSyncEnvironment $environment, EntityTypeManagerInterface $entity_type_manager, ParagraphMap $paragraph_map, Connection $database) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->requestStack = $request_stack;
    $this->environment = $environment;
    $this->entityTypeManager = $entity_type_manager;
    $this->paragraphMap = $paragraph_map;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('request_stack'),
      $container->get('osu_cas_site_sync.environment'),
      $container->get('entity_type.manager'),
      $container->get('osu_cas_site_sync.paragraph_map'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'base_url' => 'https://agsci.prod.oregonstate.edu',
      'link_text' => 'View D7',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $config = $this->getConfiguration();

    $form['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('D7 sync base URL'),
      '#description' => $this->t('Every sync link points at this host regardless of the active domain (the D7 site is a single site behind one preview hostname). No trailing slash.'),
      '#default_value' => $config['base_url'],
      '#required' => TRUE,
    ];

    $form['link_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link text'),
      '#default_value' => $config['link_text'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['base_url'] = rtrim($form_state->getValue('base_url'), '/');
    $this->configuration['link_text'] = $form_state->getValue('link_text');
  }

  /**
   * Returns the D7 sync base URL.
   *
   * One host for every domain: the per-domain production hostnames serve
   * D10 since the cutover, and the whole D7 site sits behind the single
   * agsci.prod preview hostname.
   *
   * @return string
   *   The D7 base URL, without trailing slash.
   */
  protected function getProdBaseUrl() {
    return rtrim($this->getConfiguration()['base_url'], '/');
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    return AccessResult::allowedIfHasPermission($account, 'use osu cas site sync')
      ->addCacheContexts(['user.permissions']);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $request = $this->requestStack->getCurrentRequest();
    $node = $this->routeMatch->getParameter('node');
    $nid = ($node instanceof \Drupal\node\NodeInterface) ? $node->id() : NULL;

    // Profiles were migrated from D7 user accounts, so the D7 counterpart of
    // a profile node is the user page (/user/<uid>), not this node's path.
    $prod = $this->getProdBaseUrl();
    $is_mmi = $node instanceof \Drupal\node\NodeInterface
      ? $this->nodeIsMmi($node)
      : $this->environment->getActiveDomainId() === self::MMI_DOMAIN_ID;
    if ($is_mmi) {
      // MMI content came from the separate mmi D7 site, which still serves
      // its production hostname until that domain's cutover.
      $prod = self::MMI_D7_BASE_URL;
    }
    if ($node instanceof \Drupal\node\NodeInterface
      && $node->bundle() === 'osu_profile'
      && ($uid = $is_mmi
        ? $this->mmiD7UserIdForProfile((int) $node->id())
        : $this->d7UserIdForProfile((int) $node->id()))) {
      $url = $prod . '/user/' . $uid;
    }
    elseif ($nid) {
      // /node/NID, never the alias: the same alias can exist on several
      // domains, and on the single D7 site it resolves to whichever node
      // happens to own it. MMI nids carry the migration's +400000 offset
      // (profile nodes are auto-assigned below it and never hit this: they
      // resolve to /user/<uid> above); the D7 site knows the original.
      $url = $prod . '/node/' . ($is_mmi && $nid >= self::MMI_NID_OFFSET ? $nid - self::MMI_NID_OFFSET : $nid);
    }
    else {
      $url = $prod . $request->getRequestUri();
    }

    $node_types = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $node_type) {
      $node_types[$node_type->id()] = $node_type->label();
    }
    asort($node_types);

    // D7 paragraph migrations: label shows how many nodes hold blocks
    // migrated from that type.
    $paragraph_types = [];
    foreach ($this->paragraphMap->getMap() as $type => $nids) {
      // Skip types whose blocks nothing references (e.g. paragraph_menu,
      // whose migrated blocks were superseded by osu_menu_bar_item
      // components) — a dead entry would only ever grey the arrows.
      if ($nids) {
        $paragraph_types[$type] = $type . ' (' . count($nids) . ')';
      }
    }

    // The production hostname is the recognisable name to label a domain
    // with (the label is often a long site title), but the URL stays in this
    // environment so picking a domain switches sites without leaving DDEV /
    // dev / stage. The sync window needs no per-domain hostname: it always
    // goes to the single D7 host, by node id.
    $site_domains = [];
    foreach ($this->environment->getDomains() as $id => $info) {
      $site_domains[$id] = [
        'label' => $info['production'],
        // The domain's home page (base URL), not the current path -- content
        // is domain-specific, so the current path would often 404 elsewhere.
        'url' => $info['url'],
      ];
    }
    uasort($site_domains, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

    // A group's nodes live on the group's canonical domain
    // (field_domain_source), so picking a group switches to that domain the
    // same way picking the domain itself does. Groups with no canonical
    // domain set fall back to the default domain (agsci).
    $site_groups = [];
    if ($this->entityTypeManager->hasDefinition('group')) {
      $domains = $this->environment->getDomains();
      $fallback = $this->environment->getDefaultDomainId();
      foreach ($this->entityTypeManager->getStorage('group')->loadMultiple() as $group) {
        $canonical = $group->hasField('field_domain_source')
          ? $group->get('field_domain_source')->target_id
          : NULL;
        if (!isset($domains[$canonical])) {
          $canonical = $fallback;
        }
        $site_groups[$group->id()] = [
          'label' => $group->label(),
          'url' => $domains[$canonical]['url'] ?? '',
        ];
      }
      uasort($site_groups, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
    }

    return [
      '#theme' => 'osu_cas_site_sync',
      '#url' => $url,
      '#prod_base' => $prod,
      '#nid' => $nid,
      '#link_text' => $this->getConfiguration()['link_text'],
      '#node_types' => $node_types,
      '#paragraph_types' => $paragraph_types,
      '#site_domains' => $site_domains,
      '#site_groups' => $site_groups,
      '#step_url' => Url::fromRoute('osu_cas_site_sync.step')->toString(),
      '#cache' => [
        'tags' => ['config:node_type_list', 'config:domain_record_list', 'group_list'],
      ],
      '#attached' => [
        'library' => ['osu_cas_site_sync/site_sync'],
      ],
    ];
  }

  /**
   * Whether a node belongs to the MMI domain.
   *
   * MMI content was migrated from its own D7 site (not the agsci one), so
   * its sync links must point there. The canonical domain is the signal:
   * every MMI-migrated node sets field_domain_source to the mmi domain.
   */
  protected function nodeIsMmi(\Drupal\node\NodeInterface $node): bool {
    return $node->hasField('field_domain_source')
      && $node->get('field_domain_source')->target_id === self::MMI_DOMAIN_ID;
  }

  /**
   * Resolves the mmi D7 uid an MMI profile node was migrated from.
   */
  protected function mmiD7UserIdForProfile(int $nid): ?int {
    $table = 'migrate_map_mmi_profiles';
    if (!$this->database->schema()->tableExists($table)) {
      return NULL;
    }
    $uid = $this->database->select($table, 'm')
      ->fields('m', ['sourceid1'])
      ->condition('destid1', $nid)
      ->execute()
      ->fetchField();
    return $uid ? (int) $uid : NULL;
  }

  /**
   * Resolves the D7 uid a profile node was migrated from.
   *
   * @param int $nid
   *   The osu_profile node id.
   *
   * @return int|null
   *   The source D7 uid, or NULL if the migration map is unavailable or has
   *   no row for this profile.
   */
  protected function d7UserIdForProfile(int $nid): ?int {
    $table = 'migrate_map_upgrade_d7_user_to_profile';
    if (!$this->database->schema()->tableExists($table)) {
      return NULL;
    }
    $uid = $this->database->select($table, 'm')
      ->fields('m', ['sourceid1'])
      ->condition('destid1', $nid)
      ->execute()
      ->fetchField();
    return $uid ? (int) $uid : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // The link mirrors the full request URI on the active domain.
    return Cache::mergeContexts(parent::getCacheContexts(), [
      'url.path',
      'url.query_args',
      'url.site',
    ]);
  }

}
