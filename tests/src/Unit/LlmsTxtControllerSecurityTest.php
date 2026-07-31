<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\llm_content\Controller\LlmsTxtController;
use Drupal\llm_content\Service\MarkdownConverterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests security fixes in LlmsTxtController.
 *
 * Covers PR #24: user.node_grants:view cache context on both endpoints.
 *
 * @group llm_content
 * @coversDefaultClass \Drupal\llm_content\Controller\LlmsTxtController
 */
class LlmsTxtControllerSecurityTest extends TestCase {

  /**
   * The controller under test.
   */
  protected LlmsTxtController $controller;

  /**
   * The mock markdown converter handed to the controller.
   */
  protected MarkdownConverterInterface $markdownConverter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // llm_content.settings with no enabled types — keeps llmsTxt() simple
    // (skips the entity query branch entirely).
    $llmSettings = $this->createMock(ImmutableConfig::class);
    $llmSettings->method('get')->willReturnCallback(
      static fn (string $k) => match ($k) {
        'enabled_content_types' => [],
        default => NULL,
      },
    );
    $llmSettings->method('getCacheContexts')->willReturn([]);
    $llmSettings->method('getCacheTags')->willReturn(['config:llm_content.settings']);
    $llmSettings->method('getCacheMaxAge')->willReturn(-1);

    $siteSettings = $this->createMock(ImmutableConfig::class);
    $siteSettings->method('get')->willReturnCallback(
      static fn (string $k) => match ($k) {
        'name' => 'Site',
        default => '',
      },
    );
    $siteSettings->method('getCacheContexts')->willReturn([]);
    $siteSettings->method('getCacheTags')->willReturn(['config:system.site']);
    $siteSettings->method('getCacheMaxAge')->willReturn(-1);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(
      static fn (string $name) => $name === 'system.site' ? $siteSettings : $llmSettings,
    );

    // Cache contexts manager accepts whatever tokens the controller asks for.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);

    $this->markdownConverter = $this->createMock(MarkdownConverterInterface::class);
    $this->markdownConverter->method('generateFullText')->willReturn('# Site');

    // Note: ControllerBase::languageManager() pulls from the container
    // lazily, but enabled_content_types is empty here so llmsTxt()
    // never enters the branch that calls it. No language_manager
    // service registration needed.
    $this->controller = new LlmsTxtController(
      $this->markdownConverter,
      $this->requestStack('/llms.txt'),
    );
  }

  /**
   * Builds a request stack holding a single GET request.
   *
   * @param string $uri
   *   The request URI, including any query string.
   */
  protected function requestStack(string $uri): RequestStack {
    $stack = new RequestStack();
    $stack->push(Request::create($uri));

    return $stack;
  }

  /**
   * Llms.txt response must vary on node grants to prevent cross-user leakage.
   *
   * Since the controller filters nodes with accessCheck(TRUE), the
   * response must vary by the user's node grant context — otherwise a
   * cached response from a privileged user could be served to an
   * unprivileged one.
   *
   * @covers ::llmsTxt
   */
  public function testLlmsTxtAddsNodeGrantsCacheContext(): void {
    $response = $this->controller->llmsTxt();

    $this->assertContains(
      'user.node_grants:view',
      $response->getCacheableMetadata()->getCacheContexts(),
    );
  }

  /**
   * Llms-full.txt response must vary on node grants for the same reason.
   *
   * @covers ::llmsFullTxt
   */
  public function testLlmsFullTxtAddsNodeGrantsCacheContext(): void {
    $response = $this->controller->llmsFullTxt();

    $this->assertContains(
      'user.node_grants:view',
      $response->getCacheableMetadata()->getCacheContexts(),
    );
  }

  /**
   * A query string on llms-full.txt must redirect, not build the corpus.
   *
   * The page cache keys on the whole URL, so `?x=1` … `?x=N` each stored
   * their own 13 MB copy of the body — 25 anonymous requests took Redis
   * from 209 MB to 559 MB. Redirecting caches a few hundred bytes per
   * junk URL instead, and leaves exactly one copy of the real body.
   *
   * @covers ::llmsFullTxt
   */
  public function testLlmsFullTxtRedirectsWhenQueryStringPresent(): void {
    $controller = new LlmsTxtController(
      $this->markdownConverter,
      $this->requestStack('/llms-full.txt?x=7'),
    );

    // The corpus build must not run at all.
    $this->markdownConverter->expects($this->never())->method('generateFullText');

    $response = $controller->llmsFullTxt();

    $this->assertInstanceOf(LocalRedirectResponse::class, $response);
    $this->assertSame(301, $response->getStatusCode());
    $this->assertSame('/llms-full.txt', $response->getTargetUrl());
  }

  /**
   * The same holds for llms.txt, which builds a 500-node index.
   *
   * @covers ::llmsTxt
   */
  public function testLlmsTxtRedirectsWhenQueryStringPresent(): void {
    $controller = new LlmsTxtController(
      $this->markdownConverter,
      $this->requestStack('/llms.txt?utm_source=spam'),
    );

    $response = $controller->llmsTxt();

    $this->assertInstanceOf(LocalRedirectResponse::class, $response);
    $this->assertSame(301, $response->getStatusCode());
    $this->assertSame('/llms.txt', $response->getTargetUrl());
  }

  /**
   * The redirect itself must be cacheable, or it is no cheaper than the body.
   *
   * @covers ::llmsFullTxt
   */
  public function testCanonicalRedirectIsCacheable(): void {
    $controller = new LlmsTxtController(
      $this->markdownConverter,
      $this->requestStack('/llms-full.txt?x=7'),
    );

    $response = $controller->llmsFullTxt();

    $this->assertInstanceOf(CacheableResponseInterface::class, $response);
    $this->assertNotSame(0, $response->getCacheableMetadata()->getCacheMaxAge());
  }

  /**
   * Both 200 responses must vary on query args.
   *
   * Without this context dynamic_page_cache serves `?x=1` from the
   * cached query-string-free response — the controller never runs, so
   * the redirect never fires and the page cache stores a full copy of
   * the body under the junk URL anyway.
   *
   * @dataProvider endpointProvider
   */
  public function testResponsesVaryOnQueryArgs(string $method): void {
    $response = $this->controller->{$method}();

    $this->assertContains(
      'url.query_args',
      $response->getCacheableMetadata()->getCacheContexts(),
    );
  }

  /**
   * Provides the controller's two public endpoint methods.
   *
   * @return array<string, array{string}>
   *   Test cases keyed by endpoint name.
   */
  public static function endpointProvider(): array {
    return [
      'llms.txt' => ['llmsTxt'],
      'llms-full.txt' => ['llmsFullTxt'],
    ];
  }

}
