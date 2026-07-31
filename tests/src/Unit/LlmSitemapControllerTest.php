<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\llm_content\Controller\LlmSitemapController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the LLM sitemap controller.
 *
 * @group llm_content
 * @coversDefaultClass \Drupal\llm_content\Controller\LlmSitemapController
 */
class LlmSitemapControllerTest extends TestCase {

  /**
   * A query string must redirect before the 50,000-row query runs.
   *
   * Measured at 4.6 s and 310 KB per uncached build, so every distinct
   * `?x=N` was both a full rebuild and its own page-cache entry.
   *
   * @covers ::generate
   */
  public function testQueryStringRedirectsBeforeQuerying(): void {
    $database = $this->createMock(Connection::class);
    // The redirect must short-circuit ahead of any database work.
    $database->expects($this->never())->method('select');

    $stack = new RequestStack();
    $stack->push(Request::create('/sitemap-llm.xml?x=7'));

    $controller = new LlmSitemapController($database, $stack);

    $response = $controller->generate();

    $this->assertInstanceOf(LocalRedirectResponse::class, $response);
    $this->assertSame(301, $response->getStatusCode());
    $this->assertSame('/sitemap-llm.xml', $response->getTargetUrl());
  }

}
