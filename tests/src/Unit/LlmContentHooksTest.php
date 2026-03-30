<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\llm_content\Hook\LlmContentHooks;
use Drupal\llm_content\Service\MarkdownConverterInterface;
use Drupal\llm_content\Service\XmlSitemapLinkManagerInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests hook implementations for the LLM Content module.
 *
 * @group llm_content
 * @coversDefaultClass \Drupal\llm_content\Hook\LlmContentHooks
 */
class LlmContentHooksTest extends TestCase {

  /**
   * The hooks class under test.
   */
  protected LlmContentHooks $hooks;

  /**
   * Mock markdown converter.
   */
  protected MarkdownConverterInterface $markdownConverter;

  /**
   * Mock config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Mock XML sitemap link manager.
   */
  protected XmlSitemapLinkManagerInterface $xmlSitemapLinkManager;

  /**
   * Mock queue factory.
   */
  protected QueueFactory $queueFactory;

  /**
   * Mock queue.
   */
  protected QueueInterface $queue;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->markdownConverter = $this->createMock(MarkdownConverterInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->xmlSitemapLinkManager = $this->createMock(XmlSitemapLinkManagerInterface::class);
    $this->queueFactory = $this->createMock(QueueFactory::class);
    $this->queue = $this->createMock(QueueInterface::class);
    $routeMatch = $this->createMock(RouteMatchInterface::class);

    $this->queueFactory->method('get')
      ->with('llm_content_markdown_generation')
      ->willReturn($this->queue);

    $this->hooks = new LlmContentHooks(
      $this->markdownConverter,
      $this->configFactory,
      $this->xmlSitemapLinkManager,
      $this->queueFactory,
      $routeMatch,
    );
  }

  /**
   * Creates a mock config with given settings.
   */
  protected function setUpConfig(bool $autoGenerate = TRUE, array $enabledTypes = ['article']): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function (string $key) use ($autoGenerate, $enabledTypes) {
        return match ($key) {
          'auto_generate' => $autoGenerate,
          'enabled_content_types' => $enabledTypes,
          default => NULL,
        };
      });
    $this->configFactory->method('get')
      ->with('llm_content.settings')
      ->willReturn($config);
  }

  /**
   * Creates a mock published node.
   */
  protected function createMockNode(int $nid = 1, string $bundle = 'article', bool $published = TRUE): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn((string) $nid);
    $node->method('bundle')->willReturn($bundle);
    $node->method('isPublished')->willReturn($published);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $node->method('language')->willReturn($language);

    return $node;
  }

  /**
   * Tests that entity insert queues markdown generation for published nodes.
   *
   * @covers ::entityInsert
   */
  public function testEntityInsertQueuesForPublishedNode(): void {
    $this->setUpConfig();
    $node = $this->createMockNode();

    $this->markdownConverter->expects($this->never())->method('convert');

    $this->queue->expects($this->once())
      ->method('createItem')
      ->with([
        'nid' => 1,
        'langcode' => 'en',
      ]);

    $this->hooks->entityInsert($node);
  }

  /**
   * Tests that entity update queues markdown generation for published nodes.
   *
   * @covers ::entityUpdate
   */
  public function testEntityUpdateQueuesForPublishedNode(): void {
    $this->setUpConfig();
    $node = $this->createMockNode();

    $this->markdownConverter->expects($this->never())->method('convert');

    $this->queue->expects($this->once())
      ->method('createItem')
      ->with([
        'nid' => 1,
        'langcode' => 'en',
      ]);

    $this->hooks->entityUpdate($node);
  }

  /**
   * Tests that unpublished nodes delete markdown instead of queuing.
   *
   * @covers ::entityUpdate
   */
  public function testEntityUpdateDeletesMarkdownForUnpublishedNode(): void {
    $this->setUpConfig();
    $node = $this->createMockNode(nid: 5, published: FALSE);

    $this->queue->expects($this->never())->method('createItem');

    $this->markdownConverter->expects($this->once())
      ->method('deleteMarkdown')
      ->with(5, 'en');

    $this->hooks->entityUpdate($node);
  }

  /**
   * Tests that auto_generate disabled skips queuing.
   *
   * @covers ::entityInsert
   */
  public function testAutoGenerateDisabledSkipsQueue(): void {
    $this->setUpConfig(autoGenerate: FALSE);
    $node = $this->createMockNode();

    $this->queue->expects($this->never())->method('createItem');
    $this->markdownConverter->expects($this->never())->method('convert');

    $this->hooks->entityInsert($node);
  }

  /**
   * Tests that non-enabled content types are skipped.
   *
   * @covers ::entityInsert
   */
  public function testNonEnabledContentTypeSkipped(): void {
    $this->setUpConfig(enabledTypes: ['page']);
    $node = $this->createMockNode(bundle: 'article');

    $this->queue->expects($this->never())->method('createItem');

    $this->hooks->entityInsert($node);
  }

  /**
   * Tests that non-node entities are ignored.
   *
   * @covers ::entityInsert
   */
  public function testNonNodeEntityIgnored(): void {
    $entity = $this->createMock(EntityInterface::class);

    $this->queue->expects($this->never())->method('createItem');

    $this->hooks->entityInsert($entity);
  }

}
