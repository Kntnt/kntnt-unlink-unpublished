# WordPress 6.9 as the minimum version

`kntnt-ai-visibility` requires WordPress 6.7, but this plugin requires **6.9**. Three features set the floor: the filter `render_block_core_navigation_link_allowed_post_status` (6.8) lets block navigation show private pages to those who may read them; `WP_HTML_Processor::get_current_depth()` (6.6) and subclassing the HTML Processor to splice through its lexical updates, which core itself does in 6.9 (`WP_Block::get_block_bindings_processor()`), carry the link surgery ([ADR-0006](0006-link-surgery-through-the-html-api.md)).

## Consequences

- The unit suite loads the HTML API of WordPress 6.9 (`johnpbloch/wordpress-core` 6.9.*), and CI runs the rendering suite on 6.9 as well as on the latest WordPress.
- Lowering the floor would mean dropping the navigation filter and re-checking the HTML Processor's behaviour on the older version.
