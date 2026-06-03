<?php

namespace Drupal\osu_cas_legacy_link\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block linking to the corresponding node on the legacy D7 site.
 *
 * @Block(
 *   id = "osu_cas_legacy_link",
 *   admin_label = @Translation("Legacy site link"),
 *   category = @Translation("OSU CAS")
 * )
 */
class LegacyLinkBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new LegacyLinkBlock.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'base_url' => 'https://agsci.oregonstate.edu',
      'link_text' => 'View this page on the legacy site',
      'open_in_new_tab' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $config = $this->getConfiguration();

    $form['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Legacy site base URL'),
      '#description' => $this->t('The base URL of the legacy Drupal 7 site, without trailing slash. The path /node/{nid} will be appended.'),
      '#default_value' => $config['base_url'],
      '#required' => TRUE,
    ];

    $form['link_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link text'),
      '#default_value' => $config['link_text'],
      '#required' => TRUE,
    ];

    $form['open_in_new_tab'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Open link in a new tab'),
      '#default_value' => $config['open_in_new_tab'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['base_url'] = rtrim($form_state->getValue('base_url'), '/');
    $this->configuration['link_text'] = $form_state->getValue('link_text');
    $this->configuration['open_in_new_tab'] = (bool) $form_state->getValue('open_in_new_tab');
  }

  /**
   * Gets the node from the current route, if any.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node, or NULL if not on a node page.
   */
  protected function getCurrentNode() {
    $node = $this->routeMatch->getParameter('node');
    // On revision routes, $node is the revision ID, not the entity.
    if ($node instanceof \Drupal\node\NodeInterface) {
      return $node;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    // Permission gate.
    $access = AccessResult::allowedIfHasPermission($account, 'view osu cas legacy link');

    // Only render on node pages.
    if ($access->isAllowed() && !$this->getCurrentNode()) {
      $access = AccessResult::forbidden('Not on a node page.');
    }

    // Cache per route and per user permissions.
    return $access->addCacheContexts(['route', 'user.permissions']);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->getCurrentNode();
    if (!$node) {
      return [];
    }

    $config = $this->getConfiguration();
    $url = $config['base_url'] . '/node/' . $node->id();

    $build = [
      '#theme' => 'osu_cas_legacy_link',
      '#url' => $url,
      '#nid' => $node->id(),
      '#link_text' => $config['link_text'],
      '#attributes' => [
        'class' => ['osu-cas-legacy-link'],
      ],
    ];

    if (!empty($config['open_in_new_tab'])) {
      $build['#attached']['library'][] = 'osu_cas_legacy_link/legacy_link';
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // Vary by route so the link updates per node.
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $tags = parent::getCacheTags();
    if ($node = $this->getCurrentNode()) {
      $tags = Cache::mergeTags($tags, $node->getCacheTags());
    }
    return $tags;
  }

}
