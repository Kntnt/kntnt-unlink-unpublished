# Contributing to Kntnt Unlink Unpublished

Thank you for considering a contribution. Contributions of every size help – from a typo fix to a new rendering path.

## Ways to contribute

- **Report a bug or request a feature.** [Open an issue](https://github.com/Kntnt/kntnt-unlink-unpublished/issues), and search the existing issues first to avoid duplicates.
- **Submit a pull request.** Fix a bug, improve the documentation, add a translation or implement a feature.

For anything larger than a small fix, open an issue first so the approach can be agreed before you invest the work.

## Development setup

```bash
git clone https://github.com/Kntnt/kntnt-unlink-unpublished.git
cd kntnt-unlink-unpublished
composer install
```

The plugin requires **PHP 8.4** and **WordPress 6.9** (see [`docs/adr/`](docs/adr/)). The end-to-end tests additionally need Node.js and Python 3 for the WordPress Playground harness.

## Behaviour and contracts

The behaviour is specified in [`docs/spec.md`](docs/spec.md) and its terms in [`GLOSSARY.md`](GLOSSARY.md); read both before changing what the plugin does. The hooks are described in [`docs/EXTENSIBILITY.md`](docs/EXTENSIBILITY.md). The plugin never writes to stored content, and vendor-specific cache code belongs in a site plugin, not here.

## Quality gates

Every change must pass the same gates CI enforces. Run them locally before opening a pull request:

```bash
composer phpcs     # WordPress Coding Standards (with the documented deviations)
composer stan      # PHPStan at level max
composer test      # Pest unit suite
bash run-tests.sh  # Level 1 (Pest) + Level 2 (Playground e2e)
```

`composer phpcbf` fixes most coding-standard violations automatically. The Playground e2e layer boots the plugin on PHP 8.4 via `@wp-playground/cli`; there is deliberately **no** automatic DDEV fallback (see [`docs/adr/0004-playground-e2e-no-auto-ddev-fallback.md`](docs/adr/0004-playground-e2e-no-auto-ddev-fallback.md)).

## Coding and writing standards

- **Code** follows the project coding standard in [`docs/agents/coding-standard/`](docs/agents/coding-standard/). Note the four deliberate deviations from the WordPress Coding Standards – `[ ]` arrays, PSR-4 filenames, namespaces over global function prefixes and no required Yoda conditions – which are enforced in `phpcs.xml.dist`.
- **Naming** follows [`docs/spec.md`](docs/spec.md) §1: namespace `Kntnt\Unlink_Unpublished`, slug and text domain `kntnt-unlink-unpublished` and the `kntnt_unlink_unpublished_` prefix for options, transients, post meta and hooks.
- **Documentation** is written in British English – spaced en-dashes ( – ), `-ise`/`-isation` spellings and no Oxford comma.

## Pre-1.0 policy

While the major version is `0`, the project makes **no backwards-compatibility commitments**. This policy sunsets automatically when the version crosses `1.0.0`.

## Pull-request process

1. Branch from `main` and keep each pull request focused on a single concern.
2. Make sure the quality gates above pass locally.
3. Open the pull request against `main`. CI runs lint, static analysis, unit and e2e jobs on PHP 8.4; all must be green.
4. Describe what changed and why. Link any related issue.

## Licence

By contributing, you agree that your contributions are licensed under the [GPL-2.0-or-later](LICENSE) licence that covers the project.
