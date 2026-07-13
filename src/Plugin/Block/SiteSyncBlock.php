<?php

namespace Drupal\osu_cas_site_sync\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Links the current page to its counterpart on the production site.
 *
 * The production hostname follows the active domain: the domain negotiator
 * supplies the domain id and the raw (un-overridden) domain.record config
 * supplies the production hostname, so local DDEV hostname overrides in
 * settings never leak into the generated link.
 *
 * @Block(
 *   id = "osu_cas_site_sync",
 *   admin_label = @Translation("Prod site sync"),
 *   category = @Translation("OSU CAS")
 * )
 */
class SiteSyncBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * The raw config storage (no runtime overrides).
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $configStorage;

  /**
   * The domain negotiator, when the domain module is installed.
   *
   * @var \Drupal\domain\DomainNegotiatorInterface|null
   */
  protected $domainNegotiator;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new SiteSyncBlock.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, RequestStack $request_stack, StorageInterface $config_storage, $domain_negotiator, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->requestStack = $request_stack;
    $this->configStorage = $config_storage;
    $this->domainNegotiator = $domain_negotiator;
    $this->entityTypeManager = $entity_type_manager;
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
      $container->get('config.storage'),
      $container->has('domain.negotiator') ? $container->get('domain.negotiator') : NULL,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'base_url' => 'https://agsci.oregonstate.edu',
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
      '#title' => $this->t('Fallback production base URL'),
      '#description' => $this->t('Used when no active domain can be resolved. The production hostname normally comes from the active domain record, without trailing slash.'),
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
   * Resolves the production base URL for the active domain.
   *
   * @return string
   *   The production base URL, without trailing slash.
   */
  protected function getProdBaseUrl() {
    if ($this->domainNegotiator && ($domain = $this->domainNegotiator->getActiveDomain())) {
      // The raw config holds the production hostname; the loaded domain
      // entity may carry a local-development override (e.g. a ddev. prefix).
      $record = $this->configStorage->read('domain.record.' . $domain->id());
      if (!empty($record['hostname'])) {
        $scheme = $record['scheme'] ?? 'https';
        // "variable" means "use the negotiated scheme"; prod is https.
        $scheme = in_array($scheme, ['http', 'https'], TRUE) ? $scheme : 'https';
        return $scheme . '://' . $record['hostname'];
      }
    }
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
    $url = $this->getProdBaseUrl() . $request->getRequestUri();

    $nid = NULL;
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof \Drupal\node\NodeInterface) {
      $nid = $node->id();
    }

    $node_types = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $node_type) {
      $node_types[$node_type->id()] = $node_type->label();
    }
    asort($node_types);

    $site_domains = [];
    if ($this->entityTypeManager->hasDefinition('domain')) {
      foreach ($this->entityTypeManager->getStorage('domain')->loadMultiple() as $domain) {
        // The raw config hostname is the recognisable prod name; the label
        // is often a long site title.
        $record = $this->configStorage->read('domain.record.' . $domain->id());
        $site_domains[$domain->id()] = $record['hostname'] ?? $domain->label();
      }
      asort($site_domains);
    }

    $site_groups = [];
    if ($this->entityTypeManager->hasDefinition('group')) {
      foreach ($this->entityTypeManager->getStorage('group')->loadMultiple() as $group) {
        $site_groups[$group->id()] = $group->label();
      }
      natcasesort($site_groups);
    }

    return [
      '#theme' => 'osu_cas_site_sync',
      '#url' => $url,
      '#nid' => $nid,
      '#link_text' => $this->getConfiguration()['link_text'],
      '#node_types' => $node_types,
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
