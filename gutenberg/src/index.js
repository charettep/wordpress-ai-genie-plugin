/**
 * AI Genie — Gutenberg Sidebar Plugin
 *
 * Build: cd gutenberg && npm install && npm run build
 */

import './sidebar.css';
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import {
	Button,
	Icon,
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
import { create } from '@wordpress/icons';
import { useState, useCallback, useRef, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const {
	restNamespace,
	restUrl = '',
	settings,
	promptTemplates = {},
	typeLabels,
	metaKeys,
	nonce,
	assetUrls = {},
} = window.aigGutenberg;
const pluginIconUrl = assetUrls.pluginIcon || '';
const providerIconUrls = assetUrls.providerIcons || {};
const normalizedRestUrl = String( restUrl || '' ).replace( /\/+$/, '' );

const buildRestEndpointUrl = ( endpoint ) => {
	const suffix = String( endpoint || '' ).replace( /^\/+/, '' );

	if ( normalizedRestUrl ) {
		return `${ normalizedRestUrl }/${ suffix }`;
	}

	return `/wp-json/${ restNamespace }/${ suffix }`;
};

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
	{ value: 'claude', label: 'Anthropic Claude' },
	{ value: 'openai', label: 'OpenAI' },
	{ value: 'ollama', label: 'Ollama' },
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

const CONTEXT_SCOPE_OPTIONS = [
	{ value: 'full', label: 'Full post' },
	{ value: 'selected', label: 'Selected blocks' },
	{ value: 'custom', label: 'Custom paste' },
	{ value: 'none', label: 'None' },
];

const PROMPT_PLACEHOLDERS = [
	'{title}',
	'{tone}',
	'{keywords}',
	'{keywords_line}',
	'{post_type}',
	'{language}',
	'{structure}',
	'{structure_line}',
	'{target_length}',
	'{target_length_line}',
	'{existing_content}',
	'{existing_content_block}',
];

const PROVIDER_LABELS = {
	claude: 'Anthropic Claude',
	openai: 'OpenAI',
	ollama: 'Ollama',
};

function ProviderIcon( { provider, size = 18 } ) {
	const iconUrl = providerIconUrls[ provider ];

	if ( ! iconUrl ) {
		return null;
	}

	return (
		<img
			src={ iconUrl }
			alt=""
			aria-hidden="true"
			style={ {
				width: `${ size }px`,
				height: `${ size }px`,
				objectFit: 'contain',
				borderRadius: '4px',
				flexShrink: 0,
			} }
		/>
	);
}

function PluginIconImage( { size = 20 } ) {
	if ( ! pluginIconUrl ) {
		return <Icon icon={ create } />;
	}

	return (
		<img
			src={ pluginIconUrl }
			alt=""
			aria-hidden="true"
			style={ {
				width: `${ size }px`,
				height: `${ size }px`,
				objectFit: 'contain',
				borderRadius: '5px',
				flexShrink: 0,
			} }
		/>
	);
}

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
	const [ promptOverride, setPromptOverride ] = useState( '' );
	const [ maxOutputTokens, setMaxOutputTokens ] = useState(
		String( settings.max_output_tokens ?? 15000 )
	);
	const [ maxThinkingTokens, setMaxThinkingTokens ] = useState(
		String( settings.max_thinking_tokens ?? 15000 )
	);
	const [ temperature, setTemperature ] = useState(
		Number( settings.temperature ?? 0.7 )
	);
	const [ result, setResult ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ stopping, setStopping ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ copied, setCopied ] = useState( false );
	const [ runUsage, setRunUsage ] = useState( null );
	const [ usageByProvider, setUsageByProvider ] = useState( {} );
	const [ providerModels, setProviderModels ] = useState( [] );
	const [ modelsLoading, setModelsLoading ] = useState( false );
	const [ modelsError, setModelsError ] = useState( '' );
	const abortControllerRef = useRef( null );
	const activeRunRef = useRef( null );

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
					err?.message || __( 'Unable to load provider models.', 'ai-genie' )
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

	const streamGenerate = async (
		payload,
		signal,
		onChunk,
		onUsage,
		onUsageEstimate
	) => {
		const response = await window.fetch(
			buildRestEndpointUrl( 'generate-stream' ),
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
				if ( fallback.usage ) {
					onUsage( fallback.usage );
				}
				return;
			}

			throw new Error(
				fallback.message || __( 'Unknown error', 'ai-genie' )
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
			} else if ( event.name === 'usage_estimate' && event.data ) {
				onUsageEstimate( event.data );
			} else if ( event.name === 'usage' && event.data ) {
				sawUsageEvent = true;
				onUsage( event.data );
			} else if ( event.name === 'done' && event.data?.usage && ! sawUsageEvent ) {
				sawUsageEvent = true;
				onUsage( event.data.usage );
			} else if ( event.name === 'error' ) {
				throw new Error(
					event.data?.message ||
						__( 'Request failed', 'ai-genie' )
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
		if ( stopping ) {
			return;
		}

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
					__( 'No blocks are selected. Select blocks or change Context Scope.', 'ai-genie' )
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
			provider: activeProvider,
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
			prompt_override: promptOverride.trim(),
		};

		try {
			const controller = new window.AbortController();
			const generationId = window.crypto?.randomUUID
				? window.crypto.randomUUID()
				: `aig-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }`;

			abortControllerRef.current = controller;
			activeRunRef.current = {
				generationId,
				provider: activeProvider,
			};
			payload.generation_id = generationId;

			await streamGenerate(
				payload,
				controller.signal,
				( chunk ) => {
					setResult( ( current ) => current + chunk );
				},
				( usage ) => {
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
				},
				( usageEstimate ) => {
					setRunUsage( usageEstimate );
				}
			);
		} catch ( e ) {
			if ( e?.name === 'AbortError' ) {
				return;
			}

			setError(
				e?.message || __( 'Request failed', 'ai-genie' )
			);
		} finally {
			abortControllerRef.current = null;
			if ( activeRunRef.current?.generationId === payload.generation_id ) {
				activeRunRef.current = null;
			}
			setLoading( false );
		}
	};

	const stopGeneration = async () => {
		const controller = abortControllerRef.current;
		const activeRun = activeRunRef.current;
		if ( ! controller || ! activeRun || stopping ) {
			return;
		}

		setStopping( true );

		try {
			const stopPromise = apiFetch( {
				path: `/${ restNamespace }/generate-stop`,
				method: 'POST',
				data: {
					provider: activeRun.provider,
					generation_id: activeRun.generationId,
				},
			} );

			controller.abort();
			await stopPromise;
		} catch ( e ) {
			controller.abort();
			// Best-effort backend stop; front-end abort already ended the UI stream.
		} finally {
			setStopping( false );
		}
	};

	const copyToClipboard = () => {
		window.navigator.clipboard.writeText( result ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} );
	};

	const clampNumber = ( value, min, max ) => {
		const numeric = Number( value );
		if ( ! Number.isFinite( numeric ) ) {
			return min;
		}

		return Math.min( max, Math.max( min, Math.round( numeric ) ) );
	};

	const normalizeTargetLengthInput = ( value ) => {
		const digitsOnly = String( value ?? '' ).replace( /[^\d]/g, '' );
		if ( digitsOnly === '' ) {
			return '';
		}

		return String( clampNumber( digitsOnly, 1, 10000 ) );
	};

	const commitTargetLengthInput = () => {
		setTargetLength( ( current ) => {
			if ( current === '' ) {
				return '900';
			}

			return String( clampNumber( current, 1, 10000 ) );
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

	const hasThinkingTokens = ( usage ) => {
		return usage?.thinking_tokens !== null && usage?.thinking_tokens !== undefined && Number( usage.thinking_tokens ) > 0;
	};

	const hasCost = ( usage ) => {
		return usage?.cost_usd !== null && usage?.cost_usd !== undefined && Number.isFinite( Number( usage.cost_usd ) ) && Number( usage.cost_usd ) > 0;
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

	const activeModel = modelOverride || getDefaultModelLabel( activeProvider );
	const isEstimatedRunUsage = Boolean( runUsage?.estimated );

	return (
		<Panel>
			{ /* ── Status chip ─────────────────────────── */ }
			<div style={ {
				display: 'flex',
				alignItems: 'center',
				gap: '6px',
				padding: '8px 16px',
				borderBottom: '1px solid #e0e0e0',
				fontSize: '11px',
				color: '#757575',
			} }>
				<span style={ {
					display: 'inline-block',
					width: '7px',
					height: '7px',
					borderRadius: '50%',
					background: '#2e7d32',
					flexShrink: 0,
				} } />
				<ProviderIcon provider={ activeProvider } />
				<span>
					{ PROVIDER_LABELS[ activeProvider ] || activeProvider } { activeModel !== 'auto' ? `· ${ activeModel }` : '' }
				</span>
			</div>

			{ /* ── Generate ───────────────────────────── */ }
			<PanelBody
				title={ __( 'Generate', 'ai-genie' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<SelectControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'Content Type', 'ai-genie' ) }
						value={ type }
						options={ TYPE_OPTIONS }
						onChange={ setType }
					/>
				</PanelRow>

				<PanelRow>
					<SelectControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'AI Provider', 'ai-genie' ) }
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
							'ai-genie'
						) }
						value={ keywords }
						onChange={ setKeywords }
						placeholder="e.g. WordPress, AI, automation"
					/>
				</PanelRow>

				<PanelRow>
					<SelectControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'Tone', 'ai-genie' ) }
						value={ tone }
						options={ TONE_OPTIONS }
						onChange={ setTone }
					/>
				</PanelRow>

				<PanelRow>
					<SelectControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'Language', 'ai-genie' ) }
						value={ language }
						options={ LANG_OPTIONS }
						onChange={ setLanguage }
					/>
				</PanelRow>

				<PanelRow>
					<SelectControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'Context Scope', 'ai-genie' ) }
						value={ contextScope }
						options={ CONTEXT_SCOPE_OPTIONS }
						onChange={ setContextScope }
					/>
				</PanelRow>

				{ contextScope === 'custom' && (
					<PanelRow>
						<TextareaControl
							{ ...NEXT_TEXTAREA_PROPS }
							label={ __( 'Custom Context', 'ai-genie' ) }
							value={ customContext }
							onChange={ setCustomContext }
							rows={ 4 }
							placeholder={ __( 'Paste any reference text to guide the generation.', 'ai-genie' ) }
						/>
					</PanelRow>
				) }

				{ isPostContent && (
					<>
						<PanelRow>
							<SelectControl
								{ ...NEXT_CONTROL_PROPS }
								label={ __( 'Structure', 'ai-genie' ) }
								value={ structure }
								options={ STRUCTURE_OPTIONS }
								onChange={ setStructure }
							/>
						</PanelRow>

						<PanelRow>
							<TextControl
								{ ...NEXT_CONTROL_PROPS }
								label={ __( 'TARGET LENGTH (WORDS)', 'ai-genie' ) }
								value={ targetLength }
								type="number"
								min={ 1 }
								max={ 10000 }
								step={ 1 }
								onChange={ ( value ) => setTargetLength( normalizeTargetLengthInput( value ) ) }
								onBlur={ commitTargetLengthInput }
								help={ __( 'Type an exact word target from 1 to 10000. Default: 900.', 'ai-genie' ) }
							/>
						</PanelRow>
						<PanelRow>
							<div style={ { width: '100%' } }>
								<RangeControl
									label={ __( 'Length Slider', 'ai-genie' ) }
									value={ clampNumber( targetLength || 900, 1, 10000 ) }
									onChange={ ( value ) => setTargetLength( String( clampNumber( value, 1, 10000 ) ) ) }
									min={ 1 }
									max={ 10000 }
									step={ 1 }
									withInputField={ false }
								/>
							</div>
						</PanelRow>
					</>
				) }

				<PanelRow>
					<Button
						variant="primary"
						onClick={ generate }
						disabled={ loading || stopping }
						style={ { width: '100%', justifyContent: 'center' } }
					>
						{ loading ? (
							<>
								<Spinner />{ ' ' }
								{ stopping
									? __( 'Stopping…', 'ai-genie' )
									: __( 'Generating…', 'ai-genie' ) }
							</>
						) : (
							__( '⚡ Generate', 'ai-genie' )
						) }
					</Button>
				</PanelRow>

				{ loading && (
					<PanelRow>
						<Button
							variant="secondary"
							onClick={ stopGeneration }
							disabled={ stopping }
							style={ { width: '100%', justifyContent: 'center' } }
						>
							{ stopping
								? __( 'Stopping…', 'ai-genie' )
								: __( 'Stop', 'ai-genie' ) }
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
				title={ __( 'Advanced', 'ai-genie' ) }
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
							label={ __( 'Model Override', 'ai-genie' ) }
							value={ modelOverride }
							options={ modelOptions }
							onChange={ setModelOverride }
							disabled={ modelsLoading }
							help={
								modelsLoading
									? __( 'Loading provider models…', 'ai-genie' )
									: __( 'Leave set to Default to use the saved provider model.', 'ai-genie' )
							}
						/>
					</PanelRow>
				) : (
					<PanelRow>
						<TextControl
							{ ...NEXT_CONTROL_PROPS }
							label={ __( 'Model Override', 'ai-genie' ) }
							value={ modelOverride }
							onChange={ setModelOverride }
							placeholder={ __( 'e.g. gpt-5.1', 'ai-genie' ) }
							help={ __( 'Enter a model name if you want to override the saved provider model.', 'ai-genie' ) }
						/>
					</PanelRow>
				) }

				<PanelRow>
					<TextControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'Max Output Tokens', 'ai-genie' ) }
						type="number"
						min={ 1 }
						value={ maxOutputTokens }
						onChange={ setMaxOutputTokens }
						help={ __( 'Defaults to the global setting unless overridden here.', 'ai-genie' ) }
					/>
				</PanelRow>

				<PanelRow>
					<TextControl
						{ ...NEXT_CONTROL_PROPS }
						label={ __( 'Max Thinking Tokens', 'ai-genie' ) }
						type="number"
						min={ 0 }
						value={ maxThinkingTokens }
						onChange={ setMaxThinkingTokens }
						help={ __( 'Controls reasoning budget for thinking-capable models.', 'ai-genie' ) }
					/>
				</PanelRow>

				<PanelRow>
					<RangeControl
						label={ __( 'Temperature', 'ai-genie' ) }
						value={ temperature }
						onChange={ setTemperature }
						min={ 0 }
						max={ 2 }
						step={ 0.1 }
					/>
				</PanelRow>

				<PanelRow>
					<TextareaControl
						{ ...NEXT_TEXTAREA_PROPS }
						label={ __( 'Prompt Template Override', 'ai-genie' ) }
						value={ promptOverride }
						onChange={ setPromptOverride }
						rows={ 10 }
						placeholder={ __( 'Leave blank to use the saved prompt template for this content type.', 'ai-genie' ) }
						help={ __( 'Optional. This overrides the saved prompt template for this generation only.', 'ai-genie' ) }
					/>
				</PanelRow>

				<PanelRow>
					<div style={ { display: 'flex', gap: '8px', width: '100%' } }>
						<Button
							variant="secondary"
							onClick={ () => setPromptOverride( promptTemplates[ type ] || '' ) }
							style={ { flex: 1, justifyContent: 'center' } }
						>
							{ __( 'Load Saved Prompt', 'ai-genie' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => setPromptOverride( '' ) }
							style={ { flex: 1, justifyContent: 'center' } }
						>
							{ __( 'Clear Override', 'ai-genie' ) }
						</Button>
					</div>
				</PanelRow>

				<PanelRow>
					<div style={ { width: '100%' } }>
						<div style={ { marginBottom: '6px', fontSize: '11px', fontWeight: 600, letterSpacing: '0.03em', textTransform: 'uppercase', color: '#555' } }>
							{ __( 'Available Placeholders', 'ai-genie' ) }
						</div>
						<div style={ { display: 'flex', flexWrap: 'wrap', gap: '6px' } }>
							{ PROMPT_PLACEHOLDERS.map( ( placeholder ) => (
								<code key={ placeholder } style={ { padding: '2px 6px', borderRadius: '999px', background: '#f0f0f1', fontSize: '11px' } }>
									{ placeholder }
								</code>
							) ) }
						</div>
					</div>
				</PanelRow>
			</PanelBody>

			<PanelBody
				title={ __( 'Run Usage', 'ai-genie' ) }
				initialOpen={ true }
			>
				<PanelRow>
					<div style={ { width: '100%', fontSize: '12px', lineHeight: 1.5 } }>
						{ runUsage ? (
							<>
								{ isEstimatedRunUsage && (
									<div style={ { marginBottom: '8px' } }>
										<span style={ {
											display: 'inline-block',
											padding: '2px 8px',
											borderRadius: '999px',
											background: '#f0f6fc',
											color: '#0969da',
											fontSize: '11px',
											fontWeight: 600,
										} }>
											{ __( 'Live estimate via tiktoken', 'ai-genie' ) }
										</span>
									</div>
								) }
								<div>
									<strong>{ __( 'Provider:', 'ai-genie' ) }</strong>{ ' ' }
									<span style={ { display: 'inline-flex', alignItems: 'center', gap: '6px' } }>
										<ProviderIcon provider={ runUsage.provider } size={ 16 } />
										<span>{ PROVIDER_LABELS[ runUsage.provider ] || runUsage.provider || 'unknown' }</span>
									</span>
								</div>
								<div>
									<strong>{ __( 'Model:', 'ai-genie' ) }</strong>{ ' ' }
									{ runUsage.model || 'unknown' }
								</div>
								<div>
									<strong>{ __( 'Input Tokens:', 'ai-genie' ) }</strong>{ ' ' }
									{ formatTokenValue( runUsage.input_tokens ) }
								</div>
								{ hasThinkingTokens( runUsage ) && (
									<div>
										<strong>{ __( 'Thinking Tokens:', 'ai-genie' ) }</strong>{ ' ' }
										{ formatTokenValue( runUsage.thinking_tokens ) }
									</div>
								) }
								<div>
									<strong>{ __( 'Output Tokens:', 'ai-genie' ) }</strong>{ ' ' }
									{ formatTokenValue( runUsage.output_tokens ) }
								</div>
								<div>
									<strong>{ __( 'Total Tokens:', 'ai-genie' ) }</strong>{ ' ' }
									{ formatTokenValue( runUsage.total_tokens ) }
								</div>
								{ hasCost( runUsage ) ? (
									<div>
										<strong>{ __( 'Cost (USD):', 'ai-genie' ) }</strong>{ ' ' }
										{ formatUsd( runUsage.cost_usd ) }
									</div>
								) : runUsage.provider === 'ollama' ? (
									<div style={ { opacity: 0.65, fontStyle: 'italic' } }>
										{ __( 'Local model — no API cost.', 'ai-genie' ) }
									</div>
								) : null }
							</>
						) : (
							<div style={ { opacity: 0.75 } }>
								{ __( 'Usage updates here live during generation. Final provider totals replace estimates when available.', 'ai-genie' ) }
							</div>
						) }
					</div>
				</PanelRow>

				{ currentPostUsageRows.length > 0 && (
					<>
						<PanelRow>
							<div style={ { width: '100%', borderTop: '1px solid #e0e0e0', marginTop: '8px', paddingTop: '10px' } }>
								<strong style={ { fontSize: '11px', textTransform: 'uppercase', letterSpacing: '0.05em', color: '#757575' } }>
									{ __( 'Session totals', 'ai-genie' ) }
								</strong>
							</div>
						</PanelRow>
						{ currentPostUsageRows.map( ( row ) => (
							<PanelRow key={ `${ row.postId }-${ row.provider }` }>
								<div style={ { width: '100%', fontSize: '12px', lineHeight: 1.5 } }>
									<div style={ { display: 'inline-flex', alignItems: 'center', gap: '6px' } }>
										<ProviderIcon provider={ row.provider } size={ 16 } />
										<strong>{ PROVIDER_LABELS[ row.provider ] || row.provider }</strong>{ row.model ? ` (${ row.model })` : '' }
										{ ' · ' }{ row.runs } { row.runs === 1 ? __( 'run', 'ai-genie' ) : __( 'runs', 'ai-genie' ) }
									</div>
									<div>
										{ __( 'In:', 'ai-genie' ) } { formatTokenValue( row.input_tokens ) }
										{ hasThinkingTokens( row ) && (
											<>{ ' · ' }{ __( 'Think:', 'ai-genie' ) } { formatTokenValue( row.thinking_tokens ) }</>
										) }
										{ ' · ' }{ __( 'Out:', 'ai-genie' ) } { formatTokenValue( row.output_tokens ) }
									</div>
									{ hasCost( row ) ? (
										<div>
											{ __( 'Cost:', 'ai-genie' ) } { formatUsd( row.cost_usd ) }
										</div>
									) : row.provider === 'ollama' ? (
										<div style={ { opacity: 0.65, fontStyle: 'italic' } }>
											{ __( 'Local model — no API cost.', 'ai-genie' ) }
										</div>
									) : null }
								</div>
							</PanelRow>
						) ) }
					</>
				) }
			</PanelBody>

			{ /* ── Result ─────────────────────────────── */ }
			{ result && (
				<PanelBody
					title={ __( 'Result', 'ai-genie' ) }
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
									? __( '✓ Copied!', 'ai-genie' )
									: __( 'Copy', 'ai-genie' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ () => applyResult( type, result ) }
								style={ { flex: 1 } }
							>
								{ __( 'Apply to Post', 'ai-genie' ) }
							</Button>
						</div>
					</PanelRow>
				</PanelBody>
			) }
		</Panel>
	);
}

// ── Register sidebar ──────────────────────────────────────────────────────────
const CreateIcon = () => <PluginIconImage />;

registerPlugin( 'ai-genie', {
	render: () => (
		<>
			<PluginSidebarMoreMenuItem
				target="ai-genie-sidebar"
				icon={ <CreateIcon /> }
			>
				{ __( 'AI Genie', 'ai-genie' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="ai-genie-sidebar"
				title={ __( 'AI Genie', 'ai-genie' ) }
				icon={ <CreateIcon /> }
				className="aig-gutenberg-sidebar"
			>
				<AcfSidebar />
			</PluginSidebar>
		</>
	),
} );
