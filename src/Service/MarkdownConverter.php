<?php

declare(strict_types=1);

namespace Drupal\llm_content\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use League\HTMLToMarkdown\HtmlConverter;
use Psr\Log\LoggerInterface;

/**
 * Converts Drupal nodes to markdown and manages storage.
 */
final class MarkdownConverter implements MarkdownConverterInterface {

  /**
   * The HTML to Markdown converter.
   */
  protected HtmlConverter $htmlConverter;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface $renderer,
    protected ConfigFactoryInterface $configFactory,
    protected AliasManagerInterface $aliasManager,
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    protected AccountSwitcherInterface $accountSwitcher,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('llm_content');
    $this->htmlConverter = new HtmlConverter([
      'strip_tags' => TRUE,
      'remove_nodes' => 'script style iframe nav header footer aside',
      'header_style' => 'atx',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function convert(NodeInterface $node): string {
    $config = $this->configFactory->get('llm_content.settings');
    $viewMode = $config->get('view_mode') ?? 'full';

    // Render the node to HTML as the anonymous user.
    //
    // The result is stored keyed only on (nid, langcode) — there is no
    // privilege dimension — and is then served to anonymous visitors via
    // /llms-full.txt. Rendering in the *caller's* context therefore
    // freezes whatever that user could see into public output: core's
    // entity-reference formatter access-checks against the current user,
    // so an editor saving an article with an unpublished bio reference
    // would bake that bio's title and URL into the public corpus. Switch
    // to anonymous so the stored blob can never exceed what an anonymous
    // visitor is entitled to.
    $this->accountSwitcher->switchTo(new AnonymousUserSession());
    try {
      $viewBuilder = $this->entityTypeManager->getViewBuilder('node');
      $build = $viewBuilder->view($node, $viewMode);
      $html = (string) $this->renderer->renderInIsolation($build);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to render node @nid for markdown conversion: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
        'exception' => $e,
      ]);
      return '';
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    // Strip comment sections and Drupal chrome using DOM for reliability.
    $html = $this->stripDrupalChrome($html);

    // Convert HTML to markdown.
    $markdown = $this->htmlConverter->convert($html);

    // Clean up: only allow safe URI schemes in links (allowlist approach).
    $markdown = preg_replace_callback(
      '/\[([^\]]*)\]\(([^)]+)\)/i',
      static function (array $matches): string {
        $text = $matches[1];
        $url = $matches[2];
        // Allow relative URLs, http(s), mailto, and tel schemes.
        if (preg_match('#^(https?://|mailto:|tel:|/|\#)#i', $url)) {
          return "[{$text}]({$url})";
        }
        return "[{$text}](#)";
      },
      $markdown
    ) ?? $markdown;

    // Collapse whitespace-only lines and excessive newlines from nested
    // paragraph divs (e.g. lines with just spaces or non-breaking spaces).
    $markdown = preg_replace("/\n[ \t]*\n[ \t]*\n/", "\n\n", $markdown) ?? $markdown;
    $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

    // Build frontmatter.
    $alias = $this->aliasManager->getAliasByPath('/node/' . $node->id());
    $title = strip_tags($node->label() ?? '');
    // Sanitize title for YAML safety: escape quotes, strip control characters.
    $title = preg_replace('/[\x00-\x1f\x7f]/', '', $title) ?? $title;
    $title = str_replace(['\\', '"'], ['\\\\', '\\"'], $title);
    // Sanitize alias for YAML safety: strip control chars and quote.
    $alias = preg_replace('/[\x00-\x1f\x7f]/', '', $alias) ?? $alias;
    $alias = str_replace(['\\', '"'], ['\\\\', '\\"'], $alias);
    $frontmatter = "---\n";
    $frontmatter .= 'title: "' . $title . "\"\n";
    $frontmatter .= 'url: "' . $alias . "\"\n";
    $frontmatter .= 'type: ' . $node->bundle() . "\n";
    $frontmatter .= 'date: ' . $this->dateFormatter->format($node->getCreatedTime(), 'custom', 'Y-m-d') . "\n";
    if ($node->getRevisionCreationTime()) {
      $frontmatter .= 'updated: ' . $this->dateFormatter->format($node->getRevisionCreationTime(), 'custom', 'Y-m-d') . "\n";
    }
    $frontmatter .= "---\n\n";

    // Sanitize the title for the H1 heading: strip HTML, control chars,
    // and brackets that could be used to inject markdown links (e.g. a
    // node title of `[text](javascript:alert(1))` would otherwise land
    // verbatim in the heading and execute when rendered back to HTML).
    $headingTitle = strip_tags($node->label() ?? '');
    $headingTitle = preg_replace('/[\x00-\x1f\x7f]/', '', $headingTitle) ?? $headingTitle;
    $headingTitle = str_replace(['[', ']'], ['(', ')'], $headingTitle);
    $fullMarkdown = $frontmatter . '# ' . $headingTitle . "\n\n" . trim($markdown);

    // Store in database.
    $this->database->merge('llm_content_markdown')
      ->keys([
        'nid' => $node->id(),
        'langcode' => $node->language()->getId(),
      ])
      ->fields([
        'markdown' => $fullMarkdown,
        'generated' => $this->time->getRequestTime(),
      ])
      ->execute();

    return $fullMarkdown;
  }

  /**
   * Strips comment sections, nav, and other Drupal chrome from HTML.
   */
  protected function stripDrupalChrome(string $html): string {
    $doc = new \DOMDocument();
    @$doc->loadHTML('<html><body>' . $html . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new \DOMXPath($doc);

    // Remove elements by ID (comments wrapper).
    // Use iterator_to_array() on all XPath loops that mutate the DOM,
    // because DOMNodeList is live and mutations during iteration skip nodes.
    foreach (iterator_to_array($xpath->query('//*[@id="comments"]')) as $node) {
      $node->parentNode->removeChild($node);
    }

    // Remove elements with data-drupal-selector="comments".
    foreach (iterator_to_array($xpath->query('//*[@data-drupal-selector="comments"]')) as $node) {
      $node->parentNode->removeChild($node);
    }

    // Remove "links inline" lists (contains "Log in to post comments").
    foreach (iterator_to_array($xpath->query('//ul[contains(@class, "links") and contains(@class, "inline")]')) as $node) {
      $node->parentNode->removeChild($node);
    }

    // Remove nav elements.
    foreach (iterator_to_array($xpath->query('//nav')) as $node) {
      $node->parentNode->removeChild($node);
    }

    // Convert <details>/<summary> (accordion paragraphs) to heading + content.
    foreach (iterator_to_array($xpath->query('//details')) as $details) {
      $summary = $xpath->query('summary', $details)->item(0);
      $parent = $details->parentNode;

      if ($summary) {
        $summaryText = trim($summary->textContent);
        if ($summaryText !== '') {
          $heading = $doc->createElement('h3');
          // Clone child nodes to preserve any inline HTML in the summary.
          foreach (iterator_to_array($summary->childNodes) as $child) {
            $heading->appendChild($child->cloneNode(TRUE));
          }
          $parent->insertBefore($heading, $details);
        }
        $details->removeChild($summary);
      }

      // Move remaining child nodes to preserve inner HTML structure.
      while ($details->firstChild) {
        $parent->insertBefore($details->firstChild, $details);
      }
      $parent->removeChild($details);
    }

    // Convert <figure>/<figcaption> to img + italic caption.
    foreach (iterator_to_array($xpath->query('//figure')) as $figure) {
      $img = $xpath->query('.//img', $figure)->item(0);
      $caption = $xpath->query('figcaption', $figure)->item(0);
      if ($img) {
        $figure->parentNode->insertBefore($img->cloneNode(TRUE), $figure);
      }
      if ($caption && trim($caption->textContent) !== '') {
        $p = $doc->createElement('p');
        $em = $doc->createElement('em');
        // Move child nodes to preserve any inline HTML in the caption.
        while ($caption->firstChild) {
          $em->appendChild($caption->firstChild);
        }
        $p->appendChild($em);
        $figure->parentNode->insertBefore($p, $figure);
      }
      $figure->parentNode->removeChild($figure);
    }

    // Replace <iframe> elements with link placeholders before HtmlConverter
    // strips them (iframe is in remove_nodes config).
    foreach (iterator_to_array($xpath->query('//iframe[@src]')) as $iframe) {
      $src = $iframe->getAttribute('src');
      if (preg_match('#^https?://#i', $src)) {
        $p = $doc->createElement('p');
        $a = $doc->createElement('a', 'Embedded Video');
        $a->setAttribute('href', $src);
        $p->appendChild($a);
        $iframe->parentNode->insertBefore($p, $iframe);
      }
      $iframe->parentNode->removeChild($iframe);
    }

    // Convert Paragraphs accordion title fields to <h3> headings.
    // Paragraphs renders accordion titles as:
    // <div class="field--name-field-accordion-title ...">Title text</div>.
    foreach (iterator_to_array($xpath->query('//*[contains(@class, "field--name-field-accordion-title")]')) as $titleDiv) {
      $heading = $doc->createElement('h3');
      $heading->textContent = trim($titleDiv->textContent);
      $titleDiv->parentNode->replaceChild($heading, $titleDiv);
    }

    // Convert Paragraphs media embed URL fields to clickable links.
    // Paragraphs renders embed URLs as plain text in:
    // Example: <div class="field--name-field-embed-url">https://...</div>
    $query = '//*[contains(@class, "field--name-field-embed-url")]';
    foreach (iterator_to_array($xpath->query($query)) as $urlDiv) {
      $url = trim($urlDiv->textContent);
      if (preg_match('#^https?://#i', $url)) {
        $p = $doc->createElement('p');
        $a = $doc->createElement('a', 'Embedded Video');
        $a->setAttribute('href', $url);
        $p->appendChild($a);
        $urlDiv->parentNode->replaceChild($p, $urlDiv);
      }
    }

    $body = $doc->getElementsByTagName('body')->item(0);
    if ($body === NULL) {
      return $html;
    }

    $result = '';
    foreach ($body->childNodes as $child) {
      $result .= $doc->saveHTML($child);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getStoredMarkdown(NodeInterface $node): ?string {
    $result = $this->database->select('llm_content_markdown', 'm')
      ->fields('m', ['markdown'])
      ->condition('nid', $node->id())
      ->condition('langcode', $node->language()->getId())
      ->execute()
      ->fetchField();

    return $result !== FALSE ? $result : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getMarkdown(NodeInterface $node): string {
    $result = $this->getStoredMarkdown($node);

    if ($result !== NULL) {
      return $result;
    }

    // Generate on demand if not stored.
    return $this->convert($node);
  }

  /**
   * {@inheritdoc}
   */
  public function getStoredMarkdownBatch(array $nids, string $langcode): array {
    if (empty($nids)) {
      return [];
    }
    return $this->database->select('llm_content_markdown', 'm')
      ->fields('m', ['nid', 'markdown'])
      ->condition('nid', $nids, 'IN')
      ->condition('langcode', $langcode)
      ->execute()
      ->fetchAllKeyed();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMarkdown(int $nid, ?string $langcode = NULL): void {
    $query = $this->database->delete('llm_content_markdown')
      ->condition('nid', $nid);
    if ($langcode !== NULL) {
      $query->condition('langcode', $langcode);
    }
    $query->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function generateFullText(): string {
    $config = $this->configFactory->get('llm_content.settings');
    $enabledTypes = $config->get('enabled_content_types') ?? [];
    $siteConfig = $this->configFactory->get('system.site');
    $siteName = $siteConfig->get('name') ?? 'Site';
    $siteSlogan = $siteConfig->get('slogan') ?? '';

    $output = "# {$siteName}\n\n";
    if ($siteSlogan) {
      $output .= "> {$siteSlogan}\n\n";
    }

    if (empty($enabledTypes)) {
      return $output;
    }

    // Use entity query with access check to enforce node access restrictions.
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nids = $nodeStorage->getQuery()
      ->condition('status', 1)
      ->condition('type', $enabledTypes, 'IN')
      ->accessCheck(TRUE)
      ->sort('nid', 'ASC')
      ->execute();

    if (!empty($nids)) {
      // Fetch stored markdown for the access-checked nids. Join on
      // node_field_data with default_langcode = 1 so multilingual nodes
      // only contribute their canonical translation — without this we
      // would emit one copy per language stored in llm_content_markdown.
      $query = $this->database->select('llm_content_markdown', 'm');
      $query->innerJoin('node_field_data', 'n', 'n.nid = m.nid AND n.langcode = m.langcode');
      $query->fields('m', ['markdown']);
      $query->condition('m.nid', $nids, 'IN');
      $query->condition('n.default_langcode', 1);
      $query->orderBy('m.nid', 'ASC');
      $results = $query->execute()->fetchCol();

      foreach ($results as $markdown) {
        $output .= $markdown . "\n\n---\n\n";
      }
    }

    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function getNidsMissingMarkdown(array $types, int $limit = 0): array {
    if (empty($types)) {
      return [];
    }

    $query = $this->database->select('node_field_data', 'n');
    $query->leftJoin('llm_content_markdown', 'm', 'n.nid = m.nid AND n.langcode = m.langcode');
    $query->addField('n', 'nid');
    $query->condition('n.status', 1);
    $query->condition('n.type', $types, 'IN');
    $query->condition('n.default_langcode', 1);
    $query->isNull('m.nid');
    if ($limit > 0) {
      $query->range(0, $limit);
    }
    return $query->execute()->fetchCol();
  }

}
