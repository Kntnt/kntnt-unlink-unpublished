# Kntnt Unlink Unpublished

[![Requires WordPress: 6.9+](https://img.shields.io/badge/WordPress-6.9+-blue.svg)](https://wordpress.org)
[![Requires PHP: 8.4+](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://php.net)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

A WordPress plugin that renders links to unpublished pages as plain text – and brings them back by themselves when the pages are published again, without changing a byte of stored content.

## Description

Editors link from page to page. When a page is unpublished – a draft, waiting for review, scheduled, private, trashed or deleted – every link to it leads to a 404. The usual fixes are poor: editing every link by hand and restoring it later, or a broken-link tool that strips links from the content for good.

Kntnt Unlink Unpublished decides at render time instead. **A link stays a link only if following it would show the page to the public – or, for a private page, to the visitor who sees it.** Otherwise the link is rendered as plain text, or left out where the element is nothing but a link. The stored content is never changed, so when the page is published again every link to it is back at once.

### Features

- **Links follow the page's state.** Inline links become plain text; an image link keeps its image; buttons, icon-only links and navigation items are left out; a submenu keeps its children. Live links are left exactly as they were, byte for byte.
- **Every form of a link.** Absolute links on either the `www.` or the bare host, `http` or `https`, protocol-relative and root-relative links, with or without a trailing slash, query or fragment, and `?page_id=` links, also an editor's pasted preview links.
- **One language or many.** Sites with one language, with Bogo and with Polylang work out of the box, language prefixes such as `/en/` included. Any other multilingual plugin connects through two hooks.
- **Text and blocks that exist only for a link.** Mark a sentence like "Read more under Emergency contact." with **Hide if link target is unpublished**, or a whole card with the block toggle **Hide block if link target is unpublished**, and it disappears together with its link.
- **Everywhere WordPress renders content.** Blocks, templates, template parts, navigation, synced patterns, classic content, excerpts, widgets, classic menus, REST `content.rendered`, feeds and the Markdown alternates of Kntnt AI Visibility.
- **The right answer at the page's own address.** An unpublished page answers 404 – without WordPress guessing a similarly named page to redirect to – and a deleted page answers 410 Gone. A theme can tell visitors that the page has been taken down, through a block, a body class or a function.
- **Editors see what happens.** In the block editor and the site editor, links to unpublished, private and deleted pages are marked, each differently. Site Health lists the links to deleted pages, the published pages whose address still redirects, and the links currently hidden.
- **Caches are told what changed.** Two actions announce which pages render differently after a status change; the plugin itself has no code for any particular cache.

There is no settings page. See [`docs/spec.md`](docs/spec.md) for the complete behaviour.

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.4 |
| WordPress | 6.9 |

## Installation

1. [Download the latest release ZIP](https://github.com/Kntnt/kntnt-unlink-unpublished/releases/latest/download/kntnt-unlink-unpublished.zip).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**, choose the ZIP and install it.
3. Activate the plugin.

The plugin updates through the standard WordPress update screen from its GitHub releases. On activation it remembers the address of every unpublished page that looks as if it has been published before, so that links to it are hidden and its address can say that the page is temporarily unavailable.

## Usage

Nothing needs to be configured: as soon as the plugin is active, links to pages that aren't published are rendered as plain text.

### Text and blocks that exist only for a link

Some content only points at another page and reads as a dead end without the link.

- **A sentence.** Select it in the editor and choose **Hide if link target is unpublished** in the rich-text toolbar. The sentence disappears with its link while the page is unpublished.
- **A block, such as a card.** Select the block and switch on **Hide block if link target is unpublished** under **Advanced** in the block settings. The whole block disappears while a link inside it isn't live; the grid around it closes up.

In the editor, a marked sentence has a dashed outline; a marked block has a dashed outline and a badge that says **Hidden now** while it is hidden.

### Telling visitors

Add the block **Unavailable page notice** to a block theme's 404 template and put the message inside it, for example "This page is temporarily unavailable". Choose whether it shows on the address of an unpublished page, a deleted page or both. Classic themes can use the body classes `kntnt-unlink-unpublished-unpublished` and `kntnt-unlink-unpublished-deleted` or the function `kntnt_unlink_unpublished_request_state()`.

### Page caches

Connect your page cache to the plugin's two actions in your site's own plugin; [`docs/EXTENSIBILITY.md`](docs/EXTENSIBILITY.md) has worked examples for CLP Varnish Cache and Kntnt AI Visibility.

## Development

```bash
composer install
bash run-tests.sh
```

See [`CONTRIBUTING.md`](CONTRIBUTING.md). The plugin is built from [`docs/spec.md`](docs/spec.md); the decisions behind it are in [`docs/adr/`](docs/adr/) and its language in [`GLOSSARY.md`](GLOSSARY.md).

## Licence

[GPL-2.0-or-later](LICENSE).
