---
title: "feat: Add Drupal 10 + 11 dual-version support"
type: feat
date: 2026-03-10
---

## Enhancement Summary

**Deepened on:** 2026-03-10
**Research agents used:** Drupal Expert, Architecture Strategist, Security Sentinel, Pattern Recognition Specialist, Code Simplicity Reviewer, Performance Oracle, CI/CD Best Practices Researcher, Deployment Verification Agent

### Key Improvements from Research
1. Corrected compatibility table — `#[Hook]` and `#[LegacyHook]` classes **exist** on D10.3, they're just not used for discovery
2. Simplified service registration — drop explicit args for `LlmContentHooks`, rely on autowire
3. Fixed requirements hook duplication — `.install` handles install-phase only, OOP+LegacyHook handles runtime
4. Added `hook_runtime_requirements` version gap analysis (D11.0-11.1 vs D11.2+)
5. Production-quality CI workflow with lint/test separation and experimental matrix
6. Test bootstrap fix — `REQUIREMENT_*` constants must be defined
7. Complete deployment checklist with rollback procedures

### Strategic Decision Required
The **Code Simplicity Reviewer** argues PR #26's procedural-only approach is sufficient for a ~9-month D10 support window (D10 EOL with D12 release). The LegacyHook approach is architecturally "correct" but adds complexity for a temporary need. See [Approach Trade-Off](#approach-trade-off-legacyhook-vs-procedural-only) section.

---

# feat: Add Drupal 10 + 11 dual-version support (single branch)

## Overview

Rework the llm_content module to support both Drupal 10.3+ and Drupal 11 from a single branch, using the `#[LegacyHook]` shim pattern. This replaces the approach in PR #26, which deleted OOP hook classes entirely. The LegacyHook pattern preserves D11's modern architecture while adding D10 backward compatibility through thin procedural wrappers.

## Problem Statement / Motivation

The module currently targets `^11.1` only, using D11-exclusive features (`#[Hook]` attributes, `InstallRequirementsInterface`, `RequirementSeverity` enum). This blocks deployment on sites still running Drupal 10 (e.g., mjf.org). PR #26 attempted to fix this by removing all D11 patterns and converting to procedural-only hooks, but this loses the modern architecture and will require re-conversion when D10 is eventually dropped.

## Approach Trade-Off: LegacyHook vs Procedural-Only

> **Research Insight (Code Simplicity Reviewer):** With D10 EOL expected late 2026 (~9 months away), the LegacyHook pattern's 7-step sunset plan signals overengineering. PR #26's procedural-only approach has a smaller blast radius and the cost of re-adding OOP hooks later is trivial for 7 hooks.

| Aspect | LegacyHook (this plan) | PR #26 (procedural-only) |
|--------|----------------------|--------------------------|
| Hook invocation paths | 2 (OOP + procedural) | 1 (procedural only) |
| Risk of double invocation | Yes (if `#[LegacyHook]` missed) | None |
| Sunset cleanup | 7 steps | ~3 steps |
| Cognitive load | Higher — must understand which path runs per version | Lower — one path always |
| D11 code quality | Preserved — modern OOP hooks remain | Degraded — D11 runs procedural hooks |
| Community alignment | Official Drupal core pattern | Common but not "modern" |

**Recommendation:** If speed and simplicity are the priority (9-month window), PR #26 is defensible. If you want to follow the Drupal-sanctioned pattern and keep the codebase "D11-native," proceed with this LegacyHook plan. Both are valid — it's a judgment call.

## Proposed Solution

Use the **LegacyHook dual-mode pattern** — the same approach used by Drupal core's own backward compatibility layer:

- **On D11:** Core discovers `#[Hook]` attributes on OOP classes and invokes them directly. `#[LegacyHook]` on procedural functions tells core to skip them. No double invocation.
- **On D10.3+:** `#[Hook]` and `#[LegacyHook]` attribute classes exist in core but are not used for hook discovery. The procedural functions run normally and delegate to services.

## Technical Approach

### Key Compatibility Facts

| Feature | D10.3+ Behavior | D11 Behavior |
|---------|----------------|-------------|
| `#[Hook]` attribute | Class exists but not used for hook discovery | Discovered by hook system |
| `#[LegacyHook]` attribute | Class exists but not used for hook filtering | Tells core to skip procedural function |
| `use Drupal\Core\Hook\Attribute\Hook;` | Safe — `use` is a namespace alias, resolved only when referenced in executable code | Resolves normally |
| `implements InstallRequirementsInterface` | **FATAL** — resolved at class load time, interface doesn't exist | Works |
| `RequirementSeverity::Error` | **FATAL** — enum doesn't exist | Works |
| `renderInIsolation()` | Available since 10.3.0 (confirmed) | Available |
| `autowire` / `autoconfigure` | Available since 10.2+ | Available |

> **Research Insight (Drupal Expert):** The original plan incorrectly stated `#[Hook]` and `#[LegacyHook]` classes "don't exist" on D10. They **do exist** on D10.3+ — they're just inert. D10's hook system doesn't scan for them. This is actually better than the plan assumed, since PHP never needs to handle "missing attribute class" scenarios on D10.3.

### Phase 1: Core Compatibility Changes

#### 1.1 Create `llm_content.module` with LegacyHook shims

Create a new `llm_content.module` file with procedural wrappers for all hooks. Each function:
- Has a Drupal coding standards `@file` docblock and `/** Implements hook_X(). */` docblocks
- Uses `#[LegacyHook]` attribute (inert on D10.3, tells D11 to skip)
- Delegates to the corresponding service via `\Drupal::service()`

```php
<?php

/**
 * @file
 * Hook implementations for the LLM Content module.
 *
 * These procedural functions delegate to OOP service classes. On Drupal 11,
 * the #[LegacyHook] attribute tells core to skip these and use the #[Hook]
 * attributes on the service classes directly. On Drupal 10, these functions
 * are the primary hook entry points.
 */

declare(strict_types=1);

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\LegacyHook;
use Drupal\llm_content\Hook\LlmContentHooks;
use Drupal\llm_content\Hook\LlmContentRequirementsHooks;
use Drupal\llm_content\Hook\LlmContentXmlSitemapHooks;

/**
 * Implements hook_entity_insert().
 */
#[LegacyHook]
function llm_content_entity_insert(EntityInterface $entity): void {
  \Drupal::service(LlmContentHooks::class)->entityInsert($entity);
}

/**
 * Implements hook_entity_update().
 */
#[LegacyHook]
function llm_content_entity_update(EntityInterface $entity): void {
  \Drupal::service(LlmContentHooks::class)->entityUpdate($entity);
}

/**
 * Implements hook_entity_delete().
 */
#[LegacyHook]
function llm_content_entity_delete(EntityInterface $entity): void {
  \Drupal::service(LlmContentHooks::class)->entityDelete($entity);
}

/**
 * Implements hook_page_attachments().
 *
 * Note: The &$page parameter is passed by reference through the service call.
 * Changes made in LlmContentHooks::pageAttachments() propagate back correctly.
 */
#[LegacyHook]
function llm_content_page_attachments(array &$page): void {
  \Drupal::service(LlmContentHooks::class)->pageAttachments($page);
}

/**
 * Implements hook_cron().
 */
#[LegacyHook]
function llm_content_cron(): void {
  \Drupal::service(LlmContentHooks::class)->cron();
}

/**
 * Implements hook_xmlsitemap_link_info().
 */
#[LegacyHook]
function llm_content_xmlsitemap_link_info(): array {
  return \Drupal::service(LlmContentXmlSitemapHooks::class)->xmlsitemapLinkInfo();
}

/**
 * Implements hook_runtime_requirements().
 */
#[LegacyHook]
function llm_content_runtime_requirements(): array {
  return \Drupal::service(LlmContentRequirementsHooks::class)->runtimeRequirements();
}
```

> **Research Insight (Pattern Recognition):** The `&$page` pass-by-reference in `hook_page_attachments` is a subtle correctness requirement — document it with a comment to prevent future maintainers from accidentally removing the `&`.

> **Research Insight (Pattern Recognition):** Add `@file` docblock and `/** Implements hook_X(). */` docblocks on every function — the existing codebase enforces Drupal coding standards via PHPCS, and missing docblocks will fail CI.

**Important:** The `#[Hook]` attributes on `LlmContentHooks`, `LlmContentRequirementsHooks`, and `LlmContentXmlSitemapHooks` remain intact. They are only metadata and cause no issues on D10.3+.

#### 1.2 Handle requirements hooks (D11-only classes)

**Problem:** `InstallRequirementsInterface` and `RequirementSeverity` enum do not exist in D10 and will cause fatal errors at class load time (unlike attributes, `implements` is resolved eagerly).

**Solution:** Delete the D11-only `InstallRequirementsInterface` class. Keep the OOP runtime requirements hook but replace the enum. Add a procedural `hook_requirements()` in `.install` for the **install phase only**.

> **Research Insight (Drupal Expert):** `hook_runtime_requirements()` was introduced in D11.2.0, not D11.0. On D11.0-11.1, only `hook_requirements($phase = 'runtime')` fires for runtime checks. However, since requirements arrays are keyed by `llm_content_html_to_markdown`, if both the `.install` procedural function and the OOP hook fire on D11.2+, the second result simply overwrites the first with identical data — no functional harm.

> **Research Insight (Architecture Strategist):** For maximum compatibility across D10, D11.0-11.1, and D11.2+, keep the procedural `hook_requirements()` in `.install` covering **install phase only**. The OOP `#[Hook('runtime_requirements')]` + LegacyHook shim handles runtime on all versions.

Changes:
- **Delete** `src/Install/Requirements/LlmContentRequirements.php` (uses `implements InstallRequirementsInterface`)
- **Modify** `src/Hook/LlmContentRequirementsHooks.php` — replace `RequirementSeverity::Error` / `RequirementSeverity::OK` with `REQUIREMENT_ERROR` / `REQUIREMENT_OK` constants (defined in `install.inc`, available on both D10 and D11)
- **Remove** the `use Drupal\Core\Extension\Requirement\RequirementSeverity;` import (will trigger phpcs `UnusedUseStatement` warning if left)
- **Add** procedural `llm_content_requirements($phase)` to `llm_content.install` for the **install phase only**
- **Add** a `#[LegacyHook]` shim for `runtime_requirements` in `llm_content.module` (shown in 1.1 above)

Updated `LlmContentRequirementsHooks.php`:
```php
final class LlmContentRequirementsHooks {
  use StringTranslationTrait;

  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $requirements = [];
    if (!class_exists('League\HTMLToMarkdown\HtmlConverter')) {
      $requirements['llm_content_html_to_markdown'] = [
        'title' => $this->t('LLM Content - HTML to Markdown library'),
        'value' => $this->t('Not installed'),
        'description' => $this->t('The league/html-to-markdown library is required. Run <code>composer require league/html-to-markdown:^5.0</code> in your project root.'),
        'severity' => REQUIREMENT_ERROR,
      ];
    }
    else {
      $requirements['llm_content_html_to_markdown'] = [
        'title' => $this->t('LLM Content - HTML to Markdown library'),
        'value' => $this->t('Installed'),
        'severity' => REQUIREMENT_OK,
      ];
    }
    return $requirements;
  }
}
```

Procedural fallback in `llm_content.install` (install phase only):
```php
/**
 * Implements hook_requirements().
 */
function llm_content_requirements(string $phase): array {
  $requirements = [];
  if ($phase === 'install') {
    if (!class_exists('League\HTMLToMarkdown\HtmlConverter')) {
      $requirements['llm_content_html_to_markdown'] = [
        'title' => t('LLM Content - HTML to Markdown library'),
        'description' => t('The league/html-to-markdown library is required. Run <code>composer require league/html-to-markdown:^5.0</code> in your project root.'),
        'severity' => REQUIREMENT_ERROR,
      ];
    }
  }
  return $requirements;
}
```

> **Research Insight (Security Sentinel):** Having two separate implementations of the same requirements check is a maintenance risk — if one is updated and the other isn't, a security check could be accidentally weakened. The install-phase-only approach in `.install` minimizes this duplication to just the install gate check.

#### 1.3 Register hook classes as services

Add explicit service definitions for all hook classes. On D11 these are auto-discovered, but D10 needs them registered to be fetchable via `\Drupal::service()`.

> **Research Insight (Architecture Strategist):** Remove the explicit `arguments` from `LlmContentHooks` and rely on autowire. With `_defaults: { autowire: true }`, the container resolves all 5 constructor parameters by type. Explicit args create a maintenance trap — if the constructor signature changes, `services.yml` must also be updated. The nullable `@?xmlsitemap.link_storage` on `XmlSitemapLinkManager` is the only case where explicit args are structurally necessary (autowire can't resolve optional contrib services).

Updated `llm_content.services.yml`:
```yaml
services:
  _defaults:
    autoconfigure: true
    autowire: true

  Drupal\llm_content\Service\MarkdownConverterInterface:
    class: Drupal\llm_content\Service\MarkdownConverter

  Drupal\llm_content\Service\XmlSitemapLinkManagerInterface:
    class: Drupal\llm_content\Service\XmlSitemapLinkManager
    arguments:
      - '@module_handler'
      - '@config.factory'
      - '@database'
      - '@?xmlsitemap.link_storage'
      - '@datetime.time'

  Drupal\llm_content\Hook\LlmContentHooks: {}

  Drupal\llm_content\Hook\LlmContentRequirementsHooks: {}

  Drupal\llm_content\Hook\LlmContentXmlSitemapHooks: {}

  llm_content.route_subscriber:
    class: Drupal\llm_content\Routing\RouteSubscriber
    tags:
      - { name: event_subscriber }

  llm_content.path_processor:
    class: Drupal\llm_content\PathProcessor\LlmMarkdownPathProcessor
    arguments: ['@path_alias.manager']
    tags:
      - { name: path_processor_inbound, priority: 50 }
      - { name: path_processor_outbound, priority: 200 }
```

#### 1.4 Update `core_version_requirement`

In `llm_content.info.yml`:
```yaml
core_version_requirement: ^10.4 || ^11
```

> **Research Insight (Code Simplicity Reviewer):** Use `^10.4` not `^10.3`. D10.3 is EOL since December 2024. D10.4 is the current LTS. The practical audience of D10.3 sites running PHP 8.3 in 2026 is vanishingly small, and using 10.4 reduces testing surface.

Why `^10.4`:
- `renderInIsolation()` available since 10.3.0, so 10.4 is safe
- autowire/autoconfigure available since 10.2+
- D10.4 is the current LTS; D10.3 is EOL
- Module's PHP 8.3 requirement already limits the D10 audience

#### 1.5 Update hook numbering

**Keep `llm_content_update_11001()` as-is.** Do NOT rename it.

Rationale:
- D11 sites already have schema version `11001` recorded — renaming would break their update path
- Drupal doesn't enforce that update hook numbers match the core version — `_update_11001` runs fine on D10
- The function is idempotent (guards with `$config->get($key) === NULL`), so re-running on D10 is safe
- Any future update hooks should use numbers > 11001 (e.g., `_update_11002`)

> **Research Insight (Deployment Verification):** On `drush en llm_content` (fresh install), the schema is set to the highest update hook number (11001) automatically — the update hook body does NOT run. It only runs during `drush updb` on existing installs. For D10 sites upgrading from a hypothetical older version, `_update_11001` would execute and is safe (idempotent).

### Phase 2: Test Updates

#### 2.1 Update requirements tests

- **Delete** `tests/src/Unit/LlmContentRequirementsTest.php` (tests the deleted `InstallRequirementsInterface` class)
- **Update** `tests/src/Unit/LlmContentRequirementsHooksTest.php` to assert against `REQUIREMENT_ERROR` / `REQUIREMENT_OK` constants instead of `RequirementSeverity` enum values

> **Research Insight (Architecture Strategist):** The test bootstrap at `tests/bootstrap.php` must define `REQUIREMENT_ERROR` and `REQUIREMENT_OK` constants, since `install.inc` (where they're normally defined) won't be loaded in the unit test environment. Add to `tests/bootstrap.php`:
> ```php
> if (!defined('REQUIREMENT_ERROR')) {
>   define('REQUIREMENT_ERROR', 2);
>   define('REQUIREMENT_OK', 0);
> }
> ```

#### 2.2 Ensure remaining tests pass on both versions

The other 4 test files (`LlmMarkdownPathProcessorTest`, `RouteSubscriberTest`, `XmlSitemapLinkManagerTest`, `MarkdownConverterTest`) do not use any D11-only APIs and should pass without changes.

### Phase 3: CI Matrix Testing

#### 3.1 Expand GitHub Actions

> **Research Insight (CI/CD Best Practices):** Based on analysis of Islandora, phpstan-drupal, and drupal_extension_scaffold repos, here is a production-quality workflow. Key patterns: lint runs once (not in matrix), `fail-fast: false`, experimental flag for dev branches, `pcov` over `xdebug`, `ramsey/composer-install` for caching.

Create `.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

jobs:
  lint:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          tools: composer:v2
          coverage: none

      - uses: ramsey/composer-install@v3

      - name: PHPCS
        run: vendor/bin/phpcs

  test:
    runs-on: ubuntu-24.04
    needs: lint
    strategy:
      fail-fast: false
      matrix:
        experimental: [false]
        php-version: ["8.3"]
        drupal: ["^10.4"]
        include:
          - php-version: "8.3"
            drupal: "~11.2.0"
            experimental: false
          - php-version: "8.4"
            drupal: "11.x-dev"
            experimental: true

    continue-on-error: ${{ matrix.experimental }}
    name: PHP ${{ matrix.php-version }} | Drupal ${{ matrix.drupal }}

    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}
          extensions: gd, pdo_sqlite, sqlite3
          coverage: pcov

      - name: Set Drupal core version
        run: |
          composer require drupal/core-recommended:${{ matrix.drupal }} \
            drupal/core-dev:${{ matrix.drupal }} \
            --with-all-dependencies --no-update

      - uses: ramsey/composer-install@v3

      - name: Migrate PHPUnit configuration
        run: php vendor/bin/phpunit --migrate-configuration || true

      - name: Run PHPUnit
        run: php vendor/bin/phpunit
```

> **Research Insight (CI/CD):** PHPStan for Drupal uses `mglaman/phpstan-drupal`. For cross-version support, use `"phpstan/phpstan": "^1.10 || ^2.0"` and `"mglaman/phpstan-drupal": "^1.2 || ^2.0"`. D11.2+ uses PHPStan 2.0. Add PHPStan as a separate follow-up PR to keep this one focused.

### Phase 4: Cleanup and Documentation

#### 4.1 Delete D11-only files that cause D10 fatals

| File | Reason |
|------|--------|
| `src/Install/Requirements/LlmContentRequirements.php` | `implements InstallRequirementsInterface` fatals on D10 |
| `tests/src/Unit/LlmContentRequirementsTest.php` | Tests the deleted class |

#### 4.2 PHP version decision

**Keep `php: ">=8.3"` in `composer.json`.** The codebase uses PHP 8.3 features (constructor property promotion, etc.). Lowering to 8.2 would require auditing all files and the benefit is narrow — D10 sites on 8.1/8.2 cannot use the module regardless, but any D10.4+ site running PHP 8.3 can.

> **Research Insight (Drupal Expert):** The codebase does NOT use `readonly` properties (a PHP 8.1 feature) despite the original plan claiming it does. The PHP 8.3 requirement is driven by other features. Verify mjf.org runs PHP 8.3 before proceeding.

#### 4.3 Document Drush 12+ requirement

> **Research Insight (Drupal Expert):** The `LlmContentCommands.php` Drush command class uses `#[CLI\Command]` attribute-based commands, which require Drush 12+. Drush 11 (which some D10 sites might still use) does NOT support attribute-based commands. Document this as a requirement.

Add to `llm_content.info.yml` or README:
- Requires Drush 12+ for `drush llm:generate` command

## Acceptance Criteria

### Functional Requirements

- [ ] Module installs cleanly on Drupal 10.4+ with PHP 8.3 (`drush en llm_content`)
- [ ] Module continues to work on Drupal 11.1+ with no behavior changes
- [ ] `/llms.txt` returns 200 with content list on both D10 and D11
- [ ] `/{alias}.md` returns markdown for published nodes on both D10 and D11
- [ ] `drush llm:generate` works on both versions (requires Drush 12+)
- [ ] Entity insert/update/delete hooks fire exactly once on both versions (no double invocation on D11)
- [ ] `hook_cron` queues nodes exactly once per cron run on both versions
- [ ] `hook_page_attachments` adds `<link rel="alternate">` tag exactly once on both versions
- [ ] Requirements check shows on status report on both versions
- [ ] Module blocks installation if `league/html-to-markdown` is missing on both versions
- [ ] xmlsitemap integration works when xmlsitemap is enabled on both versions
- [ ] Existing D11 sites can update module in-place without running `drush updb`

### Non-Functional Requirements

- [ ] No `#[Hook]` attributes removed from OOP classes (preserved for D11 hook discovery)
- [ ] All procedural shims have `#[LegacyHook]` attribute (7 shims = 7 attributes, 1:1 correspondence)
- [ ] All procedural shims have Drupal-standard docblocks (`/** Implements hook_X(). */`)
- [ ] `.module` file has `@file` docblock
- [ ] No D11-only classes loaded at class-definition time on D10 (no `implements`/`extends` on missing interfaces)
- [ ] CI tests pass against both D10 and D11
- [ ] `tests/bootstrap.php` defines `REQUIREMENT_*` constants for test compatibility

## Dependencies & Risks

### Dependencies

- `league/html-to-markdown:^5.0` — works on both versions, no changes needed
- `xmlsitemap` contrib module — verify it has a version supporting both D10 and D11
- **Drush 12+** — required for attribute-based Drush commands (`drush llm:generate`)

### Risks

| Risk | Likelihood | Mitigation |
|------|-----------|-----------|
| Double hook invocation if `#[LegacyHook]` accidentally omitted | Low | Code review must verify 7 shims = 7 attributes; add integration test |
| `renderInIsolation()` not in specific D10.4.x release | Very Low | Confirmed in 10.3.0; CI matrix will catch any regression |
| D10 autoloader scanning triggers class loading | None | `InstallRequirementsInterface` class is deleted, not conditionally loaded |
| PHP 8.3 requirement too narrow for D10 audience | Medium | Acknowledged trade-off; verify mjf.org runs PHP 8.3 |
| Runtime requirements gap on D11.0-11.1 | Low | `hook_runtime_requirements` introduced in D11.2; `.install` `hook_requirements` covers earlier D11 versions as fallback |
| Drush 11 sites cannot use `drush llm:generate` | Low | Document Drush 12+ requirement |

> **Research Insight (Performance Oracle):** No performance concerns. `\Drupal::service()` overhead is 1-3 microseconds per call (hash-table lookup on compiled container). Three new service definitions have zero measurable impact. `class_exists()` in requirements runs only on admin status report page. No caching implications.

> **Research Insight (Security Sentinel):** No security vulnerabilities introduced. All service IDs are hardcoded FQCNs (no injection risk). `class_exists()` triggers safe autoloader lookup. Access control parity is maintained between D10 and D11 — same route access checks, same entity access, same config validation.

## Files Changed Summary

| File | Action | Description |
|------|--------|-------------|
| `llm_content.module` | **Create** | LegacyHook procedural shims (7 functions) with docblocks |
| `llm_content.info.yml` | **Edit** | `core_version_requirement: ^10.4 \|\| ^11` |
| `llm_content.services.yml` | **Edit** | Add 3 hook class service definitions (autowired, no explicit args) |
| `llm_content.install` | **Edit** | Add procedural `hook_requirements()` for install phase only |
| `src/Hook/LlmContentRequirementsHooks.php` | **Edit** | Replace `RequirementSeverity` enum with `REQUIREMENT_*` constants; remove unused `use` import |
| `src/Install/Requirements/LlmContentRequirements.php` | **Delete** | Uses `implements InstallRequirementsInterface` (D10 fatal) |
| `tests/src/Unit/LlmContentRequirementsTest.php` | **Delete** | Tests deleted class |
| `tests/src/Unit/LlmContentRequirementsHooksTest.php` | **Edit** | Update assertions for `REQUIREMENT_*` constants |
| `tests/bootstrap.php` | **Edit** | Define `REQUIREMENT_ERROR` and `REQUIREMENT_OK` constants |
| `.github/workflows/ci.yml` | **Create** | D10/D11 matrix CI with lint + PHPUnit |

## D10 Sunset Plan

When Drupal 10 reaches **end of life** (expected with Drupal 12 release, late 2026):

> **Research Insight (Architecture Strategist):** The trigger should be D10's EOL date, not D12's release date. If D10 still has security support when D12 ships, dropping D10 in a minor release would violate Drupal's semver policy. The governing criterion is D10's EOL status.

1. Bump module minor version
2. Set `core_version_requirement: ^11 || ^12`
3. Delete `llm_content.module` (all LegacyHook shims)
4. Remove explicit hook class service definitions (D11+ auto-discovers them via `autoconfigure: true`)
5. Optionally restore `InstallRequirementsInterface` for install-time gating
6. Optionally restore `RequirementSeverity` enum usage
7. Remove procedural `hook_requirements()` from `.install` (keep only `hook_schema`, `hook_install`, `hook_update_N`)

Per Drupal semver rules, dropping an *unsupported* core version is a minor version bump, not a major one.

## Deployment Checklist

> **Research Insight (Deployment Verification Agent):** Full deployment checklist with rollback procedures.

### Pre-Deploy (Required)
- [ ] Save baseline `llm_content_markdown` row count on all D11 sites
- [ ] Verify schema version is `11001` on existing D11 sites: `drush php-eval "echo drupal_get_installed_schema_version('llm_content');"`
- [ ] Save full `llm_content.settings` config output
- [ ] Verify target D10 sites run PHP 8.3+ and Drupal 10.4+
- [ ] Verify `league/html-to-markdown` is installed via Composer
- [ ] Code review confirms all 7 shim functions have `#[LegacyHook]` attribute
- [ ] CI passes on both D10 and D11 matrix entries

### Deploy Steps
1. Deploy new module code (composer update or git pull)
2. Run `drush cr` (**mandatory** — registers new services and LegacyHook attributes)
3. Run `drush updb -y` (should report no pending updates on existing D11 sites)
4. Run `drush cr` again

### Post-Deploy Verification (Within 5 Minutes)
- [ ] Verify hook services registered: `drush php-eval "var_dump(\Drupal::hasService('Drupal\\llm_content\\Hook\\LlmContentHooks'));"`
- [ ] Verify `/llms.txt` returns HTTP 200
- [ ] Verify `/{alias}.md` returns HTTP 200 for a known published node
- [ ] Verify requirements page: `drush core:requirements --filter=llm_content`
- [ ] Create and delete a test node; confirm exactly 1 markdown row (no double invocation)
- [ ] Run `drush cron` and verify single "Cron queued" log entry in watchdog

### Rollback (Fully Reversible)
1. Revert module code to previous version/commit
2. Run `drush cr` (mandatory to clear stale service container)
3. Verify `/llms.txt` returns 200
4. Verify `llm_content_markdown` data intact (no data was modified by this change)

## References & Research

### Internal References

- PR #26: `feat/drupal10-compatibility` branch — current D10 approach (to be superseded or accepted as simpler alternative)
- `docs/solutions/runtime-errors/missing-composer-dependency-class-not-found.md` — requirements architecture lesson
- `src/Hook/LlmContentHooks.php` — main hook class with 5 `#[Hook]` attributes
- `src/Hook/LlmContentRequirementsHooks.php` — requirements hook using D11 enum
- `src/Install/Requirements/LlmContentRequirements.php` — D11 `InstallRequirementsInterface`

### External References

- [Drupal OOP Hook Implementations (11.1)](https://www.drupal.org/node/3442349)
- [LegacyHook Attribute Discussion](https://www.drupal.org/project/drupal/issues/3482464)
- [Drupal Semantic Versioning Policy](https://www.drupal.org/node/3108648)
- [core_version_requirement in info.yml](https://www.drupal.org/node/3070687)
- [DeprecationHelper for Multi-Version Support](https://www.drupal.org/node/3379306)
- [Matt Glaman: Backward-Compatible Deprecation Fixes](https://mglaman.dev/blog/writing-backward-compatible-deprecation-fixes-contributed-modules-will-be-much-easier-drupal)
- [plopesc: Implementing a Drupal Module Feature Without a Code Editor](https://discuss.lullabot.com/t/implementing-a-drupal-module-feature-without-a-code-editor-using-drupalorg-cli-skills/983)
- [AlexSkrypnyk/drupal_extension_scaffold](https://github.com/AlexSkrypnyk/drupal_extension_scaffold) — Drupal module CI template
- [mglaman/phpstan-drupal](https://github.com/mglaman/phpstan-drupal) — Reference CI workflow for PHPStan + Drupal

### Real-World Examples (Single Branch Pattern)

- **Pathauto** (`8.x-1.x`): `core_version_requirement: ^10.2 || ^11`
- **Token** (`8.x-1.x`): `core_version_requirement: ^10.3 || ^11`
- **Metatag** (`2.1.x`): `core_version_requirement: ^9.4 || ^10 || ^11`

### Tools Evaluated

- **drupalorg-cli** — Not relevant. Designed for Drupal.org's GitLab infrastructure (issue queues, forks, MRs, CI pipelines). Since this module is GitHub-hosted, the `gh` CLI covers all equivalent needs. The tool is excellent for drupal.org contrib workflows (see plopesc's blog post) but adds no value here.
