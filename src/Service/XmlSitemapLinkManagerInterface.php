<?php

declare(strict_types=1);

namespace Drupal\llm_content\Service;

use Drupal\node\NodeInterface;

/**
 * Manages xmlsitemap links for LLM content URLs.
 */
interface XmlSitemapLinkManagerInterface {

  /**
   * Checks if the xmlsitemap module is installed.
   */
  public function isAvailable(): bool;

  /**
   * Checks if xmlsitemap integration is available and enabled in config.
   */
  public function isEnabled(): bool;

  /**
   * Saves or updates the sitemap link for a node's markdown URL.
   */
  public function saveNodeLink(NodeInterface $node): void;

  /**
   * Deletes the sitemap link for a node's markdown URL.
   */
  public function deleteNodeLink(int $nid): void;

  /**
   * Saves sitemap links for the llms.txt and llms-full.txt index endpoints.
   *
   * @return int
   *   The number of index links saved (0 when integration is disabled).
   */
  public function saveIndexLinks(): int;

  /**
   * Rebuilds all LLM content sitemap links.
   *
   * @return int
   *   The total number of links saved (index endpoints + node links).
   *   Returns 0 when integration is disabled.
   */
  public function syncAllLinks(): int;

  /**
   * Removes all LLM content sitemap links.
   */
  public function removeAllLinks(): void;

}
