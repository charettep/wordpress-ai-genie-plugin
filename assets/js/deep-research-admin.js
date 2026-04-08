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
            vector_store_ids: data.get('vector_store_ids') || '',
            mcp_server_url: data.get('mcp_server_url') || '',
            mcp_server_label: data.get('mcp_server_label') || '',
            mcp_authorization: data.get('mcp_authorization') || '',
            code_interpreter_enabled: data.get('code_interpreter_enabled') === '1',
            code_memory_limit: data.get('code_memory_limit') || '1g',
        };
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
            await loadRuns();
        } catch (error) {
            formStatus.textContent = error.message;
        }
    });

    refreshButton.addEventListener('click', loadRuns);

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

    loadRuns();
});
