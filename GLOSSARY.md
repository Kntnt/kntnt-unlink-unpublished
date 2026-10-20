# Kntnt Unlink Unpublished

The plugin decides at render time whether a link to another page on the same site stays a link, so that no visitor follows a link to a page they cannot see. This glossary fixes the language for links, the pages they point at and what the plugin does to them. It is a glossary, not a spec – decisions live in `docs/spec.md` and `docs/adr/`.

## Language

### Links

**Source**:
The post, template part, navigation menu or widget that contains a link.
_Avoid_: linking page, origin

**Target**:
The post a link points at.
_Avoid_: destination, linked page

**Live**:
A link is live for a visitor when following it would show its target to the public or, for a private target, to that visitor.
_Avoid_: active, valid, working

**Would-be permalink**:
The pretty URL an unpublished target will have once it is published, including any language prefix a multilingual plugin adds.
_Avoid_: draft URL

**Last public URL**:
The URL at which a target was last published.

### Target states

**Published**:
A target the public can see – behind its password form, if it has one.

**Private**:
A target published privately: only users allowed to read it can see it.

**Unpublished**:
A target that exists but that the public cannot see: a draft, pending review, scheduled, in a custom non-public status or in the trash.
_Avoid_: dormant, offline, inactive

**Deleted**:
A target removed permanently; only its tombstone remains.

**Tombstone**:
The record a deleted target leaves behind: its last public URL and its post ID.

### What happens to a link that isn't live

**Unlink**:
Render a link's content without its `<a>` element, so that the text or image stays.
_Avoid_: strip, delink

**Drop**:
Render nothing at all of an element: one that is nothing but a link, such as a button, a navigation item or an icon-only link, or one that carries a drop marker.
_Avoid_: remove, delete

**Drop marker**:
An editor's mark on a sentence or a block saying that it exists only for its links, so that it is dropped with everything in it when a link inside it isn't live.
_Avoid_: remove marker, remove span, reference text
