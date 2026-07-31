<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\llm_content\Plugin\QueueWorker\MarkdownGenerationWorker;
use Drupal\llm_content\Service\MarkdownConverterInterface;
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
   * Builds a worker whose storage returns the given node.
   */
  protected function buildWorker(?NodeInterface $node): MarkdownGenerationWorker {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($node);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $this->converter = $this->createMock(MarkdownConverterInterface::class);

    return new MarkdownGenerationWorker(
      [],
      'llm_content_markdown_generation',
      [],
      $this->converter,
      $etm,
      $this->createMock(LoggerInterface::class),
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

}
