<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\llm_content\Plugin\QueueWorker\MarkdownGenerationWorker;
use Drupal\llm_content\Service\MarkdownConverterInterface;
use Drupal\llm_content\Service\MemoryGuardInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the markdown generation queue worker.
 *
 * @group llm_content
 * @coversDefaultClass \Drupal\llm_content\Plugin\QueueWorker\MarkdownGenerationWorker
 */
class MarkdownGenerationWorkerTest extends TestCase {

  /**
   * The converter the worker delegates to.
   */
  protected MarkdownConverterInterface $converter;

  /**
   * The memory guard the worker consults.
   */
  protected MemoryGuardInterface $memoryGuard;

  /**
   * Builds a worker whose storage returns the given node.
   */
  protected function buildWorker(?NodeInterface $node, bool $nearMemoryLimit = FALSE): MarkdownGenerationWorker {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($node);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $this->converter = $this->createMock(MarkdownConverterInterface::class);

    $this->memoryGuard = $this->createMock(MemoryGuardInterface::class);
    $this->memoryGuard->method('isNearLimit')->willReturn($nearMemoryLimit);
    $this->memoryGuard->method('describe')->willReturn('192 MB of the 256 MB PHP memory limit');

    return new MarkdownGenerationWorker(
      [],
      'llm_content_markdown_generation',
      [],
      $this->converter,
      $etm,
      $this->createMock(LoggerInterface::class),
      $this->memoryGuard,
    );
  }

  /**
   * Builds a node mock with an optional translation.
   */
  protected function buildNode(bool $published = TRUE, ?NodeInterface $translation = NULL, string $langcode = 'fr'): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('isPublished')->willReturn($published);
    $node->method('hasTranslation')->willReturnCallback(
      static fn (string $l) => $translation !== NULL && $l === $langcode,
    );
    if ($translation !== NULL) {
      $node->method('getTranslation')->willReturn($translation);
    }

    return $node;
  }

  /**
   * The queued language must be the one converted.
   *
   * The queue payload has always carried a langcode, but the worker
   * ignored it and converted the default translation. That makes a
   * translation's stored row impossible to rebuild in bulk — it is only
   * ever regenerated on demand by a request to its own /llm-md route,
   * which matters after update 11003 purges the table.
   *
   * @covers ::processItem
   */
  public function testProcessItemConvertsQueuedTranslation(): void {
    $translation = $this->createMock(NodeInterface::class);
    $translation->method('isPublished')->willReturn(TRUE);
    $node = $this->buildNode(translation: $translation);
    $worker = $this->buildWorker($node);

    $this->converter->expects($this->once())
      ->method('convert')
      ->with($this->identicalTo($translation));

    $worker->processItem(['nid' => 7, 'langcode' => 'fr']);
  }

  /**
   * A language the node has no translation for falls back to the node.
   *
   * @covers ::processItem
   */
  public function testProcessItemFallsBackWhenTranslationMissing(): void {
    $node = $this->buildNode();
    $worker = $this->buildWorker($node);

    $this->converter->expects($this->once())
      ->method('convert')
      ->with($this->identicalTo($node));

    $worker->processItem(['nid' => 7, 'langcode' => 'de']);
  }

  /**
   * An item with no langcode still converts the default translation.
   *
   * @covers ::processItem
   */
  public function testProcessItemWithoutLangcodeConvertsDefault(): void {
    $node = $this->buildNode();
    $worker = $this->buildWorker($node);

    $this->converter->expects($this->once())
      ->method('convert')
      ->with($this->identicalTo($node));

    $worker->processItem(['nid' => 7]);
  }

  /**
   * An unpublished translation must be skipped.
   *
   * The status is per-translation, so it has to be read after the
   * translation is selected — a published default with an unpublished
   * French translation must not publish the French markdown.
   *
   * @covers ::processItem
   */
  public function testProcessItemSkipsUnpublishedTranslation(): void {
    $translation = $this->createMock(NodeInterface::class);
    $translation->method('isPublished')->willReturn(FALSE);
    $node = $this->buildNode(translation: $translation);
    $worker = $this->buildWorker($node);

    $this->converter->expects($this->never())->method('convert');

    $worker->processItem(['nid' => 7, 'langcode' => 'fr']);
  }

  /**
   * A missing node is skipped without touching the converter.
   *
   * @covers ::processItem
   */
  public function testProcessItemSkipsMissingNode(): void {
    $worker = $this->buildWorker(NULL);

    $this->converter->expects($this->never())->method('convert');

    $worker->processItem(['nid' => 7, 'langcode' => 'fr']);
  }

  /**
   * A process near the memory limit suspends the queue instead of rendering.
   *
   * Rendering one node costs several MB that is not reclaimed between items,
   * so a cron run draining a large backlog climbs until PHP dies mid-item.
   *
   * @covers ::processItem
   */
  public function testProcessItemSuspendsQueueNearMemoryLimit(): void {
    $worker = $this->buildWorker($this->buildNode(), nearMemoryLimit: TRUE);

    $this->converter->expects($this->never())->method('convert');

    $this->expectException(SuspendQueueException::class);
    $worker->processItem(['nid' => 7]);
  }

  /**
   * The suspension must not be delayable.
   *
   * \Drupal\Core\Cron::processQueues() retries a delayable suspension inside
   * the same cron run, after a usleep() in the same PHP process. Sleeping
   * frees no memory, so a delay here would trip the guard again immediately
   * and spin until max_execution_time killed cron. A NULL delay makes
   * ::isDelayable() FALSE, so the queue is skipped until the next run.
   *
   * @covers ::processItem
   */
  public function testSuspensionIsNotDelayable(): void {
    $worker = $this->buildWorker($this->buildNode(), nearMemoryLimit: TRUE);

    try {
      $worker->processItem(['nid' => 7]);
      $this->fail('Expected a SuspendQueueException.');
    }
    catch (SuspendQueueException $e) {
      $this->assertNull($e->getDelay(), 'The suspension must carry no delay.');
      $this->assertFalse($e->isDelayable(), 'A delayable suspension would spin inside one cron run.');
    }
  }

  /**
   * The guard is consulted before the node is loaded, not after.
   *
   * Checking after the render would mean the memory has already been spent.
   *
   * @covers ::processItem
   */
  public function testMemoryGuardRunsBeforeLoadingTheNode(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->never())->method('load');
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $memoryGuard = $this->createMock(MemoryGuardInterface::class);
    $memoryGuard->method('isNearLimit')->willReturn(TRUE);
    $memoryGuard->method('describe')->willReturn('192 MB of the 256 MB PHP memory limit');

    $worker = new MarkdownGenerationWorker(
      [],
      'llm_content_markdown_generation',
      [],
      $this->createMock(MarkdownConverterInterface::class),
      $etm,
      $this->createMock(LoggerInterface::class),
      $memoryGuard,
    );

    $this->expectException(SuspendQueueException::class);
    $worker->processItem(['nid' => 7]);
  }

  /**
   * Ample memory leaves processing untouched.
   *
   * @covers ::processItem
   */
  public function testProcessItemProceedsWhenMemoryIsAmple(): void {
    $node = $this->buildNode();
    $worker = $this->buildWorker($node, nearMemoryLimit: FALSE);

    $this->converter->expects($this->once())
      ->method('convert')
      ->with($this->identicalTo($node));

    $worker->processItem(['nid' => 7]);
  }

}
