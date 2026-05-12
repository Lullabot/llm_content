<?php

declare(strict_types=1);

namespace Drupal\llm_content\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\llm_content\Service\MarkdownConverterInterface;
use Drupal\llm_content\Service\XmlSitemapLinkManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the LLM Content module.
 */
final class LlmContentCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    protected MarkdownConverterInterface $markdownConverter,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected XmlSitemapLinkManagerInterface $xmlSitemapLinkManager,
  ) {
    parent::__construct();
  }

  /**
   * Generate markdown for all published nodes of enabled content types.
   */
  #[CLI\Command(name: 'llm:generate', aliases: ['llm-generate'])]
  #[CLI\Option(name: 'types', description: 'Comma-separated content types to process (overrides config).')]
  #[CLI\Option(name: 'force', description: 'Regenerate even if markdown already exists.')]
  #[CLI\Option(name: 'batch-size', description: 'Number of nodes per batch.')]
  #[CLI\Usage(name: 'drush llm:generate', description: 'Generate markdown for all enabled content types.')]
  #[CLI\Usage(name: 'drush llm:generate --types=article,case_study', description: 'Generate for specific types only.')]
  #[CLI\Usage(name: 'drush llm:generate --force', description: 'Regenerate all markdown.')]
  public function generate(
    array $options = [
      'types' => NULL,
      'force' => FALSE,
      'batch-size' => 25,
    ],
  ): void {
    $config = $this->configFactory->get('llm_content.settings');

    if ($options['types']) {
      $types = array_filter(array_map('trim', explode(',', $options['types'])));
    }
    else {
      $types = $config->get('enabled_content_types') ?? [];
    }

    if (empty($types)) {
      $this->logger()->warning('No content types configured. Visit /admin/config/content/llm-content.');
      return;
    }

    $batchSize = (int) $options['batch-size'];
    if ($batchSize < 1) {
      $this->logger()->error('Batch size must be at least 1.');
      return;
    }

    if ($options['force']) {
      $nids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('status', 1)
        ->condition('type', $types, 'IN')
        ->accessCheck(FALSE)
        ->execute();
    }
    else {
      $nids = $this->markdownConverter->getNidsMissingMarkdown($types);
    }

    $total = count($nids);

    if ($total === 0) {
      $this->logger()->success('All nodes already have markdown generated.');
      return;
    }

    $this->logger()->notice("Processing {$total} nodes...");
    $processed = 0;
    $failed = 0;

    foreach (array_chunk($nids, $batchSize) as $chunk) {
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($chunk);
      foreach ($nodes as $node) {
        $result = $this->markdownConverter->convert($node);
        if ($result === '') {
          $failed++;
        }
        else {
          $processed++;
        }
      }
      $this->entityTypeManager->getStorage('node')->resetCache($chunk);
      $done = $processed + $failed;
      $this->logger()->notice("Progress: {$done}/{$total} ({$processed} OK, {$failed} failed)");
    }

    $this->logger()->success("Done. Generated: {$processed}, Failed: {$failed}, Total: {$total}.");
  }

  /**
   * Rebuild xmlsitemap links for all LLM content URLs.
   *
   * Returns DrushCommands::EXIT_FAILURE when xmlsitemap is missing or
   * integration is disabled in config, so CI/deploy scripts can detect
   * the no-op via the command's exit status.
   */
  #[CLI\Command(name: 'llm:sitemap-sync', aliases: ['llm-sitemap-sync'])]
  #[CLI\Usage(name: 'drush llm:sitemap-sync', description: 'Backfill xmlsitemap entries for existing nodes.')]
  public function sitemapSync(): int {
    if (!$this->xmlSitemapLinkManager->isAvailable()) {
      $this->logger()->error('The xmlsitemap module is not installed.');
      return self::EXIT_FAILURE;
    }
    if (!$this->xmlSitemapLinkManager->isEnabled()) {
      $this->logger()->warning('xmlsitemap_integration is disabled in llm_content.settings. Enable it first.');
      return self::EXIT_FAILURE;
    }
    $count = $this->xmlSitemapLinkManager->syncAllLinks();
    $this->logger()->success(sprintf(
      'Synced %d LLM content links into xmlsitemap. Run cron or rebuild the sitemap to publish.',
      $count,
    ));
    return self::EXIT_SUCCESS;
  }

}
