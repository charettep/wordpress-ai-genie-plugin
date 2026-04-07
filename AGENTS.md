# AGENTS Instructions

These instructions apply to the WordPress plugin in this repository.

## Release Workflow Requirement

Whenever the user asks to patch the plugin or fix/update it, do not stop at the code change. Always complete the full release workflow in this repository:

1. Bump the plugin version in `/home/p/Desktop/ai-content-forge/ai-content-forge-plugin/ai-content-forge.php`.
2. Update `/home/p/Desktop/ai-content-forge/ai-content-forge-plugin/README.md` to reflect the change.
3. Commit the changes and push them to the GitHub `main` branch.
4. Build the new release package with `/home/p/Desktop/ai-content-forge/ai-content-forge-plugin/build-release.sh`.
5. Create a new GitHub release for that version and attach the generated zip package.

## Notes

- Keep the version header and `ACF_VERSION` constant in `ai-content-forge.php` in sync.
- The release zip is produced in the repository root as `ai-content-forge-v<version>.zip`.
- Treat this release workflow as part of the task unless the user explicitly changes the requirement.
