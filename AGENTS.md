# kntnt-unlink-unpublished – agent guide

## Ground rules (authoritative)

Precedence over any conflicting skill, README or other doc unless the user overrides in the moment.

- Authoritative: this file, the files it references, and the actual code/state.

## Non-obvious

- Target URLs come from `Permalinks::would_be()`, never `get_permalink()` on the stored post: `page_link` filters reread the stored post, and Bogo then drops the language prefix of unpublished pages.
- Read posts with `Targets::find()` (SQL), never `WP_Query`/`get_posts()`: Polylang and WPML confine queries to the current language.
- `get_post( 0 )` returns the loop's global post; pass only real IDs.

## References

- `docs/agents/coding-standard/general.md` – read when writing or changing any code
- `docs/agents/coding-standard/php.md` – read when writing or changing PHP
- `docs/agents/coding-standard/wordpress.md` – read when writing or changing the plugin's code, files or tooling
- `docs/agents/coding-standard/bash.md` – read when writing or changing Bash
- `docs/agents/coding-standard/manifest.json` – read when updating the coding-standard modules from their generator
- `docs/agents/writing-standard.md` – read when writing or editing Markdown in the repo
- `docs/agents/testing.md` – read when running, writing or changing tests
- `docs/agents/releasing.md` – read when cutting a release or publishing to GitHub
- `docs/agents/spec-map.md` – read when changing, testing or rolling out what the plugin does
- `docs/EXTENSIBILITY.md` – read when adding, changing or using what other code relies on: hooks, theme integration, the drop-marker class, cache and multilingual adapters
- `GLOSSARY.md` – read when naming or writing about links, the pages they point at, or what the plugin does to them
- `docs/adr/` – read when changing a design decision or asking why something is built as it is
