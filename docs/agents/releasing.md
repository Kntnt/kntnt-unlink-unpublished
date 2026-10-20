Read when cutting a release.

Automated ([ADR-0005](../adr/0005-automated-tag-release-stable-asset.md)). Bump the `Version:` header in `kntnt-unlink-unpublished.php` to `X.Y.Z`, prepare the matching `## [X.Y.Z]` heading in `CHANGELOG.md`, commit, then create and push the tag `vX.Y.Z`. `.github/workflows/release.yml` strips the tag's leading `v` before comparing it with the header, runs `build-release-zip.sh` and publishes `kntnt-unlink-unpublished.zip` – the version-less name keeps the `latest/download` URL stable. The script takes the release notes from the matching changelog section. The plugin has no runtime dependencies, so the build ships no `vendor/`; it excludes tests, CI and dev files.

Ask Thomas before creating the GitHub repository or publishing a release.
