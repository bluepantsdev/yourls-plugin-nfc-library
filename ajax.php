<?php
/**
 * NFC Library — Smart Generate lookup endpoint.
 *
 * Called via fetch() from the plugin's admin page. Looks up a book by
 * title/author on Open Library, and returns an ISBN-derived keyword plus
 * cover image and book page URL when a match is found. Bypasses the full
 * Yourls admin page chrome so the response can be plain JSON.
 *
 * Always responds with JSON, even on internal failures, so the admin page
 * can show a real reason instead of a generic "lookup failed". Add
 * &debug=1 to the request to get diagnostic detail back in the response
 * (bootstrap path used, HTTP status from Open Library, any curl error,
 * a preview of the raw response, and any unexpected output that would
 * otherwise have broken the JSON). Debug detail is only included after
 * the Yourls login session and nonce checks pass.
 *
 * Requires the Yourls server itself to have outbound HTTPS access to
 * openlibrary.org.
 */

$nfcbooks_allow_debug = false;

// A blank HTTP 200 usually means a PHP fatal error happened with
// display_errors off. Install a shutdown handler as a safety net so even
// an uncaught fatal still produces a visible, non-empty response instead
// of nothing. Diagnostic detail is only included once auth has passed.
error_reporting( E_ALL );
ini_set( 'display_errors', '0' );

register_shutdown_function( function () use ( &$nfcbooks_allow_debug ) {
	$error = error_get_last();
	if ( $error === null || !in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
		return;
	}
	// A fatal happened. If headers weren't sent yet, we can still return JSON.
	if ( !headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		$payload = array(
			'found' => false,
			'error' => 'PHP fatal error in ajax.php' . ( $nfcbooks_allow_debug ? ' — see debug for detail.' : '.' ),
		);
		if ( $nfcbooks_allow_debug ) {
			$payload['debug'] = array( 'fatal_error' => $error );
		}
		echo json_encode( $payload );
	}
} );

ob_start();
$debug = array();

try {
	$bootstrap = dirname( __DIR__, 3 ) . '/includes/load-yourls.php';
	if ( !file_exists( $bootstrap ) ) {
		throw new Exception( 'Could not find the Yourls bootstrap file — this plugin folder may not be at the expected depth (user/plugins/nfc-library/) under your Yourls root.' );
	}
	require_once $bootstrap;

	if ( !function_exists( 'yourls_is_valid_user' ) || yourls_is_valid_user() !== true ) {
		throw new Exception( 'Not authenticated — log into the Yourls admin in this browser first, then retry.' );
	}

	// Keep in sync with NFCBOOKS_NONCE in plugin.php.
	if ( empty( $_GET['nonce'] ) || !yourls_verify_nonce( 'nfcbooks-manage', $_GET['nonce'], false ) ) {
		throw new Exception( 'Bad or missing security nonce — reload the Library admin page and try again.' );
	}

	if ( isset( $_GET['debug'] ) && $_GET['debug'] === '1' ) {
		$nfcbooks_allow_debug = true;
		ini_set( 'display_errors', '1' );
		$debug['bootstrap_path']   = $bootstrap;
		$debug['bootstrap_exists'] = true;
	}

	$title  = isset( $_GET['title'] ) ? trim( $_GET['title'] ) : '';
	$author = isset( $_GET['author'] ) ? trim( $_GET['author'] ) : '';
	$isbn   = isset( $_GET['isbn'] ) ? trim( $_GET['isbn'] ) : '';

	if ( $isbn !== '' ) {
		$result = nfcbooks_lookup_by_isbn( $isbn, $debug );
	} elseif ( $title !== '' ) {
		$result = nfcbooks_lookup_book( $title, $author, $debug );
	} else {
		throw new Exception( 'Missing title or isbn.' );
	}
} catch ( Throwable $e ) {
	$result = array(
		'found' => false,
		'error' => $e->getMessage(),
	);
}

$unexpected_output = ob_get_clean();

if ( $nfcbooks_allow_debug ) {
	if ( $unexpected_output !== '' ) {
		$debug['unexpected_output'] = substr( $unexpected_output, 0, 800 );
	}
	$result['debug'] = $debug;
}

header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
header( 'Pragma: no-cache' );
echo json_encode( $result );
exit;

function nfcbooks_lookup_book( $title, $author, &$debug ) {
	$option_name    = defined( 'NFCBOOKS_LANGUAGE_OPTION' ) ? NFCBOOKS_LANGUAGE_OPTION : 'nfcbooks_language';
	$default_lang   = defined( 'NFCBOOKS_DEFAULT_LANGUAGE' ) ? NFCBOOKS_DEFAULT_LANGUAGE : 'eng';
	$language       = function_exists( 'yourls_get_option' )
		? yourls_get_option( $option_name, $default_lang )
		: $default_lang;

	$query = 'title=' . rawurlencode( $title );
	if ( $author !== '' ) {
		$query .= '&author=' . rawurlencode( $author );
	}
	if ( $language !== '' ) {
		$query .= '&language=' . rawurlencode( $language );
	}
	// Ask for a few candidates (editions/matches), not just one.
	$url = 'https://openlibrary.org/search.json?' . $query . '&limit=5&fields=title,author_name,isbn,cover_i,key,language';
	$debug['request_url'] = $url;
	$debug['language_filter'] = $language;

	$http = nfcbooks_http_get( $url );
	$debug['http_method']  = $http['method'];
	$debug['http_code']    = $http['code'];
	$debug['http_error']   = $http['error'];
	$debug['response_preview'] = $http['body'] !== false ? substr( $http['body'], 0, 500 ) : null;

	if ( $http['body'] === false ) {
		return array( 'found' => false, 'error' => 'Lookup request failed: ' . ( $http['error'] ?: 'no response body' ) );
	}

	$data = json_decode( $http['body'], true );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		return array( 'found' => false, 'error' => 'Open Library response was not valid JSON: ' . json_last_error_msg() );
	}

	if ( empty( $data['docs'] ) ) {
		return array( 'found' => false );
	}

	$matches = array();
	$seen_isbns = array();

	foreach ( $data['docs'] as $doc ) {
		if ( empty( $doc['isbn'][0] ) ) {
			continue;
		}
		$isbn = preg_replace( '/[^0-9Xx]/', '', $doc['isbn'][0] );
		if ( $isbn === '' || isset( $seen_isbns[ $isbn ] ) ) {
			continue;
		}
		$seen_isbns[ $isbn ] = true;

		$matches[] = array(
			'isbn'      => $isbn,
			'keyword'   => 'isbn' . $isbn,
			'cover_url' => !empty( $doc['cover_i'] ) ? 'https://covers.openlibrary.org/b/id/' . $doc['cover_i'] . '-L.jpg' : '',
			'page_url'  => !empty( $doc['key'] ) ? 'https://openlibrary.org' . $doc['key'] : '',
			'title'     => isset( $doc['title'] ) ? $doc['title'] : $title,
			'author'    => !empty( $doc['author_name'][0] ) ? $doc['author_name'][0] : $author,
		);

		if ( count( $matches ) >= 5 ) {
			break;
		}
	}

	if ( empty( $matches ) ) {
		return array( 'found' => false, 'error' => 'Found matching book(s) but none had a usable ISBN on Open Library.' );
	}

	return array(
		'found'   => true,
		'matches' => $matches,
	);
}

/**
 * Direct ISBN lookup (as opposed to nfcbooks_lookup_book()'s title/author
 * search) -- used by both the admin page's "Look up by ISBN" field and,
 * via this same ajax.php endpoint, anything else that already knows the
 * ISBN (e.g. a barcode scan) and just wants title/author/cover for it.
 */
function nfcbooks_lookup_by_isbn( $isbn, &$debug ) {
	$isbn = preg_replace( '/[^0-9Xx]/', '', $isbn );
	if ( $isbn === '' ) {
		return array( 'found' => false, 'error' => 'Not a valid ISBN.' );
	}

	$url = 'https://openlibrary.org/api/books?bibkeys=ISBN:' . rawurlencode( $isbn ) . '&format=json&jscmd=data';
	$debug['request_url'] = $url;

	$http = nfcbooks_http_get( $url );
	$debug['http_method']  = $http['method'];
	$debug['http_code']    = $http['code'];
	$debug['http_error']   = $http['error'];
	$debug['response_preview'] = $http['body'] !== false ? substr( $http['body'], 0, 500 ) : null;

	if ( $http['body'] === false ) {
		return array( 'found' => false, 'error' => 'Lookup request failed: ' . ( $http['error'] ?: 'no response body' ) );
	}

	$data = json_decode( $http['body'], true );
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		return array( 'found' => false, 'error' => 'Open Library response was not valid JSON: ' . json_last_error_msg() );
	}

	$key = 'ISBN:' . $isbn;
	if ( empty( $data[ $key ] ) ) {
		return array( 'found' => false, 'error' => 'No book found on Open Library for that ISBN.' );
	}

	$book      = $data[ $key ];
	$cover_url = '';
	if ( !empty( $book['cover']['large'] ) ) {
		$cover_url = $book['cover']['large'];
	} elseif ( !empty( $book['cover']['medium'] ) ) {
		$cover_url = $book['cover']['medium'];
	}

	$match = array(
		'isbn'      => $isbn,
		'keyword'   => 'isbn' . $isbn,
		'cover_url' => $cover_url,
		'page_url'  => !empty( $book['url'] ) ? $book['url'] : '',
		'title'     => !empty( $book['title'] ) ? $book['title'] : '',
		'author'    => !empty( $book['authors'][0]['name'] ) ? $book['authors'][0]['name'] : '',
	);

	return array( 'found' => true, 'matches' => array( $match ) );
}

/**
 * Returns array( 'method' => 'curl'|'file_get_contents', 'code' => int|null,
 * 'error' => string|null, 'body' => string|false ).
 */
function nfcbooks_http_get( $url ) {
	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 8 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_USERAGENT, 'nfc-library-yourls-plugin/1.0' );
		$body       = curl_exec( $ch );
		$code       = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curl_error = curl_error( $ch );
		curl_close( $ch );

		if ( $body === false ) {
			return array( 'method' => 'curl', 'code' => $code ?: null, 'error' => $curl_error ?: 'curl_exec returned false', 'body' => false );
		}
		if ( $code >= 400 ) {
			return array( 'method' => 'curl', 'code' => $code, 'error' => 'HTTP ' . $code, 'body' => false );
		}
		return array( 'method' => 'curl', 'code' => $code, 'error' => null, 'body' => $body );
	}

	$context = stream_context_create( array(
		'http' => array(
			'method'  => 'GET',
			'timeout' => 8,
			'header'  => "User-Agent: nfc-library-yourls-plugin/1.0\r\n",
			'ignore_errors' => true,
		),
	) );

	$last_warning = null;
	set_error_handler( function ( $errno, $errstr ) use ( &$last_warning ) {
		$last_warning = $errstr;
		return true;
	} );
	$body = file_get_contents( $url, false, $context );
	restore_error_handler();

	$code = null;
	if ( isset( $http_response_header ) && is_array( $http_response_header ) && !empty( $http_response_header[0] ) ) {
		if ( preg_match( '#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m ) ) {
			$code = (int) $m[1];
		}
	}

	if ( $body === false ) {
		return array( 'method' => 'file_get_contents', 'code' => $code, 'error' => $last_warning ?: 'file_get_contents returned false', 'body' => false );
	}
	if ( $code !== null && $code >= 400 ) {
		return array( 'method' => 'file_get_contents', 'code' => $code, 'error' => 'HTTP ' . $code, 'body' => false );
	}
	return array( 'method' => 'file_get_contents', 'code' => $code, 'error' => null, 'body' => $body );
}
