# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/). While the major version is `0`, the project makes no backwards-compatibility commitments: breaking changes can land in any release.

## [Unreleased]

### Added

- Redirects on a 404 to a page that isn't live are vetoed, and the address answers with its own 404 or 410 through the theme's 404 template. This covers Slim SEO's automatic redirection, which sent a page that had been in the trash to its ID form – or a draft's ID form to itself – and WordPress's old-slug redirect for a post renamed while published and later unpublished. With debug logging on, each veto is written to the debug log.

## [0.1.0] – 2026-10-20

### Added

- Links to unpublished, private and deleted pages render as plain text at render time, or are left out where the element is nothing but a link, and come back when the pages are published – without changing stored content.
- Drop markers for text and blocks that exist only for their links: a rich-text format and a block toggle.
- 404 without guess redirects at the address of an unpublished page, 410 at the address of a deleted one, and the request state for themes through a function, body classes and the block **Unavailable page notice**.
- Last public URLs, recorded when a page is unpublished and seeded on activation, and tombstones for deleted pages.
- Editor markers for links to unpublished, private and deleted pages.
- Site Health tests for links to deleted pages, published pages whose address redirects and links currently hidden.
- The actions `kntnt_unlink_unpublished_purge_urls` and `kntnt_unlink_unpublished_purge_all` for page caches.
- Support for sites with one language, with Bogo and with Polylang, and two hooks through which any other multilingual plugin connects: the action `kntnt_unlink_unpublished_permalinks_changed` and the filter `kntnt_unlink_unpublished_hosts`.
- Swedish translation.
