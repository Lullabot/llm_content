<?php

declare(strict_types=1);

namespace Drupal\llm_content\Hook;

// phpcs:ignore Drupal.Classes.UnusedUseStatement.UnusedUse -- Used by #[Hook] attributes.
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Runtime requirements hooks for the LLM Content module.
 *
 * Separated from LlmContentHooks to avoid depending on MarkdownConverter,
 * which requires league/html-to-markdown. This class can always be
 * instantiated regardless of whether the library is present.
 */
final class LlmContentRequirementsHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $requirements = [];

    if (!class_exists('League\HTMLToMarkdown\HtmlConverter')) {
      $requirements['llm_content_html_to_markdown'] = [
        'title' => $this->t('LLM Content - HTML to Markdown library'),
        'value' => $this->t('Not installed'),
        'description' => $this->t('The league/html-to-markdown library is required. Run <code>composer require league/html-to-markdown:^5.0</code> in your project root.'),
        'severity' => REQUIREMENT_ERROR,
      ];
    }
    else {
      $requirements['llm_content_html_to_markdown'] = [
        'title' => $this->t('LLM Content - HTML to Markdown library'),
        'value' => $this->t('Installed'),
        'severity' => REQUIREMENT_OK,
      ];
    }

    return $requirements;
  }

}
