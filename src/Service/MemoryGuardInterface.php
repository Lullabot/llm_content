<?php

declare(strict_types=1);

namespace Drupal\llm_content\Service;

/**
 * Reports how close the current process is to the PHP memory limit.
 *
 * Markdown generation renders a whole node per item, and that memory is not
 * fully reclaimed between items. A long-running process — cron draining the
 * queue, or `drush llm:generate` working through a backlog — therefore grows
 * steadily and can exhaust the limit. Callers use this to stop cleanly before
 * that happens rather than dying mid-item.
 */
interface MemoryGuardInterface {

  /**
   * Whether memory use has reached the configured share of the limit.
   *
   * @return bool
   *   TRUE if the caller should stop processing. Always FALSE when PHP has
   *   no memory limit, since there is nothing to run out of.
   */
  public function isNearLimit(): bool;

  /**
   * The PHP memory limit in bytes.
   *
   * @return int|null
   *   The limit, or NULL when unlimited or unparseable.
   */
  public function getLimit(): ?int;

  /**
   * The memory currently allocated to the process, in bytes.
   */
  public function getUsage(): int;

  /**
   * A short human-readable summary of usage against the limit.
   *
   * Intended for log and exception messages, e.g. "192 MB of the 256 MB PHP
   * memory limit".
   */
  public function describe(): string;

}
