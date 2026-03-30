<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\llm_content\Service\MarkdownConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests the MarkdownConverter batch query methods.
 *
 * @group llm_content
 * @coversDefaultClass \Drupal\llm_content\Service\MarkdownConverter
 */
class MarkdownConverterBatchTest extends TestCase {

  /**
   * Creates a converter instance with a mock database injected.
   *
   * @return array{MarkdownConverter, \PHPUnit\Framework\MockObject\MockObject}
   *   The converter and the database mock.
   */
  protected function createConverterWithDatabase(): array {
    $database = $this->createMock(Connection::class);

    $reflection = new \ReflectionClass(MarkdownConverter::class);
    $converter = $reflection->newInstanceWithoutConstructor();

    $dbProperty = $reflection->getProperty('database');
    $dbProperty->setAccessible(TRUE);
    $dbProperty->setValue($converter, $database);

    return [$converter, $database];
  }

  /**
   * Tests getStoredMarkdownBatch returns keyed results.
   *
   * @covers ::getStoredMarkdownBatch
   */
  public function testGetStoredMarkdownBatchReturnsKeyedResults(): void {
    [$converter, $database] = $this->createConverterWithDatabase();

    $expected = [
      1 => '# Node 1 content',
      5 => '# Node 5 content',
      10 => '# Node 10 content',
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAllKeyed')->willReturn($expected);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $database->method('select')
      ->with('llm_content_markdown', 'm')
      ->willReturn($select);

    $result = $converter->getStoredMarkdownBatch([1, 5, 10], 'en');

    $this->assertSame($expected, $result);
  }

  /**
   * Tests getStoredMarkdownBatch returns empty array for empty input.
   *
   * @covers ::getStoredMarkdownBatch
   */
  public function testGetStoredMarkdownBatchEmptyInput(): void {
    $reflection = new \ReflectionClass(MarkdownConverter::class);
    $converter = $reflection->newInstanceWithoutConstructor();

    $result = $converter->getStoredMarkdownBatch([], 'en');

    $this->assertSame([], $result);
  }

}
