<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
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
   * Mock route match.
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * The container the hooks run against.
   */
  protected ContainerBuilder $container;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Cache::invalidateTags() and Cache::mergeContexts() call the static
    // \Drupal container.
    $container = new ContainerBuilder();
    $container->set('cache_tags.invalidator', $this->createMock(CacheTagsInvalidatorInterface::class));
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
    $this->container = $container;

    $this->markdownConverter = $this->createMock(MarkdownConverterInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->xmlSitemapLinkManager = $this->createMock(XmlSitemapLinkManagerInterface::class);
    $this->queueFactory = $this->createMock(QueueFactory::class);
    $this->queue = $this->createMock(QueueInterface::class);
    $this->routeMatch = $this->createMock(RouteMatchInterface::class);

    $this->queueFactory->method('get')
      ->with('llm_content_markdown_generation')
      ->willReturn($this->queue);

    $this->hooks = new LlmContentHooks(
      $this->markdownConverter,
      $this->configFactory,
      $this->xmlSitemapLinkManager,
      $this->queueFactory,
      $this->routeMatch,
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

  /**
   * Prepares a node canonical route match and a stubbed URL generator.
   */
  protected function setUpPageAttachments(NodeInterface $node): void {
    $this->routeMatch->method('getRouteName')->willReturn('entity.node.canonical');
    $this->routeMatch->method('getParameter')->with('node')->willReturn($node);

    $generatedUrl = (new GeneratedUrl())
      ->setGeneratedUrl('/node/' . $node->id() . '/markdown');
    $generatedUrl->addCacheTags(['llm_content:url']);
    $generatedUrl->addCacheContexts(['url.site']);

    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')->willReturn($generatedUrl);
    $this->container->set('url_generator', $urlGenerator);
  }

  /**
   * Tests that attachments added by other modules survive.
   *
   * GeneratedUrl::applyTo() assigns $page['#attached'] wholesale, so applying
   * it directly discards everything earlier hook_page_attachments()
   * implementations contributed.
   *
   * @covers ::pageAttachments
   */
  public function testPageAttachmentsPreservesExistingAttachments(): void {
    $this->setUpConfig();
    $node = $this->createMockNode();
    $this->setUpPageAttachments($node);

    $page = [
      '#attached' => [
        'library' => ['other_module/other_library'],
        'html_head' => [
          [['#tag' => 'meta', '#attributes' => ['name' => 'other']], 'other_module_meta'],
        ],
      ],
    ];

    $this->hooks->pageAttachments($page);

    $this->assertSame(['other_module/other_library'], $page['#attached']['library']);

    $keys = array_column($page['#attached']['html_head'], 1);
    $this->assertContains('other_module_meta', $keys);
    $this->assertContains('llm_content_alternate', $keys);
  }

  /**
   * Tests that the alternate link and URL cacheability are both applied.
   *
   * @covers ::pageAttachments
   */
  public function testPageAttachmentsAddsAlternateLinkAndCacheability(): void {
    $this->setUpConfig();
    $node = $this->createMockNode();
    $this->setUpPageAttachments($node);

    $page = [
      '#cache' => [
        'tags' => ['other_module:tag'],
        'contexts' => ['languages:language_interface'],
      ],
    ];

    $this->hooks->pageAttachments($page);

    $link = $page['#attached']['html_head'][0][0];
    $this->assertSame('link', $link['#tag']);
    $this->assertSame('alternate', $link['#attributes']['rel']);
    $this->assertSame('text/markdown', $link['#attributes']['type']);
    $this->assertSame('/node/1/markdown', $link['#attributes']['href']);

    $this->assertContains('other_module:tag', $page['#cache']['tags']);
    $this->assertContains('llm_content:url', $page['#cache']['tags']);
    $this->assertContains('languages:language_interface', $page['#cache']['contexts']);
    $this->assertContains('url.site', $page['#cache']['contexts']);
  }

  /**
   * Tests that pages outside the node canonical route are untouched.
   *
   * @covers ::pageAttachments
   */
  public function testPageAttachmentsSkipsNonNodeRoutes(): void {
    $this->routeMatch->method('getRouteName')->willReturn('system.admin');

    $page = ['#attached' => ['library' => ['other_module/other_library']]];
    $this->hooks->pageAttachments($page);

    $this->assertSame(['#attached' => ['library' => ['other_module/other_library']]], $page);
  }

  /**
   * Tests that unpublished nodes get no alternate link.
   *
   * @covers ::pageAttachments
   */
  public function testPageAttachmentsSkipsUnpublishedNode(): void {
    $this->setUpConfig();
    $node = $this->createMockNode(published: FALSE);
    $this->routeMatch->method('getRouteName')->willReturn('entity.node.canonical');
    $this->routeMatch->method('getParameter')->with('node')->willReturn($node);

    $page = [];
    $this->hooks->pageAttachments($page);

    $this->assertSame([], $page);
  }

}
