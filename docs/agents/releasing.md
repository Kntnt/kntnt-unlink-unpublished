Read when cutting a release.

Automated ([ADR-0005](../adr/0005-automated-tag-release-stable-asset.md)). Bump the `Version:` header in `kntnt-unlink-unpublished.php` to `X.Y.Z`, prepare the matching `## [X.Y.Z]` heading in `CHANGELOG.md`, commit, then create and push the tag `vX.Y.Z`. `.github/workflows/release.yml` strips the tag's leading `v` before comparing it with the header, runs `bin/build-release-assets` and publishes the `kntnt-unlink-unpublished.zip` it writes to `release-assets/` – the version-less name keeps the `latest/download` URL stable. The workflow takes the release notes from the matching changelog section. The plugin has no runtime dependencies, so the build ships no `vendor/`; it takes only the runtime files from the committed tree, leaving out tests, CI and dev files. Running `bin/build-release-assets` by hand builds the same zip from `HEAD`; it never publishes.

Ask Thomas before creating the GitHub repository or publishing a release.
