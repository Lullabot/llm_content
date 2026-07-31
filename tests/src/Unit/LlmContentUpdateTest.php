<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Truncate;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\llm_content\Service\MarkdownConverterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
   * Queue items created during the update.
   *
   * @var array<int, array<string, mixed>>
   */
  protected array $queued = [];

  /**
   * Table names passed to Connection::truncate().
   *
   * @var array<int, string>
   */
  protected array $truncated = [];

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
  }

  /**
   * Builds a container wired for llm_content_update_11003().
   *
   * @param string[] $enabledTypes
   *   The configured enabled content types.
   * @param int[] $missingNids
   *   The nids the converter reports as lacking stored markdown, which
   *   after a truncate is every published node of an enabled type.
   */
  protected function setUpContainer(array $enabledTypes, array $missingNids): void {
    $truncate = $this->createMock(Truncate::class);
    $truncate->method('execute')->willReturn(0);

    $database = $this->createMock(Connection::class);
    $database->method('truncate')->willReturnCallback(
      function (string $table) use ($truncate): Truncate {
        $this->truncated[] = $table;
        return $truncate;
      },
    );

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn (string $key) => $key === 'enabled_content_types' ? $enabledTypes : NULL,
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($settings);

    $converter = $this->createMock(MarkdownConverterInterface::class);
    $converter->method('getNidsMissingMarkdown')->willReturn($missingNids);

    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')->willReturnCallback(
      function (mixed $data): bool {
        $this->queued[] = $data;
        return TRUE;
      },
    );
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->method('get')->willReturn($queue);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerInterface::class));

    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $invalidator->method('invalidateTags')->willReturnCallback(
      function (array $tags): void {
        $this->invalidated = array_merge($this->invalidated, $tags);
      },
    );

    $container = new ContainerBuilder();
    $container->set('database', $database);
    $container->set('config.factory', $configFactory);
    $container->set(MarkdownConverterInterface::class, $converter);
    $container->set('queue', $queueFactory);
    $container->set('logger.factory', $loggerFactory);
    $container->set('cache_tags.invalidator', $invalidator);
    \Drupal::setContainer($container);
  }

  /**
   * The update must drop every stored row, not just refresh them.
   */
  public function testUpdatePurgesStoredMarkdown(): void {
    $this->setUpContainer(['article'], [10, 20]);

    llm_content_update_11003();

    $this->assertSame(['llm_content_markdown'], $this->truncated);
  }

  /**
   * Every node reported as missing must be queued for regeneration.
   */
  public function testUpdateQueuesRegeneration(): void {
    $this->setUpContainer(['article', 'page'], [10, 20, 30]);

    llm_content_update_11003();

    $this->assertSame(
      [['nid' => 10], ['nid' => 20], ['nid' => 30]],
      $this->queued,
    );
  }

  /**
   * Cached llms.txt and llms-full.txt output must be invalidated.
   *
   * The purge leaves both endpoints empty until the queue drains; the
   * previously cached — and possibly tainted — bodies must not keep
   * being served in the meantime.
   */
  public function testUpdateInvalidatesListCacheTag(): void {
    $this->setUpContainer(['article'], [10]);

    llm_content_update_11003();

    $this->assertContains('llm_content:list', $this->invalidated);
  }

  /**
   * The operator is told the endpoints are incomplete until drained.
   */
  public function testUpdateReturnsOperatorGuidance(): void {
    $this->setUpContainer(['article'], [10]);

    $message = llm_content_update_11003();

    $this->assertStringContainsString('queue:run llm_content_markdown_generation', $message);
  }

  /**
   * With no enabled types the purge still happens and nothing is queued.
   */
  public function testUpdatePurgesEvenWithNoEnabledTypes(): void {
    $this->setUpContainer([], []);

    llm_content_update_11003();

    $this->assertSame(['llm_content_markdown'], $this->truncated);
    $this->assertSame([], $this->queued);
  }

}
