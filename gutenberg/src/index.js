/**
 * AI Content Forge — Gutenberg Sidebar Plugin
 *
 * Build: cd gutenberg && npm install && npm run build
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
	RangeControl,
	Notice,
	Spinner,
	Panel,
	PanelBody,
	PanelRow,
} from '@wordpress/components';
import { useState, useCallback, useRef, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const { restNamespace, settings, typeLabels, metaKeys, nonce } = window.acfGutenberg;

const NEXT_CONTROL_PROPS = {
	__next40pxDefaultSize: true,
	__nextHasNoMarginBottom: true,
};

const NEXT_TEXTAREA_PROPS = {
	__nextHasNoMarginBottom: true,
};

// ── Tone options ──────────────────────────────────────────────────────────────
const TONE_OPTIONS = [
	{ value: 'professional', label: 'Professional' },
	{ value: 'conversational', label: 'Conversational' },
	{ value: 'authoritative', label: 'Authoritative' },
	{ value: 'friendly', label: 'Friendly' },
	{ value: 'humorous', label: 'Humorous' },
	{ value: 'persuasive', label: 'Persuasive' },
];

const LANG_OPTIONS = [
	{ value: 'English', label: 'English' },
	{ value: 'French', label: 'French (Français)' },
	{ value: 'Spanish', label: 'Spanish (Español)' },
	{ value: 'German', label: 'German (Deutsch)' },
	{ value: 'Portuguese', label: 'Portuguese' },
	{ value: 'Italian', label: 'Italian (Italiano)' },
];

const PROVIDER_OPTIONS = [
	{ value: '', label: `Default (${ settings.default_provider })` },
	{ value: 'claude', label: '🟠 Anthropic Claude' },
	{ value: 'openai', label: '🟢 OpenAI' },
	{ value: 'ollama', label: '🔵 Ollama (Local)' },
];

const TYPE_OPTIONS = Object.entries( typeLabels ).map(
	( [ value, label ] ) => ( { value, label } )
);

const STRUCTURE_OPTIONS = [
	{ value: 'Full Draft', label: 'Full Draft' },
	{ value: 'Outline', label: 'Outline' },
	{ value: 'Bulleted Summary', label: 'Bulleted Summary' },
	{ value: 'Q&A', label: 'Q&A' },
];

const TARGET_LENGTH_OPTIONS = [
	{ value: '300', label: '300 words (short)' },
	{ value: '600', label: '600 words (medium)' },
	{ value: '900', label: '900 words (default)' },
	{ value: '1200', label: '1200 words (long)' },
];

const CONTEXT_SCOPE_OPTIONS = [
	{ value: 'full', label: 'Full post' },
	{ value: 'selected', label: 'Selected blocks' },
	{ value: 'custom', label: 'Custom paste' },
	{ value: 'none', label: 'None' },
];

// ── Helper: apply generated content to post ───────────────────────────────────
function useApplyResult() {
	const { editPost } = useDispatch( 'core/editor' );
	const { resetBlocks } = useDispatch( 'core/block-editor' );

	return useCallback(
		( type, content ) => {
			const { createBlock, rawHandler } = window.wp.blocks;

			switch ( type ) {
				case 'post_content': {
					const blocks = rawHandler( { HTML: content } );

					if ( Array.isArray( blocks ) && blocks.length > 0 ) {
						resetBlocks( blocks );
						break;
					}

					// Fallback to a raw HTML block if Gutenberg cannot parse the markup.
					resetBlocks( [ createBlock( 'core/html', { content } ) ] );
					break;
				}
				case 'seo_title':
					editPost( { title: content } );
					break;
				case 'excerpt':
					editPost( { excerpt: content } );
					break;
				case 'meta_description':
					editPost( {
						meta: { [ metaKeys.metaDescription ]: content },
					} );
					break;
				default:
					break;
			}
		},
		[ editPost, resetBlocks ]
	);
}

// ── Helper: resolve default model name for a provider ────────────────────────
function getDefaultModelLabel( providerSlug ) {
	if ( providerSlug === 'claude' ) {
		return settings.claude_model || 'auto';
	}
	if ( providerSlug === 'openai' ) {
		return settings.openai_model || 'auto';
	}
	if ( providerSlug === 'ollama' ) {
		return settings.ollama_model || 'auto';
	}
	return 'auto';
}

// ── Main sidebar component ────────────────────────────────────────────────────
function AcfSidebar() {
	const [ type, setType ] = useState( 'post_content' );
	const [ provider, setProvider ] = useState( '' );
	const [ keywords, setKeywords ] = useState( '' );
	const [ tone, setTone ] = useState( 'professional' );
	const [ language, setLanguage ] = useState( 'English' );
	const [ contextScope, setContextScope ] = useState( 'full' );
	const [ customContext, setCustomContext ] = useState( '' );
	const [ targetLength, setTargetLength ] = useState( '900' );
	const [ structure, setStructure ] = useState( 'Full Draft' );
	const [ modelOverride, setModelOverride ] = useState( '' );
	const [ maxOutputTokens, setMaxOutputTokens ] = useState(
		String( settings.max_output_tokens ?? 1500 )
	);
	const [ maxThinkingTokens, setMaxThinkingTokens ] = useState(
		String( settings.max_thinking_tokens ?? 0 )
	);
	const [ temperature, setTemperature ] = useState(
		Number( settings.temperature ?? 0.7 )
	);
	const [ result, setResult ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ copied, setCopied ] = useState( false );
	const [ runUsage, setRunUsage ] = useState( null );
	const [ usageByProvider, setUsageByProvider ] = useState( {} );
	const [ providerModels, setProviderModels ] = useState( [] );
	const [ modelsLoading, setModelsLoading ] = useState( false );
	const [ modelsError, setModelsError ] = useState( '' );
	const abortControllerRef = useRef( null );

	const { postTitle, postType, postContent, postId, selectedBlocks } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		const blockEditor = select( 'core/block-editor' );
		return {
			postTitle: editor.getEditedPostAttribute( 'title' ) || '',
			postType: editor.getCurrentPostType() || 'post',
			postContent: editor.getEditedPostAttribute( 'content' ) || '',
			postId: editor.getCurrentPostId(),
			selectedBlocks: blockEditor?.getSelectedBlocks ? blockEditor.getSelectedBlocks() : [],
		};
	}, [] );

	const activeProvider = provider || settings.default_provider;
	const applyResult = useApplyResult();

	// ── Fetch provider models when active provider changes ───────────────────
	useEffect( () => {
		if ( ! activeProvider ) {
			return;
		}

		let cancelled = false;

		setModelsLoading( true );
		setModelsError( '' );
		setProviderModels( [] );
		setModelOverride( '' );

		apiFetch( {
			path: `/${ restNamespace }/provider-models?provider=${ encodeURIComponent( activeProvider ) }`,
		} )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}

				if ( res?.models && Array.isArray( res.models ) ) {
					setProviderModels( res.models );
				} else if ( Array.isArray( res ) ) {
					setProviderModels( res );
				} else {
					setProviderModels( [] );
				}
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}

				setModelsError(
					err?.message || __( 'Unable to load provider models.', 'ai-content-forge' )
				);
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setModelsLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ activeProvider ] );

	// ── SSE helpers ──────────────────────────────────────────────────────────
	const parseEventFrame = ( frame ) => {
		const lines = frame.split( /\r?\n/ );
		let name = 'message';
		let data = '';

		for ( const line of lines ) {
			if ( line.startsWith( 'event:' ) ) {
				name = line.slice( 6 ).trim();
			} else if ( line.startsWith( 'data:' ) ) {
				data += line.slice( 5 ).trim();
			}
		}

		if ( ! data ) {
			return null;
		}

		try {
			return { name, data: JSON.parse( data ) };
		} catch ( e ) {
			return null;
		}
	};

	const extractUsageFallback = ( rawStream, onUsage ) => {
		const frames = rawStream
			.split( /\n\n+/ )
			.map( ( frame ) => frame.trim() )
			.filter( Boolean );

		for ( const frame of frames ) {
			const event = parseEventFrame( frame );

			if ( ! event ) {
				continue;
			}

			if ( event.name === 'usage' && event.data ) {
				onUsage( event.data );
				return;
			}

			if ( event.name === 'done' && event.data?.usage ) {
				onUsage( event.data.usage );
				return;
			}
		}
	};

	const streamGenerate = async ( payload, signal, onChunk, onUsage ) => {
		const response = await window.fetch(
			`/wp-json/${ restNamespace }/generate-stream`,
			{
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce || '',
				},
				credentials: 'same-origin',
				body: JSON.stringify( payload ),
				signal,
			}
		);

		if ( ! response.ok || ! response.body ) {
			const fallback = await apiFetch( {
				path: `/${ restNamespace }/generate`,
				method: 'POST',
				data: payload,
				signal,
			} );

			if ( fallback.success ) {
				onChunk( fallback.result || '' );
				return;
			}

			throw new Error(
				fallback.message || __( 'Unknown error', 'ai-content-forge' )
			);
		}

		const reader = response.body.getReader();
		const decoder = new window.TextDecoder();
		let buffer = '';
		let rawStream = '';
		let sawUsageEvent = false;
		const processFrame = ( frame ) => {
			const event = parseEventFrame( frame );

			if ( ! event ) {
				return;
			}

			if ( event.name === 'chunk' && event.data?.text ) {
				onChunk( event.data.text );
			} else if ( event.name === 'usage' && event.data ) {
				sawUsageEvent = true;
				onUsage( event.data );
			} else if ( event.name === 'done' && event.data?.usage ) {
				sawUsageEvent = true;
				onUsage( event.data.usage );
			} else if ( event.name === 'error' ) {
				throw new Error(
					event.data?.message ||
						__( 'Request failed', 'ai-content-forge' )
				);
			}
		};

		for ( ;; ) {
			const { done, value } = await reader.read();

			if ( done ) {
				break;
			}

			const decoded = decoder.decode( value, { stream: true } );
			rawStream += decoded;
			buffer += decoded;

			for ( ;; ) {
				const boundary = buffer.indexOf( '\n\n' );
				if ( boundary === -1 ) {
					break;
				}

				const frame = buffer.slice( 0, boundary );
				buffer = buffer.slice( boundary + 2 );
				processFrame( frame );
			}
		}

		buffer += decoder.decode();
		rawStream += buffer;

		const trailingFrames = buffer
			.split( /\n\n+/ )
			.map( ( frame ) => frame.trim() )
			.filter( Boolean );

		for ( const frame of trailingFrames ) {
			processFrame( frame );
		}

		if ( ! sawUsageEvent ) {
			extractUsageFallback( rawStream, onUsage );
		}
	};

	// ── Generate handler ─────────────────────────────────────────────────────
	const generate = async () => {
		abortControllerRef.current?.abort();

		setLoading( true );
		setError( '' );
		setResult( '' );
		setCopied( false );
		setRunUsage( null );

		const isPostContent = type === 'post_content';

		// Resolve existing content based on context scope.
		let rawContext = '';
		if ( contextScope === 'none' ) {
			rawContext = '';
		} else if ( contextScope === 'selected' ) {
			if ( ! selectedBlocks || selectedBlocks.length === 0 ) {
				setLoading( false );
				setError(
					__( 'No blocks are selected. Select blocks or change Context Scope.', 'ai-content-forge' )
				);
				return;
			}
			const { serialize } = window.wp.blocks || {};
			rawContext = serialize ? serialize( selectedBlocks ) : postContent;
		} else if ( contextScope === 'custom' ) {
			rawContext = customContext || '';
		} else {
			rawContext = postContent;
		}

		const existingSnippet = ( rawContext || '' )
			.replace( /<[^>]*>/g, ' ' )
			.replace( /\s+/g, ' ' )
			.trim()
			.slice( 0, 1000 );

		const payload = {
			type,
			provider,
			title: postTitle,
			keywords,
			tone,
			language,
			post_type: postType,
			existing_content: existingSnippet,
			target_length: isPostContent ? toNumber( targetLength ) : 0,
			structure: isPostContent ? structure : '',
			model: modelOverride,
			max_output_tokens: toNumber( maxOutputTokens ),
			max_thinking_tokens: toNumber( maxThinkingTokens ),
			temperature: Number.isFinite( temperature ) ? temperature : toNumber( temperature ),
		};

		try {
			const controller = new window.AbortController();
			abortControllerRef.current = controller;

			await streamGenerate( payload, controller.signal, ( chunk ) => {
				setResult( ( current ) => current + chunk );
			}, ( usage ) => {
				setRunUsage( usage );
				setUsageByProvider( ( current ) => {
					const providerKey = usage?.provider || 'unknown';
					const postKey = String( postId || 0 );
					const bucketKey = `${ postKey }::${ providerKey }`;
					const prev = current[ bucketKey ] || {
						provider: providerKey,
						postId: postKey,
						runs: 0,
						input_tokens: 0,
						thinking_tokens: 0,
						output_tokens: 0,
						total_tokens: 0,
						cost_usd: 0,
					};

					return {
						...current,
						[ bucketKey ]: {
							...prev,
							model: usage?.model || prev.model || '',
							runs: prev.runs + 1,
							input_tokens: prev.input_tokens + toNumber( usage?.input_tokens ),
							thinking_tokens: prev.thinking_tokens + toNumber( usage?.thinking_tokens ),
							output_tokens: prev.output_tokens + toNumber( usage?.output_tokens ),
							total_tokens: prev.total_tokens + toNumber( usage?.total_tokens ),
							cost_usd: prev.cost_usd + toNumber( usage?.cost_usd ),
						},
					};
				} );
			} );
		} catch ( e ) {
			if ( e?.name === 'AbortError' ) {
				return;
			}

			setError(
				e?.message || __( 'Request failed', 'ai-content-forge' )
			);
		} finally {
			abortControllerRef.current = null;
			setLoading( false );
		}
	};

	const stopGeneration = async () => {
		const controller = abortControllerRef.current;
		if ( ! controller ) {
			return;
		}

		controller.abort();

		try {
			await apiFetch( {
				path: `/${ restNamespace }/generate-stop`,
				method: 'POST',
				data: { provider },
			} );
		} catch ( e ) {
			// Best-effort backend stop; front-end abort already ended the UI stream.
		}
	};

	const copyToClipboard = () => {
		window.navigator.clipboard.writeText( result ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} );
	};

	const toNumber = ( value ) => {
		const n = Number( value );
		return Number.isFinite( n ) ? n : 0;
	};

	const formatTokenValue = ( value ) => {
		if ( value === null || value === undefined ) {
			return 'n/a';
		}

		const n = Number( value );
		if ( ! Number.isFinite( n ) ) {
			return 'n/a';
		}

		return n.toLocaleString();
	};

	const formatUsd = ( value ) => {
		const n = Number( value );
		if ( ! Number.isFinite( n ) || n <= 0 ) {
			return '$0.000000';
		}

		return `$${ n.toFixed( 6 ) }`;
	};

	const currentPostUsageRows = Object.values( usageByProvider ).filter(
		( row ) => String( row.postId ) === String( postId || 0 )
	);

	const isPostContent = type === 'post_content';
	const hasModels = providerModels.length > 0;
	const defaultModelLabel = getDefaultModelLabel( activeProvider );
	const modelOptions = [
		{ value: '', label: `Default (${ defaultModelLabel })` },
		...providerModels.map( ( m ) => ( { value: m.id, label: m.label || m.id } ) ),
	];

	return (
		<Panel>
			{ /* ── Generate ───────────────────────────── */ }
			<PanelBody
				title={ __( 'Generate', 'ai-content-forge' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<SelectControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'Content Type', 'ai-content-forge' ) }
						value={ type }
						options={ TYPE_OPTIONS }
						onChange={ setType }
					/>
				</PanelRow>

				<PanelRow>
					<SelectControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'AI Provider', 'ai-content-forge' ) }
						value={ provider }
						options={ PROVIDER_OPTIONS }
						onChange={ setProvider }
					/>
				</PanelRow>

				<PanelRow>
					<TextControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __(
							'Keywords / Topic hints',
							'ai-content-forge'
						) }
						value={ keywords }
						onChange={ setKeywords }
						placeholder="e.g. WordPress, AI, automation"
					/>
				</PanelRow>

				<PanelRow>
					<SelectControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'Tone', 'ai-content-forge' ) }
						value={ tone }
						options={ TONE_OPTIONS }
						onChange={ setTone }
					/>
				</PanelRow>

				<PanelRow>
					<SelectControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'Language', 'ai-content-forge' ) }
						value={ language }
						options={ LANG_OPTIONS }
						onChange={ setLanguage }
					/>
				</PanelRow>

				<PanelRow>
					<SelectControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'Context Scope', 'ai-content-forge' ) }
						value={ contextScope }
						options={ CONTEXT_SCOPE_OPTIONS }
						onChange={ setContextScope }
					/>
				</PanelRow>

				{ contextScope === 'custom' && (
					<PanelRow>
						<TextareaControl
							{ ...NEXT_TEXTAREA_PROPS }
							label={ __( 'Custom Context', 'ai-content-forge' ) }
							value={ customContext }
							onChange={ setCustomContext }
							rows={ 4 }
							placeholder={ __( 'Paste any reference text to guide the generation.', 'ai-content-forge' ) }
						/>
					</PanelRow>
				) }

				{ isPostContent && (
					<>
						<PanelRow>
							<SelectControl
								{ ...NEXT_CONTROL_PROPS }
								label={ __( 'Structure', 'ai-content-forge' ) }
								value={ structure }
								options={ STRUCTURE_OPTIONS }
								onChange={ setStructure }
							/>
						</PanelRow>

						<PanelRow>
							<SelectControl
								{ ...NEXT_CONTROL_PROPS }
								label={ __( 'Target Length', 'ai-content-forge' ) }
								value={ targetLength }
								options={ TARGET_LENGTH_OPTIONS }
								onChange={ setTargetLength }
							/>
						</PanelRow>
					</>
				) }

				<PanelRow>
					<Button
						variant="primary"
						onClick={ generate }
						disabled={ loading }
						style={ { width: '100%', justifyContent: 'center' } }
					>
						{ loading ? (
							<>
								<Spinner />{ ' ' }
								{ __( 'Generating…', 'ai-content-forge' ) }
							</>
						) : (
							__( '⚡ Generate', 'ai-content-forge' )
						) }
					</Button>
				</PanelRow>

				{ loading && (
					<PanelRow>
						<Button
							variant="secondary"
							onClick={ stopGeneration }
							style={ { width: '100%', justifyContent: 'center' } }
						>
							{ __( 'Stop', 'ai-content-forge' ) }
						</Button>
					</PanelRow>
				) }

				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }

			</PanelBody>

			{ /* ── Advanced ───────────────────────────── */ }
			<PanelBody
				title={ __( 'Advanced', 'ai-content-forge' ) }
				initialOpen={ false }
			>
				{ modelsError && (
					<Notice status="warning" isDismissible={ false }>
						{ modelsError }
					</Notice>
				) }

				{ hasModels ? (
					<PanelRow>
						<SelectControl
							{ ...NEXT_CONTROL_PROPS }
							label={ __( 'Model Override', 'ai-content-forge' ) }
							value={ modelOverride }
							options={ modelOptions }
							onChange={ setModelOverride }
							disabled={ modelsLoading }
							help={
								modelsLoading
									? __( 'Loading provider models…', 'ai-content-forge' )
									: __( 'Leave set to Default to use the saved provider model.', 'ai-content-forge' )
							}
						/>
					</PanelRow>
				) : (
					<PanelRow>
						<TextControl
							{ ...NEXT_CONTROL_PROPS }
							label={ __( 'Model Override', 'ai-content-forge' ) }
							value={ modelOverride }
							onChange={ setModelOverride }
							placeholder={ __( 'e.g. gpt-5.1', 'ai-content-forge' ) }
							help={ __( 'Enter a model name if you want to override the saved provider model.', 'ai-content-forge' ) }
						/>
					</PanelRow>
				) }

				<PanelRow>
					<TextControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'Max Output Tokens', 'ai-content-forge' ) }
						type="number"
						min={ 1 }
						value={ maxOutputTokens }
						onChange={ setMaxOutputTokens }
						help={ __( 'Defaults to the global setting unless overridden here.', 'ai-content-forge' ) }
					/>
				</PanelRow>

				<PanelRow>
					<TextControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'Max Thinking Tokens', 'ai-content-forge' ) }
						type="number"
						min={ 0 }
						value={ maxThinkingTokens }
						onChange={ setMaxThinkingTokens }
						help={ __( 'Controls reasoning budget for thinking-capable models.', 'ai-content-forge' ) }
					/>
				</PanelRow>

				<PanelRow>
					<RangeControl
						label={ __( 'Temperature', 'ai-content-forge' ) }
						value={ temperature }
						onChange={ setTemperature }
						min={ 0 }
						max={ 2 }
						step={ 0.1 }
					/>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'Run Usage', 'ai-content-forge' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<div style={ { width: '100%', fontSize: '12px', lineHeight: 1.5 } }>
						{ runUsage ? (
							<>
								<div>
									<strong>{ __( 'Provider:', 'ai-content-forge' ) }</strong>{ ' ' }
									{ runUsage.provider || 'unknown' }
								</div>
								<div>
									<strong>{ __( 'Model:', 'ai-content-forge' ) }</strong>{ ' ' }
									{ runUsage.model || 'unknown' }
								</div>
								<div>
									<strong>{ __( 'Input Tokens:', 'ai-content-forge' ) }</strong>{ ' ' }
									{ formatTokenValue( runUsage.input_tokens ) }
								</div>
								<div>
									<strong>{ __( 'Thinking Tokens:', 'ai-content-forge' ) }</strong>{ ' ' }
									{ formatTokenValue( runUsage.thinking_tokens ) }
								</div>
								<div>
									<strong>{ __( 'Output Tokens:', 'ai-content-forge' ) }</strong>{ ' ' }
									{ formatTokenValue( runUsage.output_tokens ) }
								</div>
								<div>
									<strong>{ __( 'Total Tokens:', 'ai-content-forge' ) }</strong>{ ' ' }
									{ formatTokenValue( runUsage.total_tokens ) }
								</div>
								<div>
									<strong>{ __( 'Cost (USD):', 'ai-content-forge' ) }</strong>{ ' ' }
									{ formatUsd( runUsage.cost_usd ) }
								</div>
							</>
						) : (
							<div style={ { opacity: 0.75 } }>
								{ __( 'Usage appears here after a generation run completes.', 'ai-content-forge' ) }
							</div>
						) }
					</div>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'Post Usage Totals', 'ai-content-forge' ) }
				initialOpen={ false }
			>
				{ currentPostUsageRows.length > 0 ? (
					currentPostUsageRows.map( ( row ) => (
						<PanelRow key={ `${ row.postId }-${ row.provider }` }>
							<div style={ { width: '100%', fontSize: '12px', lineHeight: 1.5 } }>
								<div>
									<strong>{ row.provider }</strong>{ row.model ? ` (${ row.model })` : '' } · { row.runs } run(s)
								</div>
								<div>
									{ __( 'Input:', 'ai-content-forge' ) } { formatTokenValue( row.input_tokens ) } · { __( 'Thinking:', 'ai-content-forge' ) } { formatTokenValue( row.thinking_tokens ) }
								</div>
								<div>
									{ __( 'Output:', 'ai-content-forge' ) } { formatTokenValue( row.output_tokens ) } · { __( 'Total:', 'ai-content-forge' ) } { formatTokenValue( row.total_tokens ) }
								</div>
								<div>
									{ __( 'Cost (USD):', 'ai-content-forge' ) } { formatUsd( row.cost_usd ) }
								</div>
							</div>
						</PanelRow>
					) )
				) : (
					<PanelRow>
						<div style={ { width: '100%', fontSize: '12px', lineHeight: 1.5, opacity: 0.75 } }>
							{ __( 'Totals appear here after at least one generation run for the current post.', 'ai-content-forge' ) }
						</div>
					</PanelRow>
				) }
			</PanelBody>

			{ /* ── Result ─────────────────────────────── */ }
			{ result && (
				<PanelBody
					title={ __( 'Result', 'ai-content-forge' ) }
					initialOpen={ true }
				>
					<PanelRow>
						<TextareaControl
							{ ...NEXT_TEXTAREA_PROPS }
							value={ result }
							onChange={ setResult }
							rows={ 10 }
							style={ {
								fontFamily: 'monospace',
								fontSize: '12px',
							} }
						/>
					</PanelRow>
					<PanelRow>
						<div
							style={ {
								display: 'flex',
								gap: '8px',
								width: '100%',
							} }
						>
							<Button
								variant="secondary"
								onClick={ copyToClipboard }
								style={ { flex: 1 } }
							>
								{ copied
									? __( '✓ Copied!', 'ai-content-forge' )
									: __( 'Copy', 'ai-content-forge' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ () => applyResult( type, result ) }
								style={ { flex: 1 } }
							>
								{ __( 'Apply to Post', 'ai-content-forge' ) }
							</Button>
						</div>
					</PanelRow>
				</PanelBody>
			) }
		</Panel>
	);
}

// ── Register sidebar ──────────────────────────────────────────────────────────
registerPlugin( 'ai-content-forge', {
	render: () => (
		<>
			<PluginSidebarMoreMenuItem target="ai-content-forge-sidebar">
				{ __( 'AI Content Forge', 'ai-content-forge' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="ai-content-forge-sidebar"
				title={ __( 'AI Content Forge', 'ai-content-forge' ) }
				icon="superhero-alt"
			>
				<AcfSidebar />
			</PluginSidebar>
		</>
	),
} );
