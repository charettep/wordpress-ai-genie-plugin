# CLAUDE Instructions

For this plugin repository, any request to patch, fix, or update the plugin must include the release process, not just the code edit.

Required workflow for every plugin patch/fix/update:

1. Increase the plugin version in `ai-content-forge.php`.
2. Update `README.md` for the shipped change.
3. Commit all relevant changes and push to the `main` branch on GitHub.
4. Run `./build-release.sh` to produce the new versioned zip package.
5. Publish a new GitHub release for that version and include the built zip asset.

Keep the plugin header version and `ACF_VERSION` synchronized.
