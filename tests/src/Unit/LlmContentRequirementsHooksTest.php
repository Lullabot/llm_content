<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\llm_content\Hook\LlmContentRequirementsHooks;
use PHPUnit\Framework\TestCase;

/**
 * Tests runtime requirements hooks for the LLM Content module.
 *
 * @group llm_content
 * @coversDefaultClass \Drupal\llm_content\Hook\LlmContentRequirementsHooks
 */
class LlmContentRequirementsHooksTest extends TestCase {

  /**
   * Tests runtimeRequirements() returns OK when library is present.
   *
   * @covers ::runtimeRequirements
   */
  public function testRuntimeRequirementsWhenLibraryPresent(): void {
    // The library is installed in the test environment via Composer.
    $hooks = new LlmContentRequirementsHooks();
    $hooks->setStringTranslation($this->createStub(TranslationInterface::class));
    $requirements = $hooks->runtimeRequirements();

    $this->assertArrayHasKey('llm_content_html_to_markdown', $requirements);
    $this->assertSame(REQUIREMENT_OK, $requirements['llm_content_html_to_markdown']['severity']);
  }

}
