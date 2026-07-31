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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

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

    $this->controller = new LlmMarkdownController(
      $this->markdownConverter,
      $this->requestStack(),
    );
  }

  /**
   * Builds a request stack holding a single GET request.
   *
   * @param string $uri
   *   The request URI, including any query string.
   */
  protected function requestStack(string $uri = '/node/1/llm-md'): RequestStack {
    $stack = new RequestStack();
    $stack->push(Request::create($uri));

    return $stack;
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

  /**
   * A query string must produce a redirect instead of a rendered body.
   *
   * Otherwise every distinct `?x=N` caches its own copy of the markdown.
   *
   * @covers ::view
   */
  public function testQueryStringRedirectsToCanonicalUrl(): void {
    $controller = new LlmMarkdownController(
      $this->markdownConverter,
      $this->requestStack('/node/1/llm-md?x=7'),
    );
    $node = $this->createMockNode();

    // The expensive path must not run at all.
    $this->markdownConverter->expects($this->never())->method('getMarkdown');

    $response = $controller->view($node);

    $this->assertSame(301, $response->getStatusCode());
    $this->assertSame('/node/1/llm-md', $response->headers->get('Location'));
  }

  /**
   * The redirect must not be overridable by a `destination` parameter.
   *
   * Core's RedirectResponseSubscriber rewrites the target of any
   * RedirectResponse it sees when the request carries `?destination=`.
   * Returning one here would make every endpoint a permanent, publicly
   * cached redirect to an arbitrary internal path.
   *
   * @covers ::view
   */
  public function testCanonicalRedirectIgnoresDestinationParameter(): void {
    $controller = new LlmMarkdownController(
      $this->markdownConverter,
      $this->requestStack('/node/1/llm-md?destination=/user/logout'),
    );

    $response = $controller->view($this->createMockNode());

    $this->assertNotInstanceOf(RedirectResponse::class, $response);
    $this->assertSame('/node/1/llm-md', $response->headers->get('Location'));
  }

  /**
   * The 200 response must vary on query args.
   *
   * Without this context, dynamic_page_cache answers `?x=1` from the
   * cached query-string-free response, the controller never runs, and
   * the redirect above never gets a chance to fire.
   *
   * @covers ::view
   */
  public function testResponseVariesOnQueryArgs(): void {
    $node = $this->createMockNode();
    $this->markdownConverter->method('getMarkdown')->willReturn('# Test');

    $response = $this->controller->view($node);

    $this->assertContains(
      'url.query_args',
      $response->getCacheableMetadata()->getCacheContexts(),
    );
  }

}
