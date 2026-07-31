# LLM Content

A Drupal module that generates markdown views of your site content for LLM crawlers and AI agents. Supports Drupal 10.4+ and 11.2+.

Keep your normal Drupal-rendered HTML for humans and classic SEO, while also serving parallel markdown versions for AI bots. LLM crawlers discover content via a dedicated sitemap and structured index files, getting a clean, low-noise representation that's easier to embed and summarize than raw HTML.

## Requirements

- Drupal 10.4+ or 11.2+
- PHP 8.3+
- `league/html-to-markdown` ^5.0

### Optional

- `drupal/xmlsitemap` ^2.0 -- Adds LLM content URLs to the site's main XML sitemap

## Installation

```bash
composer require league/html-to-markdown:^5.0
drush en llm_content
```

Place the module in `web/modules/custom/llm_content/` or install via Composer.

## Configuration

Navigate to **Administration > Configuration > Content > LLM Content Settings** (`/admin/config/content/llm-content`).

- **Enabled content types** -- Select which content types to expose as markdown
- **View mode** -- Choose which view mode to render before converting to markdown
- **Auto-generate** -- Automatically regenerate markdown when content is saved

## Endpoints

| Path | Description | Access |
|------|-------------|--------|
| `/node/{id}/llm-md` | Individual node as markdown with YAML frontmatter | Node view access |
| `/llms.txt` | Directory listing of all enabled content with links | `access content` |
| `/llms-full.txt` | Full markdown of all enabled content concatenated | `access content` |
| `/sitemap-llm.xml` | XML sitemap listing all markdown URLs (can be disabled) | `access content` |

All four answer `GET` and `HEAD` only. Other methods return 405: Drupal's
page cache declines to serve non-cacheable methods, so an unrestricted
`POST` would re-run the controller on every request with no rate limit.

None of them read query parameters, and a request carrying any is
answered with a 301 to the bare path. Without that, `?x=1` … `?x=N` each
occupy their own page-cache entry holding a full copy of the response —
on a 2,000-node site that is roughly 14 MB of cache per junk URL.

Content is rendered as the anonymous user regardless of who triggers the
generation, so the stored markdown can never contain more than an
anonymous visitor is entitled to see.

### Example: Individual Node Markdown

```
GET /node/1/llm-md
```

Returns:

```markdown
---
title: "My Article"
url: /my-article
type: article
date: "February 9, 2026"
updated: "2026-02-09T12:00:00+00:00"
---

# My Article

The full content of the article converted to clean markdown...
```

### Example: llms.txt

```
GET /llms.txt
```

Returns a directory-style listing following the [llms.txt specification](https://llmstxt.org/):

```markdown
# My Site

> Site slogan here

## Content

- [My Article](/node/1/llm-md): Brief description...
- [Another Page](/node/2/llm-md): Brief description...
```

## How It Works

1. When a node is saved, the module renders it using Drupal's render system with the configured view mode
2. Drupal-specific chrome (navigation, comments, admin toolbars) is stripped via DOM manipulation
3. The cleaned HTML is converted to markdown using `league/html-to-markdown`
4. YAML frontmatter (title, URL, type, dates) is prepended
5. The result is stored in a custom database table (`llm_content_markdown`) for fast retrieval
6. Endpoints serve the stored markdown with appropriate cache tags for automatic invalidation

## XML Sitemap Integration

The module optionally integrates with the [XML Sitemap](https://www.drupal.org/project/xmlsitemap) contrib module. When xmlsitemap is installed, LLM content URLs can be included in the site's main XML sitemap instead of (or in addition to) the built-in `/sitemap-llm.xml`.

### Setup

```bash
composer require drupal/xmlsitemap
drush en xmlsitemap
```

Then visit **LLM Content Settings** and expand the "XML Sitemap Integration" fieldset:

- **Enable XML Sitemap integration** -- Adds all LLM content URLs to the xmlsitemap link table
- **Priority for node markdown URLs** -- 0.0 to 1.0 (default: 0.5)
- **Change frequency** -- Hourly, daily, weekly, monthly, or yearly (default: weekly)
- **Priority for index endpoints** -- Priority for `/llms.txt` and `/llms-full.txt` (default: 0.7)

When you enable integration, all existing node markdown URLs and index endpoints are bulk-synced into the sitemap. After that, links are kept in sync automatically as nodes are created, updated, unpublished, or deleted.

### Manual sync

If you enable `xmlsitemap_integration: true` via imported config (rather than the settings form), run `drush llm:sitemap-sync` to backfill the xmlsitemap link table. The same sync also runs automatically from `hook_install()` and update hook `11002`, so most deploys should not need the manual command.

After deploying a version that adds new drush commands, run `drush cache:rebuild` so Drush discovers the new command signature.

### Disabling the Built-in Sitemap

If you prefer to use xmlsitemap exclusively, check "Disable built-in /sitemap-llm.xml" in the "Built-in Sitemap" fieldset. This returns a 403 for `/sitemap-llm.xml`. The setting takes effect after a cache rebuild.

### How It Works

- The module registers a custom `llm_content` link type with xmlsitemap via `hook_xmlsitemap_link_info`
- Two subtypes are registered: `node_markdown` (individual node URLs) and `index` (llms.txt endpoints)
- All interaction with xmlsitemap uses `\Drupal::service('xmlsitemap.link_storage')` behind runtime guards -- no hard dependency on xmlsitemap classes
- A `RouteSubscriber` dynamically disables the built-in sitemap route when configured

## Security

- Individual node endpoints respect Drupal's entity access system (`node.view`)
- Listing endpoints require `access content` permission
- Only published nodes of enabled content types are exposed
- Markdown is rendered as the anonymous user, so referenced entities the
  saving user could see but the public cannot are never stored
- YAML frontmatter values are sanitized against injection
- `llms.txt` titles and descriptions are collapsed to a single line each, so
  author-supplied text cannot pose as an additional entry
- URI schemes in markdown links use an allowlist (http, https, mailto, tel, relative paths)
- XML sitemap uses `XMLWriter` for safe generation
- Endpoints answer `GET`/`HEAD` only and redirect away query strings, so
  neither can be used to bypass the page cache
- All responses include `X-Content-Type-Options: nosniff`

### Upgrading

Update `11003` clears `llm_content_markdown` and requeues every published
node of an enabled type. Rows written before that update were rendered in
the request context of whoever saved the node, so they may contain
content the public cannot see. Until the queue drains — 100 items per
cron run — `/llms.txt` and `/llms-full.txt` are incomplete; run
`drush queue:run llm_content_markdown_generation` to finish immediately.

## Permissions

| Permission | Description |
|------------|-------------|
| `administer llm content` | Configure module settings (restricted) |

Public endpoints use standard Drupal access controls -- no additional permissions needed for viewing.

## Cache Invalidation

The module uses Drupal's cache tag system for automatic invalidation:

- `node:{id}` -- Individual node markdown is invalidated when that node changes
- `node_list` -- Listing endpoints are invalidated when any node is created/deleted
- `llm_content:list` -- Custom tag invalidated on saves to enabled content types

## Architecture

```
src/
  Controller/
    LlmMarkdownController.php        # /node/{id}/llm-md
    LlmsTxtController.php            # /llms.txt and /llms-full.txt
    LlmSitemapController.php         # /sitemap-llm.xml
  Service/
    MarkdownConverterInterface.php
    MarkdownConverter.php             # HTML-to-markdown conversion + DB storage
    XmlSitemapLinkManagerInterface.php
    XmlSitemapLinkManager.php         # Optional xmlsitemap link CRUD
  Hook/
    LlmContentHooks.php              # Entity lifecycle hooks (OOP with #[Hook] attributes)
    LlmContentXmlSitemapHooks.php    # hook_xmlsitemap_link_info
    LlmContentRequirementsHooks.php  # Runtime requirements checks
  Routing/
    RouteSubscriber.php               # Disables built-in sitemap when configured
  Form/
    LlmContentSettingsForm.php        # Admin configuration form
  PathProcessor/
    LlmMarkdownPathProcessor.php      # Clean URL support for .md extension
llm_content.module                    # LegacyHook shims for Drupal 10 compatibility
```

## License

GPL-2.0-or-later
