<?php

declare(strict_types=1);

namespace Drupal\llm_content\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the LLM sitemap XML endpoint.
 */
final class LlmSitemapController extends ControllerBase {

  use CanonicalUrlTrait;

  public function __construct(
    protected Connection $database,
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('request_stack'),
    );
  }

  /**
   * Generates the LLM sitemap XML.
   */
  public function generate(): Response {
    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL && ($redirect = $this->redirectToCanonicalUrl($request)) !== NULL) {
      return $redirect;
    }

    $config = $this->config('llm_content.settings');
    $enabledTypes = $config->get('enabled_content_types') ?? [];
    // Use Drupal's URL generator for safe base URL resolution.
    $baseUrl = Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString();
    $baseUrl = rtrim($baseUrl, '/');

    // Use XMLWriter for safe XML generation.
    $xml = new \XMLWriter();
    $xml->openMemory();
    $xml->startDocument('1.0', 'UTF-8');
    $xml->startElement('urlset');
    $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    if (!empty($enabledTypes)) {
      // Use a direct DB query to avoid loading full entity objects.
      // The sitemap only needs nid and changed time.
      $query = $this->database->select('node_field_data', 'n');
      $query->fields('n', ['nid', 'changed']);
      $query->condition('n.status', 1);
      $query->condition('n.type', $enabledTypes, 'IN');
      $query->condition('n.default_langcode', 1);
      $query->orderBy('n.changed', 'DESC');
      $query->range(0, 50000);
      $results = $query->execute();

      foreach ($results as $row) {
        $xml->startElement('url');

        $xml->startElement('loc');
        $xml->text(Url::fromRoute('llm_content.markdown_view', ['node' => $row->nid], ['absolute' => TRUE])->toString());
        $xml->endElement();

        $xml->startElement('lastmod');
        $xml->text(date('Y-m-d\TH:i:sP', (int) $row->changed));
        $xml->endElement();

        $xml->startElement('changefreq');
        $xml->text('weekly');
        $xml->endElement();

        $xml->endElement();
      }
    }

    // Add llms.txt and llms-full.txt.
    $xml->startElement('url');
    $xml->startElement('loc');
    $xml->text($baseUrl . '/llms.txt');
    $xml->endElement();
    $xml->startElement('changefreq');
    $xml->text('daily');
    $xml->endElement();
    $xml->endElement();

    $xml->startElement('url');
    $xml->startElement('loc');
    $xml->text($baseUrl . '/llms-full.txt');
    $xml->endElement();
    $xml->startElement('changefreq');
    $xml->text('daily');
    $xml->endElement();
    $xml->endElement();

    // Urlset.
    $xml->endElement();
    $xml->endDocument();

    $output = $xml->outputMemory();

    $response = new CacheableResponse($output, 200, [
      'Content-Type' => 'application/xml; charset=utf-8',
      'X-Content-Type-Options' => 'nosniff',
    ]);

    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->addCacheTags(['llm_content:list', 'node_list', 'path_alias_list']);
    $cacheMetadata->addCacheContexts(['user.permissions', 'user.node_grants:view']);
    $response->addCacheableDependency($cacheMetadata);
    $response->addCacheableDependency($config);
    $this->varyOnQueryArgs($response);

    return $response;
  }

}
