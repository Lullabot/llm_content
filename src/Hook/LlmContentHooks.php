<?php

declare(strict_types=1);

namespace Drupal\llm_content\Hook;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
// phpcs:ignore Drupal.Classes.UnusedUseStatement.UnusedUse -- Used by #[Hook] attributes.
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\llm_content\Service\MarkdownConverterInterface;
use Drupal\llm_content\Service\XmlSitemapLinkManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for the LLM Content module.
 */
final class LlmContentHooks {

  public function __construct(
    protected MarkdownConverterInterface $markdownConverter,
    protected ConfigFactoryInterface $configFactory,
    protected XmlSitemapLinkManagerInterface $xmlSitemapLinkManager,
    protected QueueFactory $queueFactory,
    protected RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    if (!$entity instanceof NodeInterface) {
      return;
    }
    $this->handleNodeSave($entity);
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    if (!$entity instanceof NodeInterface) {
      return;
    }
    $this->handleNodeSave($entity);
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if (!$entity instanceof NodeInterface) {
      return;
    }
    $this->markdownConverter->deleteMarkdown((int) $entity->id());
    if ($this->xmlSitemapLinkManager->isEnabled()) {
      $this->xmlSitemapLinkManager->deleteNodeLink((int) $entity->id());
    }
    Cache::invalidateTags(['llm_content:list']);
  }

  /**
   * Implements hook_page_attachments().
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$page): void {
    if ($this->routeMatch->getRouteName() !== 'entity.node.canonical') {
      return;
    }
    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface) {
      return;
    }
    $config = $this->configFactory->get('llm_content.settings');
    $enabledTypes = $config->get('enabled_content_types') ?? [];
    if (!in_array($node->bundle(), $enabledTypes, TRUE) || !$node->isPublished()) {
      return;
    }
    /** @var \Drupal\Core\GeneratedUrl $generatedUrl */
    $generatedUrl = Url::fromRoute('llm_content.markdown_view', ['node' => $node->id()])->toString(TRUE);
    $page['#attached']['html_head'][] = [
      [
        '#tag' => 'link',
        '#attributes' => [
          'rel' => 'alternate',
          'type' => 'text/markdown',
          'href' => $generatedUrl->getGeneratedUrl(),
        ],
      ],
      'llm_content_alternate',
    ];
    // Merge cacheability from the generated URL without clobbering
    // attachments added by earlier page_attachments hooks.
    BubbleableMetadata::createFromRenderArray($page)
      ->merge(BubbleableMetadata::createFromObject($generatedUrl))
      ->applyTo($page);
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron(): void {
    $config = $this->configFactory->get('llm_content.settings');
    $types = $config->get('enabled_content_types') ?: [];
    if (empty($types)) {
      return;
    }

    // Limit queuing to 100 per cron run to prevent unbounded queue growth.
    $nids = $this->markdownConverter->getNidsMissingMarkdown($types, 100);
    if (empty($nids)) {
      return;
    }

    $queue = $this->queueFactory->get('llm_content_markdown_generation');
    foreach ($nids as $nid) {
      $queue->createItem(['nid' => (int) $nid]);
    }

    \Drupal::logger('llm_content')->notice('Cron queued @count nodes for markdown generation.', [
      '@count' => count($nids),
    ]);
  }

  /**
   * Handles node save events for enabled content types.
   */
  protected function handleNodeSave(NodeInterface $node): void {
    $config = $this->configFactory->get('llm_content.settings');

    if (!$config->get('auto_generate')) {
      return;
    }

    $enabledTypes = $config->get('enabled_content_types') ?? [];
    if (!in_array($node->bundle(), $enabledTypes, TRUE)) {
      return;
    }

    if ($node->isPublished()) {
      // Queue markdown generation instead of running synchronously to avoid
      // blocking entity save with render + DOM parse + markdown conversion.
      $queue = $this->queueFactory->get('llm_content_markdown_generation');
      $queue->createItem([
        'nid' => (int) $node->id(),
        'langcode' => $node->language()->getId(),
      ]);
      if ($this->xmlSitemapLinkManager->isEnabled()) {
        $this->xmlSitemapLinkManager->saveNodeLink($node);
      }
    }
    else {
      // Remove markdown for this specific translation only.
      $this->markdownConverter->deleteMarkdown((int) $node->id(), $node->language()->getId());
      if ($this->xmlSitemapLinkManager->isEnabled()) {
        $this->xmlSitemapLinkManager->deleteNodeLink((int) $node->id());
      }
    }

    Cache::invalidateTags(['llm_content:list']);
  }

}
