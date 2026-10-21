---
status: superseded by ADR-0012
---

# Releases are automated on version tags, with a stable-named asset

Superseded by [ADR-0012](0012-releases-made-by-release-stable-asset.md).

Pushing a version tag (`vX.Y.Z`) runs `.github/workflows/release.yml`, which checks that the `Version:` header matches the tag without its `v`, and runs `build-release-zip.sh` to build the zip and attach it to the release. The asset is named `kntnt-unlink-unpublished.zip`, without a version, so `…/releases/latest/download/kntnt-unlink-unpublished.zip` always resolves to the newest release. Release notes come from the matching `CHANGELOG.md` section. The same script builds a local zip for testing.

## Consequences

- The plugin has no runtime dependencies and loads its classes with its own autoloader, so the zip ships no `vendor/` and the build runs no Composer.
- The zip holds only the runtime keep-list: the main file, `autoloader.php`, `classes/`, `blocks/`, `js/`, `languages/`, `install.php`, `uninstall.php`, `README.md` and `LICENSE`.
- The `Updater` identifies the asset by content type, not by name.
