# e2e tests run on WordPress Playground; no automatic DDEV fallback

End-to-end tests use **WordPress Playground** (WASM PHP 8.4, `@wp-playground/cli`) driven over real HTTP, as in `kntnt-ai-visibility`. There is deliberately **no** automatic fallback to DDEV: if Playground cannot exercise a behaviour, the question goes to the maintainer.

Playground runs a single worker and drives no browser. Three behaviours are therefore tested through proxies agreed in the spec (docs/spec.md §13): cron publishing is exercised by calling `wp_publish_post()`, the function the cron event calls; the redirect check's loopback request, which a single worker cannot answer, is answered by a `pre_http_request` fixture; and the editor markers are asserted as the stylesheet the editor loads, with their look in a real editor checked by hand at rollout.

## Consequences

- No DDEV fallback may be wired into tooling or CI.
- A behaviour that needs more than these proxies is raised to the maintainer, not worked around.
