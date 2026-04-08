const DEFAULT_PROXY_HEADER = 'X-Ollama-Proxy-Token';
const DEFAULT_ALLOWED_METHODS = 'GET, POST, OPTIONS, HEAD';
const DEFAULT_ALLOWED_HEADERS = [ 'Content-Type', 'Authorization', DEFAULT_PROXY_HEADER ];

export default {
	async fetch( request, env ) {
		const origin = request.headers.get( 'Origin' ) || '';
		const corsHeaders = buildCorsHeaders( origin, env );

		if ( request.method === 'OPTIONS' ) {
			return new Response( null, {
				status: 204,
				headers: corsHeaders,
			} );
		}

		if ( ! isAllowedOrigin( origin, env ) ) {
			return jsonError( 'Origin not allowed.', 403, corsHeaders );
		}

		const proxyHeaderName = ( env.PROXY_AUTH_HEADER_NAME || DEFAULT_PROXY_HEADER ).trim() || DEFAULT_PROXY_HEADER;
		const expectedProxyToken = String( env.PROXY_AUTH_HEADER_VALUE || '' ).trim();
		const receivedProxyToken = String( request.headers.get( proxyHeaderName ) || '' ).trim();

		if ( expectedProxyToken === '' || receivedProxyToken !== expectedProxyToken ) {
			return jsonError( 'Forbidden', 403, corsHeaders );
		}

		const upstreamBaseUrl = String( env.UPSTREAM_OLLAMA_URL || '' ).trim().replace( /\/+$/, '' );
		if ( upstreamBaseUrl === '' ) {
			return jsonError( 'Missing upstream URL.', 500, corsHeaders );
		}

		const upstreamUrl = new URL( request.url );
		const upstreamRequestUrl = upstreamBaseUrl + upstreamUrl.pathname + upstreamUrl.search;
		const upstreamHeaders = new Headers( request.headers );
		const upstreamAccessHeaderName = String( env.UPSTREAM_AUTH_HEADER_NAME || 'Authorization' ).trim() || 'Authorization';
		const upstreamClientId = String( env.UPSTREAM_CF_ACCESS_CLIENT_ID || '' ).trim();
		const upstreamClientSecret = String( env.UPSTREAM_CF_ACCESS_CLIENT_SECRET || '' ).trim();

		upstreamHeaders.delete( 'host' );
		upstreamHeaders.delete( proxyHeaderName );

		if ( upstreamClientId !== '' && upstreamClientSecret !== '' ) {
			upstreamHeaders.set( 'CF-Access-Client-Id', upstreamClientId );
			upstreamHeaders.set( 'CF-Access-Client-Secret', upstreamClientSecret );
			upstreamHeaders.set(
				upstreamAccessHeaderName,
				JSON.stringify( {
					'cf-access-client-id': upstreamClientId,
					'cf-access-client-secret': upstreamClientSecret,
				} )
			);
		}

		try {
			const upstreamResponse = await fetch( upstreamRequestUrl, {
				method: request.method,
				headers: upstreamHeaders,
				body: request.method === 'GET' || request.method === 'HEAD' ? undefined : request.body,
				redirect: 'follow',
			} );

			const responseHeaders = new Headers( upstreamResponse.headers );
			applyCorsHeaders( responseHeaders, origin, env );

			return new Response( upstreamResponse.body, {
				status: upstreamResponse.status,
				statusText: upstreamResponse.statusText,
				headers: responseHeaders,
			} );
		} catch ( error ) {
			return jsonError(
				error instanceof Error ? error.message : 'Upstream request failed.',
				502,
				corsHeaders
			);
		}
	},
};

function jsonError( message, status, headers ) {
	const responseHeaders = new Headers( headers );
	responseHeaders.set( 'Content-Type', 'application/json; charset=utf-8' );

	return new Response(
		JSON.stringify( {
			success: false,
			message,
		} ),
		{
			status,
			headers: responseHeaders,
		}
	);
}

function isAllowedOrigin( origin, env ) {
	if ( origin === '' ) {
		return true;
	}

	const allowedOrigins = parseAllowedOrigins( env.ALLOWED_ORIGINS );

	if ( allowedOrigins.includes( '*' ) ) {
		return true;
	}

	return allowedOrigins.includes( origin );
}

function parseAllowedOrigins( rawValue ) {
	return String( rawValue || '' )
		.split( ',' )
		.map( ( item ) => item.trim() )
		.filter( Boolean );
}

function buildCorsHeaders( origin, env ) {
	const headers = new Headers();
	applyCorsHeaders( headers, origin, env );
	return headers;
}

function applyCorsHeaders( headers, origin, env ) {
	const allowedOrigins = parseAllowedOrigins( env.ALLOWED_ORIGINS );
	const proxyHeaderName = ( env.PROXY_AUTH_HEADER_NAME || DEFAULT_PROXY_HEADER ).trim() || DEFAULT_PROXY_HEADER;
	const allowedHeaders = new Set( DEFAULT_ALLOWED_HEADERS );
	const requestedHeaders = String( env.CORS_ALLOWED_HEADERS || '' )
		.split( ',' )
		.map( ( item ) => item.trim() )
		.filter( Boolean );

	allowedHeaders.add( proxyHeaderName );
	requestedHeaders.forEach( ( header ) => allowedHeaders.add( header ) );

	if ( allowedOrigins.includes( '*' ) ) {
		headers.set( 'Access-Control-Allow-Origin', '*' );
	} else if ( origin !== '' && allowedOrigins.includes( origin ) ) {
		headers.set( 'Access-Control-Allow-Origin', origin );
		headers.append( 'Vary', 'Origin' );
	}

	headers.set( 'Access-Control-Allow-Methods', DEFAULT_ALLOWED_METHODS );
	headers.set( 'Access-Control-Allow-Headers', Array.from( allowedHeaders ).join( ', ' ) );
	headers.set( 'Access-Control-Max-Age', '86400' );
}
