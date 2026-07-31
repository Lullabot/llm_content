<?php

declare(strict_types=1);

namespace Drupal\llm_content\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\llm_content\Service\MarkdownConverterInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for serving node markdown views.
 */
final class LlmMarkdownController extends ControllerBase {

  use CanonicalUrlTrait;

  public function __construct(
    protected MarkdownConverterInterface $markdownConverter,
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(MarkdownConverterInterface::class),
      $container->get('request_stack'),
    );
  }

  /**
   * Serves a node as markdown.
   */
  public function view(NodeInterface $node): Response {
    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL && ($redirect = $this->redirectToCanonicalUrl($request)) !== NULL) {
      return $redirect;
    }

    $config = $this->config('llm_content.settings');
    $enabledTypes = $config->get('enabled_content_types') ?? [];

    // Return a cacheable 404 for disabled types or unpublished nodes so
    // repeated requests are served from cache instead of hitting PHP.
    if (!in_array($node->bundle(), $enabledTypes, TRUE) || !$node->isPublished()) {
      $response = new CacheableResponse('', Response::HTTP_NOT_FOUND);
      $cacheMetadata = new CacheableMetadata();
      $cacheMetadata->addCacheTags(['node:' . $node->id()]);
      $response->addCacheableDependency($cacheMetadata);
      $response->addCacheableDependency($node);
      $response->addCacheableDependency($config);
      $this->varyOnQueryArgs($response);
      return $response;
    }

    $markdown = $this->markdownConverter->getMarkdown($node);

    $response = new CacheableResponse($markdown, 200, [
      'Content-Type' => 'text/markdown; charset=utf-8',
      'X-Content-Type-Options' => 'nosniff',
    ]);

    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->addCacheTags(['node:' . $node->id()]);
    $cacheMetadata->addCacheContexts(['url.path']);
    $response->addCacheableDependency($cacheMetadata);
    $response->addCacheableDependency($node);
    $response->addCacheableDependency($config);
    $this->varyOnQueryArgs($response);

    return $response;
  }

}
