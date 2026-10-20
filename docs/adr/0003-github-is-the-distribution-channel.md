# GitHub, not wordpress.org, is the distribution channel

As for its siblings, the plugin is distributed through GitHub releases, with self-updates through the GitHub-release `Updater` ported from `kntnt-ai-visibility`. No wordpress.org machinery is added – no `readme.txt`, no plugin-page assets, no SVN deploy.

## Consequences

- The `Updater` is wired in `Plugin`; it reads the repository from the Plugin URI header and caches the release check for six hours. Unlike the sibling it has no filter for that time, since the spec allows no hooks beyond those it lists (docs/spec.md §8).
- A move to wordpress.org would be a separately scoped effort.
