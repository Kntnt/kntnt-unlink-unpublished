# Last public URLs in post meta; would-be permalinks from the post object

Links point at the URL a page had while it was public. A draft that is renamed, or deleted after it was renamed, no longer has that URL, so the plugin records a target's **last public URL** as post meta when it leaves the published state, matches it while the target is unpublished and gives it to the tombstone on deletion (docs/spec.md §5.2). Post meta is not content, so the rule never to write stored content holds. Targets unpublished before the plugin was installed are seeded on activation from their would-be permalink when they look once-published: a slug and a set `post_date_gmt`.

Every URL of a target is computed by `Permalinks` from a clone of the post object set to `publish`, never with `get_permalink()` on the stored post. `post_updated`, where the last public URL is recorded, runs after the database has changed, and `page_link` callbacks receive only the post ID and look up the stored post – Bogo, the first real site's multilingual plugin, then links an unpublished page with `?lang=en` instead of `/en/`. Pages are therefore linked as a *sample*, the flag core uses for a draft's would-be URL in the editor, which every multilingual plugin honours.

## Consequences

- A clone must keep the `raw` filter, or `get_post()` fetches the stored post again.
- Walking a page's ancestors must never call `get_post( 0 )`, which answers with the global post of the loop.
- Uninstall removes the post meta.
