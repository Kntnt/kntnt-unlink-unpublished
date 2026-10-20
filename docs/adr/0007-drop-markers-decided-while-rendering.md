# A marked block is decided while it renders, not from its output

A block carrying the drop marker is dropped when a link inside it isn't live (docs/spec.md §4.3). Blocks render from the inside out, so by the time a marked group reaches `render_block`, the plugin has already unlinked or dropped the links of its inner blocks – checking the group's output would find nothing. `Content_Filter` therefore keeps a stack of the blocks being rendered, opened on `pre_render_block` and closed on `render_block`, and counts every link that isn't live as it meets it. A marked block is dropped when the count grew while it rendered. A link inside an inner marked block is counted for every marked block around it, so nesting needs no special case.

## Consequences

- Links inside dynamic blocks are counted too, because their output passes the same filters.
- Blocks that core renders without `pre_render_block` – the items of a navigation block, the posts of a query loop – get no frame of their own. A frame is closed only by a block of the same name, so they are never mistaken for the block on top. Such a block cannot itself be dropped by a marker.
- The same stack tells a buttons block or a list that a child was dropped, so it can go when nothing is left in it.
