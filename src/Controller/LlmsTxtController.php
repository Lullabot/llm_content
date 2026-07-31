<?php

declare(strict_types=1);

namespace Drupal\llm_content\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\llm_content\Service\MarkdownConverterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for llms.txt and llms-full.txt endpoints.
 */
final class LlmsTxtController extends ControllerBase {

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
   * Generates the llms.txt file.
   */
  public function llmsTxt(): Response {
    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL && ($redirect = $this->redirectToCanonicalUrl($request)) !== NULL) {
      return $redirect;
    }

    $config = $this->config('llm_content.settings');
    $enabledTypes = $config->get('enabled_content_types') ?? [];
    $siteConfig = $this->config('system.site');
    $siteName = $siteConfig->get('name') ?? 'Site';
    $siteSlogan = $siteConfig->get('slogan') ?? '';

    $output = "# {$siteName}\n\n";
    if ($siteSlogan) {
      $output .= "> {$siteSlogan}\n\n";
    }

    if (!empty($enabledTypes)) {
      $nodeStorage = $this->entityTypeManager()->getStorage('node');
      $query = $nodeStorage->getQuery()
        ->condition('status', 1)
        ->condition('type', $enabledTypes, 'IN')
        ->accessCheck(TRUE)
        ->sort('created', 'DESC')
        ->range(0, 500);
      $nids = $query->execute();

      if (!empty($nids)) {
        $output .= "## Content\n\n";
        $langcode = $this->languageManager()->getCurrentLanguage()->getId();
        foreach (array_chunk($nids, 50) as $batch) {
          $nodes = $nodeStorage->loadMultiple($batch);
          // Pre-fetch all stored markdown for this batch in one query.
          $batchMarkdown = $this->markdownConverter->getStoredMarkdownBatch($batch, $langcode);
          foreach ($nodes as $node) {
            // Sanitize title for markdown link safety: strip HTML and escape
            // characters that could break markdown link syntax.
            $title = strip_tags($node->label() ?? 'Untitled');
            $title = str_replace(['[', ']'], ['(', ')'], $title);
            $url = Url::fromRoute('llm_content.markdown_view', ['node' => $node->id()])->toString();
            $description = '';
            if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
              $body = $node->get('body')->first();
              $description = $body->summary ?: mb_substr(strip_tags($body->value ?? ''), 0, 200);
            }
            else {
              // Fallback: use batch-fetched markdown instead of per-node query.
              $stored = $batchMarkdown[(int) $node->id()] ?? '';
              $stored = preg_replace('/^---\n.*?\n---\n+/s', '', $stored) ?? $stored;
              $stored = preg_replace('/^# .+\n+/', '', $stored) ?? $stored;
              $stored = strip_tags($stored);
              $stored = preg_replace('/^#{1,6}\s+/m', '', $stored) ?? $stored;
              $stored = preg_replace('/\*{1,2}([^*]+)\*{1,2}/', '$1', $stored) ?? $stored;
              $stored = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $stored) ?? $stored;
              $stored = preg_replace('/\s+/', ' ', $stored) ?? $stored;
              $description = mb_substr(trim($stored), 0, 200);
            }
            $output .= "- [{$title}]({$url})";
            if ($description) {
              $output .= ": {$description}";
            }
            $output .= "\n";
          }
          $nodeStorage->resetCache($batch);
        }
      }
    }

    $response = new CacheableResponse($output, 200, [
      'Content-Type' => 'text/plain; charset=utf-8',
      'X-Content-Type-Options' => 'nosniff',
    ]);

    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->addCacheTags(['llm_content:list', 'node_list', 'path_alias_list']);
    $cacheMetadata->addCacheContexts(['user.permissions', 'user.node_grants:view']);
    $response->addCacheableDependency($cacheMetadata);
    $response->addCacheableDependency($config);
    $response->addCacheableDependency($siteConfig);
    $this->varyOnQueryArgs($response);

    return $response;
  }

  /**
   * Generates the llms-full.txt content dynamically.
   */
  public function llmsFullTxt(): Response {
    $request = $this->requestStack->getCurrentRequest();
    if ($request !== NULL && ($redirect = $this->redirectToCanonicalUrl($request)) !== NULL) {
      return $redirect;
    }

    $config = $this->config('llm_content.settings');
    $content = $this->markdownConverter->generateFullText();

    $response = new CacheableResponse($content, 200, [
      'Content-Type' => 'text/plain; charset=utf-8',
      'X-Content-Type-Options' => 'nosniff',
    ]);

    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->addCacheTags(['llm_content:list', 'node_list']);
    $cacheMetadata->addCacheContexts(['user.permissions', 'user.node_grants:view']);
    $response->addCacheableDependency($cacheMetadata);
    $response->addCacheableDependency($config);
    $this->varyOnQueryArgs($response);

    return $response;
  }

}
