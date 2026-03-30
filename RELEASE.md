# Release Process

This module uses [semantic versioning](https://semver.org/) with GitHub releases.

## Branch rules

- **main** is protected — all changes require a pull request with:
  - At least 1 approving review
  - Passing CI checks (lint + tests on Drupal 10.4 and 11.2)
  - Up-to-date branch (no stale merges)
- Direct pushes to main are not allowed

## Versioning

Follow semver: `MAJOR.MINOR.PATCH`

- **PATCH** (v1.0.1): bug fixes, performance improvements, test additions
- **MINOR** (v1.1.0): new features, new configuration options, new endpoints
- **MAJOR** (v2.0.0): breaking changes to APIs, config schema,
  or database schema

## Creating a release

1. Ensure main is up to date and CI is green
2. Tag the release:
   ```bash
   git checkout main
   git pull
   git tag v1.x.x
   git push origin v1.x.x
   ```
3. The [release workflow](.github/workflows/release.yml) automatically creates a GitHub release with generated changelog

## Commit message conventions

Use [Conventional Commits](https://www.conventionalcommits.org/) prefixes:

- `feat:` — new feature
- `fix:` — bug fix
- `perf:` — performance improvement
- `test:` — test additions or fixes
- `docs:` — documentation changes
- `refactor:` — code restructuring without behavior change
- `ci:` — CI/CD workflow changes
