# Link surgery through WordPress's HTML API, spliced byte by byte

The spec offered `Dom\HTMLDocument` or `WP_HTML_Processor` for unlinking and dropping elements, and requires that only the bytes of the elements unlinked or dropped change (docs/spec.md §4.2). `Dom\HTMLDocument` re-serialises a whole fragment – `&#038;` becomes `&amp;`, `<br />` becomes `<br>`, attributes are re-quoted – so it cannot meet that requirement. The surgery is therefore done on the HTML API: a subclass reads the byte span of each opener and closer through a short-lived bookmark and splices the cuts into the processor's `lexical_updates`, the technique core uses in `WP_Block::get_block_bindings_processor()` and the Interactivity API's directives processor.

The walk itself (`Link_Surgery`) is shared by two surgeons: `Tree_Surgeon`, built on `WP_HTML_Processor`, parses as a browser does and is used first; `Lexical_Surgeon`, built on `WP_HTML_Tag_Processor` with its own stack of open elements, takes over when the HTML Processor refuses a fragment (it does, for example, on foster-parented table content). Tokens the tree builder invents – implied closers, reconstructed formatting elements – have no span and are never cut.

## Consequences

- A fragment without a drop marker or a link that isn't live is never parsed; one that is parsed but needs no cut is returned exactly as it came in.
- Every surgery test runs against both surgeons.
- Removing an element is a splice of its opener and closer (unlink) or of its whole range (drop); cuts inside a dropped range are discarded.
