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

  /**
   * Maximum length, in characters, of a single entry's text.
   */
  protected const ENTRY_MAX_LENGTH = 200;

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
            $title = $this->sanitizeEntryText($node->label() ?? 'Untitled');
            // Neutralize markdown link syntax in the title so it cannot
            // close the link and open another one.
            $title = str_replace(['[', ']'], ['(', ')'], $title);
            $url = Url::fromRoute('llm_content.markdown_view', ['node' => $node->id()])->toString();
            $description = '';
            $body = $node->hasField('body') ? $node->get('body')->first() : NULL;
            if ($body !== NULL) {
              // Prefer the summary, but fall back to the body when it is
              // absent — or when sanitizing leaves nothing behind, which
              // a whitespace-only summary previously did not do.
              $description = $this->sanitizeEntryText((string) $body->summary);
              if ($description === '') {
                $description = $this->sanitizeEntryText((string) $body->value);
              }
            }
            else {
              // Fallback: use batch-fetched markdown instead of per-node query.
              $stored = $batchMarkdown[(int) $node->id()] ?? '';
              $stored = preg_replace('/^---\n.*?\n---\n+/s', '', $stored) ?? $stored;
              $stored = preg_replace('/^# .+\n+/', '', $stored) ?? $stored;
              $stored = preg_replace('/^#{1,6}\s+/m', '', $stored) ?? $stored;
              $stored = preg_replace('/\*{1,2}([^*]+)\*{1,2}/', '$1', $stored) ?? $stored;
              $stored = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $stored) ?? $stored;
              $description = $this->sanitizeEntryText($stored);
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
   * Reduces author-controlled text to a single safe llms.txt line fragment.
   *
   * Every entry in llms.txt is one line — `- [title](url): description` —
   * so any newline that survives into either field ends the entry early
   * and lets the remainder pose as a new top-level entry. A body summary
   * of "ok\n- [Totally Legit Doc](https://evil.example/x)" would appear
   * in the file as its own link, indistinguishable from real site
   * content, in a file LLM crawlers treat as site-endorsed. Anyone with
   * edit access to any enabled bundle can write one.
   *
   * Collapsing whitespace runs to single spaces closes that, and the
   * length cap keeps one node from crowding out the rest of the index.
   *
   * @param string $text
   *   The raw author-supplied text.
   *
   * @return string
   *   Text safe to interpolate into a single llms.txt line.
   */
  protected function sanitizeEntryText(string $text): string {
    $text = strip_tags($text);

    // Collapse newlines, tabs, and runs of spaces into single spaces.
    // The /u pattern returns NULL on invalid UTF-8, so fall back to the
    // ASCII-only form, which cannot fail — never to the raw input.
    $collapsed = preg_replace('/\s+/u', ' ', $text);
    if ($collapsed === NULL) {
      $collapsed = preg_replace('/\s+/', ' ', $text) ?? '';
    }

    // Drop any control characters \s does not cover (NUL, DEL, and the
    // rest of the C0 range).
    $stripped = preg_replace('/[\x00-\x1f\x7f]/', '', $collapsed);
    if ($stripped === NULL) {
      $stripped = $collapsed;
    }

    return mb_substr(trim($stripped), 0, self::ENTRY_MAX_LENGTH);
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
