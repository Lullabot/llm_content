<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drush\Commands\DrushCommands;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\llm_content\Drush\Commands\LlmContentCommands;
use Drupal\llm_content\Service\MarkdownConverterInterface;
use Drupal\llm_content\Service\XmlSitemapLinkManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Tests the sitemapSync drush command's three guard branches.
 *
 * @group llm_content
 * @coversDefaultClass \Drupal\llm_content\Drush\Commands\LlmContentCommands
 */
class LlmContentCommandsSitemapSyncTest extends TestCase {

  /**
   * Skips the test when Drush is not in the autoloader.
   *
   * The CI test job installs drupal/core but not drush/drush, so the
   * LlmContentCommands class (which extends DrushCommands) cannot load.
   * These tests are intended to run in environments where Drush is
   * available (developer machines, integration test environments).
   */
  protected function setUp(): void {
    parent::setUp();
    if (!class_exists(DrushCommands::class)) {
      $this->markTestSkipped('Drush is not installed in this environment.');
    }
  }

  /**
   * Builds the command with mocked deps and a recording logger.
   *
   * Uses ReflectionClass::newInstanceWithoutConstructor() to skip
   * DrushCommands::__construct(), which would otherwise require Drush
   * runtime internals. The logger is a real PSR-3 implementation that
   * records every call (including Drush's non-PSR success() method).
   *
   * @return array{
   *   LlmContentCommands,
   *   object,
   *   \PHPUnit\Framework\MockObject\MockObject&XmlSitemapLinkManagerInterface
   *   }
   *   The command, the recording logger, and the xmlsitemap manager mock.
   */
  protected function buildCommand(): array {
    $markdownConverter = $this->createMock(MarkdownConverterInterface::class);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $xmlSitemapLinkManager = $this->createMock(XmlSitemapLinkManagerInterface::class);

    // Recording PSR-3 logger that also accepts Drush's non-PSR
    // success() / notice() / etc. via __call. Every call appends
    // ['level' => ..., 'message' => ...] to $calls.
    $logger = new class () extends AbstractLogger {

      /**
       * Recorded calls, each ['level' => string, 'message' => string].
       *
       * @var array<int, array{level: string, message: string}>
       */
      public array $calls = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->calls[] = ['level' => (string) $level, 'message' => (string) $message];
      }

      /**
       * Captures non-PSR logger calls (e.g. Drush's success()).
       */
      public function __call(string $name, array $args): void {
        $this->calls[] = ['level' => $name, 'message' => (string) ($args[0] ?? '')];
      }

    };

    $reflection = new \ReflectionClass(LlmContentCommands::class);
    $command = $reflection->newInstanceWithoutConstructor();

    foreach ([
      'markdownConverter' => $markdownConverter,
      'configFactory' => $configFactory,
      'entityTypeManager' => $entityTypeManager,
      'xmlSitemapLinkManager' => $xmlSitemapLinkManager,
    ] as $name => $value) {
      $prop = $reflection->getProperty($name);
      $prop->setAccessible(TRUE);
      $prop->setValue($command, $value);
    }

    // DrushCommands::logger() reads from a protected $logger property.
    $loggerProp = new \ReflectionProperty(DrushCommands::class, 'logger');
    $loggerProp->setAccessible(TRUE);
    $loggerProp->setValue($command, $logger);

    return [$command, $logger, $xmlSitemapLinkManager];
  }

  /**
   * Returns the levels recorded by the test logger, in call order.
   *
   * @return string[]
   *   The level names.
   */
  protected function levels(object $logger): array {
    return array_column($logger->calls, 'level');
  }

  /**
   * Asserts a single logger call exists at the given level with substring.
   */
  protected function assertLoggedAt(object $logger, string $level, string $needle): void {
    foreach ($logger->calls as $call) {
      if ($call['level'] === $level && str_contains($call['message'], $needle)) {
        $this->addToAssertionCount(1);
        return;
      }
    }
    $this->fail(sprintf(
      'Expected a %s log containing "%s". Got: %s',
      $level,
      $needle,
      json_encode($logger->calls),
    ));
  }

  /**
   * When xmlsitemap module is not installed, logs error and skips sync.
   *
   * @covers ::sitemapSync
   */
  public function testSitemapSyncErrorsWhenXmlsitemapMissing(): void {
    [$command, $logger, $manager] = $this->buildCommand();

    $manager->method('isAvailable')->willReturn(FALSE);
    $manager->expects($this->never())->method('isEnabled');
    $manager->expects($this->never())->method('syncAllLinks');

    $exitCode = $command->sitemapSync();

    $this->assertSame(DrushCommands::EXIT_FAILURE, $exitCode);
    $this->assertLoggedAt($logger, 'error', 'xmlsitemap module is not installed');
  }

  /**
   * When integration is disabled in config, warns and skips sync.
   *
   * @covers ::sitemapSync
   */
  public function testSitemapSyncWarnsWhenIntegrationDisabled(): void {
    [$command, $logger, $manager] = $this->buildCommand();

    $manager->method('isAvailable')->willReturn(TRUE);
    $manager->method('isEnabled')->willReturn(FALSE);
    $manager->expects($this->never())->method('syncAllLinks');

    $exitCode = $command->sitemapSync();

    $this->assertSame(DrushCommands::EXIT_FAILURE, $exitCode);
    $this->assertLoggedAt($logger, 'warning', 'xmlsitemap_integration is disabled');
  }

  /**
   * When module installed and integration enabled, runs the sync.
   *
   * @covers ::sitemapSync
   */
  public function testSitemapSyncRunsSyncWhenAvailableAndEnabled(): void {
    [$command, $logger, $manager] = $this->buildCommand();

    $manager->method('isAvailable')->willReturn(TRUE);
    $manager->method('isEnabled')->willReturn(TRUE);
    $manager->expects($this->once())
      ->method('syncAllLinks')
      ->willReturn(42);

    $exitCode = $command->sitemapSync();

    $this->assertSame(DrushCommands::EXIT_SUCCESS, $exitCode);
    // No error or warning on the happy path.
    $this->assertNotContains('error', $this->levels($logger));
    $this->assertNotContains('warning', $this->levels($logger));
    // Drush's success() level is recorded by __call on the test logger.
    // The reported count should match the value returned by syncAllLinks().
    $this->assertLoggedAt($logger, 'success', 'Synced 42 LLM content links');
  }

}
