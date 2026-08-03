<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\llm_content\Service\MemoryGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests the memory guard.
 *
 * @group llm_content
 * @coversDefaultClass \Drupal\llm_content\Service\MemoryGuard
 */
class MemoryGuardTest extends TestCase {

  /**
   * The memory_limit in force before a test changed it.
   */
  protected string|false $originalLimit = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->originalLimit = ini_get('memory_limit');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->originalLimit !== FALSE) {
      ini_set('memory_limit', $this->originalLimit);
    }
    parent::tearDown();
  }

  /**
   * An unlimited PHP memory limit can never be near its limit.
   *
   * @covers ::getLimit
   * @covers ::isNearLimit
   */
  public function testUnlimitedNeverSuspends(): void {
    ini_set('memory_limit', '-1');
    $guard = new MemoryGuard(0.75);

    $this->assertNull($guard->getLimit());
    $this->assertFalse($guard->isNearLimit());
  }

  /**
   * A limit far above current usage does not trip the guard.
   *
   * @covers ::isNearLimit
   */
  public function testAmpleHeadroomDoesNotSuspend(): void {
    ini_set('memory_limit', '4G');
    $guard = new MemoryGuard(0.75);

    $this->assertSame(4 * 1024 * 1024 * 1024, $guard->getLimit());
    $this->assertFalse($guard->isNearLimit());
  }

  /**
   * A threshold that current usage already exceeds trips the guard.
   *
   * Rather than allocating memory to cross a fixed threshold, this pins the
   * threshold just under whatever the process is already using.
   *
   * @covers ::isNearLimit
   */
  public function testExceededThresholdSuspends(): void {
    ini_set('memory_limit', '4G');
    $limit = 4 * 1024 * 1024 * 1024;
    // A threshold below the share of the limit already in use.
    $threshold = (memory_get_usage(TRUE) / $limit) / 2;
    $guard = new MemoryGuard($threshold);

    $this->assertTrue($guard->isNearLimit());
  }

  /**
   * Suffixed limits are parsed, not read as raw integers.
   *
   * @covers ::getLimit
   */
  public function testParsesSuffixedLimits(): void {
    ini_set('memory_limit', '256M');
    $this->assertSame(256 * 1024 * 1024, (new MemoryGuard())->getLimit());

    ini_set('memory_limit', '1G');
    $this->assertSame(1024 * 1024 * 1024, (new MemoryGuard())->getLimit());
  }

  /**
   * An out-of-range threshold falls back to the default.
   *
   * A threshold of 0 would suspend on the very first item and the queue would
   * never drain; one above 1 could never trip at all.
   *
   * @covers ::__construct
   */
  public function testOutOfRangeThresholdFallsBackToDefault(): void {
    ini_set('memory_limit', '4G');

    $this->assertFalse((new MemoryGuard(0.0))->isNearLimit());
    $this->assertFalse((new MemoryGuard(-1.0))->isNearLimit());
    $this->assertFalse((new MemoryGuard(5.0))->isNearLimit());
  }

  /**
   * The description names both usage and limit for log messages.
   *
   * @covers ::describe
   */
  public function testDescribeMentionsUsageAndLimit(): void {
    ini_set('memory_limit', '256M');
    $this->assertStringContainsString('256 MB PHP memory limit', (new MemoryGuard())->describe());

    ini_set('memory_limit', '-1');
    $this->assertStringContainsString('no PHP memory limit', (new MemoryGuard())->describe());
  }

}
