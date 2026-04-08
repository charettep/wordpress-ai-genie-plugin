# Deep Research Plan

Build a first-release Deep Research system as a dedicated wp-admin product inside AI Genie, with OpenAI deep research as the execution engine and full WordPress-native management around jobs, sources, OAuth-linked tools, batch submissions, and report publishing. The implementation should treat deep research as a durable background workflow with typed Responses output, not as a synchronous text-generation call and not as a Gutenberg sidebar feature.

## Scope
- In: a dedicated `AI Genie -> Deep Research` admin page, full OAuth login/token refresh ownership for remote MCP/connectors, direct CSV/JSONL batch uploads, batch-from-preset workflows, optional background runs, webhooks, vector stores, code interpreter, citations/audit views, and `Create post/page from report` publishing actions.
- Out: Claude/Ollama deep research parity, Zapier-specific integration, generic arbitrary MCP write-action support, and any claim that background deep research is ZDR-compatible.

## Action items
- [ ] Add a versioned install/upgrade system so the next major release can safely create custom Deep Research tables, persist upgrade state, and remain compatible with normal WordPress upload/install/update flows.
- [ ] Introduce a dedicated Deep Research admin controller with its own submenu/page, assets, and route-specific UI state instead of extending the current tabbed settings page in place.
- [ ] Create a Deep Research data model with separate storage for site settings, OAuth clients/accounts/tokens, research runs, batch jobs, source presets, vector store mappings, MCP server definitions, report publishing records, and normalized response output items.
- [ ] Extend the OpenAI layer into a Deep Research service that can create/retrieve/cancel Responses, build deep-research tool payloads, manage vector stores/files, manage code interpreter containers/files, and create/retrieve/cancel Batch jobs.
- [ ] Implement full OAuth ownership for supported remote MCP servers/connectors: admin UI to register client credentials and scopes, login/callback endpoints, encrypted token persistence, refresh scheduling, revocation/error recovery, and auth health.
- [ ] Add Deep Research REST endpoints for run creation/status/cancel, batch upload/create/import, source CRUD, OAuth connect/refresh/disconnect, vector store ingestion/status, report publishing, and webhook ingestion.
- [ ] Orchestrate execution around background Responses when enabled, with verified webhook completion when possible and WP-Cron/admin polling fallback where needed.
- [ ] Build the source-management UI around documented platform limits: web search domain allow-lists up to 100 domains, file search with at most two attached vector stores per run, deep-research-compatible remote MCP limited to `search` and `fetch`, and code interpreter container controls with memory tiers.
- [ ] Add dual batch ingestion modes: direct CSV/JSONL upload in wp-admin and batch creation from saved Deep Research prompt/source presets.
- [ ] Build the report workspace around typed response items and publishing actions: show final `message` text with clickable citations, consulted sources, tool traces, file citations, MCP audit details, and `Create post` / `Create page` actions that create draft content only.
- [ ] Harden privacy, failure handling, and docs: warn that background mode is not ZDR-compatible, surface third-party retention risk for MCP/connectors, validate and minimize logged auth data, handle stale OAuth tokens and container expiry, and document self-hosted requirements for webhooks/OAuth callbacks.
- [ ] Verify and ship end to end: test schema upgrades, OAuth connect/refresh flows, webhook fallback behavior, vector store ingestion, deep research run rendering, batch upload/import, and post/page publishing; then bump the plugin version, build the release zip, create the GitHub release, and confirm install/update/activation through standard wp-admin upload still works.
