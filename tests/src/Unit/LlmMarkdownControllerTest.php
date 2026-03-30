<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\llm_content\Controller\LlmMarkdownController;
use Drupal\llm_content\Service\MarkdownConverterInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests the LLM Markdown controller.
 *
 * @group llm_content
 * @coversDefaultClass \Drupal\llm_content\Controller\LlmMarkdownController
 */
class LlmMarkdownControllerTest extends TestCase {

  /**
   * Mock markdown converter.
   */
  protected MarkdownConverterInterface $markdownConverter;

  /**
   * The controller under test.
   */
  protected LlmMarkdownController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->markdownConverter = $this->createMock(MarkdownConverterInterface::class);

    // Set up a minimal Drupal container for ControllerBase::config().
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function (string $key) {
        return match ($key) {
          'enabled_content_types' => ['article'],
          default => NULL,
        };
      });
    $config->method('getCacheContexts')->willReturn([]);
    $config->method('getCacheTags')->willReturn(['config:llm_content.settings']);
    $config->method('getCacheMaxAge')->willReturn(-1);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('llm_content.settings')
      ->willReturn($config);

    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);

    $this->controller = new LlmMarkdownController($this->markdownConverter);
  }

  /**
   * Creates a mock node.
   */
  protected function createMockNode(int $nid = 1, string $bundle = 'article', bool $published = TRUE): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn((string) $nid);
    $node->method('bundle')->willReturn($bundle);
    $node->method('isPublished')->willReturn($published);
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheTags')->willReturn(['node:' . $nid]);
    $node->method('getCacheMaxAge')->willReturn(-1);

    return $node;
  }

  /**
   * Tests 404 response for unpublished nodes is cacheable.
   *
   * @covers ::view
   */
  public function testUnpublishedNodeReturnsCacheable404(): void {
    $node = $this->createMockNode(nid: 42, published: FALSE);

    $this->markdownConverter->expects($this->never())->method('getMarkdown');

    $response = $this->controller->view($node);

    $this->assertInstanceOf(CacheableResponse::class, $response);
    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * Tests 404 response for disabled content type is cacheable.
   *
   * @covers ::view
   */
  public function testDisabledContentTypeReturnsCacheable404(): void {
    $node = $this->createMockNode(nid: 10, bundle: 'page');

    $this->markdownConverter->expects($this->never())->method('getMarkdown');

    $response = $this->controller->view($node);

    $this->assertInstanceOf(CacheableResponse::class, $response);
    $this->assertSame(404, $response->getStatusCode());
  }

  /**
   * Tests 200 response for published enabled node.
   *
   * @covers ::view
   */
  public function testPublishedEnabledNodeReturns200(): void {
    $node = $this->createMockNode();

    $this->markdownConverter->method('getMarkdown')
      ->with($node)
      ->willReturn('# Test');

    $response = $this->controller->view($node);

    $this->assertInstanceOf(CacheableResponse::class, $response);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('# Test', $response->getContent());
    $this->assertSame('text/markdown; charset=utf-8', $response->headers->get('Content-Type'));
  }

}
