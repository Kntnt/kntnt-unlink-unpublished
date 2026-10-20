# Redirects follow the visibility rule

Other code can turn the 404 of an unpublished target into a 301. Slim SEO's automatic redirection does it for every page that has been in the trash once, and core's old-slug redirect for every post renamed while it was published. Both redirect to `get_permalink()`, which for a post that isn't published is its ID form, so the visitor gets a 301 to a 404, or an endless loop. A redirect is therefore followed only if its target is live (docs/spec.md §6), and only a redirect issued while answering a front-end 404 is judged: there a veto removes nothing an editor decided, it only cuts a detour that ends in the same 404. On a published page a redirect is an editorial decision, and vetoing it would mean showing the very page the editor chose to send visitors away from.

- **Core's old slugs** are stopped through `old_slug_redirect_post_id`, so core falls through to its own 404.
- **Every other redirect** is judged in a `wp_redirect` filter at the last priority, which `wp_safe_redirect()` passes through too. It looks at where a redirect goes, not at who issued it, so it needs no vendor code. The location is resolved through the index alone, the lookup that links use.
- **The veto renders the 404 itself**, because callers exit right after `wp_redirect()`. The filter runs before `wp_redirect()` sets its status, so the 404, the 410 or the filtered status code already set stands.
- **The 404 is rendered as WordPress 6.9's template loader renders it**: no body for HEAD, the embed, 404 or index template through `template_include`, a path that resolves to a readable `.php` or `.html` file, the action `wp_before_include_template` – where core starts its template enhancement output buffer – and `load_template()`, which gives the template the globals that the loader's global scope would. A classic theme's 404 template may use `$wp_query` without declaring it global. The loader itself is not included, because it fires `template_redirect` again.

Four alternatives were rejected. Rendering the 404 at `template_redirect` priority 1 for every request whose path is in the index is simpler and catches redirects sent with a raw header, but it overrides rules that point elsewhere and skips unrelated `template_redirect` callbacks such as 404 logging, maintenance modes and forced logins. Removing other plugins' rules or records, such as Slim SEO's `_ss_old_permalink`, is vendor code that edits other plugins' data, and it would not cover core or other redirect managers. Returning `false` from `wp_redirect` without rendering leaves the visitor an empty page. A second lookup through `url_to_postid()` disagrees with the index exactly where the index is selective on purpose: with an unpublished static front page it maps `/` to that page and would veto redirects to the home page.

No Site Health test reports vetoes. After a veto nothing is left for the visitor to suffer, editors often cannot act on the cause – Slim SEO keeps its automatic records as post meta, not as rules in its redirect list – and recording vetoes would mean database writes on anonymous 404s that bots can drive. Vetoes reach the debug log instead, when it is on.

## Consequences

- Redirects sent with a raw `header( 'Location: …' )`, and redirects issued before the main query is parsed, are not seen.
- A location the index doesn't hold passes, even when it belongs to a target that isn't live – a trashed page's `/slug__trashed/` path, which core never redirects to.
- A redirect from a published page to a target that isn't live still fires.
- A vetoed 404 skips the `template_redirect` callbacks that would have run after the one that redirected, as the redirect itself would have.
