<?php

declare(strict_types=1);

namespace Drupal\llm_content\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\node\NodeInterface;

/**
 * Manages xmlsitemap links for LLM content URLs.
 */
final class XmlSitemapLinkManager implements XmlSitemapLinkManagerInterface {

  /**
   * The link type used for all LLM content sitemap links.
   */
  public const LINK_TYPE = 'llm_content';

  /**
   * Subtype for node markdown URLs.
   */
  public const SUBTYPE_NODE = 'node_markdown';

  /**
   * Subtype for index endpoint URLs.
   */
  public const SUBTYPE_INDEX = 'index';

  /**
   * Number of links to save per batch in syncAllLinks().
   */
  private const SYNC_BATCH_SIZE = 100;

  /**
   * Constructs a new XmlSitemapLinkManager.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param object|null $linkStorage
   *   The xmlsitemap link storage service, or NULL if xmlsitemap is absent.
   *   Typed as object because XmlSitemapLinkStorageInterface may not exist.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
    protected ?object $linkStorage,
    protected TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isAvailable(): bool {
    return $this->moduleHandler->moduleExists('xmlsitemap');
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    if (!$this->isAvailable() || !$this->linkStorage) {
      return FALSE;
    }
    return (bool) $this->configFactory->get('llm_content.settings')
      ->get('xmlsitemap_integration');
  }

  /**
   * {@inheritdoc}
   */
  public function saveNodeLink(NodeInterface $node): void {
    if (!$this->isEnabled()) {
      return;
    }

    $config = $this->configFactory->get('llm_content.settings');

    $link = [
      'type' => self::LINK_TYPE,
      'id' => (string) $node->id(),
      'subtype' => self::SUBTYPE_NODE,
      'loc' => '/node/' . $node->id() . '/llm-md',
      'language' => $node->language()->getId(),
      'access' => $node->isPublished() ? 1 : 0,
      'status' => 1,
      'lastmod' => $node->getChangedTime(),
      'priority' => (float) $config->get('xmlsitemap_priority'),
      'changefreq' => (int) $config->get('xmlsitemap_changefreq'),
    ];

    $this->linkStorage->save($link);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteNodeLink(int $nid): void {
    if (!$this->isEnabled()) {
      return;
    }

    $this->linkStorage->delete(self::LINK_TYPE, (string) $nid);
  }

  /**
   * {@inheritdoc}
   */
  public function saveIndexLinks(): int {
    if (!$this->isEnabled()) {
      return 0;
    }

    $config = $this->configFactory->get('llm_content.settings');
    $priority = (float) $config->get('xmlsitemap_index_priority');
    $changefreq = (int) $config->get('xmlsitemap_changefreq');

    $indexLinks = [
      'llms_txt' => '/llms.txt',
      'llms_full_txt' => '/llms-full.txt',
    ];

    $saved = 0;
    foreach ($indexLinks as $id => $loc) {
      $link = [
        'type' => self::LINK_TYPE,
        'id' => $id,
        'subtype' => self::SUBTYPE_INDEX,
        'loc' => $loc,
        'language' => '',
        'access' => 1,
        'status' => 1,
        'lastmod' => $this->time->getRequestTime(),
        'priority' => $priority,
        'changefreq' => $changefreq,
      ];
      $this->linkStorage->save($link);
      $saved++;
    }
    return $saved;
  }

  /**
   * {@inheritdoc}
   */
  public function syncAllLinks(): int {
    if (!$this->isEnabled()) {
      return 0;
    }

    // Remove existing links first.
    $this->removeAllLinks();

    // Save index links.
    $saved = $this->saveIndexLinks();

    // Save links for all nodes with stored markdown in batches.
    $config = $this->configFactory->get('llm_content.settings');
    $enabledTypes = $config->get('enabled_content_types') ?? [];
    if (empty($enabledTypes)) {
      return $saved;
    }

    $priority = (float) $config->get('xmlsitemap_priority');
    $changefreq = (int) $config->get('xmlsitemap_changefreq');
    $offset = 0;

    do {
      $query = $this->database->select('llm_content_markdown', 'm');
      $query->innerJoin('node_field_data', 'n', 'm.nid = n.nid AND m.langcode = n.langcode');
      $query->fields('m', ['nid', 'langcode']);
      $query->fields('n', ['type', 'status', 'changed']);
      $query->condition('n.type', $enabledTypes, 'IN');
      $query->range($offset, self::SYNC_BATCH_SIZE);
      $results = $query->execute()->fetchAll();

      foreach ($results as $row) {
        $link = [
          'type' => self::LINK_TYPE,
          'id' => (string) $row->nid,
          'subtype' => self::SUBTYPE_NODE,
          'loc' => '/node/' . $row->nid . '/llm-md',
          'language' => $row->langcode,
          'access' => (int) $row->status,
          'status' => 1,
          'lastmod' => (int) $row->changed,
          'priority' => $priority,
          'changefreq' => $changefreq,
        ];
        $this->linkStorage->save($link);
        $saved++;
      }

      $offset += self::SYNC_BATCH_SIZE;
    } while (count($results) === self::SYNC_BATCH_SIZE);

    return $saved;
  }

  /**
   * {@inheritdoc}
   */
  public function removeAllLinks(): void {
    if (!$this->isAvailable() || !$this->linkStorage) {
      return;
    }

    $this->linkStorage->deleteMultiple(['type' => self::LINK_TYPE]);
  }

}
