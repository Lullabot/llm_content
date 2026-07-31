<?php

declare(strict_types=1);

namespace Drupal\Tests\llm_content\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests HTTP method restrictions on the module's routes.
 *
 * Drupal's page_cache and dynamic_page_cache both decline to serve
 * non-cacheable request methods. A route with no `methods:` key therefore
 * lets an unauthenticated POST re-execute the controller on every single
 * request, with no token and no rate limit — /llms-full.txt costs a full
 * corpus build, /sitemap-llm.xml a 50k-row query, and /node/N/llm-md can
 * write to llm_content_markdown through the generate-on-demand path.
 *
 * @group llm_content
 */
class RoutingSecurityTest extends TestCase {

  /**
   * The parsed routing definitions.
   *
   * @var array<string, array<string, mixed>>
   */
  protected array $routes;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $file = dirname(__DIR__, 3) . '/llm_content.routing.yml';
    $this->assertFileExists($file);
    $this->routes = Yaml::parseFile($file);
  }

  /**
   * Content-serving routes must only answer cacheable methods.
   *
   * @dataProvider contentRouteProvider
   */
  public function testContentRoutesRestrictMethods(string $routeName): void {
    $this->assertArrayHasKey($routeName, $this->routes);
    $this->assertArrayHasKey(
      'methods',
      $this->routes[$routeName],
      sprintf('Route %s must declare a methods restriction.', $routeName),
    );
    $this->assertSame(
      ['GET', 'HEAD'],
      $this->routes[$routeName]['methods'],
      sprintf('Route %s must only allow GET and HEAD.', $routeName),
    );
  }

  /**
   * Provides the anonymous, content-serving route names.
   *
   * @return array<string, array{string}>
   *   Test cases keyed by route name.
   */
  public static function contentRouteProvider(): array {
    return [
      'markdown view' => ['llm_content.markdown_view'],
      'sitemap' => ['llm_content.sitemap_llm'],
      'llms.txt' => ['llm_content.llms_txt'],
      'llms-full.txt' => ['llm_content.llms_full_txt'],
    ];
  }

  /**
   * The settings form must not be method-restricted — it needs POST.
   */
  public function testSettingsFormRouteAcceptsPost(): void {
    $this->assertArrayHasKey('llm_content.settings', $this->routes);
    $this->assertArrayNotHasKey('methods', $this->routes['llm_content.settings']);
  }

  /**
   * Every route in the file is either GET/HEAD-only or an admin form.
   *
   * Guards against a future route being added without a deliberate
   * decision about which methods it answers.
   */
  public function testNoUnrestrictedControllerRoutes(): void {
    foreach ($this->routes as $name => $definition) {
      // Form routes legitimately need POST.
      if (isset($definition['defaults']['_form'])) {
        continue;
      }
      $this->assertArrayHasKey(
        'methods',
        $definition,
        sprintf('Controller route %s has no methods restriction.', $name),
      );
    }
  }

}
