# Extensibility

Kntnt Unlink Unpublished is zero-config; everything a site can adjust goes through the hooks below. Cache-specific code belongs in the site's own plugin – typically an MU plugin – never in this plugin (docs/spec.md §1.5). The terms are those of `GLOSSARY.md`.

## Cache actions

When a target's live state changes, the rendered HTML of every source that links to it changes too, although no one edited those sources. The plugin cannot purge a cache itself; it announces what changed. Both actions fire on `shutdown`, after the change is committed, and only when something changed for anonymous visitors – never on an ordinary save of a published page.

| Action | Arguments | Contract |
|---|---|---|
| `kntnt_unlink_unpublished_purge_urls` | `string[] $urls`, `\WP_Post $target`, `string $change` | The listed URLs now render differently. `$urls` holds the permalinks of the published sources that link to the target and the target's own URLs when their response code changed (200, 404, 410). `$change` is the target's new state – `published`, `private`, `unpublished` (trash included) or `deleted` – or `moved` for a slug or parent change while unpublished. Fires once per target per request. |
| `kntnt_unlink_unpublished_purge_all` | `?\WP_Post $target`, `string $change` | Site-wide output changed: a template part, a navigation, a synced pattern, a classic menu or a widget links to the target, so the whole page cache should be purged. Fires at most once per request and replaces that request's `purge_urls`. When more than one target caused it, `$target` is `null` and `$change` is `multiple`. |

What the actions do not cover:

- **Other URLs that show a source's content.** `purge_urls` carries permalinks only. Archives that show full content, feeds, REST responses and Markdown alternates are not listed; an adapter that needs them widens the purge itself, for example with a tag-based purge.
- **Logged-in pages.** A private page's link is live only for visitors who can read it, which relies on logged-in requests not being page-cached – the norm for page caches.
- **Ordinary edits.** Saving a source is the cache plugin's own business; these actions only cover changes the cache cannot see.

## Filters

Add a hook only when a real need appears; these are all there is (docs/spec.md §8).

| Filter | Value | Default |
|---|---|---|
| `kntnt_unlink_unpublished_post_types` | `string[]` – the post types whose posts are targets | public post types except `attachment` |
| `kntnt_unlink_unpublished_is_live` | `bool $live`, `\WP_Post $target`, `int $user_id` – whether a link to the target is live for the user | the status matrix: live for a private target the user can `read_post`, otherwise not |
| `kntnt_unlink_unpublished_site_wide_post_types` | `string[]` – source post types whose links make a change call for `purge_all` | `wp_template`, `wp_template_part`, `wp_navigation`, `wp_block` |
| `kntnt_unlink_unpublished_status_code` | `int $code`, `string $state`, `?\WP_Post $target` – the status code of a request for an unpublished or deleted URL | 404 for `unpublished`, 410 for `deleted` |
| `kntnt_unlink_unpublished_hosts` | `string[]` – hosts, with an optional port, whose absolute URLs count as the same site | the home host; every host comes with its `www.` twin |

- **`is_live` reaches only targets in the index.** The fast path looks hrefs up in the index of targets that aren't published, so the filter can make an unpublished or private target live but can never hide a published one – it is never asked about one.
- **`status_code` is called only for a request state.** That is `unpublished` for the 404 at the address of a page that has been public and `deleted` at a tombstone. A 503 during a short planned absence is a typical use; a never-published or private page's address keeps WordPress's plain 404.

## Multilingual plugins

Sites with one language, with Bogo and with Polylang work out of the box. Any other multilingual plugin – WPML, TranslatePress or one of your own – connects through two hooks. Its permalink filters already give pages their language prefix or domain; the plugin only needs to hear what it cannot see for itself.

| Hook | Kind | Contract |
|---|---|---|
| `kntnt_unlink_unpublished_permalinks_changed` | action the plugin listens to, no arguments | Fire it after something changed permalinks in a way the plugin cannot see: a post's language, a language's prefix or domain, a custom permalink. The plugin rebuilds its index, so links to the new URLs are hidden at once. |
| `kntnt_unlink_unpublished_hosts` | filter | Add the hosts that serve the site's languages, when languages live on domains or subdomains of their own. Applied once per request, on first use. |

The built-in adapters use these same hooks: Bogo's post meta `_locale` and `_original_post`, and Polylang's `language` taxonomy, language settings and language domains (docs/adr/0010).

### Connecting another multilingual plugin

```php
<?php
/**
 * Tells Kntnt Unlink Unpublished about a multilingual plugin that keeps its
 * languages in a table of its own and serves English from en.example.com.
 */

declare( strict_types = 1 );

// The plugin's own action after it changes a post's language – the name is an
// example; use the action your multilingual plugin fires.
add_action( 'my_languages_post_language_changed', static fn() => do_action( 'kntnt_unlink_unpublished_permalinks_changed' ) );

// The English domain serves the same site.
add_filter( 'kntnt_unlink_unpublished_hosts', static fn( array $hosts ): array => [ ...$hosts, 'en.example.com' ] );
```

## Theme integration

The state of a request for an unpublished or deleted page reaches the theme in three ways. Each says `unpublished` only for a page that has been public, so a draft or private page that never was public gives a plain 404 that reveals nothing.

- **`kntnt_unlink_unpublished_request_state(): ?string`** – `'unpublished'`, `'deleted'` or `null`.
- **Body classes** – `kntnt-unlink-unpublished-unpublished` or `kntnt-unlink-unpublished-deleted`.
- **The block `kntnt-unlink-unpublished/notice`** – a container whose inner blocks render only when the request state matches its `when` attribute (`unpublished`, `deleted` or `both`). Place it in a block theme's 404 template to say "This page is temporarily unavailable" without code.

## Redirect plugins

A redirect on a 404 to a target that isn't live is vetoed, and the request is answered with its own 404 or 410 – the theme's 404 template, the request state, the body class and the notice block included. The plugin judges where a redirect goes, not who issued it, so redirect plugins need no adapter (docs/adr/0011).

- **Vetoed:** a redirect through `wp_redirect()` or `wp_safe_redirect()`, issued while answering a front-end 404, to a same-site URL of a target that isn't live for the visitor – its would-be permalink, its last public URL, a tombstone URL or an ID form. Core's old-slug redirect is stopped the same way, through `old_slug_redirect_post_id`.
- **Still wins:** a redirect to a live page, the home page or another site, and any redirect on a request that isn't a 404, such as a published page's own redirect rule. A redirect sent with a raw `header( 'Location: …' )`, or issued before the main query is parsed, is not seen.

The known case is Slim SEO's **Automatic redirection**, which is on by default. It records a page's old permalink whenever the page's slug changes – moving a page to the trash changes it – and on a 404 at that address sends the visitor to the page's current permalink, which for a page that isn't published is its ID form. Without the veto, a page that has been in the trash once answers 301 to a 404 every time it is unpublished, and a draft's ID form redirects to itself.

With `WP_DEBUG` and `WP_DEBUG_LOG` on, each veto writes one line to the debug log with the request URI, the location and the status code. Nothing is stored and nothing is reported in Site Health.

## Drop markers

An element carrying the class `kntnt-unlink-unpublished-drop` is dropped with everything in it when a link inside it isn't live. Editors set it with the rich-text format **Hide if link target is unpublished** or the block toggle **Hide block if link target is unpublished**; a theme or a pattern can also write the class directly – on a block under Advanced → Additional CSS class(es), or on any element in classic content.

## Worked examples

### CLP Varnish Cache

CLP Varnish Cache purges only on upgrades, theme switches and plugin (de)activation, so a site behind it needs this adapter in an MU plugin. Its manager class purges a URL, a host or a tag; its own full purge is a purge of the host and of the site's tag prefix.

```php
<?php
/**
 * Purges Varnish when Kntnt Unlink Unpublished announces changed output.
 */

declare( strict_types = 1 );

add_action(
	'kntnt_unlink_unpublished_purge_urls',
	static function ( array $urls ): void {
		if ( ! class_exists( 'ClpVarnishCacheManager' ) ) {
			return;
		}
		$manager = new ClpVarnishCacheManager();
		if ( ! $manager->is_enabled() ) {
			return;
		}
		foreach ( $urls as $url ) {
			try {
				$manager->purge_url( $url );
			} catch ( \Throwable $e ) {
				error_log( 'Varnish purge failed for ' . $url . ': ' . $e->getMessage() );
			}
		}
	},
);

add_action(
	'kntnt_unlink_unpublished_purge_all',
	static function (): void {
		if ( ! class_exists( 'ClpVarnishCacheManager' ) ) {
			return;
		}
		$manager = new ClpVarnishCacheManager();
		if ( ! $manager->is_enabled() ) {
			return;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( is_string( $host ) && $host !== '' ) {
			$manager->purge_host( $host );
		}
		$prefix = $manager->get_cache_tag_prefix();
		if ( $prefix !== '' ) {
			$manager->purge_tag( $prefix );
		}
	},
);
```

### Kntnt AI Visibility

The Markdown alternates and `llms.txt` of `kntnt-ai-visibility` render through `the_content` as an anonymous visitor, so they follow the visibility rule – but they are cached, and a target's status change does not touch their sources. Forward both actions to its indirect-change action, which takes no arguments and invalidates every alternate.

```php
<?php
/**
 * Rebuilds the Markdown alternates when links to a target appear or disappear.
 */

declare( strict_types = 1 );

foreach ( [ 'kntnt_unlink_unpublished_purge_urls', 'kntnt_unlink_unpublished_purge_all' ] as $action ) {
	add_action( $action, static fn() => do_action( 'kntnt_ai_visibility_indirect_content_changed' ) );
}
```

## See also

- `docs/spec.md` – the full behaviour, §7 for the cache contract.
- `GLOSSARY.md` – the domain terms.
