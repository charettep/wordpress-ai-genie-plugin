/* AI Content Forge — Admin JS */
/* global acfAdmin, jQuery */
jQuery( function ( $ ) {
    const { restUrl, nonce, i18n } = acfAdmin;
    const syncableProviders = [ 'claude', 'openai', 'ollama' ];
    const debounceTimers = {};
    const requestVersions = {};

    // ══════════════════════════════════════════════════════════════════════════
    // Tab navigation
    // ══════════════════════════════════════════════════════════════════════════
    const LS_TAB_KEY = 'aig-active-tab';
    const DEFAULT_TAB = 'providers';

    function getInitialTab() {
        // URL param wins (supports bookmarks)
        const params = new URLSearchParams( window.location.search );
        const urlTab = params.get( 'tab' );
        if ( urlTab && $( '[data-panel="' + urlTab + '"]' ).length ) {
            return urlTab;
        }
        // Fall back to localStorage (survives settings-save redirect)
        const stored = window.localStorage && window.localStorage.getItem( LS_TAB_KEY );
        if ( stored && $( '[data-panel="' + stored + '"]' ).length ) {
            return stored;
        }
        return DEFAULT_TAB;
    }

    function activateTab( tabId ) {
        // Update nav tabs
        $( '.aig-tab-nav .nav-tab' ).removeClass( 'nav-tab-active' );
        $( '.aig-tab-nav .nav-tab[data-tab="' + tabId + '"]' ).addClass( 'nav-tab-active' );

        // Show/hide panels
        $( '.aig-tab-panel' ).hide().attr( 'aria-hidden', 'true' );
        $( '.aig-tab-panel[data-panel="' + tabId + '"]' ).show().attr( 'aria-hidden', 'false' );

        // Persist
        if ( window.localStorage ) {
            window.localStorage.setItem( LS_TAB_KEY, tabId );
        }

        // Reflect in URL without page reload
        if ( window.history && window.history.replaceState ) {
            const url = new URL( window.location.href );
            url.searchParams.set( 'tab', tabId );
            window.history.replaceState( null, '', url.toString() );
        }
    }

    // Wire tab clicks
    $( '.aig-tab-nav .nav-tab' ).on( 'click', function ( e ) {
        e.preventDefault();
        activateTab( $( this ).data( 'tab' ) );
    } );

    // Wire cross-tab links (e.g. "Open Setup Guide →" in Ollama card)
    $( document ).on( 'click', '.aig-tab-link', function ( e ) {
        e.preventDefault();
        const target = $( this ).data( 'target-tab' );
        if ( target ) {
            activateTab( target );
            window.scrollTo( { top: 0, behavior: 'smooth' } );
        }
    } );

    // Activate initial tab on load
    activateTab( getInitialTab() );

    // ══════════════════════════════════════════════════════════════════════════
    // Dirty-state tracking + sticky footer
    // ══════════════════════════════════════════════════════════════════════════
    let isDirty = false;

    function markDirty() {
        if ( isDirty ) { return; }
        isDirty = true;
        $( '#aig-save-footer' ).addClass( 'is-dirty' );
        $( '#aig-dirty-notice' ).show();
    }

    function markClean() {
        isDirty = false;
        $( '#aig-save-footer' ).removeClass( 'is-dirty' );
        $( '#aig-dirty-notice' ).hide();
    }

    // Track any form change
    $( '#aig-settings-form' ).on( 'change input', 'input, select, textarea', function () {
        markDirty();
    } );

    // Discard: HTML form.reset() restores all fields to their PHP-rendered defaults
    $( '#aig-discard-btn' ).on( 'click', function () {
        document.getElementById( 'aig-settings-form' ).reset();
        markClean();
        // Re-trigger provider sync so model dropdowns refresh to saved state
        syncableProviders.forEach( function ( slug ) {
            scheduleProviderSync( slug );
        } );
    } );

    // On form submit, clear dirty flag so footer hides on next load
    $( '#aig-settings-form' ).on( 'submit', function () {
        markClean();
        if ( window.localStorage ) {
            // Preserve active tab across the settings-save redirect
            const params = new URLSearchParams( window.location.search );
            const active = params.get( 'tab' ) || DEFAULT_TAB;
            window.localStorage.setItem( LS_TAB_KEY, active );
        }
    } );

    // ══════════════════════════════════════════════════════════════════════════
    // Summary strip badge mirroring
    // ══════════════════════════════════════════════════════════════════════════
    function updateSummaryBadge( slug, status ) {
        const $badge = $( '[data-summary-provider="' + slug + '"]' );
        if ( ! $badge.length ) { return; }
        $badge.removeClass( 'is-checking is-connected is-error' );
        if ( status ) { $badge.addClass( 'is-' + status ); }
    }

    // ── Per-model output token limits (prefix-matched, longest first) ─────────
    const MODEL_TOKEN_LIMITS = [
        // OpenAI — Responses API models
        [ 'gpt-5-pro',     200000 ],
        [ 'gpt-5',         128000 ],
        [ 'gpt-4.1-mini',  32768  ],
        [ 'gpt-4.1-nano',  32768  ],
        [ 'gpt-4.1',       32768  ],
        [ 'gpt-4o-mini',   16384  ],
        [ 'gpt-4o',        16384  ],
        [ 'o4-mini',       100000 ],
        [ 'o3-mini',       100000 ],
        [ 'o3',            100000 ],
        [ 'o1-mini',       65536  ],
        [ 'o1',            100000 ],
        // Anthropic Claude 4 series
        [ 'claude-opus-4',   32000 ],
        [ 'claude-sonnet-4', 64000 ],
        [ 'claude-haiku-4',  16000 ],
        // Anthropic Claude 3.5 series
        [ 'claude-3-5-sonnet', 8192 ],
        [ 'claude-3-5-haiku',  8192 ],
        // Anthropic Claude 3 series
        [ 'claude-3-opus',   4096 ],
        [ 'claude-3-sonnet', 4096 ],
        [ 'claude-3-haiku',  4096 ],
    ];

    function getModelTokenLimit( modelId ) {
        if ( ! modelId ) { return null; }
        const id = modelId.toLowerCase();
        for ( const [ prefix, limit ] of MODEL_TOKEN_LIMITS ) {
            if ( id.startsWith( prefix.toLowerCase() ) ) {
                return limit;
            }
        }
        return null;
    }

    function getDefaultProvider() {
        return $( 'input[name$="[default_provider]"]:checked' ).val() || '';
    }

    function getProviderLabel( slug ) {
        return $( '.aig-summary-badge[data-summary-provider="' + slug + '"] .aig-summary-badge-label' ).text().trim() || slug;
    }

    function updateSummarySelection() {
        const defaultProvider = getDefaultProvider();
        const modelId = getProviderSelect( defaultProvider ).val() || '';

        $( '.aig-summary-badge' )
            .removeClass( 'is-selected' )
            .attr( 'aria-checked', 'false' )
            .find( '.aig-badge-indicator' )
            .text( '●' );

        $( '.aig-summary-badge[data-summary-provider="' + defaultProvider + '"]' )
            .addClass( 'is-selected' )
            .attr( 'aria-checked', 'true' )
            .find( '.aig-badge-indicator' )
            .text( '⭐' );

        $( '#aig-summary-default-provider' ).text( getProviderLabel( defaultProvider ) );
        $( '#aig-summary-default-model' ).text( modelId ? '— ' + modelId : '' );
    }

    function updateTokenLimitHint() {
        const $hint  = $( '#aig-token-limit-hint' );
        const $input = $( '#aig-max-output-tokens' );
        if ( ! $hint.length || ! $input.length ) { return; }

        const defaultProvider = getDefaultProvider();
        const $select         = getProviderSelect( defaultProvider );
        const modelId         = $select.val() || '';
        const limit           = getModelTokenLimit( modelId );

        if ( limit ) {
            $input.attr( 'max', limit );
            $hint.text( modelId + ' supports up to ' + limit.toLocaleString() + ' generated tokens. Reasoning-capable providers may count thinking tokens against this cap.' );
        } else if ( modelId ) {
            $input.attr( 'max', 200000 );
            $hint.text( 'Check your provider\u2019s documentation for the exact token limit and whether thinking tokens share the same cap.' );
        } else {
            $hint.text( '' );
        }
    }

    // ── Summary strip provider selection ─────────────────────────────────────
    $( 'input[name$="[default_provider]"]' ).on( 'change', function () {
        updateSummarySelection();
        updateTokenLimitHint();
    } );

    function setProviderStatus( slug, status, message ) {
        const $status = $( '#status-' + slug );

        if ( $status.length ) {
            $status.removeClass( 'is-checking is-connected is-error' );

            if ( ! status ) {
                $status.text( '' );
            } else if ( 'checking' === status ) {
                $status.addClass( 'is-checking' ).text( i18n.checking );
            } else if ( 'connected' === status ) {
                $status.addClass( 'is-connected' ).text( i18n.connected );
            } else {
                $status.addClass( 'is-error' ).text( message ? i18n.failed + ': ' + message : i18n.failed );
            }
        }

        // Mirror status to summary strip badge
        updateSummaryBadge( slug, status || '' );
    }

    function getProviderSelect( slug ) {
        return $( '.aig-model-select[data-provider="' + slug + '"]' );
    }

    function getProviderSyncInput( slug ) {
        if ( 'ollama' === slug ) {
            return $( '.aig-base-url-input[data-provider="' + slug + '"]' );
        }

        return $( '.aig-api-key-input[data-provider="' + slug + '"]' );
    }

    function getOllamaAuthHeaderNameInput() {
        return $( '.aig-ollama-auth-input[data-role="header-name"]' );
    }

    function getOllamaAuthHeaderValueInput() {
        return $( '.aig-ollama-auth-input[data-role="header-value"]' );
    }

    function resetProviderSelect( slug ) {
        const $select = getProviderSelect( slug );
        const placeholder = 'ollama' === slug
            ? ( $select.data( 'placeholder' ) || i18n.enterBaseUrl )
            : ( $select.data( 'placeholder' ) || i18n.enterApiKey );

        $select.empty().append(
            $( '<option />', {
                value: '',
                text: placeholder,
                selected: true,
            } )
        );
    }

    function setSelectOptions( $select, models, selectedModel ) {
        const current = selectedModel || $select.val() || '';

        $select.empty();

        if ( ! models.length ) {
            $select.append(
                $( '<option />', {
                    value: '',
                    text:  $select.data( 'empty-label' ) || i18n.noModels,
                    selected: true,
                } )
            );
            return;
        }

        models.forEach( function ( model ) {
            const value = model.id || '';
            const label = model.label || value;

            $select.append(
                $( '<option />', {
                    value,
                    text: label,
                    selected: value === current,
                } )
            );
        } );

        if ( ! models.some( function ( model ) { return model.id === current; } ) ) {
            $select.val( models[0].id );
        }

        updateTokenLimitHint();
    }

    function scheduleProviderSync( slug ) {
        clearTimeout( debounceTimers[ slug ] );
        debounceTimers[ slug ] = window.setTimeout( function () {
            syncProvider( slug );
        }, 500 );
    }

    function syncProvider( slug ) {
        const $input = getProviderSyncInput( slug );
        const $select = getProviderSelect( slug );
        const configValue = String( $input.val() || '' ).trim();
        const currentModel = String( $select.val() || '' ).trim();

        if ( ! configValue ) {
            setProviderStatus( slug, '' );
            resetProviderSelect( slug );
            updateTokenLimitHint();
            return;
        }

        requestVersions[ slug ] = ( requestVersions[ slug ] || 0 ) + 1;
        const requestVersion = requestVersions[ slug ];

        setProviderStatus( slug, 'checking' );
        $select.prop( 'disabled', true );

        $.ajax( {
            url:         restUrl + '/sync-provider',
            method:      'POST',
            contentType: 'application/json',
            beforeSend:  function ( xhr ) { xhr.setRequestHeader( 'X-WP-Nonce', nonce ); },
            data:        JSON.stringify( Object.assign( {
                provider: slug,
                current_model: currentModel,
            }, 'ollama' === slug ? {
                base_url: configValue,
                auth_header_name: String( getOllamaAuthHeaderNameInput().val() || '' ).trim(),
                auth_header_value: String( getOllamaAuthHeaderValueInput().val() || '' ).trim(),
            } : {
                api_key: configValue,
            } ) ),
        } )
        .done( function ( res ) {
            if ( requestVersion !== requestVersions[ slug ] ) {
                return;
            }

            setSelectOptions( $select, res.models || [], res.selected_model || currentModel );
            setProviderStatus( slug, 'connected' );
            updateSummarySelection();
        } )
        .fail( function ( xhr ) {
            if ( requestVersion !== requestVersions[ slug ] ) {
                return;
            }

            const msg = xhr.responseJSON?.message || xhr.responseJSON?.data?.message || '';
            setProviderStatus( slug, 'error', msg );
        } )
        .always( function () {
            if ( requestVersion !== requestVersions[ slug ] ) {
                return;
            }

            $select.prop( 'disabled', false );
            updateSummarySelection();
        } );
    }

    // ── Prompt rail switching ────────────────────────────────────────────────
    function activatePromptType( type ) {
        $( '.aig-prompt-rail-item' ).removeClass( 'is-active' );
        $( '.aig-prompt-rail-item[data-prompt-type="' + type + '"]' ).addClass( 'is-active' );
        $( '.aig-prompt-pane' ).removeClass( 'is-active' );
        $( '.aig-prompt-pane[data-prompt-pane="' + type + '"]' ).addClass( 'is-active' );
    }

    $( document ).on( 'click', '.aig-prompt-rail-item', function () {
        activatePromptType( $( this ).data( 'prompt-type' ) );
    } );

    // ── API key show / hide toggle ───────────────────────────────────────────
    $( document ).on( 'click', '.aig-key-toggle', function () {
        const $button = $( this );
        const $input = $button.closest( '.aig-key-wrap' ).find( 'input' ).first();

        if ( ! $input.length ) {
            return;
        }

        const isPassword = $input.attr( 'type' ) === 'password';

        $input.attr( 'type', isPassword ? 'text' : 'password' );
        $button
            .text( isPassword ? '🙈' : '👁' )
            .attr( 'aria-pressed', isPassword ? 'true' : 'false' );
    } );

    // ── Claude / OpenAI live sync ────────────────────────────────────────────
    $( '.aig-api-key-input, .aig-base-url-input' ).on( 'input', function () {
        scheduleProviderSync( $( this ).data( 'provider' ) );
    } );

    $( '.aig-ollama-auth-input' ).on( 'input', function () {
        scheduleProviderSync( 'ollama' );
    } );

    syncableProviders.forEach( function ( slug ) {
        const $input = getProviderSyncInput( slug );

        if ( String( $input.val() || '' ).trim() ) {
            scheduleProviderSync( slug );
        }
    } );

    // Initial hint on page load (before any sync completes)
    updateSummarySelection();
    updateTokenLimitHint();

    // ── Model select change → refresh token limit hint ───────────────────────
    $( '.aig-model-select' ).on( 'change', function () {
        updateSummarySelection();
        updateTokenLimitHint();
    } );

} );
