<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the update hook that purges privilege-tainted stored markdown.
 *
 * Fixing convert() to render as anonymous only protects rows written
 * from that point on. Rows already in llm_content_markdown were rendered
 * in whatever request context saved them, so they can contain content
 * only a privileged user could see — and /llms-full.txt serves them to
 * anonymous visitors. They have to be dropped, not left in place.
 *
 * @group llm_content
 */
class LlmContentUpdateTest extends TestCase {

  /**
   * Stand-in for the llm_content_markdown table.
   *
   * @var array<int, array{nid: int, langcode: string}>
   */
  protected array $table = [];

  /**
   * Queue items created during the update.
   *
   * @var array<int, mixed>
   */
  protected array $queued = [];

  /**
   * Cache tags invalidated during the update.
   *
   * @var array<int, string>
   */
  protected array $invalidated = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/llm_content.install';
    $this->table = [];
    $this->queued = [];
    $this->invalidated = [];
  }

  /**
   * Wires a container backed by an in-memory stand-in for the table.
   *
   * @param array<int, array{nid: int, langcode: string}> $rows
   *   The rows the table starts with.
   */
  protected function setUpContainer(array $rows): void {
    $this->table = $rows;

    $database = $this->createMock(Connection::class);
    $database->method('select')->willReturn($this->buildSelect());
    $database->method('delete')->willReturnCallback(fn (): Delete => $this->buildDelete());

    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')->willReturnCallback(
      function (mixed $data): bool {
        $this->queued[] = $data;
        return TRUE;
      },
    );
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->willReturn($queue);

    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $invalidator->method('invalidateTags')->willReturnCallback(
      function (array $tags): void {
        $this->invalidated = array_merge($this->invalidated, $tags);
      },
    );

    $container = new ContainerBuilder();
    $container->set('database', $database);
    $container->set('queue', $queueFactory);
    $container->set('cache_tags.invalidator', $invalidator);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a select mock answering both the count and the row query.
   */
  protected function buildSelect(): SelectInterface {
    $countStatement = $this->createMock(StatementInterface::class);
    $countStatement->method('fetchField')->willReturnCallback(
      fn (): int => count($this->table),
    );
    $countSelect = $this->createMock(SelectInterface::class);
    $countSelect->method('execute')->willReturn($countStatement);

    $rowStatement = $this->createMock(StatementInterface::class);
    $rowStatement->method('fetchAll')->willReturnCallback(
      function (): array {
        $rows = array_slice($this->table, 0, 100);
        return array_map(
          static fn (array $row): object => (object) $row,
          $rows,
        );
      },
    );

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('countQuery')->willReturn($countSelect);
    $select->method('execute')->willReturn($rowStatement);

    return $select;
  }

  /**
   * Builds a delete mock that removes the matching row from the table.
   */
  protected function buildDelete(): Delete {
    $conditions = [];
    $delete = $this->createMock(Delete::class);
    $delete->method('condition')->willReturnCallback(
      function (string $field, mixed $value) use (&$conditions, &$delete): Delete {
        $conditions[$field] = $value;
        return $delete;
      },
    );
    $delete->method('execute')->willReturnCallback(
      function () use (&$conditions): int {
        $before = count($this->table);
        $this->table = array_values(array_filter(
          $this->table,
          static fn (array $row) => (int) $row['nid'] !== (int) ($conditions['nid'] ?? -1)
            || $row['langcode'] !== ($conditions['langcode'] ?? NULL),
        ));
        return $before - count($this->table);
      },
    );

    return $delete;
  }

  /**
   * Runs the update to completion, returning the final message.
   *
   * @param int $maxPasses
   *   Safety valve so a non-converging hook fails the test instead of
   *   looping forever.
   *
   * @return array{string, int}
   *   The last returned message and the number of passes taken.
   */
  protected function runUpdate(int $maxPasses = 50): array {
    $sandbox = [];
    $message = '';
    $passes = 0;
    do {
      $message = llm_content_update_11003($sandbox);
      $passes++;
      $this->assertLessThanOrEqual($maxPasses, $passes, 'Update did not converge.');
    } while (($sandbox['#finished'] ?? 0) < 1);

    return [$message, $passes];
  }

  /**
   * Builds a table of $count rows in the given language.
   *
   * @return array<int, array{nid: int, langcode: string}>
   *   The generated rows.
   */
  protected function rows(int $count, string $langcode = 'en', int $offset = 0): array {
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
      $rows[] = ['nid' => $offset + $i, 'langcode' => $langcode];
    }
    return $rows;
  }

  /**
   * Every stored row must be gone once the update finishes.
   */
  public function testUpdatePurgesEveryStoredRow(): void {
    $this->setUpContainer($this->rows(3));

    $this->runUpdate();

    $this->assertSame([], $this->table);
  }

  /**
   * Every purged row must be queued for regeneration.
   */
  public function testUpdateQueuesEveryPurgedRow(): void {
    $this->setUpContainer($this->rows(3));

    $this->runUpdate();

    $this->assertSame(
      [
        ['nid' => 1, 'langcode' => 'en'],
        ['nid' => 2, 'langcode' => 'en'],
        ['nid' => 3, 'langcode' => 'en'],
      ],
      $this->queued,
    );
  }

  /**
   * Translation rows must be requeued, not just destroyed.
   *
   * Requeueing from a node query would return default-language nids
   * only, so every non-default-language row would be purged with
   * nothing to rebuild it. Working from the stored rows keeps the
   * language dimension the table is keyed on.
   */
  public function testUpdateRequeuesTranslationRows(): void {
    $this->setUpContainer([
      ['nid' => 7, 'langcode' => 'en'],
      ['nid' => 7, 'langcode' => 'fr'],
      ['nid' => 7, 'langcode' => 'de'],
    ]);

    $this->runUpdate();

    $this->assertSame([], $this->table);
    $this->assertSame(
      ['en', 'fr', 'de'],
      array_column($this->queued, 'langcode'),
    );
  }

  /**
   * The purge must be batched rather than done in one pass.
   *
   * An unbatched truncate on a large site can empty the table and then
   * exceed max_execution_time while queueing, leaving the update marked
   * failed with no stored markdown and nothing queued to rebuild it.
   */
  public function testUpdateBatchesLargeTables(): void {
    $this->setUpContainer($this->rows(250));

    [, $passes] = $this->runUpdate();

    $this->assertGreaterThan(1, $passes, 'The update ran in a single pass.');
    $this->assertSame([], $this->table);
    $this->assertCount(250, $this->queued);
  }

  /**
   * Progress must be reported so drush can render a batch.
   */
  public function testUpdateReportsProgressWhileIncomplete(): void {
    $this->setUpContainer($this->rows(250));

    $sandbox = [];
    llm_content_update_11003($sandbox);

    $this->assertGreaterThan(0, $sandbox['#finished']);
    $this->assertLessThan(1, $sandbox['#finished']);
  }

  /**
   * Per-node cache tags must be invalidated alongside the rows.
   *
   * /node/N/llm-md responses carry only a node:N tag, so invalidating
   * llm_content:list alone would leave them serving the pre-fix body
   * out of the page cache indefinitely.
   */
  public function testUpdateInvalidatesPerNodeCacheTags(): void {
    $this->setUpContainer($this->rows(2));

    $this->runUpdate();

    $this->assertContains('node:1', $this->invalidated);
    $this->assertContains('node:2', $this->invalidated);
  }

  /**
   * The cached llms.txt and llms-full.txt bodies must be invalidated.
   */
  public function testUpdateInvalidatesListCacheTag(): void {
    $this->setUpContainer($this->rows(1));

    $this->runUpdate();

    $this->assertContains('llm_content:list', $this->invalidated);
  }

  /**
   * The operator is told how the queue actually drains.
   *
   * The worker is time-bounded at 60 seconds per cron run; the "100 per
   * run" figure is LlmContentHooks::cron()'s queueing cap, which this
   * update bypasses by enqueueing everything up front.
   */
  public function testUpdateReturnsAccurateOperatorGuidance(): void {
    $this->setUpContainer($this->rows(2));

    [$message] = $this->runUpdate();

    $this->assertStringContainsString('queue:run llm_content_markdown_generation', $message);
    $this->assertStringNotContainsString('100 items per cron run', $message);
  }

  /**
   * An empty table finishes in one pass without queueing anything.
   */
  public function testUpdateWithEmptyTableFinishesImmediately(): void {
    $this->setUpContainer([]);

    [, $passes] = $this->runUpdate();

    $this->assertSame(1, $passes);
    $this->assertSame([], $this->queued);
    $this->assertContains('llm_content:list', $this->invalidated);
  }

}
