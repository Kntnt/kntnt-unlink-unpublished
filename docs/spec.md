# Kntnt Unlink Unpublished – specification

This is the build specification for a new WordPress plugin. It is written for the agent who builds the plugin from an empty repository. The decisions in §1 are settled with the maintainer (Thomas Barregren); build to them, not around them. Where something is genuinely unclear, stop and ask rather than guess. `GLOSSARY.md` fixes the terms this document uses.

## Purpose

Editors link from page to page. When the target page is unpublished – a draft, waiting for review, scheduled, private, trashed or deleted – the link leads to a 404 and the visitor hits a dead end. The usual fixes are poor: editing every link by hand and restoring it later, or a broken-link tool that strips the link from the content so it never comes back.

The plugin makes the decision at render time instead. **A link stays a link only if following it would show the page to the public – or, for a private page, to the visitor who sees it.** Otherwise the link is rendered as plain text, or dropped where the element is nothing but a link. The stored content is never changed, so when the page is published again every link to it is back at once, with no one having to remember where they were.

The plugin also makes the address of an unpublished page say the right thing to visitors and search engines, keeps editors informed in the block editor and Site Health, and tells caches which pages changed – without knowing anything about any particular cache.

## 1. Settled decisions

1. **Name and identity.** Kntnt Unlink Unpublished; slug, text domain and repository `kntnt-unlink-unpublished`; GitHub `Kntnt/kntnt-unlink-unpublished`; PHP namespace `Kntnt\Unlink_Unpublished`; prefix `kntnt_unlink_unpublished_` for options, transients, post meta and hooks; CSS and HTML class prefix `kntnt-unlink-unpublished-`; block namespace `kntnt-unlink-unpublished`.
2. **Render time only.** The plugin never writes to stored post content, template parts, menus or widgets. Everything it does to links happens while output is produced. Its own records – a target's last public URL in post meta, tombstones and redirect-check results in options – are not content.
3. **The visibility rule.** A link to an internal target is live – it stays a link – if, and only if, following it would show the target to the public or, for a private target, to the current visitor. Editors see what the public sees: WordPress shows an editor a preview of a draft at its pretty URL, but links to the draft are unlinked for the editor too. §3 turns this into a status matrix.
4. **Status codes, no redirects.** The URL of an unpublished target answers **404** to the public. RFC 9110 §15.5.5 says a 404 does not indicate whether the absence is temporary or permanent, which is exactly "temporarily gone"; search engines keep re-crawling 404 URLs and re-index them when they return 200. The URL of a deleted target answers **410 Gone** (RFC 9110 §15.5.11), the code for a known permanent absence. The plugin creates no redirects, because a redirect of a removed page to the home page is treated as a soft 404 by Google anyway and a stored redirect would keep firing after the page is republished.
5. **Cache-agnostic.** WordPress has no API for purging a page cache, and no third-party convention is widely adopted (LiteSpeed, WP Rocket, Nginx Helper, Proxy Cache Purge and others each have their own). The plugin therefore emits its own two actions (§7) and contains no vendor-specific code. Adapters belong in the site's own MU plugin, the same convention as `kntnt-ai-visibility`'s `docs/EXTENSIBILITY.md`.
6. **Tombstones for deleted targets.** When a target is deleted permanently, the plugin records its last public URL and post ID as a tombstone. Links to a tombstone stay unlinked and are reported in Site Health so an editor can clean them up.
7. **Editors see what the plugin does.** In the block editor and site editor, links to unpublished, private and deleted targets are visibly marked, each state differently, using CSS only (§9).
8. **Zero-config.** No settings page. Behaviour is adjustable through filters and actions (§8).
9. **Pre-1.0 rules** as in the sibling repositories: no backward-compatibility, migration or deprecation work before 1.0.0.

## 2. Glossary

`GLOSSARY.md` at the repository root defines source, target, live, would-be permalink, last public URL, the four target states (published, private, unpublished, deleted), tombstone, unlink, drop and drop marker. Use these terms consistently in code, docs and UI, and none of the words listed under _Avoid_ – *dormant* among them. The editor-facing labels in §4.3 say "hide", which is deliberate UI copy.

## 3. The status matrix

Targets are posts of every public post type except attachments (filterable, §8). Links to media files and to external sites are never touched.

| Target state | Following the link gives | Live for anonymous visitors | Live for logged-in visitors |
|---|---|---|---|
| Published | the page | yes | yes |
| Published, password-protected | the password form; the page exists | yes | yes |
| Private | the page to those who can read it, otherwise 404 | no | yes if `current_user_can( 'read_post', $id )` |
| Unpublished: scheduled (`future`) | 404 to the public; a preview to users who can edit it | no | no |
| Unpublished: draft, pending review, any custom non-public status | 404 to the public; a preview to users who can edit it | no | no |
| Unpublished: in the trash | 404; WordPress has renamed the slug with `__trashed` and stored the original in `_wp_desired_post_slug` | no, matched against the original URL | no |
| Restored from the trash | WordPress restores it as a draft with its original slug (since 5.6, filter `wp_untrash_post_status`); the list table's Undo link restores the previous status | no until published | no until published |
| Deleted | 410 via the tombstone | no | no |

A link that isn't live is unlinked or dropped (§4.2).

More rules:

- **A published post that currently owns the URL wins.** If a slug freed by trashing or deletion is taken by a new published post, links to that URL are links again. The same holds for the rare reuse of a deleted post's ID.
- **Published descendants of an unpublished parent are still live.** WordPress serves them at their full path to everyone, because it checks the status of the requested page only, never of its ancestors. The exception is a trashed ancestor: its slug change moves the descendants' URLs to `/parent__trashed/child/`, and the old path no longer resolves. Treat links using the pre-trash path as not live.
- **Scheduled publishing needs no special case.** Cron moves the target from `future` to `publish`, the ordinary status-change handling applies (§6 and §7), and the links come back.
- **An unpublished target that moves keeps its last public URL.** While a target is unpublished or private, links to its would-be permalink and to its last public URL are both not live (§5.2). When it is published again, the record is cleared, and links to an old URL behave like any other broken link – WordPress keeps no old-slug redirects for pages either.

## 4. Link handling in output

### 4.1 What counts as a link to a target

An `href` points at a target when, after normalisation, it matches the target's would-be permalink, its last public URL, a tombstone URL, or an ID form of a target or a tombstone. Normalise as follows:

- Same site only: absolute URLs on the home host with `http` or `https`, protocol-relative URLs and root-relative paths. Treat the `www.` and bare host as the same host. The filter `kntnt_unlink_unpublished_hosts` (§8) adds hosts that serve the same site, such as a multilingual plugin's language domains; each comes with its `www.` twin too. Document-relative hrefs (`../logi/`) are ignored; the block editor never writes them.
- Decode percent-encoding, then compare paths in lowercase and ignore a trailing slash.
- Strip the query string and fragment before matching the path, except for the ID forms `?page_id=N`, `?p=N` and `?post_type=…&p=N`, which match their target directly whatever other parameters come with them – an editor's pasted preview link `?page_id=123&preview=true` matches.
- Use the would-be permalink: the permalink of a clone of the post (with `filter` still `raw`, or `get_post()` re-fetches it) with `post_status` set to `publish`, and for trashed posts and trashed ancestors with the slug from `_wp_desired_post_slug`. This keeps multilingual permalink filters in play – Bogo's `/en/` prefix must come out right. `page_link` callbacks receive only the post ID and look up the stored post – Bogo then links an unpublished page with `?lang=en` instead of `/en/` – so a page is linked with `get_page_link( $clone, false, true )`, as a *sample*, the flag core uses for a draft's would-be URL in the editor; `post_link`, `post_type_link` and `get_page_uri()` see the clone. Compute every URL of a target this way, a published one's included, because hooks such as `post_updated` run after the stored post has changed (docs/adr/0009).
- Read the site's root from the `home` option, not from `home_url()`, which multilingual plugins filter with the visitor's language.
- A target without a slug (never saved with one) can only be matched through its ID forms.
- Bogo also answers the default locale with a prefix (`/sv/praktiskt/`). That variant is not matched: no content uses it.
- Never index a URL that WordPress serves as something else:
  - the home URL – an unpublished static front page has the would-be permalink `/`, and every logo and home link would be unlinked;
  - the posts page – WordPress serves the blog index at its URL whatever the page's status;
  - any path that collides with a post-type archive, whose rewrite rule beats the page rules.

  Collisions with term archives are accepted as rare.

### 4.2 What happens to a link that isn't live

| Element | Result |
|---|---|
| `<a>` containing text, including visually hidden text (PHP cannot see CSS) | Unlink: replace the element with its inner HTML, so the text stays |
| `<a>` containing embedded media (`img`, `picture`, `video`, `figure`) | Unlink: the media stays without the link |
| `<a>` with no text outside `svg` elements and no embedded media (an empty overlay or stretched link, an icon-only link, an icon font) | Drop |
| Button: `core/button`, or any `<a>` with the class `wp-block-button__link` or `wp-element-button` | Drop the button |
| Navigation item (`core/navigation-link`, classic menu item) | Drop the item |
| Submenu parent (`core/navigation-submenu`, classic parent item) | Keep the children; the label becomes a placeholder link (§4.6) |
| Inside an element with a drop marker (§4.3) | Drop the whole element, including everything in it |

**Containers a drop leaves empty.** After a drop, remove the enclosing `p` or `li` if nothing visible is left in it – no text and no embedded media – then a `ul` or `ol` left without items, and a `core/buttons` wrapper left without buttons. Go no higher: groups, columns and covers stay.

**Byte-identity.** Only the bytes of the elements that are unlinked or dropped change. Everything else keeps its bytes, live links in the same fragment included, and a fragment the plugin did not change is returned exactly as it came in.

### 4.3 Drop markers

Unlinking keeps the text around a link, which is right when the sentence still makes sense without it. Some content only exists to point at the target, and without the link it reads as a dead end:

- a sentence like "Läs mer under Nödkontakt." or "Se Logi och mat.";
- a whole block, like a card on a landing page with an icon, a heading, a one-line description and a "Read more" button for one subpage.

For these the editor sets a **drop marker**. One rule covers both levels: **an element carrying the class `kntnt-unlink-unpublished-drop` is dropped together with its entire content if any link inside it isn't live.** Otherwise the element renders as normal.

**Inline – the marked span.**

- **Applying it.** The editor selects the sentence and applies a rich-text format, **Hide if link target is unpublished**. Register it with `registerFormatType` and a `RichTextToolbarButton`.
- **Markup.** The format wraps the selection in `<span class="kntnt-unlink-unpublished-drop">…</span>`.
- **Rendering.** If every link inside is live, unwrap the span and keep its content as it is.
- **Classic content.** An element carrying the class in classic, non-block content follows the same rule.

**Block level – the marked block.**

- **Applying it.** Add a toggle, **Hide block if link target is unpublished**, to the block inspector of every block that supports custom class names. Use an `editor.BlockEdit` filter with `InspectorControls`.
- **Storage.** The toggle adds `kntnt-unlink-unpublished-drop` to, or removes it from, the block's `className` attribute, so the marker is the same class as the inline one. A block marked by typing the class under Advanced → Additional CSS class(es) behaves identically, and the plugin adds no attribute of its own to stored content.
- **Rendering.** Blocks render from the inside out, so by the time a marked block reaches `render_block`, the plugin has already unlinked or dropped the links of its inner blocks and its output no longer shows them. Therefore, while a marked block renders, the plugin notes every link in its subtree that isn't live, including links inside dynamic blocks, whose output passes the same filters: a stack of the blocks being rendered is opened on `pre_render_block` and closed on `render_block`, and a count of the links that aren't live is compared (docs/adr/0007). When the marked block has rendered, `render_block` returns an empty string if anything was noted, and the block's output unchanged otherwise. The whole block is the element: wrappers, siblings and parents are not involved, so a grid closes up around a dropped card. Blocks core renders without `pre_render_block`, such as navigation items, cannot themselves be dropped by a marker.
- **Nesting.** Nested markers need no special case. A link that isn't live is noted for every marked block it sits in, so an outer marked block is dropped whole, whatever its inner blocks would have done.

In the editor, both levels are shown so editors can see what a marker covers (§9).

### 4.4 Coverage

The rule must apply wherever WordPress renders content for the public, not only in the theme's HTML. That includes REST `content.rendered`, feeds and third-party consumers such as the Markdown alternates of `kntnt-ai-visibility`, which render through `the_content` as an anonymous visitor. Hook the rendering paths rather than buffering the final page output:

- `render_block` – blocks in post content, templates, template parts, navigation and synced patterns;
- `the_content` and `the_excerpt` at a late priority – classic, non-block content;
- `wp_nav_menu_objects` – classic menus;
- `widget_text` and `widget_block_content` – widgets.

Processing must be idempotent, because the same HTML can pass through more than one of these. Links rendered elsewhere are out of scope (§11).

### 4.5 Implementation constraints

- **Parser.** Do the surgery with a subclass of `WP_HTML_Processor` that splices tags out through its text replacements (`$this->lexical_updates[]`) and bookmarks (docs/adr/0006). Core does the same in 6.9: `WP_Block::get_block_bindings_processor()` subclasses `WP_HTML_Processor`, and the Interactivity API's directives processor subclasses the Tag Processor. This is what makes per-element byte-identity (§4.2) possible; `Dom\HTMLDocument` would re-serialise whole fragments. Where the HTML Processor cannot parse a fragment, fall back to the Tag Processor and match closers by counting same-name tags, as the Interactivity API does. Record the choice in an ADR.
- **Fast path.** A fragment containing no `href` that could match the index must cost a string search and nothing more. Parse only fragments with a candidate.

### 4.6 Navigation

Core decides first for post-type navigation entries – `kind` `post-type`, or `type` `post` or `page`, with an `id`:

- **`core/navigation-link`** renders nothing when its post isn't published, private included. Use the filter `render_block_core_navigation_link_allowed_post_status` (since 6.8) to allow `private` for a user who can `read_post` the target, so navigation follows the matrix.
- **`core/navigation-submenu`** renders nothing when its post isn't published, and its children go with it; this is hard-coded. The navigation block renders its items with `$inner_block->render()`, which applies neither `render_block_data` nor `pre_render_block`, so the fix is in `render_block`: when core has rendered a submenu as nothing and its post isn't published, render a clone of the block instance without the `id` – the clone keeps the block context – and handle its label like any other link (docs/adr/0008).
- **Dropped items.** The navigation block closes its list at every item that renders as nothing and opens a new one at the next, so an item dropped in the middle would split the navigation in two lists. Take the top-level links that won't render – those core would refuse and those the plugin would drop – out of the list in `block_core_navigation_render_inner_blocks`.
- **Submenu labels.** The label of a submenu parent whose link isn't live – block or classic – keeps its `<a>` as a placeholder link: remove `href`, `target` and `rel` and keep the rest, so the navigation's styling and toggles still work. Inline links are different: there the `<a>` goes, so the text no longer looks like a link.
- **Typed URLs.** Entries with `kind` `custom` are matched by URL like any other link.
- **Classic menus.** Core already hides items whose target is trashed, gives items whose target is a draft a plain `?page_id=` URL (matched as an ID form) and deletes the menu items of a post deleted permanently. A tombstone therefore never has a classic menu among its sources.

## 5. The index, last public URLs and tombstones

### 5.1 The index

- Build a map from normalised URL to target ID and state (unpublished, private, deleted), and a map from post ID to the same for the ID forms. Include every unpublished and private post of the targeted post types (auto-drafts excluded), the trashed ones under their desired slug, the last public URL of every unpublished or private target, and every tombstone with its URL and ID. Apply the exclusions in §4.1.
- Keep it in the object cache, with a transient fallback, under a version key. Bump the version whenever an input changes:
  - `transition_post_status`;
  - `post_updated` when the slug or parent changes;
  - `deleted_post`;
  - a write of a last public URL or a tombstone;
  - the action `kntnt_unlink_unpublished_permalinks_changed` (§8), through which the built-in adapters for Bogo and Polylang (§12) – and any other multilingual plugin or site adapter – announce a change the plugin cannot see, such as a post's language;
  - a permalink structure change, and changes to `show_on_front`, `page_on_front` and `page_for_posts`;
  - a change in the set of post-type archive slugs – make them part of the version key, so a deploy that registers a new archive rebuilds the index.
- Read the targets with SQL, not `WP_Query`: Polylang and WPML restrict every query to the current language, and `suppress_filters` does not stop them, so an index built during a request in one language would miss the others.
- The index must hold up on a site with a few hundred unpublished posts without a measurable cost on cached requests.
- The filter `kntnt_unlink_unpublished_is_live` is consulted only for targets in the index. It can make an unpublished or private target live; it cannot hide a published one, which is never looked up. Document this limit.

### 5.2 Last public URLs

- When a target leaves the published state, for any other state, private included, record the URL it had while published – its permalink computed from the post as it was before the change – as the post meta `_kntnt_unlink_unpublished_last_public_url`.
- While the target is unpublished or private, links to its last public URL are matched as well as links to its would-be permalink.
- When the target is published again, delete the record.
- A target that has never been published – a page created private, a draft never published – has no last public URL.
- **Seeding on activation.** Targets unpublished before the plugin was installed have no record. On activation, give every unpublished target that looks once-published its would-be permalink as last public URL. A target looks once-published when it has a slug and a set `post_date_gmt`: WordPress leaves `post_date_gmt` at `0000-00-00 00:00:00` for drafts and pending posts that were never published or dated by hand, and keeps the date when a published post goes back to draft. Scheduled posts are excluded.

### 5.3 Tombstones

- When a target is deleted permanently and has a last public URL, or is published at the time, record a tombstone: the last public URL (normalised), the post ID, the title, the post type and the time of deletion. Keep all tombstones in one option, not autoloaded.
- Links matching a tombstone's URL or the ID forms of its post ID are not live. A published post that owns the URL or the ID wins over the tombstone.
- The daily housekeeping event (§10) prunes a tombstone when no source in any status except trash links to it and it is at least 90 days old.

## 6. Requests for unpublished and deleted URLs

- **Unpublished URL.** WordPress already answers 404 to the public. A user who can edit the target gets a preview with 200, and the plugin leaves that request alone: no request state, no body class. The plugin must stop `redirect_guess_404_permalink()` from 301-redirecting any URL in the index to a similarly named published page: return `false` from `do_redirect_guess_404_permalink` when the request path is in the index.
- **Tombstone URL.** Answer 410, not 404, and suppress the guess redirect the same way.
- **Request state.** The function `kntnt_unlink_unpublished_request_state(): ?string` returns:
  - `'unpublished'` for a 404 at a URL of an unpublished target that has a last public URL – the page has been public;
  - `'deleted'` for a tombstone URL;
  - `null` for everything else, never-published and private targets included, so that their URLs give a plain 404 that reveals nothing WordPress itself would not.
- **Telling visitors.** The request state reaches the theme through the function, the body classes `kntnt-unlink-unpublished-unpublished` and `kntnt-unlink-unpublished-deleted`, and the container block `kntnt-unlink-unpublished/notice`, whose inner blocks render only when the request state matches its `when` attribute (`unpublished`, `deleted` or `both`). Placed in a block theme's 404 template, the block lets the site say "This page is temporarily unavailable" without code.
- **Existing redirect rules.** Rules held by SEO and redirect plugins run before WordPress's 404 handling and win. The plugin does not read or change them. It detects the conflict they cause after publication instead (§10.2).
- **Filter `kntnt_unlink_unpublished_status_code`.** Lets a site change the status code per request state, for example to 503 during a short planned absence. It is called only when the request state is `unpublished` or `deleted`. The default is 404 or 410.

## 7. Cache contract

When a target's live state changes, the rendered HTML of every source that links to it changes too, although no one edited those sources. The plugin cannot purge any cache itself. It announces what changed:

```php
/**
 * The listed URLs now render differently and should be purged.
 *
 * @param string[] $urls   Absolute URLs of the affected published sources and the target's own URL(s).
 * @param \WP_Post $target The target whose live state changed.
 * @param string   $change The target's new state – 'published', 'private', 'unpublished' or 'deleted' – or 'moved'.
 */
do_action( 'kntnt_unlink_unpublished_purge_urls', $urls, $target, $change );

/**
 * Site-wide output changed – for example a template part, a menu or a synced
 * pattern links to the target – so the whole page cache should be purged.
 *
 * @param \WP_Post|null $target The target, or null when several targets caused it.
 * @param string        $change As above, or 'multiple' when $target is null.
 */
do_action( 'kntnt_unlink_unpublished_purge_all', $target, $change );
```

- **When to fire.** Judge the change for anonymous visitors, after it is committed: on `wp_after_insert_post`, which every path fires, cron's `wp_publish_post()` included, and on `deleted_post`.
  - Purge the sources when a link to the target turns live or stops being live, or when the set of URLs that match the target changes.
  - Include the target's own URLs whenever their response code changes (200, 404, 410).
  - Fire nothing when nothing changed for anonymous visitors, for example on an ordinary save of a published page.
- **What that gives.**
  - published → private, unpublished or trash: the sources and the target's URL;
  - unpublished or private → published: the same;
  - draft ↔ trash and private ↔ unpublished: nothing;
  - trash → deleted: the target's URL only, and never `purge_all`;
  - a published target deleted directly: the sources and the target's URL;
  - a slug or parent change while unpublished: the sources that link to the old or the new URL, with `$change` `'moved'`;
  - trashing a parent with published descendants moves their URLs: their sources too.
- **`$change`.** The target's new state – `'published'`, `'private'`, `'unpublished'` (trash included) or `'deleted'` – or `'moved'`. A target restored from the trash gets the state it is restored to.
- **Queue and coalesce.** Queue the changes during the request and fire on `shutdown`. `purge_urls` fires once per target, with duplicate URLs removed. `purge_all` fires at most once per request and replaces that request's `purge_urls`; when more than one target caused it, `$target` is `null` and `$change` is `'multiple'`.
- **Finding the sources.** Search stored content – posts in every status except trash, revisions and auto-drafts, and the widget options – for the target's URL variants: absolute with both hosts and both schemes, root-relative, its last public URL and its ID forms. Find classic menu items by `_menu_item_object_id`. If a source is a `wp_template`, `wp_template_part`, `wp_navigation` or `wp_block` post, a classic menu or a widget, fire `purge_all`. Otherwise fire `purge_urls` with the permalinks of the published sources and the target's own URL(s) – its 404, 410 or 200 response may be cached too. The site-wide types are filterable (§8).
- **Permalinks only.** Other URLs that show a source's content – archives that display full content, feeds, REST responses, Markdown alternates – are not listed. Document that `purge_urls` carries permalinks only and that an adapter widens the purge itself, for example with a tag-based purge.
- **No default listener.** The plugin's tests verify the actions are fired; adapters are written and tested in each site's own plugin. Document a worked adapter example in `docs/EXTENSIBILITY.md`. One example covers CLP Varnish Cache: `purge_url()` per URL, and `purge_host()` plus `purge_tag()` for purge-all. Another forwards to `do_action( 'kntnt_ai_visibility_indirect_content_changed' )`, which takes no arguments and invalidates every Markdown alternate and `llms.txt`.

## 8. Hooks for sites and other plugins

### Filters

| Filter | Value | Default |
|---|---|---|
| `kntnt_unlink_unpublished_post_types` | `string[]` target post types | public post types except `attachment` |
| `kntnt_unlink_unpublished_is_live` | `bool $live, \WP_Post $target, int $user_id` – consulted only for targets in the index (§5.1) | the §3 matrix |
| `kntnt_unlink_unpublished_site_wide_post_types` | `string[]` source types that trigger purge-all | `wp_template`, `wp_template_part`, `wp_navigation`, `wp_block` |
| `kntnt_unlink_unpublished_status_code` | `int $code, string $state, ?\WP_Post $target` – called only for the request states `unpublished` and `deleted` | 404 for `unpublished`, 410 for `deleted` |
| `kntnt_unlink_unpublished_hosts` | `string[]` hosts, with an optional port, whose absolute URLs count as the same site – applied once per request, on first use | the home host; every host comes with its `www.` twin |

### Actions the plugin listens to

| Action | Arguments | Contract |
|---|---|---|
| `kntnt_unlink_unpublished_permalinks_changed` | none | Permalinks changed in a way the plugin cannot see – a post's language, a language's prefix or domain, a custom permalink. The plugin rebuilds its index. Multilingual plugins and site adapters fire it; the built-in Bogo and Polylang adapters do. |

The actions the plugin fires are in §7. Add a hook only when a real need appears; do not speculate beyond these lists.

## 9. Editor markers

- **What the editor sees.** In the block editor and site editor canvas, mark every link whose target is:
  - **unpublished** – for example a dashed underline in an amber tone and a small `::after` badge "Unpublished" / "Opublicerad";
  - **private** – a badge "Private" / "Privat";
  - **deleted** – for example a red wavy underline and a badge "Deleted" / "Raderad".

  The exact styling is free as long as the three states are distinct, legible in light and dark editor themes and never mistakable for the editor's own selection styling.
- **CSS only.** Generate the stylesheet server-side from the whole index, so a link an editor inserts during the session is marked too. That makes the slugs of private pages and drafts visible to anyone who can open the editor, which is accepted. Use attribute selectors per URL variant (`a[href="…"]`, plus `a[href^="…?"]` and `a[href^="…#"]` for query and fragment variants), escaped correctly. Add it inline through `enqueue_block_assets`, limited to the editor (`is_admin()`), so it reaches the iframed canvas. Never touch the editor's DOM or rich-text values: decorating content in a `contenteditable` element risks writing the decoration into the saved post.
- **Drop markers** from §4.3 get their own editor styles. A marked span gets a subtle dashed outline. A marked block gets a dashed outline and a small corner badge, "Hidden if target unpublished" / "Döljs om länkmålet är opublicerat". When a marked block currently contains a link that isn't live, the badge says so: "Hidden now" / "Dolt nu" (`:has()` with the link selectors). Both are CSS on the marker class in the editor canvas, where the block's `className` is applied to its wrapper.
- **Freshness.** The markers reflect the index when the editor loaded. Live refresh during an editing session is not required.

## 10. Site Health

Register the checks through `site_status_tests`; make the scans asynchronous tests. One daily cron event, `kntnt_unlink_unpublished_housekeeping`, does the periodic work.

1. **Links to deleted pages** (status *recommended*). List each tombstone with the sources that still link to it, with edit links, so an editor can remove or repoint them. The housekeeping event prunes tombstones as §5.3 says.
2. **Published pages whose address redirects** (status *recommended*). When a target becomes published, schedule a one-off loopback `HEAD` request to its permalink without following redirects. If the answer is a 3xx, record it and report the page here – the typical cause is a redirect rule left in an SEO or redirect plugin from when the page was unpublished. If the loopback cannot be made, record "could not check" rather than a failure. The housekeeping event re-checks every recorded page, those marked "could not check" included, and the asynchronous test re-checks them when Site Health is viewed. Clear the record when a check returns 200.
3. **Links currently hidden** (status *good*, informational). A count, and a list in the details, of the targets that aren't live and that sources currently link to.

## 11. Non-goals

- Links to URLs that never existed (typos), and links to the old URL of a target republished at a new one – the job of a broken-link checker.
- External links, links to media files, multisite cross-site links.
- Links rendered outside the hooks in §4.4: comments (`comment_text`), custom fields printed by the theme (ACF and the like), term and author descriptions, and themes that echo `get_the_content()` unfiltered. Each is a one-line hook to add if a site needs it.
- Purging other URLs that show a source's content (§7).
- Bogo's prefixed variant of the default locale (§4.1).
- Built-in support for multilingual plugins other than Bogo and Polylang; they connect through the hooks in §8. With Polylang Pro's shared slugs across language domains, two pages can share a path on different hosts, which the plugin cannot tell apart.
- Creating, reading or editing redirect rules of other plugins.
- E-mail rendering (newsletters render outside these hooks).
- Classic-editor (TinyMCE) markers. Add them only if a site needs them.
- Any cache-vendor code.

## 12. Compatibility and platform

- **Platform.** PHP 8.4 floor, as in the sibling repositories. WordPress 6.9 floor: the navigation filter in §4.6 needs 6.8, and the parser in §4.5 follows a 6.9 precedent. `kntnt-ai-visibility` requires 6.7, so record the reason in an ADR, together with the PHP floor.
- **Editor code.** Plain ES2022 against the `wp.*` globals, with no build step, as the coding standard's `js/` directory describes.
- **Themes.** Block themes and classic themes.
- **Multilingual.** Sites with one language, sites with Bogo and sites with Polylang are supported and tested. Would-be permalinks get their language from the multilingual plugin's own permalink filters; what the plugin cannot see by itself – a post that changes language, the hosts of languages on domains of their own – comes in through the two hooks for language plugins (§8). Built-in adapters, `Language_Plugins`, connect Bogo (post meta `_locale` and `_original_post`) and Polylang (the `language` taxonomy, language and URL settings, language domains) through those same hooks; any other multilingual plugin, WPML among them, connects through them from its own code or a site adapter (docs/adr/0010).
- **Page caches.** The logged-in variant of a private target's link relies on logged-in requests not being page-cached, which is the norm. State this in the docs.
- **Translations.** English source strings with a Swedish (`sv_SE`) translation shipped, for PHP and JavaScript.

## 13. Acceptance criteria

Each line is a test the builder writes first, marked with its level: **[unit]** Pest with Brain Monkey, **[e2e]** WordPress Playground driven over HTTP, **[manual]** checked at rollout (§15). Playground runs a single worker and drives no browser, so cron, loopback requests and the editor UI are tested through the proxies named below. The statuses are those of §3.

- **[e2e]** A published page linking to a draft, pending, scheduled or trashed page renders the link text without `<a>`, for an anonymous visitor and for an administrator.
- **[e2e]** Publishing the target makes every one of those links render as links again, with no change to the stored content of any source.
- **[e2e]** A link to a private page is a link for a user with `read_post` and plain text for an anonymous visitor.
- **[e2e]** A link to a password-protected published page stays a link.
- **[e2e]** A trashed target is matched through its original URL; restoring it (draft) keeps the links unlinked until it is published.
- **[e2e]** When an unpublished target's slug changes, links to its last public URL and to its new would-be permalink are both unlinked.
- **[e2e]** Deleting a target permanently creates a tombstone. The URL answers 410, links by URL and by ID form stay unlinked, and the Site Health test – called through its REST endpoint or callback, not the UI – lists the sources.
- **[e2e]** The unpublished URL answers 404 to an anonymous visitor and is not guess-redirected to a similarly named page; an administrator gets the preview with 200.
- **[e2e]** The request state, the body class and the notice block say `unpublished` for a once-published target, nothing for a never-published draft or a private page, and `deleted` for a tombstone.
- **[e2e]** Activation seeds the last public URL of an unpublished target with a slug and a set `post_date_gmt`, and of no other.
- **[unit]** An icon-only link is dropped, an image link keeps its image, button-class links are dropped and the containers a drop empties are removed.
- **[e2e]** Buttons and navigation items linking to an unpublished target are dropped; a submenu parent keeps its children with a placeholder label; a navigation link to a private page shows for a user who can read it.
- **[e2e]** A marked span containing a link to an unpublished target disappears with its text; with a live target only the span element goes.
- **[e2e]** A marked block that contains a link to an unpublished target – in itself or in an inner block – renders nothing, including its icon, heading and text. With a live target it renders unchanged. Its siblings in a grid are unaffected, and an outer marked block is dropped whole.
- **[e2e]** A block marked by hand under Additional CSS class(es) behaves like one marked with the toggle. **[manual]** The block-inspector toggle adds and removes the marker class in `className`.
- **[e2e]** Bogo and Polylang, each with Swedish as the default language and English under `/en/`: an English target's link with the `/en/` prefix is matched; Swedish and English sources are each handled; a draft switched to English takes effect at once.
- **[e2e]** A third-party permalink filter that fires `kntnt_unlink_unpublished_permalinks_changed` has its moved URL matched at once; a host added through `kntnt_unlink_unpublished_hosts` is same-site.
- **[unit]** Relative, protocol-relative, `www.`/bare-host, `http`/`https`, trailing-slash, percent-encoded, query-string and fragment variants match, as do `?page_id=` with extra parameters; document-relative hrefs, the home URL, the posts page and archive paths do not.
- **[e2e]** Unlinking applies to REST `content.rendered` and feeds, not only to the HTML page.
- **[e2e]** A status change fires `kntnt_unlink_unpublished_purge_urls` with the linking sources and the target's URL. A link in a template part or navigation makes it fire `kntnt_unlink_unpublished_purge_all` instead. An ordinary save of a published page fires neither, emptying the trash fires `purge_urls` with the target's URL only, and a bulk change fires `purge_all` once. A test MU plugin records the actions.
- **[e2e]** Cron publishing of a scheduled target fires the purge action: a test endpoint calls `wp_publish_post()`, the function the cron run calls. Real cron scheduling is not tested.
- **[unit]** The redirect check, with `pre_http_request` mocked: a 3xx is recorded, a 200 clears the record and a failed request records "could not check". **[e2e]** Site Health reports a seeded record.
- **[e2e]** The editor stylesheet carries the selectors for unpublished, private and deleted targets and is enqueued for the editor, and saving a post through REST leaves its content unchanged. **[manual]** The markers show in the editor.
- **[unit]** A fragment without candidates is returned byte-identical without being parsed, and a changed fragment keeps the bytes of everything but the elements unlinked or dropped.

## 14. Building it

- **Repository layout.** Scaffold the repository after `kntnt-ai-visibility`, the newest sibling, at `~/Projects/kntnt-ai-visibility`. That covers:
  - `AGENTS.md` and a `CLAUDE.md` symlink;
  - `docs/agents/` with the coding, writing, testing and releasing standards – copy and adapt them, do not reinvent them;
  - `GLOSSARY.md`, `docs/adr/`, `docs/EXTENSIBILITY.md`;
  - the PSR-4 `classes/` layout with `Pascal_Snake_Case` class names;
  - `install.php` and `uninstall.php`; uninstall removes the tombstone option, the redirect-check records, the last-public-URL post meta, transients and cron events;
  - the GitHub-release updater and the release scripts.
- **Testing.** Follow that repository's testing approach: Pest unit tests plus WordPress Playground end-to-end tests, with no DDEV fallback. If Playground cannot exercise a behaviour beyond the proxies in §13, stop and raise it.
- **Documentation.** British English, spaced en-dashes, no Oxford comma, one physical line per paragraph.
- **Before going outside the machine.** Ask Thomas before creating the GitHub repository or publishing a release.

## 15. Rollout on the first site (after the build – not part of it)

The deploying agent does this on www.elfsborgsmarschen.se, operated through the Novamira CLI (`novamira --site www.elfsborgsmarschen.se`):

1. Install and activate the plugin. Activation seeds the last public URL of the 30 unpublished pages, which have kept both slug and `post_date_gmt` (§5.2).
2. Add a site MU plugin that listens to the two actions in §7: CLP Varnish Cache purges, plus `kntnt_ai_visibility_indirect_content_changed`.
3. Delete the 30 Slim SEO redirects whose note starts with "Avpublicerad 2026-10-20" (option `ss_redirects`). The pages then answer 404 through the plugin instead of 302.
4. Apply the drop marker to the reference sentences. In Swedish: "Läs mer under Nödkontakt." on Hundar and "Se Logi och mat." twice on Packlista. In English: "Read more under Emergency contact." on Dogs and "See Accommodation and meals." twice on Packing list. The other links to Banor/Tracks are plain inline links and unlink as they are.
5. Replace the manual hiding of cards on the landing pages Praktiskt (page 1140) and Practical (page 1238):
   - remove the Block Visibility `blockVisibility.hideBlock` flags from the seven cards hidden on 2026-10-20 on each page;
   - give **all 14 cards on each page** the block drop marker – the `tertiary` card groups in the grid.

   Unpublishing any subpage then hides its card by itself, and republishing brings it back.
6. Purge Varnish completely. CLP Varnish Cache does not purge when content is saved, and its cache lives for seven days, so steps 3–5 are not visible until it is purged.
7. Verify:
   - for an anonymous visitor, the former 302 URLs answer 404;
   - for a logged-in administrator, the former 302 URLs give the preview with 200;
   - for both, Praktiskt and Practical show exactly the cards of published subpages, and no rendered page links to a target that isn't live;
   - the editor markers show;
   - Site Health is clean;
   - publishing and unpublishing a test page purges Varnish.
