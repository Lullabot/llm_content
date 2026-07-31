<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\llm_content\Controller\LlmsTxtController;
use Drupal\llm_content\Service\MarkdownConverterInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests that llms.txt entries stay one well-formed line each.
 *
 * The file is a line-oriented format: each entry is
 * `- [title](url): description`. Any newline surviving into the title or
 * the description ends the entry early and lets whatever follows pose as
 * its own top-level entry — a fake link, possibly off-site, in a file
 * that LLM crawlers treat as site-endorsed. Anyone with edit access to
 * any enabled bundle can write one.
 *
 * @group llm_content
 * @coversDefaultClass \Drupal\llm_content\Controller\LlmsTxtController
 */
class LlmsTxtEntryFormatTest extends TestCase {

  /**
   * Renders llms.txt for a single node with the given field values.
   *
   * @param string $label
   *   The node title.
   * @param string|null $summary
   *   The body summary, or NULL for none.
   * @param string $value
   *   The body value.
   * @param bool $hasBody
   *   Whether the node has a non-empty body field at all.
   * @param array<int, string> $storedMarkdown
   *   Stored markdown keyed by nid, used for the description fallback.
   *
   * @return string
   *   The generated llms.txt body.
   */
  protected function renderFor(
    string $label,
    ?string $summary = NULL,
    string $value = '',
    bool $hasBody = TRUE,
    array $storedMarkdown = [],
  ): string {
    $llmSettings = $this->createMock(ImmutableConfig::class);
    $llmSettings->method('get')->willReturnCallback(
      static fn (string $k) => $k === 'enabled_content_types' ? ['article'] : NULL,
    );
    $llmSettings->method('getCacheContexts')->willReturn([]);
    $llmSettings->method('getCacheTags')->willReturn([]);
    $llmSettings->method('getCacheMaxAge')->willReturn(-1);

    $siteSettings = $this->createMock(ImmutableConfig::class);
    $siteSettings->method('get')->willReturnCallback(
      static fn (string $k) => $k === 'name' ? 'Site' : '',
    );
    $siteSettings->method('getCacheContexts')->willReturn([]);
    $siteSettings->method('getCacheTags')->willReturn([]);
    $siteSettings->method('getCacheMaxAge')->willReturn(-1);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(
      static fn (string $name) => $name === 'system.site' ? $siteSettings : $llmSettings,
    );

    $query = $this->createMock(QueryInterface::class);
    $query->method('condition')->willReturnSelf();
    $query->method('accessCheck')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([1]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn([1 => $this->buildNode($label, $summary, $value, $hasBody)]);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturn($storage);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn($language);

    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')->willReturn('/node/1/llm-md');

    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('cache_contexts_manager', $cacheContextsManager);
    $container->set('entity_type.manager', $etm);
    $container->set('language_manager', $languageManager);
    $container->set('url_generator', $urlGenerator);
    \Drupal::setContainer($container);

    $markdownConverter = $this->createMock(MarkdownConverterInterface::class);
    $markdownConverter->method('getStoredMarkdownBatch')->willReturn($storedMarkdown);

    $stack = new RequestStack();
    $stack->push(Request::create('/llms.txt'));

    $controller = new LlmsTxtController($markdownConverter, $stack);

    return (string) $controller->llmsTxt()->getContent();
  }

  /**
   * Builds a node mock with a body field.
   */
  protected function buildNode(string $label, ?string $summary, string $value, bool $hasBody = TRUE): NodeInterface {
    $item = $this->createMock(FieldItemInterface::class);
    $item->method('__get')->willReturnCallback(
      static fn (string $property) => match ($property) {
        'summary' => $summary,
        'value' => $value,
        default => NULL,
      },
    );

    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn(!$hasBody);
    $list->method('first')->willReturn($hasBody ? $item : NULL);

    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn('1');
    $node->method('label')->willReturn($label);
    $node->method('hasField')->willReturn(TRUE);
    $node->method('get')->willReturn($list);

    return $node;
  }

  /**
   * Returns the entry lines of a generated llms.txt body.
   *
   * @return string[]
   *   Every line that begins a top-level list entry.
   */
  protected function entryLines(string $output): array {
    return array_values(array_filter(
      explode("\n", $output),
      static fn (string $line) => str_starts_with($line, '- ['),
    ));
  }

  /**
   * Returns every line of a generated llms.txt body that carries content.
   *
   * That is, everything but headings and blank lines. With a single node
   * configured, exactly one such line should exist.
   *
   * @return string[]
   *   The content lines.
   */
  protected function contentLines(string $output): array {
    return array_values(array_filter(
      explode("\n", $output),
      static fn (string $line) => trim($line) !== '' && !str_starts_with($line, '#'),
    ));
  }

  /**
   * A newline in the body summary must not create a second entry.
   *
   * This is the reported case: the summary branch previously received no
   * treatment at all — no tag stripping, no newline handling, no cap.
   *
   * @covers ::llmsTxt
   */
  public function testSummaryNewlineCannotInjectAnEntry(): void {
    $output = $this->renderFor(
      'Real Article',
      "ok\n- [Totally Legit Doc](https://evil.example/x)",
    );

    $lines = $this->entryLines($output);

    $this->assertCount(1, $lines, 'The injected link became its own entry.');
    $this->assertStringNotContainsString("\n- [Totally Legit", $output);
    // The text survives, inline, as part of the one legitimate entry.
    $this->assertStringContainsString('evil.example', $lines[0]);
  }

  /**
   * The same holds for the body value when there is no summary.
   *
   * @covers ::llmsTxt
   */
  public function testBodyValueNewlineCannotInjectAnEntry(): void {
    $output = $this->renderFor(
      'Real Article',
      NULL,
      "intro\n- [Fake Entry](https://evil.example/y)",
    );

    $this->assertCount(1, $this->entryLines($output));
  }

  /**
   * A newline in the node title must not split the entry across lines.
   *
   * The title was already tag-stripped and bracket-escaped, but nothing
   * removed newlines from it. Bracket escaping alone only stops the
   * injected text from looking like a link — it still lands on its own
   * line, where a line-oriented consumer reads it as a separate item.
   *
   * @covers ::llmsTxt
   */
  public function testTitleNewlineCannotInjectAnEntry(): void {
    $output = $this->renderFor("Innocent\n- [Fake Entry](https://evil.example/z)");

    $this->assertCount(1, $this->entryLines($output));
    // One node must produce exactly one line of content: anything else
    // means the title broke out of its entry.
    $this->assertCount(1, $this->contentLines($output));
  }

  /**
   * HTML in the summary must be stripped, matching the title's treatment.
   *
   * @covers ::llmsTxt
   */
  public function testSummaryHtmlIsStripped(): void {
    $output = $this->renderFor('Real Article', '<script>alert(1)</script>Safe text');

    $this->assertStringNotContainsString('<script>', $output);
    $this->assertStringContainsString('Safe text', $output);
  }

  /**
   * A long summary must be capped so one node cannot crowd out the index.
   *
   * The value branch already capped at 200 characters; the summary
   * branch did not, which is the asymmetry the review flagged.
   *
   * @covers ::llmsTxt
   */
  public function testSummaryIsLengthCapped(): void {
    $output = $this->renderFor('Real Article', str_repeat('a', 5000));

    $lines = $this->entryLines($output);
    $description = explode('): ', $lines[0], 2)[1] ?? '';

    $this->assertSame(200, mb_strlen($description));
  }

  /**
   * A whitespace-only summary falls through to the body value.
   *
   * Previously `$summary ?: $value` treated "  " as a usable summary and
   * emitted a trailing ": " with nothing after it.
   *
   * @covers ::llmsTxt
   */
  public function testWhitespaceOnlySummaryFallsBackToValue(): void {
    $output = $this->renderFor('Real Article', "  \n  ", 'The actual body.');

    $this->assertStringContainsString(': The actual body.', $output);
  }

  /**
   * A markdown link in a description must not stay a link.
   *
   * Collapsing newlines stops the injected text becoming its own entry,
   * but left inline it still renders as a live link attributed to this
   * site. The title has been bracket-escaped all along; the description
   * needs the same treatment.
   *
   * @covers ::llmsTxt
   */
  public function testDescriptionMarkdownLinkIsNeutralized(): void {
    $output = $this->renderFor(
      'Real Article',
      'See [Official Docs](https://evil.example/x) for details',
    );

    $this->assertStringNotContainsString('[Official Docs]', $output);
    $this->assertStringContainsString('See (Official Docs)(https://evil.example/x)', $output);
  }

  /**
   * A bare "<" in prose must not swallow the rest of the description.
   *
   * PHP's strip_tags() reads "<100 KB before storage" as an
   * unterminated tag and discards everything from the "<" onward,
   * silently truncating legitimate copy mid-sentence.
   *
   * @covers ::llmsTxt
   */
  public function testSummaryKeepsTextAfterBareLessThan(): void {
    $output = $this->renderFor(
      'Real Article',
      'Compresses uploads to <100 KB before storage',
    );

    $this->assertStringContainsString(
      'Compresses uploads to <100 KB before storage',
      $output,
    );
  }

  /**
   * Real tags are still removed even though strip_tags() is not used.
   *
   * @covers ::llmsTxt
   */
  public function testSummaryStillStripsRealTags(): void {
    $output = $this->renderFor(
      'Real Article',
      'Safe <img src=x onerror=alert(1)> text',
    );

    $this->assertStringNotContainsString('onerror', $output);
    // The gap the tag left behind is collapsed with the surrounding
    // whitespace, so the words end up separated by a single space.
    $this->assertStringContainsString('Safe text', $output);
  }

  /**
   * Long node titles must not be truncated by the description cap.
   *
   * Titles are bounded at 255 characters by the node schema; cutting
   * one at 200 makes the link text disagree with the page it points at.
   *
   * @covers ::llmsTxt
   */
  public function testLongTitleIsNotTruncated(): void {
    $title = str_repeat('t', 240);

    $output = $this->renderFor($title);

    $this->assertStringContainsString("- [{$title}](/node/1/llm-md)", $output);
  }

  /**
   * A body field present but empty must still reach the fallback.
   *
   * FieldItemList::isEmpty() is TRUE for an item holding only an empty
   * value, so such a node has no usable description of its own and
   * should fall through to its generated markdown.
   *
   * @covers ::llmsTxt
   */
  public function testEmptyBodyFallsBackToStoredMarkdown(): void {
    $output = $this->renderFor(
      'Real Article',
      hasBody: FALSE,
      storedMarkdown: [1 => "---\ntitle: \"x\"\n---\n\n# Real Article\n\nDerived description."],
    );

    $this->assertStringContainsString(': Derived description.', $output);
  }

  /**
   * A well-formed entry is still emitted unchanged.
   *
   * @covers ::llmsTxt
   */
  public function testOrdinaryEntryIsUnaffected(): void {
    $output = $this->renderFor('Real Article', 'A short summary.');

    $this->assertStringContainsString(
      '- [Real Article](/node/1/llm-md): A short summary.',
      $output,
    );
  }

}
