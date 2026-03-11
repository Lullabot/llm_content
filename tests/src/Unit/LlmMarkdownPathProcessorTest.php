<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\llm_content\PathProcessor\LlmMarkdownPathProcessor;
use Drupal\path_alias\AliasManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the LlmMarkdownPathProcessor.
 *
 * @group llm_content
 * @coversDefaultClass \Drupal\llm_content\PathProcessor\LlmMarkdownPathProcessor
 */
class LlmMarkdownPathProcessorTest extends TestCase {

  /**
   * Creates an AliasManagerInterface stub with a configured method return.
   */
  private function createAliasManagerStub(string $method, string $returnValue): AliasManagerInterface {
    $stub = $this->createStub(AliasManagerInterface::class);
    $stub->method($method)->willReturn($returnValue);
    return $stub;
  }

  /**
   * @covers ::processInbound
   */
  public function testInboundResolvesAliasMdToNodeLlmMd(): void {
    $aliasManager = $this->createAliasManagerStub('getPathByAlias', '/node/42');
    $processor = new LlmMarkdownPathProcessor($aliasManager);

    $result = $processor->processInbound('/my-article.md', Request::create('/my-article.md'));

    $this->assertSame('/node/42/llm-md', $result);
  }

  /**
   * @covers ::processInbound
   */
  public function testInboundPassthroughForNonMdPaths(): void {
    $aliasManager = $this->createStub(AliasManagerInterface::class);
    $processor = new LlmMarkdownPathProcessor($aliasManager);

    $result = $processor->processInbound('/my-article', Request::create('/my-article'));

    $this->assertSame('/my-article', $result);
  }

  /**
   * @covers ::processInbound
   */
  public function testInboundPassthroughWhenAliasDoesNotResolve(): void {
    $aliasManager = $this->createAliasManagerStub('getPathByAlias', '/unknown-page');
    $processor = new LlmMarkdownPathProcessor($aliasManager);

    $result = $processor->processInbound('/unknown-page.md', Request::create('/unknown-page.md'));

    $this->assertSame('/unknown-page.md', $result);
  }

  /**
   * @covers ::processInbound
   */
  public function testInboundPassthroughForBareMdExtension(): void {
    $aliasManager = $this->createStub(AliasManagerInterface::class);
    $processor = new LlmMarkdownPathProcessor($aliasManager);

    $result = $processor->processInbound('/.md', Request::create('/.md'));

    $this->assertSame('/.md', $result);
  }

  /**
   * @covers ::processInbound
   */
  public function testInboundPassthroughWhenAliasResolvesToNonNodePath(): void {
    $aliasManager = $this->createAliasManagerStub('getPathByAlias', '/taxonomy/term/5');
    $processor = new LlmMarkdownPathProcessor($aliasManager);

    $result = $processor->processInbound('/my-term.md', Request::create('/my-term.md'));

    $this->assertSame('/my-term.md', $result);
  }

  /**
   * @covers ::processOutbound
   */
  public function testOutboundConvertsNodeLlmMdToAliasMd(): void {
    $aliasManager = $this->createAliasManagerStub('getAliasByPath', '/my-article');
    $processor = new LlmMarkdownPathProcessor($aliasManager);

    $result = $processor->processOutbound('/node/42/llm-md');

    $this->assertSame('/my-article.md', $result);
  }

  /**
   * @covers ::processOutbound
   */
  public function testOutboundPassthroughForNonLlmMdPaths(): void {
    $aliasManager = $this->createStub(AliasManagerInterface::class);
    $processor = new LlmMarkdownPathProcessor($aliasManager);

    $result = $processor->processOutbound('/node/42/edit');

    $this->assertSame('/node/42/edit', $result);
  }

  /**
   * @covers ::processOutbound
   */
  public function testOutboundKeepsPathWhenNoAliasExists(): void {
    $aliasManager = $this->createAliasManagerStub('getAliasByPath', '/node/99');
    $processor = new LlmMarkdownPathProcessor($aliasManager);

    $result = $processor->processOutbound('/node/99/llm-md');

    $this->assertSame('/node/99/llm-md', $result);
  }

  /**
   * @covers ::processOutbound
   */
  public function testOutboundAddsCacheTagToBubbleableMetadata(): void {
    $aliasManager = $this->createAliasManagerStub('getAliasByPath', '/my-article');
    $processor = new LlmMarkdownPathProcessor($aliasManager);

    $metadata = new BubbleableMetadata();
    $options = [];
    $processor->processOutbound('/node/42/llm-md', $options, NULL, $metadata);

    $this->assertContains('path_alias_list', $metadata->getCacheTags());
  }

}
