# Releases are made by /release, with a stable-named asset

Supersedes [ADR-0005](0005-automated-tag-release-stable-asset.md).

Releases are made with Kntnt's `/release` Skill, which releases every Kntnt project the same way (Kntnt/skills ADR-0246). It promotes `[Unreleased]` in `CHANGELOG.md`, bumps the `Version:` header of `kntnt-unlink-unpublished.php`, commits and pushes, pushes the tag `vX.Y.Z` and creates the GitHub release with the changelog section for `X.Y.Z` as its notes. It then empties `release-assets/`, runs `bin/build-release-assets` and uploads every file the build leaves there. The build never publishes.

The tag-triggered workflow `.github/workflows/release.yml` that ADR-0005 introduced is removed. It created the release on every pushed version tag, so it raced `/release` to create the same release. Keeping the workflow and letting `/release` only push the tag was rejected: the plugin would be released differently from every other Kntnt project, which is the drift the shared convention removed.

The rest of ADR-0005 stands:

- **The asset is named `kntnt-unlink-unpublished.zip`**, without a version, so `…/releases/latest/download/kntnt-unlink-unpublished.zip`, which the README links to, always resolves to the newest release. The `Updater` finds the asset by content type, not by name, so it needs a zip but not that name.
- **Tags carry a `v` prefix** (`vX.Y.Z`); the `Version:` header carries the bare version.
- **The header must match the tag.** `bin/build-release-assets` refuses to build a tagged `HEAD` whose header has another version than the tag without its `v`, so a release cannot ship a zip that WordPress reads as another version.
- **Release notes are the `CHANGELOG.md` section** for the version, now taken by `/release`.
- **The zip holds only the runtime keep-list** in `bin/build-release-assets` and ships no `vendor/`, since the plugin has no runtime dependencies.

## Consequences

- Pushing a version tag by hand publishes nothing.
- Between `gh release create` and the upload, the newest release has no zip. The `latest/download` link answers 404 in that window, and the `Updater` skips the update rather than offering a package that doesn't exist. A site that checked in the window caches that release for six hours (ADR-0003), so it is offered the update up to six hours later than it would otherwise be. Both are harmless.
- `bin/build-release-assets`, or `composer build`, builds the same zip by hand from the committed state at `HEAD` and writes it to `release-assets/`.
