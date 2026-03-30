<?php

declare(strict_types=1);

namespace Drupal\llm_content\Service;

use Drupal\node\NodeInterface;

/**
 * Interface for the markdown converter service.
 */
interface MarkdownConverterInterface {

  /**
   * Converts a node to markdown and stores the result.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to convert.
   *
   * @return string
   *   The generated markdown.
   */
  public function convert(NodeInterface $node): string;

  /**
   * Gets stored markdown for a node, generating if needed.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to get markdown for.
   *
   * @return string
   *   The markdown content.
   */
  public function getMarkdown(NodeInterface $node): string;

  /**
   * Gets stored markdown for a node without triggering generation.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to get markdown for.
   *
   * @return string|null
   *   The stored markdown content, or NULL if not yet generated.
   */
  public function getStoredMarkdown(NodeInterface $node): ?string;

  /**
   * Deletes stored markdown for a node.
   *
   * @param int $nid
   *   The node ID.
   * @param string|null $langcode
   *   The language code to delete. If NULL, deletes all languages.
   */
  public function deleteMarkdown(int $nid, ?string $langcode = NULL): void;

  /**
   * Generates the full-text content for all enabled nodes.
   *
   * @return string
   *   The aggregated markdown content.
   */
  public function generateFullText(): string;

  /**
   * Gets stored markdown for multiple nodes in a single query.
   *
   * @param int[] $nids
   *   Array of node IDs.
   * @param string $langcode
   *   The language code.
   *
   * @return array<int, string>
   *   Associative array keyed by nid with markdown values.
   */
  public function getStoredMarkdownBatch(array $nids, string $langcode): array;

  /**
   * Finds published node IDs of given types that have no stored markdown.
   *
   * Uses a SQL anti-join for efficient querying on large sites.
   *
   * @param array $types
   *   Content type machine names to check.
   * @param int $limit
   *   Maximum number of nids to return. 0 for no limit.
   *
   * @return array
   *   Array of node IDs missing markdown.
   */
  public function getNidsMissingMarkdown(array $types, int $limit = 0): array;

}
