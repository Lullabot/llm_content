<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\llm_content\Controller\LlmsTxtController;
use Drupal\llm_content\Service\MarkdownConverterInterface;
use PHPUnit\Framework\TestCase;

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

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn($language);

    $markdownConverter = $this->createMock(MarkdownConverterInterface::class);
    $markdownConverter->method('generateFullText')->willReturn('# Site');

    $this->controller = new LlmsTxtController($markdownConverter, $languageManager);
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

}
