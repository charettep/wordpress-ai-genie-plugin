/* global aigDeepResearchAdmin */
document.addEventListener('DOMContentLoaded', () => {
    const config = window.aigDeepResearchAdmin || {};
    const restUrl = (config.restUrl || '').replace(/\/$/, '');
    const nonce = config.nonce || '';
    const i18n = config.i18n || {};

    const form = document.getElementById('aig-deep-research-form');
    const runsRoot = document.getElementById('aig-dr-runs');
    const refreshButton = document.getElementById('aig-dr-refresh-runs');
    const formStatus = document.getElementById('aig-dr-form-status');

    const sourceForm = document.getElementById('aig-dr-source-form');
    const sourceStatus = document.getElementById('aig-dr-source-status');
    const sourceList = document.getElementById('aig-dr-sources-list');
    const sourceOptions = document.getElementById('aig-dr-source-options');

    const vectorStoreForm = document.getElementById('aig-dr-vector-store-form');
    const vectorStoreStatus = document.getElementById('aig-dr-vector-store-status');
    const vectorStoreList = document.getElementById('aig-dr-vector-stores-list');
    const vectorStoreOptions = document.getElementById('aig-dr-vector-store-options');

    const webhookDetails = document.getElementById('aig-dr-webhook-details');

    let savedSources = [];
    let vectorStores = [];

    async function request(path, options = {}) {
        const response = await fetch(restUrl + path, {
            method: options.method || 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce,
            },
            body: options.body ? JSON.stringify(options.body) : undefined,
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error((data && data.message) || i18n.error || 'Request failed.');
        }

        return data;
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function checkedValues(root, name) {
        return Array.from(root.querySelectorAll(`input[name="${name}"]:checked`)).map((input) => input.value);
    }

    function collectFormData() {
        const data = new FormData(form);

        return {
            title: data.get('title') || '',
            prompt: data.get('prompt') || '',
            model: data.get('model') || '',
            max_tool_calls: Number(data.get('max_tool_calls') || 12),
            background: data.get('background') === '1',
            web_search_enabled: data.get('web_search_enabled') === '1',
            web_domain_allowlist: data.get('web_domain_allowlist') || '',
            vector_store_ids: checkedValues(form, 'vector_store_ids[]'),
            saved_source_ids: checkedValues(form, 'saved_source_ids[]'),
            code_interpreter_enabled: data.get('code_interpreter_enabled') === '1',
            code_memory_limit: data.get('code_memory_limit') || '1g',
        };
    }

    function renderWebhook(webhook) {
        if (!webhookDetails) {
            return;
        }

        if (!webhook || !webhook.url) {
            webhookDetails.innerHTML = '<p class="description">Webhook details unavailable.</p>';
            return;
        }

        webhookDetails.innerHTML = `
            <div class="aig-dr-webhook-card">
                <p><strong>Endpoint:</strong> <code>${escapeHtml(webhook.url)}</code></p>
                <p><strong>Enabled:</strong> ${webhook.enabled ? 'Yes' : 'No'}</p>
                <p><strong>Secret configured:</strong> ${webhook.secret_configured ? 'Yes' : 'No'}</p>
                <p><strong>Verification:</strong> ${escapeHtml(webhook.verification || 'none')}</p>
            </div>
        `;
    }

    function renderSourceOptions() {
        if (!sourceOptions) {
            return;
        }

        if (!savedSources.length) {
            sourceOptions.innerHTML = '<p class="description">No saved MCP sources yet.</p>';
            return;
        }

        sourceOptions.innerHTML = savedSources.map((source) => {
            const cfg = source.config || {};
            return `
                <label class="aig-dr-option-item">
                    <input type="checkbox" name="saved_source_ids[]" value="${escapeHtml(source.id)}" ${source.status === 'active' ? '' : 'disabled'}>
                    <span>
                        <strong>${escapeHtml(source.name)}</strong>
                        <small>${escapeHtml(cfg.server_url || '')}</small>
                    </span>
                </label>
            `;
        }).join('');
    }

    function renderSourcesList() {
        if (!sourceList) {
            return;
        }

        if (!savedSources.length) {
            sourceList.innerHTML = '<p class="description">No saved MCP sources yet.</p>';
            renderSourceOptions();
            return;
        }

        sourceList.innerHTML = savedSources.map((source) => {
            const cfg = source.config || {};
            return `
                <article class="aig-dr-managed-card" data-source-id="${source.id}">
                    <div class="aig-dr-managed-head">
                        <div>
                            <h3>${escapeHtml(source.name)}</h3>
                            <p class="aig-dr-meta">
                                <span>${escapeHtml(source.source_type || '')}</span>
                                <span>${escapeHtml(source.status || '')}</span>
                                <span>${escapeHtml(cfg.server_label || '')}</span>
                            </p>
                        </div>
                        <button type="button" class="button aig-dr-delete-source">Delete</button>
                    </div>
                    <p><strong>URL:</strong> ${escapeHtml(cfg.server_url || '')}</p>
                </article>
            `;
        }).join('');

        renderSourceOptions();
    }

    function enforceVectorStoreLimit() {
        const inputs = Array.from(document.querySelectorAll('input[name="vector_store_ids[]"]'));
        const checked = inputs.filter((input) => input.checked);

        inputs.forEach((input) => {
            input.disabled = !input.checked && checked.length >= 2;
        });
    }

    function renderVectorStoreOptions() {
        if (!vectorStoreOptions) {
            return;
        }

        if (!vectorStores.length) {
            vectorStoreOptions.innerHTML = '<p class="description">No vector stores found yet.</p>';
            return;
        }

        vectorStoreOptions.innerHTML = vectorStores.map((store) => `
            <label class="aig-dr-option-item">
                <input type="checkbox" name="vector_store_ids[]" value="${escapeHtml(store.id)}">
                <span>
                    <strong>${escapeHtml(store.name || store.id)}</strong>
                    <small>${escapeHtml(store.id)}</small>
                </span>
            </label>
        `).join('');

        enforceVectorStoreLimit();
    }

    function renderVectorStoresList() {
        if (!vectorStoreList) {
            return;
        }

        if (!vectorStores.length) {
            vectorStoreList.innerHTML = '<p class="description">No vector stores found yet.</p>';
            renderVectorStoreOptions();
            return;
        }

        vectorStoreList.innerHTML = vectorStores.map((store) => {
            const files = store.file_counts || {};
            return `
                <article class="aig-dr-managed-card" data-vector-store-id="${escapeHtml(store.id)}">
                    <div class="aig-dr-managed-head">
                        <div>
                            <h3>${escapeHtml(store.name || store.id)}</h3>
                            <p class="aig-dr-meta">
                                <span>${escapeHtml(store.status || '')}</span>
                                <span>${escapeHtml(store.id || '')}</span>
                            </p>
                        </div>
                        <button type="button" class="button aig-dr-delete-vector-store">Delete</button>
                    </div>
                    <p><strong>Files:</strong> ${escapeHtml(files.total || 0)}</p>
                </article>
            `;
        }).join('');

        renderVectorStoreOptions();
    }

    function renderRuns(runs) {
        if (!Array.isArray(runs) || runs.length === 0) {
            runsRoot.innerHTML = '<p class="description">No Deep Research runs yet.</p>';
            return;
        }

        runsRoot.innerHTML = runs.map((run) => {
            const annotations = Array.isArray(run.report_annotations) ? run.report_annotations : [];
            const itemSummary = Array.isArray(run.items) ? run.items.map((item) => item.type).join(', ') : '';
            const draftLinks = run.draft_post_id ? `<p><strong>Draft:</strong> #${run.draft_post_id}</p>` : '';

            return `
                <article class="aig-dr-run-card" data-run-id="${run.id}">
                    <div class="aig-dr-run-head">
                        <div>
                            <h3>${escapeHtml(run.title || run.prompt || 'Untitled run')}</h3>
                            <p class="aig-dr-meta">
                                <span>${escapeHtml(run.model || '')}</span>
                                <span>${escapeHtml(run.status || '')}</span>
                                <span>${escapeHtml(run.response_status || '')}</span>
                            </p>
                        </div>
                        <div class="aig-dr-run-actions">
                            <button type="button" class="button aig-dr-run-refresh">Refresh</button>
                            <button type="button" class="button aig-dr-run-cancel" ${['queued', 'running'].includes(run.status) ? '' : 'disabled'}>Cancel</button>
                            <button type="button" class="button aig-dr-run-draft" data-post-type="post" ${run.report_message ? '' : 'disabled'}>Create Post Draft</button>
                            <button type="button" class="button aig-dr-run-draft" data-post-type="page" ${run.report_message ? '' : 'disabled'}>Create Page Draft</button>
                        </div>
                    </div>
                    <p><strong>Prompt:</strong> ${escapeHtml(run.prompt || '')}</p>
                    <p><strong>Tool trace:</strong> ${escapeHtml(itemSummary || 'None yet')}</p>
                    <p><strong>Citations:</strong> ${annotations.length}</p>
                    ${draftLinks}
                    <div class="aig-dr-report">${escapeHtml(run.report_message || 'No final report yet.')}</div>
                    ${run.last_error ? `<p class="aig-dr-error"><strong>Error:</strong> ${escapeHtml(run.last_error)}</p>` : ''}
                </article>
            `;
        }).join('');
    }

    async function loadRuns() {
        refreshButton.disabled = true;

        try {
            const data = await request('/deep-research/runs');
            renderRuns(data.runs || []);
        } catch (error) {
            runsRoot.innerHTML = `<p class="aig-dr-error">${escapeHtml(error.message)}</p>`;
        } finally {
            refreshButton.disabled = false;
        }
    }

    async function loadSources() {
        try {
            const data = await request('/deep-research/sources');
            savedSources = Array.isArray(data.sources) ? data.sources : [];
            renderSourcesList();
            renderWebhook(data.webhook || null);
        } catch (error) {
            sourceList.innerHTML = `<p class="aig-dr-error">${escapeHtml(error.message)}</p>`;
            sourceOptions.innerHTML = `<p class="aig-dr-error">${escapeHtml(error.message)}</p>`;
        }
    }

    async function loadVectorStores() {
        try {
            const data = await request('/deep-research/vector-stores');
            vectorStores = Array.isArray(data.vector_stores) ? data.vector_stores : [];
            renderVectorStoresList();
        } catch (error) {
            vectorStoreList.innerHTML = `<p class="aig-dr-error">${escapeHtml(error.message)}</p>`;
            vectorStoreOptions.innerHTML = `<p class="aig-dr-error">${escapeHtml(error.message)}</p>`;
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        formStatus.textContent = i18n.creating || 'Starting research…';

        try {
            await request('/deep-research/runs', {
                method: 'POST',
                body: collectFormData(),
            });
            form.reset();
            formStatus.textContent = 'Research started.';
            renderVectorStoreOptions();
            renderSourceOptions();
            await loadRuns();
        } catch (error) {
            formStatus.textContent = error.message;
        }
    });

    sourceForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        sourceStatus.textContent = 'Saving source…';

        try {
            const data = new FormData(sourceForm);
            await request('/deep-research/sources', {
                method: 'POST',
                body: {
                    source_type: 'mcp',
                    name: data.get('name') || '',
                    server_label: data.get('server_label') || '',
                    server_url: data.get('server_url') || '',
                    authorization: data.get('authorization') || '',
                    active: data.get('active') === '1',
                },
            });
            sourceForm.reset();
            sourceStatus.textContent = 'Source saved.';
            await loadSources();
        } catch (error) {
            sourceStatus.textContent = error.message;
        }
    });

    vectorStoreForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        vectorStoreStatus.textContent = 'Creating vector store…';

        try {
            const data = new FormData(vectorStoreForm);
            await request('/deep-research/vector-stores', {
                method: 'POST',
                body: {
                    name: data.get('name') || '',
                },
            });
            vectorStoreForm.reset();
            vectorStoreStatus.textContent = 'Vector store created.';
            await loadVectorStores();
        } catch (error) {
            vectorStoreStatus.textContent = error.message;
        }
    });

    refreshButton.addEventListener('click', loadRuns);

    form.addEventListener('change', (event) => {
        if (event.target && event.target.name === 'vector_store_ids[]') {
            enforceVectorStoreLimit();
        }
    });

    sourceList.addEventListener('click', async (event) => {
        const button = event.target.closest('.aig-dr-delete-source');
        const card = event.target.closest('[data-source-id]');

        if (!button || !card) {
            return;
        }

        const sourceId = card.getAttribute('data-source-id');

        try {
            button.disabled = true;
            await request(`/deep-research/sources/${sourceId}`, { method: 'DELETE' });
            await loadSources();
        } catch (error) {
            button.disabled = false;
            window.alert(error.message);
        }
    });

    vectorStoreList.addEventListener('click', async (event) => {
        const button = event.target.closest('.aig-dr-delete-vector-store');
        const card = event.target.closest('[data-vector-store-id]');

        if (!button || !card) {
            return;
        }

        const vectorStoreId = card.getAttribute('data-vector-store-id');

        try {
            button.disabled = true;
            await request(`/deep-research/vector-stores/${encodeURIComponent(vectorStoreId)}`, { method: 'DELETE' });
            await loadVectorStores();
        } catch (error) {
            button.disabled = false;
            window.alert(error.message);
        }
    });

    runsRoot.addEventListener('click', async (event) => {
        const button = event.target.closest('button');
        const card = event.target.closest('[data-run-id]');

        if (!button || !card) {
            return;
        }

        const runId = card.getAttribute('data-run-id');

        try {
            if (button.classList.contains('aig-dr-run-refresh')) {
                button.disabled = true;
                button.textContent = i18n.refreshing || 'Refreshing…';
                await request(`/deep-research/runs/${runId}/refresh`, { method: 'POST' });
                await loadRuns();
            }

            if (button.classList.contains('aig-dr-run-cancel')) {
                button.disabled = true;
                button.textContent = i18n.cancelling || 'Cancelling…';
                await request(`/deep-research/runs/${runId}/cancel`, { method: 'POST' });
                await loadRuns();
            }

            if (button.classList.contains('aig-dr-run-draft')) {
                button.disabled = true;
                button.textContent = i18n.drafting || 'Creating draft…';
                const postType = button.getAttribute('data-post-type');
                const data = await request(`/deep-research/runs/${runId}/create-draft`, {
                    method: 'POST',
                    body: { post_type: postType },
                });
                await loadRuns();
                if (data.result && data.result.edit_link) {
                    window.open(data.result.edit_link, '_blank', 'noopener');
                }
            }
        } catch (error) {
            button.disabled = false;
            button.textContent = 'Retry';
            window.alert(error.message);
        }
    });

    loadSources();
    loadVectorStores();
    loadRuns();
});
