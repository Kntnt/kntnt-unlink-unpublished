Read when cutting a release.

Releases are made with `/release` ([ADR-0012](../adr/0012-releases-made-by-release-stable-asset.md)). It promotes `[Unreleased]` in `CHANGELOG.md`, bumps the `Version:` header in `kntnt-unlink-unpublished.php`, commits and pushes, pushes the tag `vX.Y.Z` and creates the GitHub release with the changelog section as its notes. It then runs `bin/build-release-assets` and uploads the `kntnt-unlink-unpublished.zip` it writes to `release-assets/` – the version-less name keeps the `latest/download` URL stable. No workflow runs on a tag, so a tag pushed by hand publishes nothing.

`bin/build-release-assets`, or `composer build`, builds the same zip by hand from the committed state at `HEAD` and never publishes. It refuses to build a tagged `HEAD` whose `Version:` header has another version than the tag without its `v`. The plugin has no runtime dependencies, so the zip ships no `vendor/`; it holds only the runtime files, leaving out tests, CI and dev files.

Ask Thomas before creating the GitHub repository or publishing a release.
