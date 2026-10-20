# Navigation: work with core, which decides first

Core renders nothing for a `core/navigation-link` or `core/navigation-submenu` whose post isn't published (docs/spec.md §4.6). The spec proposed removing a submenu's post ID in `render_block_data`, but the navigation block renders its items with `$inner_block->render()`, which applies neither `render_block_data` nor `pre_render_block` – only `render_block`. So:

- **Private pages.** The filter `render_block_core_navigation_link_allowed_post_status` adds the target's status when the target is live for the visitor, so a reader of a private page sees its link.
- **Submenus.** When core has rendered a submenu as nothing and its post isn't published, `render_block` renders a clone of the block instance without the post ID – the clone keeps the block context – and the label becomes a placeholder link while the children stay.
- **Dropped items.** The navigation block closes its list at every item that renders as nothing and opens a new one at the next, so an item dropped in the middle would split the navigation in two lists. `block_core_navigation_render_inner_blocks` takes the links that won't render out of the list beforehand – those core would refuse and those the plugin would drop.

## Consequences

- A refused submenu is rendered twice; its children are cheap to render again.
- Items nested in a submenu are rendered by the submenu itself, without the list-splitting logic, and are dropped in `render_block`.
