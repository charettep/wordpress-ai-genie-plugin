( function ( wp ) {
    if ( ! wp || ! wp.element || ! wp.components || ! wp.apiFetch || ! window.acfAdmin ) {
        return;
    }

    const { createElement, useState, useEffect } = wp.element;
    const { Button, Spinner, Notice, TextControl, SelectControl, PanelBody, ToggleControl, TabPanel } = wp.components;
    const { __ } = wp.i18n;

    const PROVIDER_LABELS = {
        claude: __( 'Anthropic Claude', 'ai-content-forge' ),
        openai: __( 'OpenAI', 'ai-content-forge' ),
        ollama: __( 'Ollama (Local LLM)', 'ai-content-forge' ),
    };

    const DEFAULT_PROMPTS = {
        post_content: __( 'Post Content Prompt', 'ai-content-forge' ),
        seo_title: __( 'SEO Title Prompt', 'ai-content-forge' ),
        meta_description: __( 'Meta Description Prompt', 'ai-content-forge' ),
        excerpt: __( 'Excerpt Prompt', 'ai-content-forge' ),
    };

    function apiRequest( path, options = {} ) {
        const controller = new AbortController();
        const timeout = options.timeout || 15000;

        const timer = setTimeout( () => controller.abort(), timeout );

        return wp.apiFetch( {
            path: path,
            headers: {
                'X-WP-Nonce': acfAdmin.nonce,
                'Content-Type': options.method === 'POST' || options.method === 'PUT' || options.method === 'PATCH' ? 'application/json' : undefined,
            },
            method: options.method || 'GET',
            data: options.body ? options.body : undefined,
            signal: controller.signal,
        } )
            .finally( () => clearTimeout( timer ) );
    }

    const App = () => {
        const [settings, setSettings] = useState( acfAdmin.settings || {} );
        const [providers, setProviders] = useState( [] );
        const [models, setModels] = useState( {} );
        const [promptTemplates, setPromptTemplates] = useState( [] );
        const [activeTab, setActiveTab] = useState( 'providers' );
        const [statusMessage, setStatusMessage] = useState( null );
        const [saving, setSaving] = useState( false );

        useEffect( () => {
            apiRequest( `${ acfAdmin.restUrl }/providers` )
                .then( ( data ) => setProviders( data ) )
                .catch( ( err ) => setStatusMessage( { type: 'error', text: err.message || 'Failed to load providers.' } ) );

            apiRequest( `${ acfAdmin.restUrl }/prompt-templates` )
                .then( ( data ) => setPromptTemplates( data.templates || [] ) )
                .catch( ( err ) => setStatusMessage( { type: 'error', text: err.message || 'Failed to load prompt templates.' } ) );

            // for each provider, preload stored models
            Object.keys( PROVIDER_LABELS ).forEach( ( provider ) => {
                loadProviderModels( provider );
            } );
        }, [] );

        const loadProviderModels = ( provider ) => {
            apiRequest( `${ acfAdmin.restUrl }/provider-models?provider=${ encodeURIComponent( provider ) }` )
                .then( ( resp ) => {
                    if ( resp.success ) {
                        setModels( ( prev ) => ( { ...prev, [ provider ]: resp.models || [] } ) );
                    }
                } )
                .catch( () => {
                    // ignore failure, fallback to sync provider call
                } );
        };

        const syncProviderModels = ( provider, config ) => {
            setStatusMessage( { type: 'info', text: __( 'Syncing models...', 'ai-content-forge' ) } );
            apiRequest( `${ acfAdmin.restUrl }/sync-provider`, {
                method: 'POST',
                body: JSON.stringify( { provider, ...config } ),
            } )
                .then( ( resp ) => {
                    if ( resp.success ) {
                        setModels( ( prev ) => ( { ...prev, [ provider ]: resp.models || [] } ) );
                        setStatusMessage( { type: 'success', text: __( 'Models synced.', 'ai-content-forge' ) } );
                    } else {
                        setStatusMessage( { type: 'error', text: resp.message || __( 'Failed to sync models.', 'ai-content-forge' ) } );
                    }
                } )
                .catch( ( err ) => {
                    setStatusMessage( { type: 'error', text: err.message || __( 'Failed to sync models.', 'ai-content-forge' ) } );
                } );
        };

        const onSettingChange = ( key, value ) => {
            setSettings( ( prev ) => ( { ...prev, [ key ]: value } ) );
        };

        const onProviderConfigChange = ( provider, key, value ) => {
            const settingKey = provider === 'ollama' ? 'ollama_url' : `${ provider }_api_key`;
            onSettingChange( settingKey, value );
        };

        const saveSettings = () => {
            setSaving( true );
            apiRequest( `${ acfAdmin.restUrl }/settings`, {
                method: 'POST',
                body: JSON.stringify( settings ),
            } )
                .then( ( resp ) => {
                    if ( resp.success ) {
                        setSettings( resp.settings );
                        setStatusMessage( { type: 'success', text: __( 'Settings saved.', 'ai-content-forge' ) } );
                    } else {
                        setStatusMessage( { type: 'error', text: resp.message || __( 'Failed to save settings.', 'ai-content-forge' ) } );
                    }
                } )
                .catch( ( err ) => {
                    setStatusMessage( { type: 'error', text: err.message || __( 'Failed to save settings.', 'ai-content-forge' ) } );
                } )
                .finally( () => setSaving( false ) );
        };

        const updatePromptTemplate = ( type, template ) => {
            apiRequest( `${ acfAdmin.restUrl }/prompt-templates/${ encodeURIComponent( type ) }`, {
                method: 'PATCH',
                body: JSON.stringify( { template } ),
            } )
                .then( ( resp ) => {
                    if ( resp.success ) {
                        setPromptTemplates( ( prev ) => prev.map( ( item ) => ( item.type === type ? { ...item, template: resp.template } : item ) ) );
                        setStatusMessage( { type: 'success', text: __( 'Prompt saved.', 'ai-content-forge' ) } );
                    } else {
                        setStatusMessage( { type: 'error', text: resp.message || __( 'Failed to save prompt.', 'ai-content-forge' ) } );
                    }
                } )
                .catch( ( err ) => setStatusMessage( { type: 'error', text: err.message || __( 'Failed to save prompt.', 'ai-content-forge' ) } ) );
        };

        const renderProviderSection = ( provider ) => {
            const valueKey = provider === 'ollama' ? 'ollama_url' : `${ provider }_api_key`;
            const modelKey = `${ provider }_model`;
            const providerLabel = PROVIDER_LABELS[provider];

            return createElement( PanelBody, { title: providerLabel, initialOpen: true, key: provider },
                createElement( 'div', { style: { marginBottom: '1rem' } },
                    provider === 'ollama'
                        ? createElement( TextControl, {
                            label: __( 'Base URL', 'ai-content-forge' ),
                            value: settings.ollama_url || '',
                            onChange: ( value ) => onSettingChange( 'ollama_url', value ),
                        })
                        : createElement( TextControl, {
                            label: __( 'API Key', 'ai-content-forge' ),
                            type: 'password',
                            value: settings[ valueKey ] || '',
                            onChange: ( value ) => onSettingChange( valueKey, value ),
                        })
                ),
                createElement( Button, {
                    isPrimary: true,
                    onClick: () => syncProviderModels( provider, {
                        provider,
                        api_key: provider !== 'ollama' ? settings[ `${ provider }_api_key` ] : '',
                        base_url: provider === 'ollama' ? settings.ollama_url : '',
                        current_model: settings[ modelKey ] || '',
                    } ),
                }, __( 'Refresh models', 'ai-content-forge' ) ),
                createElement( SelectControl, {
                    label: __( 'Model', 'ai-content-forge' ),
                    value: settings[ modelKey ] || '',
                    options: [ { label: __( 'Select', 'ai-content-forge' ), value: '' } ].concat( ( models[ provider ] || [] ).map( ( model ) => ( { label: model.id || model, value: model.id || model } ) ) ),
                    onChange: ( value ) => onSettingChange( modelKey, value ),
                })
            );
        };

        const tabs = [
            {
                name: 'providers',
                title: __( 'Providers', 'ai-content-forge' ),
                className: 'acf-tab-providers',
            },
            {
                name: 'prompts',
                title: __( 'Prompt Templates', 'ai-content-forge' ),
                className: 'acf-tab-prompts',
            },
        ];

        return createElement( 'div', { className: 'acf-react-admin-wrap' },
            statusMessage && createElement( Notice, { status: statusMessage.type, isDismissible: true, onRemove: () => setStatusMessage( null ) }, statusMessage.text ),
            createElement( TabPanel, {
                className: 'acf-admin-tabs',
                activeClass: 'is-active',
                onSelect: ( tab ) => setActiveTab( tab.name ),
                initialTabName: activeTab,
                tabs,
            }, ( tab ) => {
                if ( tab.name === 'providers' ) {
                    return createElement( 'div', null,
                        createElement( PanelBody, { title: __( 'Default Provider', 'ai-content-forge' ), initialOpen: true },
                            createElement( SelectControl, {
                                label: __( 'Default Provider', 'ai-content-forge' ),
                                value: settings.default_provider || 'claude',
                                options: Object.keys( PROVIDER_LABELS ).map( ( key ) => ( { label: PROVIDER_LABELS[key], value: key } ) ),
                                onChange: ( value ) => onSettingChange( 'default_provider', value ),
                            })
                        ),
                        Object.keys( PROVIDER_LABELS ).map( ( provider ) => renderProviderSection( provider ) ),
                        createElement( PanelBody, { title: __( 'Generation Settings', 'ai-content-forge' ), initialOpen: false },
                            createElement( TextControl, {
                                label: __( 'Max Output Tokens', 'ai-content-forge' ),
                                value: settings.max_output_tokens || 1500,
                                type: 'number',
                                onChange: ( value ) => onSettingChange( 'max_output_tokens', parseInt( value, 10 ) || 0 ),
                            }),
                            createElement( TextControl, {
                                label: __( 'Temperature', 'ai-content-forge' ),
                                value: settings.temperature || 0.7,
                                type: 'number',
                                step: 0.1,
                                onChange: ( value ) => onSettingChange( 'temperature', parseFloat( value ) || 0.0 ),
                            })
                        )
                    );
                }

                return createElement( 'div', null,
                    promptTemplates.map( ( item ) =>
                        createElement( PanelBody, { title: item.label, initialOpen: false, key: item.type },
                            createElement( 'textarea', {
                                className: 'acf-prompt-template',
                                value: item.template,
                                onChange: ( event ) => {
                                    const value = event.target.value;
                                    setPromptTemplates( ( prev ) => prev.map( ( p ) => ( p.type === item.type ? { ...p, template: value } : p ) ) );
                                },
                                rows: 10,
                                style: { width: '100%', fontFamily: 'monospace' },
                            } ),
                            createElement( Button, {
                                isSecondary: true,
                                onClick: () => updatePromptTemplate( item.type, item.template ),
                            }, __( 'Save Prompt', 'ai-content-forge' ) )
                        )
                    )
                );
            } ),
            createElement( 'div', { style: { marginTop: '1rem' } },
                createElement( Button, { isPrimary: true, isBusy: saving, onClick: saveSettings }, __( 'Save Settings', 'ai-content-forge' ) )
            )
        );
    };

    document.addEventListener( 'DOMContentLoaded', function () {
        const root = document.getElementById( 'acf-admin-react-app' );
        if ( root ) {
            wp.element.render( createElement( App ), root );
        }
    } );

} )( window.wp );
