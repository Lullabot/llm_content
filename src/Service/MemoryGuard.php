<?php

declare(strict_types=1);

namespace Drupal\llm_content\Service;

use Drupal\Component\Utility\Bytes;

/**
 * Compares real memory usage against a share of the PHP memory limit.
 */
final class MemoryGuard implements MemoryGuardInterface {

  /**
   * Fraction of the limit at which processing should stop.
   */
  private readonly float $threshold;

  /**
   * Constructs a MemoryGuard.
   *
   * @param float $threshold
   *   Share of the PHP memory limit, between 0 and 1, at which
   *   ::isNearLimit() starts returning TRUE. The headroom left over has to
   *   cover one more node render, so this should stay well below 1.
   */
  public function __construct(float $threshold = 0.75) {
    // A nonsensical threshold would either suspend immediately or never
    // suspend at all; fall back to the default rather than doing either.
    $this->threshold = ($threshold > 0.0 && $threshold <= 1.0) ? $threshold : 0.75;
  }

  /**
   * {@inheritdoc}
   */
  public function isNearLimit(): bool {
    $limit = $this->getLimit();
    if ($limit === NULL) {
      return FALSE;
    }
    return $this->getUsage() >= (int) ($limit * $this->threshold);
  }

  /**
   * {@inheritdoc}
   */
  public function getLimit(): ?int {
    $raw = trim((string) ini_get('memory_limit'));
    if ($raw === '') {
      return NULL;
    }
    // "-1" means unlimited. Bytes::toNumber() strips every character that is
    // not a digit or a dot, so it maps "-1" to 1 — a one-byte limit that
    // would suspend the queue forever. The sign has to be caught here,
    // before converting.
    if (str_starts_with($raw, '-')) {
      return NULL;
    }
    $bytes = (int) Bytes::toNumber($raw);
    return $bytes > 0 ? $bytes : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getUsage(): int {
    // Real usage: the memory actually claimed from the OS, which is what the
    // limit is enforced against.
    return memory_get_usage(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function describe(): string {
    $limit = $this->getLimit();
    $usedMb = (int) round($this->getUsage() / 1048576);
    if ($limit === NULL) {
      return sprintf('%d MB in use with no PHP memory limit set', $usedMb);
    }
    return sprintf(
      '%d MB of the %d MB PHP memory limit',
      $usedMb,
      (int) round($limit / 1048576),
    );
  }

}
