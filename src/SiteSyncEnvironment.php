<?php

namespace Drupal\osu_cas_site_sync;

use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the current environment's hostname for each domain.
 *
 * Domain records store *production* hostnames — the config export is
 * environment-neutral — but every environment serves the same sites under a
 * derived hostname (see docroot/sites/sites.php):
 *
 * | production           | DDEV                      | Acquia dev               | Acquia stage               |
 * |----------------------|---------------------------|--------------------------|----------------------------|
 * | bee.oregonstate.edu  | ddev.bee.oregonstate.edu  | bee.dev.oregonstate.edu  | bee.stage.oregonstate.edu  |
 * | barleyworld.org      | ddev.barleyworld.org      | dev.barleyworld.org      | stage.barleyworld.org      |
 *
 * So DDEV prefixes "ddev."; dev/stage insert their token as the second label
 * of an oregonstate.edu hostname and prefix it on any other hostname.
 *
 * The block uses this to keep site links (the domain dropdown, and the
 * domain the request is on) inside the current environment. The compare
 * link is unrelated: it always points at the single D7 preview host,
 * configured on the block.
 */
class SiteSyncEnvironment {

  /**
   * Environment identifiers.
   */
  const PRODUCTION = 'prod';
  const DDEV = 'ddev';
  const DEV = 'dev';
  const STAGE = 'stage';

  /**
   * The registrable domain whose hostnames take the token as a second label.
   */
  const OSU_DOMAIN = 'oregonstate.edu';

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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The domain negotiator, when the domain module is installed.
   *
   * @var \Drupal\domain\DomainNegotiatorInterface|null
   */
  protected $domainNegotiator;

  /**
   * The detected environment, or NULL before the first lookup.
   *
   * @var string|null
   */
  protected $environment;

  /**
   * Per-request cache of the domain map.
   *
   * @var array|null
   */
  protected $domains;

  /**
   * Constructs a new SiteSyncEnvironment.
   */
  public function __construct(RequestStack $request_stack, StorageInterface $config_storage, EntityTypeManagerInterface $entity_type_manager, $domain_negotiator = NULL) {
    $this->requestStack = $request_stack;
    $this->configStorage = $config_storage;
    $this->entityTypeManager = $entity_type_manager;
    $this->domainNegotiator = $domain_negotiator;
  }

  /**
   * Returns the environment this site is running in.
   *
   * @return string
   *   One of the environment constants.
   */
  public function getEnvironment(): string {
    if ($this->environment === NULL) {
      $this->environment = $this->detectEnvironment();
    }
    return $this->environment;
  }

  /**
   * Detects the environment from the hosting platform's own signals.
   *
   * Acquia calls the staging environment "test" and may number the
   * development ones (dev2, dev3); anything unrecognised — including
   * continuous delivery environments, whose hostnames follow no pattern we
   * can derive — is treated as production, i.e. hostnames are left alone.
   *
   * @return string
   *   One of the environment constants.
   */
  protected function detectEnvironment(): string {
    $override = Settings::get('osu_cas_site_sync_environment');
    if (in_array($override, [self::PRODUCTION, self::DDEV, self::DEV, self::STAGE], TRUE)) {
      return $override;
    }
    if (getenv('IS_DDEV_PROJECT') === 'true') {
      return self::DDEV;
    }
    $acquia = getenv('AH_SITE_ENVIRONMENT') ?: ($_ENV['AH_SITE_ENVIRONMENT'] ?? '');
    if (str_starts_with($acquia, 'dev')) {
      return self::DEV;
    }
    if (str_starts_with($acquia, 'test') || str_starts_with($acquia, 'stg') || str_starts_with($acquia, 'stage')) {
      return self::STAGE;
    }
    return self::PRODUCTION;
  }

  /**
   * Maps a production hostname onto the current environment.
   *
   * @param string $hostname
   *   A production hostname, e.g. "bee.oregonstate.edu".
   *
   * @return string
   *   The hostname serving that site in this environment.
   */
  public function toEnvironmentHostname(string $hostname): string {
    $environment = $this->getEnvironment();
    if ($environment === self::PRODUCTION || $hostname === '') {
      return $hostname;
    }
    if ($environment === self::DDEV) {
      return 'ddev.' . $hostname;
    }

    // Dev and stage take the token as a label inside oregonstate.edu
    // hostnames (bee.dev.oregonstate.edu, support.dev.roots.oregonstate.edu)
    // and as a prefix everywhere else (dev.barleyworld.org).
    $suffix = '.' . self::OSU_DOMAIN;
    if (str_ends_with($hostname, $suffix)) {
      $prefix = substr($hostname, 0, -strlen($suffix));
      [$first, $rest] = array_pad(explode('.', $prefix, 2), 2, '');
      if ($first !== '') {
        return $first . '.' . $environment . ($rest !== '' ? '.' . $rest : '') . $suffix;
      }
    }
    return $environment . '.' . $hostname;
  }

  /**
   * Returns every domain with its production and environment hostnames.
   *
   * @return array
   *   Keyed by domain id, each value an array with:
   *   - production: the production hostname.
   *   - hostname: the hostname serving this domain in this environment.
   *   - url: the domain's home page in this environment.
   */
  public function getDomains(): array {
    if ($this->domains !== NULL) {
      return $this->domains;
    }
    $this->domains = [];
    if (!$this->entityTypeManager->hasDefinition('domain')) {
      return $this->domains;
    }

    foreach ($this->entityTypeManager->getStorage('domain')->loadMultiple() as $domain) {
      // The raw config holds the production hostname; the loaded entity may
      // carry an environment override (DDEV sets one per domain record in
      // settings.local.php).
      $record = $this->configStorage->read('domain.record.' . $domain->id()) ?: [];
      $production = $record['hostname'] ?? $domain->getHostname();
      $scheme = in_array($record['scheme'] ?? '', ['http', 'https'], TRUE) ? $record['scheme'] : 'https';
      // An override is what domain negotiation actually matches, so it wins
      // over the derived hostname; without one, derive it.
      $hostname = $domain->getHostname() !== $production
        ? $domain->getHostname()
        : $this->toEnvironmentHostname($production);

      $this->domains[$domain->id()] = [
        'production' => $production,
        'hostname' => $hostname,
        'url' => $scheme . '://' . $hostname . base_path(),
      ];
    }

    return $this->domains;
  }

  /**
   * Returns the domain id to fall back on when nothing else resolves.
   *
   * @return string|null
   *   The default domain's id (agsci, the one flagged is_default), the first
   *   domain when none is flagged, or NULL when there are no domains.
   */
  public function getDefaultDomainId(): ?string {
    $domains = $this->getDomains();
    if (!$domains) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('domain');
    $default = method_exists($storage, 'loadDefaultId') ? $storage->loadDefaultId() : NULL;
    return isset($domains[$default]) ? $default : array_key_first($domains);
  }

  /**
   * Returns the domain id the current request is being served under.
   *
   * On Acquia dev and stage the request hostname never matches a domain
   * record (the records hold production hostnames), so the negotiator falls
   * back to the default domain. Matching the request against each domain's
   * environment hostname resolves it correctly instead.
   *
   * @return string|null
   *   The domain id, or NULL when none can be resolved.
   */
  public function getActiveDomainId(): ?string {
    $request = $this->requestStack->getCurrentRequest();
    $host = $request ? strtolower($request->getHost()) : '';
    if ($host !== '') {
      foreach ($this->getDomains() as $id => $info) {
        if (strtolower($info['hostname']) === $host) {
          return $id;
        }
      }
    }
    if ($this->domainNegotiator && ($domain = $this->domainNegotiator->getActiveDomain())) {
      return $domain->id();
    }
    return NULL;
  }

}
