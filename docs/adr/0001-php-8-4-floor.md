# PHP 8.4 as the minimum runtime version

The plugin requires **PHP 8.4**, the floor of the sibling repositories (`kntnt-ai-visibility`, `kntnt-autolink`). The code uses what 8.4 brings: `array_any()`, typed class constants and readonly classes, and the PHP floor lets the plugin rely on the same toolchain and CI matrix as its siblings. Unlike `kntnt-ai-visibility`, the floor is not forced by `Dom\HTMLDocument`: the link surgery runs on WordPress's own HTML API ([ADR-0006](0006-link-surgery-through-the-html-api.md)).

## Consequences

- PHPStan's `phpVersion` is pinned to `80400`, so PHP-8.5-only syntax and built-ins are rejected.
- CI tests PHP 8.4 only.
- `Requires PHP: 8.4` in the plugin header makes WordPress refuse activation on older PHP; the main file's guard covers environments that load the plugin outside activation.
