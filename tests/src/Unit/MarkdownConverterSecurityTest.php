<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Entity\EntityViewBuilderInterface;
use League\HTMLToMarkdown\HtmlConverter;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\llm_content\Service\MarkdownConverter;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests security fixes in MarkdownConverter.
 *
 * Covers PR #24: title XSS sanitization, YAML frontmatter injection
 * defense, and access-checked entity query in generateFullText().
 *
 * @group llm_content
 * @coversDefaultClass \Drupal\llm_content\Service\MarkdownConverter
 */
class MarkdownConverterSecurityTest extends TestCase {

  /**
   * Builds a MarkdownConverter with fully-mocked dependencies for convert().
   *
   * The provided $renderedHtml is what renderer->renderInIsolation() returns
   * — i.e. the simulated rendered node markup that convert() will then
   * strip + convert to markdown.
   *
   * @return array{MarkdownConverter, NodeInterface}
   *   The converter and a mock node ready for convert().
   */
  protected function buildConverterForConvert(
    string $renderedHtml = '<p>Body content.</p>',
    string $label = 'Test',
    string $alias = '/test',
  ): array {
    $reflection = new \ReflectionClass(MarkdownConverter::class);
    $converter = $reflection->newInstanceWithoutConstructor();

    // htmlConverter is normally constructed in __construct().
    $htmlConverter = new HtmlConverter([
      'strip_tags' => TRUE,
      'remove_nodes' => 'script style iframe nav header footer aside',
      'header_style' => 'atx',
    ]);
    $this->setProp($reflection, $converter, 'htmlConverter', $htmlConverter);
    $this->setProp($reflection, $converter, 'logger', $this->createMock(LoggerInterface::class));

    // configFactory: convert() reads view_mode from llm_content.settings.
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn (string $k) => $k === 'view_mode' ? 'full' : NULL
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($settings);
    $this->setProp($reflection, $converter, 'configFactory', $configFactory);

    // entityTypeManager: convert() calls getViewBuilder('node')->view().
    $viewBuilder = $this->createMock(EntityViewBuilderInterface::class);
    $viewBuilder->method('view')->willReturn(['#markup' => $renderedHtml]);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getViewBuilder')->with('node')->willReturn($viewBuilder);
    $this->setProp($reflection, $converter, 'entityTypeManager', $etm);

    // renderer: returns the canned HTML.
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('renderInIsolation')->willReturn($renderedHtml);
    $this->setProp($reflection, $converter, 'renderer', $renderer);

    // aliasManager: returns the canned alias.
    $aliasManager = $this->createMock(AliasManagerInterface::class);
    $aliasManager->method('getAliasByPath')->willReturn($alias);
    $this->setProp($reflection, $converter, 'aliasManager', $aliasManager);

    // dateFormatter & time: cheap stubs.
    $dateFormatter = $this->createMock(DateFormatterInterface::class);
    $dateFormatter->method('format')->willReturn('2026-01-01');
    $this->setProp($reflection, $converter, 'dateFormatter', $dateFormatter);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);
    $this->setProp($reflection, $converter, 'time', $time);

    // database->merge() chain — swallow the write.
    $merge = $this->createMock(Merge::class);
    $merge->method('keys')->willReturnSelf();
    $merge->method('fields')->willReturnSelf();
    $merge->method('execute')->willReturn(Merge::STATUS_INSERT);
    $database = $this->createMock(Connection::class);
    $database->method('merge')->willReturn($merge);
    $this->setProp($reflection, $converter, 'database', $database);

    // Node mock.
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn('1');
    $node->method('bundle')->willReturn('article');
    $node->method('label')->willReturn($label);
    $node->method('language')->willReturn($language);
    $node->method('getCreatedTime')->willReturn(1700000000);
    $node->method('getRevisionCreationTime')->willReturn(1700000000);

    return [$converter, $node];
  }

  /**
   * Sets a protected/private property via reflection.
   */
  protected function setProp(\ReflectionClass $reflection, object $obj, string $name, mixed $value): void {
    $prop = $reflection->getProperty($name);
    $prop->setAccessible(TRUE);
    $prop->setValue($obj, $value);
  }

  /**
   * HTML tags in node titles must be stripped from the YAML title field.
   *
   * @covers ::convert
   */
  public function testConvertStripsHtmlFromYamlTitle(): void {
    [$converter, $node] = $this->buildConverterForConvert(
      label: 'Hello <script>alert(1)</script> World',
    );

    $output = $converter->convert($node);

    $this->assertStringContainsString('title: "Hello alert(1) World"', $output);
    $this->assertStringNotContainsString('<script>', $output);
  }

  /**
   * HTML tags in node titles must be stripped from the markdown H1.
   *
   * @covers ::convert
   */
  public function testConvertStripsHtmlFromHeadingTitle(): void {
    [$converter, $node] = $this->buildConverterForConvert(
      label: 'Title with <img src=x onerror=alert(1)> tag',
    );

    $output = $converter->convert($node);

    $this->assertMatchesRegularExpression('/^# Title with  tag\n/m', $output);
    $this->assertStringNotContainsString('onerror', $output);
  }

  /**
   * Markdown brackets in titles must not appear verbatim in the H1.
   *
   * Without sanitization, a title of `[click](javascript:alert(1))` lands
   * verbatim in the H1 and renders as a malicious link when consumers
   * convert the markdown back to HTML. The fix replaces `[`/`]` with
   * `(`/`)` — same defense the llms.txt link title uses.
   *
   * @covers ::convert
   */
  public function testConvertReplacesBracketsInHeadingTitle(): void {
    [$converter, $node] = $this->buildConverterForConvert(
      label: '[click](javascript:alert(1))',
    );

    $output = $converter->convert($node);

    // Brackets in the heading have been neutralized.
    $this->assertMatchesRegularExpression(
      '/^# \(click\)\(javascript:alert\(1\)\)\n/m',
      $output,
    );
    $this->assertStringNotContainsString('# [click]', $output);
  }

  /**
   * Control characters in titles must be stripped from the H1.
   *
   * A newline in the title would break the markdown H1 line and could
   * inject additional headings or arbitrary content.
   *
   * @covers ::convert
   */
  public function testConvertStripsControlCharsFromHeadingTitle(): void {
    [$converter, $node] = $this->buildConverterForConvert(
      label: "Innocent\n## Injected H2",
    );

    $output = $converter->convert($node);

    // The newline is gone, so no second-level heading can be injected.
    $this->assertMatchesRegularExpression(
      '/^# Innocent## Injected H2\n/m',
      $output,
    );
    $this->assertDoesNotMatchRegularExpression('/^## Injected H2$/m', $output);
  }

  /**
   * Double quotes and backslashes in titles must be YAML-escaped.
   *
   * Without escaping, a title containing `"` breaks the YAML
   * `title: "..."` value and could inject arbitrary frontmatter fields.
   *
   * @covers ::convert
   */
  public function testConvertEscapesYamlQuotesInTitle(): void {
    [$converter, $node] = $this->buildConverterForConvert(
      label: 'He said "hi" \\o/',
    );

    $output = $converter->convert($node);

    // Backslash → \\, double-quote → \".
    $this->assertStringContainsString('title: "He said \\"hi\\" \\\\o/"', $output);
  }

  /**
   * Control characters (including newlines) must be stripped from the title.
   *
   * A newline in the unescaped title would break out of the title: line
   * and inject arbitrary YAML keys.
   *
   * @covers ::convert
   */
  public function testConvertStripsControlCharsFromTitle(): void {
    [$converter, $node] = $this->buildConverterForConvert(
      label: "Innocent\ninjected: true",
    );

    $output = $converter->convert($node);

    // The newline (and the injected key indentation) collapses — the
    // injected `injected: true` key must NOT appear as its own YAML line.
    $this->assertStringContainsString('title: "Innocentinjected: true"', $output);
    $this->assertDoesNotMatchRegularExpression('/^injected: true$/m', $output);
  }

  /**
   * Path aliases must be quoted and sanitized in the url frontmatter field.
   *
   * Bare unquoted aliases containing newlines could inject frontmatter
   * keys. The fix quotes the url: value and strips control characters.
   *
   * @covers ::convert
   */
  public function testConvertQuotesAndSanitizesUrlAlias(): void {
    [$converter, $node] = $this->buildConverterForConvert(
      alias: "/legit\ninjected: true",
    );

    $output = $converter->convert($node);

    // Url value is quoted, and the injected newline+key is stripped.
    $this->assertStringContainsString('url: "/legitinjected: true"', $output);
    $this->assertDoesNotMatchRegularExpression('/^injected: true$/m', $output);
  }

  /**
   * Backslashes and double-quotes in aliases must be YAML-escaped.
   *
   * @covers ::convert
   */
  public function testConvertEscapesYamlQuotesInAlias(): void {
    [$converter, $node] = $this->buildConverterForConvert(
      alias: '/path/with"quote\\back',
    );

    $output = $converter->convert($node);

    $this->assertStringContainsString('url: "/path/with\\"quote\\\\back"', $output);
  }

  /**
   * GenerateFullText() must use accessCheck(TRUE) on the entity query.
   *
   * This is the core access-control fix: previously the method ran a raw
   * DB join that bypassed node access. The PR replaced it with an entity
   * query that respects node grants.
   *
   * @covers ::generateFullText
   */
  public function testGenerateFullTextUsesAccessCheck(): void {
    [$converter] = $this->buildConverterForGenerateFullText(
      enabledTypes: ['article'],
      accessibleNids: [],
    );

    // The expectation is enforced inside buildConverterForGenerateFullText
    // via accessCheck()->with(TRUE). Reaching here means the query was
    // configured correctly; this assertion just keeps PHPUnit happy.
    $output = $converter->generateFullText();

    $this->assertStringStartsWith('# Site', $output);
    // No content section when access-filtered nids is empty.
    $this->assertStringNotContainsString("---\n\n", $output);
  }

  /**
   * GenerateFullText() returns site header only when no enabled types set.
   *
   * @covers ::generateFullText
   */
  public function testGenerateFullTextWithNoEnabledTypesShortCircuits(): void {
    [$converter] = $this->buildConverterForGenerateFullText(
      enabledTypes: [],
      accessibleNids: [],
      expectEntityQuery: FALSE,
    );

    $output = $converter->generateFullText();

    $this->assertSame("# Site\n\n", $output);
  }

  /**
   * GenerateFullText() concatenates only stored markdown for accessible nids.
   *
   * @covers ::generateFullText
   */
  public function testGenerateFullTextOnlyFetchesAccessibleNids(): void {
    [$converter] = $this->buildConverterForGenerateFullText(
      enabledTypes: ['article'],
      accessibleNids: [10, 20],
      storedMarkdown: ['# Ten body', '# Twenty body'],
    );

    $output = $converter->generateFullText();

    $this->assertStringContainsString('# Ten body', $output);
    $this->assertStringContainsString('# Twenty body', $output);
    $this->assertStringContainsString("\n\n---\n\n", $output);
  }

  /**
   * Builds a converter wired for generateFullText() with mock expectations.
   *
   * @param string[] $enabledTypes
   *   The enabled content type machine names.
   * @param int[] $accessibleNids
   *   The nids the entity query (with accessCheck) should return.
   * @param string[] $storedMarkdown
   *   The markdown rows the follow-up SELECT should return.
   * @param bool $expectEntityQuery
   *   Whether the entity query should be invoked. FALSE when enabledTypes
   *   is empty and the method short-circuits.
   *
   * @return array{MarkdownConverter, \PHPUnit\Framework\MockObject\MockObject}
   *   The converter and the database mock.
   */
  protected function buildConverterForGenerateFullText(
    array $enabledTypes,
    array $accessibleNids,
    array $storedMarkdown = [],
    bool $expectEntityQuery = TRUE,
  ): array {
    $reflection = new \ReflectionClass(MarkdownConverter::class);
    $converter = $reflection->newInstanceWithoutConstructor();

    // configFactory: enabled_content_types + system.site.
    $llmSettings = $this->createMock(ImmutableConfig::class);
    $llmSettings->method('get')->willReturnCallback(
      static fn (string $k) => match ($k) {
        'enabled_content_types' => $enabledTypes,
        default => NULL,
      },
    );
    $siteSettings = $this->createMock(ImmutableConfig::class);
    $siteSettings->method('get')->willReturnCallback(
      static fn (string $k) => match ($k) {
        'name' => 'Site',
        default => '',
      },
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturnCallback(
      static fn (string $name) => $name === 'system.site' ? $siteSettings : $llmSettings,
    );
    $this->setProp($reflection, $converter, 'configFactory', $configFactory);

    // entityTypeManager + storage + entity query.
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    if ($expectEntityQuery) {
      $query = $this->createMock(QueryInterface::class);
      $query->method('condition')->willReturnSelf();
      $query->method('sort')->willReturnSelf();
      // Strict: accessCheck must be called with TRUE.
      $query->expects($this->atLeastOnce())
        ->method('accessCheck')
        ->with(TRUE)
        ->willReturnSelf();
      $query->method('execute')->willReturn($accessibleNids);

      $storage = $this->createMock(EntityStorageInterface::class);
      $storage->method('getQuery')->willReturn($query);
      $etm->method('getStorage')->with('node')->willReturn($storage);
    }
    else {
      $etm->expects($this->never())->method('getStorage');
    }
    $this->setProp($reflection, $converter, 'entityTypeManager', $etm);

    // database->select() chain — only invoked when accessibleNids is non-empty.
    $database = $this->createMock(Connection::class);
    if (!empty($accessibleNids)) {
      $statement = $this->createMock(StatementInterface::class);
      $statement->method('fetchCol')->willReturn($storedMarkdown);

      $select = $this->createMock(SelectInterface::class);
      $select->method('fields')->willReturnSelf();
      $select->method('condition')->willReturnSelf();
      $select->method('orderBy')->willReturnSelf();
      $select->method('execute')->willReturn($statement);
      // Asserts the canonical-translation join was added on top of the
      // access-check fix — otherwise multilingual sites would emit one
      // copy of the same node per stored language.
      $select->expects($this->once())
        ->method('innerJoin')
        ->with(
          'node_field_data',
          'n',
          $this->stringContains('n.langcode = m.langcode'),
        )
        ->willReturnSelf();

      $database->expects($this->once())
        ->method('select')
        ->with('llm_content_markdown', 'm')
        ->willReturn($select);
    }
    else {
      $database->expects($this->never())->method('select');
    }
    $this->setProp($reflection, $converter, 'database', $database);

    return [$converter, $database];
  }

}
