Read when running or changing the test suites.

`bash run-tests.sh [--unit-only|--e2e-only]` runs Level 1 (Pest unit) then Level 2 (WordPress Playground e2e, `@wp-playground/cli`, PHP 8.4).

- **Level 1** – `tests/Unit/`. Pest with Brain Monkey; the link surgery runs against WordPress's real HTML API, loaded from the `johnpbloch/wordpress-core` dev dependency at the 6.9 floor (`tests/bootstrap.php`). Every surgery test runs against both the tree surgeon and the lexical fallback.
- **Level 2** – `tests/Integration/`. `playground-smoke.sh` boots the plugin; `playground-rendering.py` seeds every state of the status matrix and drives rendering, requests, redirects, purges, Site Health and the editor over real HTTP; `playground-bogo.py` and `playground-polylang.py` repeat the multilingual case with Bogo and with Polylang – Swedish as default language, English under `/en/` – including a draft that switches language; `playground-slim-seo.py` walks a page through a trash cycle with Slim SEO's automatic redirection on and shows that each redirect to a page that isn't live is vetoed. The drivers share `playground_site.py`. `KNTNT_RENDERING_WP=6.9` runs the rendering suite on the WordPress floor.

The Pest `Integration` suite runs the Level 2 scripts only when `KNTNT_RUN_PLAYGROUND=1`, so a plain `pest` run stays offline.

Playground runs one worker and drives no browser, so three behaviours are tested through proxies (docs/spec.md §13): cron publishing calls `wp_publish_post()` directly, the redirect check's loopback is answered by a `pre_http_request` fixture, and the editor markers are asserted as the stylesheet the editor loads – their look in a real editor is a manual check at rollout.

No DDEV fallback – if Playground cannot exercise a behaviour beyond these proxies, STOP and raise it (ADR-0004). CI runs lint, stan, unit and the e2e suites on PHP 8.4.
