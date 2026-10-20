# Multilingual plugins connect through two hooks; Bogo and Polylang are built in

The plugin supports sites with one language, with Bogo and with Polylang. A multilingual plugin's own permalink filters already put a language into would-be permalinks ([ADR-0009](0009-last-public-urls-and-would-be-permalinks.md)), so what the plugin needs from it is narrow: to hear when permalinks change in a way it cannot see – a post that changes language, a language whose prefix or domain changes – and which hosts serve the site's languages. Two hooks carry exactly that: the action `kntnt_unlink_unpublished_permalinks_changed`, which rebuilds the index, and the filter `kntnt_unlink_unpublished_hosts`, which adds same-site hosts.

The built-in support is written against those hooks rather than beside them. `Language_Plugins` fires the action when Bogo writes `_locale` or `_original_post`, when Polylang assigns a term in its `language` taxonomy and when Polylang adds, updates or deletes a language or changes its settings, and it adds the hosts of Polylang's languages for languages on domains of their own. Any other multilingual plugin – WPML among them – connects the same way, from its own code or a site adapter. The alternatives were adapter code per plugin with no public seam, or detection heuristics such as rebuilding on every term change, which would rebuild the index on every save of every post.

## Consequences

- Targets are read with SQL (`Targets::find()`), not with `WP_Query`: Polylang and WPML restrict every query to the current language, and `suppress_filters` does not stop them, so an index built during a request in one language would miss the others.
- Path keys ignore the host, so with Polylang Pro's shared slugs across language domains two pages can share a key; that case is not supported.
- The hosts filter is applied once per request, on first use, because the plugin is constructed before multilingual plugins set up their languages.
