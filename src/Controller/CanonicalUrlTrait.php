<?php

declare(strict_types=1);

namespace Drupal\llm_content\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Cache\CacheableResponseInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Collapses query-string variants of a URL onto the canonical path.
 *
 * None of this module's endpoints read query parameters, but Drupal's
 * page cache keys on the full URL. Without this, `?x=1` … `?x=N` each
 * store a separate copy of the response body — 13 MB apiece for
 * /llms-full.txt, which measurably exhausted Redis (209 MB → 559 MB in
 * 25 anonymous requests). Redirecting instead caches a few hundred bytes
 * per junk URL and leaves exactly one copy of the real body.
 */
trait CanonicalUrlTrait {

  /**
   * Builds a redirect to the query-string-free form of the request URL.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\Response|null
   *   A cacheable 301 to the canonical path, or NULL when the request
   *   already has no query string and should be served normally.
   */
  protected function redirectToCanonicalUrl(Request $request): ?Response {
    if ($request->query->count() === 0) {
      return NULL;
    }

    $target = $request->getBaseUrl() . $request->getPathInfo();
    // Strip anything that could break out of the Location header. The
    // path already matched one of this module's routes, so this only
    // guards against surprises in path processing.
    $target = preg_replace('/[\x00-\x1f\x7f]/', '', $target) ?? '/';

    // Deliberately NOT a RedirectResponse. Core's
    // RedirectResponseSubscriber lets a `?destination=` query parameter
    // overwrite the target of any RedirectResponse it sees — including
    // the SecuredRedirectResponse subclasses — so returning one here
    // would turn every endpoint into `/llms.txt?destination=/anywhere`,
    // a permanent, publicly cached redirect to an arbitrary internal
    // path. A plain response carrying a Location header is not matched
    // by that subscriber and redirects exactly where we say.
    $response = new CacheableResponse('', Response::HTTP_MOVED_PERMANENTLY, [
      'Location' => $target,
    ]);
    $this->varyOnQueryArgs($response);

    return $response;
  }

  /**
   * Declares that a response depends on the request's query arguments.
   *
   * The 200 responses need this as much as the redirect does. Without
   * it, dynamic_page_cache would answer `?x=1` from the cached
   * query-string-free response — the controller would never run, the
   * redirect would never be emitted, and the page cache would go on
   * storing a full copy of the body under every junk URL.
   *
   * @param \Drupal\Core\Cache\CacheableResponseInterface $response
   *   The response to annotate.
   */
  protected function varyOnQueryArgs(CacheableResponseInterface $response): void {
    $metadata = new CacheableMetadata();
    $metadata->addCacheContexts(['url.query_args']);
    $response->addCacheableDependency($metadata);
  }

}
