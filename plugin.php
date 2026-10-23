<?php
/*
Plugin Name: NFC Library
Plugin URI: https://sund.link/
Description: Adds a public /library page listing NFC-tagged books, and an admin page to create/edit/delete them. Each book is backed by a normal Yourls short link, so it also works as a plain redirect target.
Version: 0.5.2
Author: Shaun
Author URI:
*/

if ( !defined( 'YOURLS_ABSPATH' ) ) die();

define( 'NFCBOOKS_VERSION', '0.5.2' );
define( 'NFCBOOKS_PASSWORD_ENCRYPT_OPTION', 'nfcbooks_password_encrypt_migrated' );
define( 'NFCBOOKS_PASSWORD_ENC_PREFIX', 'nfcenc1:' );
define( 'NFCBOOKS_TABLE', YOURLS_DB_PREFIX . 'book_tags' );
define( 'NFCBOOKS_NONCE', 'nfcbooks-manage' );
define( 'NFCBOOKS_LANGUAGE_OPTION', 'nfcbooks_language' );
define( 'NFCBOOKS_DEFAULT_LANGUAGE', 'eng' );
define( 'NFCBOOKS_AMAZON_OPTION', 'nfcbooks_amazon_link' );
define( 'NFCBOOKS_DEFAULT_AMAZON_LINK', '1' ); // on by default
define( 'NFCBOOKS_AMAZON_MODE_OPTION', 'nfcbooks_amazon_mode' );
define( 'NFCBOOKS_DEFAULT_AMAZON_MODE', 'title_author' ); // or 'isbn'
define( 'NFCBOOKS_LIBRARY_TITLE_OPTION', 'nfcbooks_library_title' );
define( 'NFCBOOKS_DEFAULT_LIBRARY_TITLE', 'Library' );

/* -----------------------------------------------------------------------
 * Custom API actions: edit / delete
 *
 * Stock Yourls' API (yourls-api.php) only ships create ("shorturl") and
 * read ("stats", "url-stats", "expand", "db-stats") actions -- there's no
 * built-in way to edit or delete a link over the API. Yourls dispatches
 * any action it doesn't recognize through the `api_action_{action}`
 * filter, authenticated the same way (signature token) as any other API
 * call, so we hook that to add `nfclibrary_edit` and `nfclibrary_delete`
 * for the companion iOS app to use.
 * --------------------------------------------------------------------- */

yourls_add_filter( 'api_action_nfclibrary_edit', 'nfcbooks_api_action_edit' );
function nfcbooks_api_action_edit() {
	$keyword     = isset( $_REQUEST['keyword'] ) ? yourls_sanitize_string( $_REQUEST['keyword'] ) : '';
	$url         = isset( $_REQUEST['url'] ) ? $_REQUEST['url'] : '';
	$title       = isset( $_REQUEST['title'] ) ? $_REQUEST['title'] : '';
	$new_keyword = ( isset( $_REQUEST['new_keyword'] ) && $_REQUEST['new_keyword'] !== '' )
		? yourls_sanitize_string( $_REQUEST['new_keyword'] )
		: $keyword;

	if ( $keyword === '' || $url === '' ) {
		return array( 'status' => 'fail', 'statusCode' => 400, 'message' => 'Missing keyword or url.' );
	}
	if ( yourls_get_keyword_longurl( $keyword ) === false ) {
		return array( 'status' => 'fail', 'statusCode' => 404, 'message' => 'No link found for that keyword.' );
	}
	if ( $new_keyword === 'library' ) {
		return array( 'status' => 'fail', 'statusCode' => 400, 'message' => 'The keyword "library" is reserved for the public listing page.' );
	}
	if ( $new_keyword !== $keyword && yourls_get_keyword_longurl( $new_keyword ) !== false ) {
		return array( 'status' => 'fail', 'statusCode' => 409, 'message' => 'That keyword is already in use.' );
	}

	yourls_edit_link( $url, $keyword, $new_keyword, $title );

	$ydb = yourls_get_db();

	// Carry this book's row over to the new keyword *before* the upsert
	// below (which is keyed by keyword) -- otherwise renaming would leave
	// its author/cover/notes/password orphaned under the old keyword
	// (book_tags' primary key) instead of following the link.
	if ( $new_keyword !== $keyword ) {
		$ydb->fetchAffected(
			"UPDATE `" . NFCBOOKS_TABLE . "` SET keyword = :new_keyword WHERE keyword = :keyword",
			array( 'new_keyword' => $new_keyword, 'keyword' => $keyword )
		);
	}

	// Optional book metadata + password -- each is only written when the
	// request explicitly includes it, so omitting a field leaves whatever
	// is already stored untouched rather than blanking it out. (Password is
	// metadata only: this plugin never uses it to actually lock the
	// physical NFC tag, it's just remembered so the app can show it back to
	// you later.)
	$optional_fields = array( 'author', 'cover_url', 'notes', 'password' );
	$provided         = array( 'keyword' => $new_keyword, 'title' => $title );
	$update_clauses   = array( 'title = VALUES(title)' );
	foreach ( $optional_fields as $field ) {
		if ( isset( $_REQUEST[ $field ] ) ) {
			if ( $field === 'password' ) {
				$provided[ $field ] = nfcbooks_prepare_stored_password( $_REQUEST[ $field ] );
			} else {
				$provided[ $field ] = $_REQUEST[ $field ];
			}
			$update_clauses[] = "`$field` = VALUES(`$field`)";
		}
	}

	$columns      = array_keys( $provided );
	$placeholders = array_map( function ( $c ) { return ':' . $c; }, $columns );

	// Upsert (not plain UPDATE): links created via the iOS app go through the
	// native "shorturl" action, which never inserts a NFCBOOKS_TABLE row, so
	// there may be nothing here yet to update.
	$ydb->fetchAffected(
		"INSERT INTO `" . NFCBOOKS_TABLE . "` (`" . implode( '`, `', $columns ) . "`, created)
		 VALUES (" . implode( ', ', $placeholders ) . ", NOW())
		 ON DUPLICATE KEY UPDATE " . implode( ', ', $update_clauses ),
		$provided
	);

	return array(
		'status'     => 'success',
		'statusCode' => 200,
		'message'    => 'Link updated.',
		'keyword'    => $new_keyword,
		'url'        => $url,
		'title'      => $title,
	);
}

yourls_add_filter( 'api_action_nfclibrary_delete', 'nfcbooks_api_action_delete' );
function nfcbooks_api_action_delete() {
	$keyword = isset( $_REQUEST['keyword'] ) ? yourls_sanitize_string( $_REQUEST['keyword'] ) : '';

	if ( $keyword === '' ) {
		return array( 'status' => 'fail', 'statusCode' => 400, 'message' => 'Missing keyword.' );
	}
	if ( yourls_get_keyword_longurl( $keyword ) === false ) {
		return array( 'status' => 'fail', 'statusCode' => 404, 'message' => 'No link found for that keyword.' );
	}

	yourls_delete_link_by_keyword( $keyword );

	$ydb = yourls_get_db();
	$ydb->fetchAffected(
		"DELETE FROM `" . NFCBOOKS_TABLE . "` WHERE keyword = :keyword",
		array( 'keyword' => $keyword )
	);

	return array(
		'status'     => 'success',
		'statusCode' => 200,
		'message'    => 'Link deleted.',
		'keyword'    => $keyword,
	);
}

yourls_add_filter( 'api_action_nfclibrary_meta', 'nfcbooks_api_action_meta' );
function nfcbooks_api_action_meta() {
	// Bulk-fetch this plugin's per-keyword metadata (author, cover, notes,
	// password) so the app can merge it into the native "stats" action's
	// results without one API round-trip per link. Passwords are encrypted at
	// rest but returned decrypted here (API signature auth required).
	$ydb  = yourls_get_db();
	$rows = $ydb->fetchObjects( "SELECT keyword, author, cover_url, notes, password FROM `" . NFCBOOKS_TABLE . "`" );

	$meta = array();
	foreach ( $rows as $row ) {
		$stored   = (string) $row->password;
		$password = nfcbooks_password_for_api( $stored );
		$meta[ $row->keyword ] = array(
			'author'       => (string) $row->author,
			'cover_url'    => (string) $row->cover_url,
			'notes'        => (string) $row->notes,
			'password'     => $password,
			'has_password' => ( $stored !== '' ),
		);
	}

	return array(
		'status'     => 'success',
		'statusCode' => 200,
		'meta'       => $meta,
	);
}

yourls_add_filter( 'api_action_nfclibrary_status', 'nfcbooks_api_action_status' );
function nfcbooks_api_action_status() {
	// Lets the companion iOS app confirm this plugin is installed and
	// active, and which version -- without this action existing at all,
	// Yourls' core API responds with its own "unknown action" error,
	// which the app also treats as "not detected".
	return array(
		'status'     => 'success',
		'statusCode' => 200,
		'version'    => NFCBOOKS_VERSION,
		'message'    => 'NFC Library plugin active.',
	);
}

/* -----------------------------------------------------------------------
 * Setup: make sure our extra table exists
 * --------------------------------------------------------------------- */

yourls_add_action( 'plugins_loaded', 'nfcbooks_maybe_create_table' );
function nfcbooks_get_language() {
	return yourls_get_option( NFCBOOKS_LANGUAGE_OPTION, NFCBOOKS_DEFAULT_LANGUAGE );
}

function nfcbooks_amazon_link_enabled() {
	return yourls_get_option( NFCBOOKS_AMAZON_OPTION, NFCBOOKS_DEFAULT_AMAZON_LINK ) === '1';
}

function nfcbooks_amazon_search_url( $title, $author ) {
	$q = trim( $title . ' ' . $author );
	return 'https://www.amazon.com/s?k=' . rawurlencode( $q ) . '&i=stripbooks';
}

function nfcbooks_get_amazon_mode() {
	$mode = yourls_get_option( NFCBOOKS_AMAZON_MODE_OPTION, NFCBOOKS_DEFAULT_AMAZON_MODE );
	return in_array( $mode, array( 'title_author', 'isbn' ), true ) ? $mode : NFCBOOKS_DEFAULT_AMAZON_MODE;
}

function nfcbooks_get_library_title() {
	$title = trim( (string) yourls_get_option( NFCBOOKS_LIBRARY_TITLE_OPTION, '' ) );
	return $title !== '' ? $title : NFCBOOKS_DEFAULT_LIBRARY_TITLE;
}

// Books created by Smart Generate have a keyword like "isbn9780441007493" —
// no separate ISBN column, so we recover it from the keyword when we can.
function nfcbooks_extract_isbn_from_keyword( $keyword ) {
	if ( preg_match( '/^isbn([0-9Xx]{9,13})$/', (string) $keyword, $m ) ) {
		return $m[1];
	}
	return '';
}

// Amazon product pages are addressed by ASIN, and for print books that ASIN
// is almost always just the ISBN-10. We only ever have ISBN-13 on hand
// (that's what Open Library gives us), so convert it when possible.
function nfcbooks_isbn13_to_isbn10( $isbn13 ) {
	$isbn13 = preg_replace( '/[^0-9]/', '', (string) $isbn13 );
	if ( strlen( $isbn13 ) !== 13 || substr( $isbn13, 0, 3 ) !== '978' ) {
		// Only 978-prefixed ISBN-13s have an ISBN-10 equivalent; 979-prefixed
		// ones (increasingly common for newer books) simply don't.
		return '';
	}
	$core = substr( $isbn13, 3, 9 );
	$sum  = 0;
	for ( $i = 0; $i < 9; $i++ ) {
		$sum += ( 10 - $i ) * (int) $core[ $i ];
	}
	$remainder  = $sum % 11;
	$check      = ( 11 - $remainder ) % 11;
	$check_char = ( $check === 10 ) ? 'X' : (string) $check;
	return $core . $check_char;
}

function nfcbooks_normalize_isbn10( $isbn ) {
	$isbn = strtoupper( preg_replace( '/[^0-9Xx]/', '', (string) $isbn ) );
	if ( strlen( $isbn ) === 10 ) {
		return $isbn;
	}
	if ( strlen( $isbn ) === 13 ) {
		return nfcbooks_isbn13_to_isbn10( $isbn );
	}
	return '';
}

/**
 * Returns array( 'url' => <primary link to show>, 'is_direct' => bool,
 * 'search_fallback' => <a search link, or null when the primary IS the
 * search> ). The direct /dp/ ISBN-10 link is a heuristic (ASIN isn't always
 * identical to ISBN-10) so whenever we use it, we also hand back a search
 * link the book page can offer as a "not the right book?" escape hatch.
 */
function nfcbooks_amazon_urls( $book ) {
	if ( nfcbooks_get_amazon_mode() === 'isbn' ) {
		$isbn = nfcbooks_extract_isbn_from_keyword( $book->keyword );
		if ( $isbn !== '' ) {
			$isbn10 = nfcbooks_normalize_isbn10( $isbn );
			if ( $isbn10 !== '' ) {
				// Direct product page — works for most English print editions,
				// but the ASIN can occasionally point at the wrong edition/title.
				return array(
					'url'             => 'https://www.amazon.com/dp/' . $isbn10,
					'is_direct'       => true,
					'search_fallback' => 'https://www.amazon.com/s?k=' . rawurlencode( $isbn ) . '&i=stripbooks',
				);
			}
			// Couldn't derive a clean ISBN-10 (e.g. a 979-prefixed ISBN-13) —
			// an ISBN search still beats a /dp/ link that would just 404.
			return array(
				'url'             => 'https://www.amazon.com/s?k=' . rawurlencode( $isbn ) . '&i=stripbooks',
				'is_direct'       => false,
				'search_fallback' => null,
			);
		}
		// No ISBN on record for this book (added via Random/Custom) — fall back.
	}
	return array(
		'url'             => nfcbooks_amazon_search_url( $book->title, $book->author ),
		'is_direct'       => false,
		'search_fallback' => null,
	);
}

// Kept for anything that just wants the one link (e.g. future API use).
function nfcbooks_amazon_url( $book ) {
	$urls = nfcbooks_amazon_urls( $book );
	return $urls['url'];
}

function nfcbooks_maybe_create_table() {
	$ydb = yourls_get_db();
	$ydb->fetchAffected(
		"CREATE TABLE IF NOT EXISTS `" . NFCBOOKS_TABLE . "` (
			`keyword` VARCHAR(200) NOT NULL,
			`title` VARCHAR(255) NOT NULL DEFAULT '',
			`author` VARCHAR(255) NOT NULL DEFAULT '',
			`cover_url` VARCHAR(500) NOT NULL DEFAULT '',
			`notes` TEXT NULL,
			`password` VARCHAR(255) NOT NULL DEFAULT '',
			`created` DATETIME NOT NULL,
			PRIMARY KEY (`keyword`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);
	nfcbooks_maybe_add_password_column();
	nfcbooks_maybe_widen_password_column();
	nfcbooks_maybe_encrypt_passwords_at_rest();
}

// Installs that already had this table before 0.3.0 won't have the
// `password` column yet -- add it if it's missing.
function nfcbooks_maybe_add_password_column() {
	$ydb = yourls_get_db();
	$existing = $ydb->fetchObjects( "SHOW COLUMNS FROM `" . NFCBOOKS_TABLE . "` LIKE 'password'" );
	if ( empty( $existing ) ) {
		$ydb->fetchAffected( "ALTER TABLE `" . NFCBOOKS_TABLE . "` ADD COLUMN `password` VARCHAR(255) NOT NULL DEFAULT '' AFTER `notes`" );
	}
}

function nfcbooks_password_key_configured() {
	return defined( 'NFCBOOKS_PASSWORD_KEY' ) && NFCBOOKS_PASSWORD_KEY !== '';
}

function nfcbooks_password_encryption_key() {
	if ( !nfcbooks_password_key_configured() ) {
		return false;
	}
	$raw = (string) NFCBOOKS_PASSWORD_KEY;
	if ( preg_match( '/^[0-9a-f]{64}$/i', $raw ) ) {
		$key = hex2bin( $raw );
		return ( $key !== false && strlen( $key ) === 32 ) ? $key : false;
	}
	$key = base64_decode( $raw, true );
	if ( $key !== false && strlen( $key ) === 32 ) {
		return $key;
	}
	return false;
}

function nfcbooks_password_encryption_available() {
	return nfcbooks_password_encryption_key() !== false;
}

function nfcbooks_password_is_encrypted( $stored ) {
	return is_string( $stored ) && strncmp( $stored, NFCBOOKS_PASSWORD_ENC_PREFIX, strlen( NFCBOOKS_PASSWORD_ENC_PREFIX ) ) === 0;
}

function nfcbooks_encrypt_password( $plain ) {
	$plain = (string) $plain;
	if ( $plain === '' || nfcbooks_password_is_encrypted( $plain ) ) {
		return $plain;
	}
	$key = nfcbooks_password_encryption_key();
	if ( $key === false ) {
		return $plain;
	}

	if ( function_exists( 'sodium_crypto_secretbox' ) ) {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );
		if ( $cipher === false ) {
			return $plain;
		}
		return NFCBOOKS_PASSWORD_ENC_PREFIX . 'sbox:' . base64_encode( $nonce . $cipher );
	}

	if ( function_exists( 'openssl_encrypt' ) ) {
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( $cipher === false || strlen( $tag ) !== 16 ) {
			return $plain;
		}
		return NFCBOOKS_PASSWORD_ENC_PREFIX . 'gcm:' . base64_encode( $iv . $tag . $cipher );
	}

	return $plain;
}

function nfcbooks_decrypt_password( $stored ) {
	$stored = (string) $stored;
	if ( $stored === '' || !nfcbooks_password_is_encrypted( $stored ) ) {
		return $stored;
	}
	$key = nfcbooks_password_encryption_key();
	if ( $key === false ) {
		return '';
	}

	$body = substr( $stored, strlen( NFCBOOKS_PASSWORD_ENC_PREFIX ) );
	$sep  = strpos( $body, ':' );
	if ( $sep === false ) {
		return '';
	}
	$method = substr( $body, 0, $sep );
	$raw    = base64_decode( substr( $body, $sep + 1 ), true );
	if ( $raw === false ) {
		return '';
	}

	if ( $method === 'sbox' && function_exists( 'sodium_crypto_secretbox_open' ) ) {
		$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( strlen( $raw ) <= $nonce_len ) {
			return '';
		}
		$nonce  = substr( $raw, 0, $nonce_len );
		$cipher = substr( $raw, $nonce_len );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		return ( $plain === false ) ? '' : $plain;
	}

	if ( $method === 'gcm' && function_exists( 'openssl_decrypt' ) ) {
		if ( strlen( $raw ) <= 28 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return ( $plain === false ) ? '' : $plain;
	}

	return '';
}

function nfcbooks_prepare_stored_password( $plain ) {
	$plain = (string) $plain;
	if ( $plain === '' ) {
		return '';
	}
	if ( nfcbooks_password_is_encrypted( $plain ) ) {
		return $plain;
	}
	return nfcbooks_encrypt_password( $plain );
}

function nfcbooks_password_for_api( $stored ) {
	return nfcbooks_decrypt_password( $stored );
}

function nfcbooks_maybe_widen_password_column() {
	$ydb = yourls_get_db();
	$cols = $ydb->fetchObjects( "SHOW COLUMNS FROM `" . NFCBOOKS_TABLE . "` LIKE 'password'" );
	if ( empty( $cols ) ) {
		return;
	}
	$type = isset( $cols[0]->Type ) ? strtolower( (string) $cols[0]->Type ) : '';
	if ( preg_match( '/varchar\((\d+)\)/', $type, $m ) && (int) $m[1] < 255 ) {
		$ydb->fetchAffected( "ALTER TABLE `" . NFCBOOKS_TABLE . "` MODIFY COLUMN `password` VARCHAR(255) NOT NULL DEFAULT ''" );
	}
}

function nfcbooks_maybe_encrypt_passwords_at_rest() {
	if ( yourls_get_option( NFCBOOKS_PASSWORD_ENCRYPT_OPTION, '0' ) === '1' ) {
		return;
	}
	if ( !nfcbooks_password_encryption_available() ) {
		return;
	}

	$ydb  = yourls_get_db();
	$rows = $ydb->fetchObjects( "SELECT keyword, password FROM `" . NFCBOOKS_TABLE . "` WHERE password != ''" );
	foreach ( $rows as $row ) {
		$stored = (string) $row->password;
		if ( nfcbooks_password_is_encrypted( $stored ) ) {
			continue;
		}
		$encrypted = nfcbooks_encrypt_password( $stored );
		if ( $encrypted === $stored ) {
			continue;
		}
		$ydb->fetchAffected(
			"UPDATE `" . NFCBOOKS_TABLE . "` SET password = :password WHERE keyword = :keyword",
			array(
				'password' => $encrypted,
				'keyword'  => $row->keyword,
			)
		);
	}

	yourls_update_option( NFCBOOKS_PASSWORD_ENCRYPT_OPTION, '1' );
}

/* -----------------------------------------------------------------------
 * Public /library page
 * Intercepted early, before Yourls tries to resolve "library" as a keyword,
 * so the keyword "library" itself is reserved by this plugin — don't use it
 * for a regular short link.
 * --------------------------------------------------------------------- */

yourls_add_action( 'plugins_loaded', 'nfcbooks_intercept_public_page' );
function nfcbooks_intercept_public_page() {
	$path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
	if ( $path === '' ) {
		return;
	}

	if ( $path === 'library' ) {
		nfcbooks_render_public_page();
		exit;
	}

	// Any keyword that's a tagged book gets its own local info page instead
	// of a normal Yourls redirect straight out to the external URL.
	$book = nfcbooks_get_book( $path );
	if ( $book ) {
		nfcbooks_bump_clicks( $book->keyword );
		nfcbooks_render_book_page( $book );
		exit;
	}
}

function nfcbooks_bump_clicks( $keyword ) {
	// We're intercepting before Yourls' own redirect (and click-counting)
	// logic runs, so bump the counter ourselves to keep it meaningful.
	$ydb = yourls_get_db();
	$ydb->fetchAffected(
		"UPDATE `" . YOURLS_DB_TABLE_URL . "` SET clicks = clicks + 1 WHERE keyword = :keyword",
		array( 'keyword' => $keyword )
	);
}

function nfcbooks_get_all_books() {
	$ydb = yourls_get_db();
	return $ydb->fetchObjects(
		"SELECT b.*, u.url AS target_url, u.clicks AS clicks
		 FROM `" . NFCBOOKS_TABLE . "` b
		 LEFT JOIN `" . YOURLS_DB_TABLE_URL . "` u ON u.keyword = b.keyword
		 ORDER BY b.author, b.title"
	);
}

function nfcbooks_get_book( $keyword ) {
	$ydb  = yourls_get_db();
	$rows = $ydb->fetchObjects(
		"SELECT b.*, u.url AS target_url, u.clicks AS clicks
		 FROM `" . NFCBOOKS_TABLE . "` b
		 LEFT JOIN `" . YOURLS_DB_TABLE_URL . "` u ON u.keyword = b.keyword
		 WHERE b.keyword = :keyword
		 LIMIT 1",
		array( 'keyword' => $keyword )
	);
	return !empty( $rows ) ? $rows[0] : null;
}

function nfcbooks_render_public_page() {
	$books         = nfcbooks_get_all_books();
	$site          = yourls_get_yourls_site();
	$library_title = nfcbooks_get_library_title();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Cache-Control: no-cache, must-revalidate' );
	?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo yourls_esc_html( $library_title ); ?></title>
<style>
	body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem;color:#222;background:#fff}
	h1{font-size:1.6rem;margin-bottom:1.25rem}
	ul{list-style:none;padding:0;margin:0}
	li{display:flex;gap:.9rem;padding:.75rem 0;border-bottom:1px solid #eee;align-items:center}
	li img{width:44px;height:64px;object-fit:cover;border-radius:3px;background:#eee;flex-shrink:0}
	li a{color:#0645ad;text-decoration:none;font-weight:600}
	li a:hover{text-decoration:underline}
	.author{color:#666;font-size:.9rem}
	.empty{color:#666}
</style>
</head>
<body>
<h1><?php echo yourls_esc_html( $library_title ); ?></h1>
<?php if ( empty( $books ) ) : ?>
	<p class="empty">No books tagged yet.</p>
<?php else : ?>
	<ul>
	<?php foreach ( $books as $b ) : ?>
		<li>
			<?php if ( !empty( $b->cover_url ) ) : ?>
				<img src="<?php echo yourls_esc_attr( $b->cover_url ); ?>" alt="">
			<?php endif; ?>
			<div>
				<div><a href="<?php echo yourls_esc_attr( $site . '/' . $b->keyword ); ?>"><?php echo yourls_esc_html( $b->title ); ?></a></div>
				<?php if ( !empty( $b->author ) ) : ?><div class="author"><?php echo yourls_esc_html( $b->author ); ?></div><?php endif; ?>
			</div>
		</li>
	<?php endforeach; ?>
	</ul>
<?php endif; ?>
</body>
</html>
<?php
}

function nfcbooks_render_book_page( $book ) {
	$site = yourls_get_yourls_site();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Cache-Control: no-cache, must-revalidate' );
	?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo yourls_esc_html( $book->title ); ?></title>
<style>
	body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:520px;margin:2rem auto;padding:0 1rem;color:#222;background:#fff}
	.cover{width:160px;border-radius:6px;box-shadow:0 2px 10px rgba(0,0,0,.15);margin-bottom:1.1rem}
	h1{font-size:1.6rem;margin:0 0 .2rem}
	.author{color:#666;margin-bottom:1rem}
	.notes{white-space:pre-wrap;line-height:1.5;margin:1rem 0;color:#333}
	.info-link{display:inline-block;margin-top:.6rem;margin-right:.5rem;padding:.5rem .9rem;background:#0645ad;color:#fff;text-decoration:none;font-weight:600;border-radius:5px}
	.info-link:hover{background:#043a8c}
	.amazon-link{display:inline-block;margin-top:.6rem;padding:.5rem .9rem;background:#f0c14b;color:#111;text-decoration:none;font-weight:600;border-radius:5px;border:1px solid #a88734}
	.amazon-link:hover{background:#ddb347}
	.amazon-fallback{font-size:.8rem;color:#888;margin:.4rem 0 0}
	.amazon-fallback a{color:#666}
	.back{display:block;margin-top:2.5rem;font-size:.85rem;color:#888;text-decoration:none}
	.back:hover{text-decoration:underline}
</style>
</head>
<body>
<?php if ( !empty( $book->cover_url ) ) : ?>
	<img class="cover" src="<?php echo yourls_esc_attr( $book->cover_url ); ?>" alt="">
<?php endif; ?>
<h1><?php echo yourls_esc_html( $book->title ); ?></h1>
<?php if ( !empty( $book->author ) ) : ?>
	<div class="author"><?php echo yourls_esc_html( $book->author ); ?></div>
<?php endif; ?>
<?php if ( !empty( $book->notes ) ) : ?>
	<div class="notes"><?php echo nl2br( yourls_esc_html( $book->notes ) ); ?></div>
<?php endif; ?>
<div>
<?php if ( !empty( $book->target_url ) ) : ?>
	<a class="info-link" href="<?php echo yourls_esc_attr( $book->target_url ); ?>" target="_blank" rel="noopener">More info ↗</a>
<?php endif; ?>
<?php if ( nfcbooks_amazon_link_enabled() ) : $amazon = nfcbooks_amazon_urls( $book ); ?>
	<a class="amazon-link" href="<?php echo yourls_esc_attr( $amazon['url'] ); ?>" target="_blank" rel="noopener">Amazon ↗</a>
<?php endif; ?>
</div>
<?php if ( nfcbooks_amazon_link_enabled() && !empty( $amazon['is_direct'] ) && !empty( $amazon['search_fallback'] ) ) : ?>
	<p class="amazon-fallback">Wrong book? <a href="<?php echo yourls_esc_attr( $amazon['search_fallback'] ); ?>" target="_blank" rel="noopener">Search Amazon instead ↗</a></p>
<?php endif; ?>
<a class="back" href="<?php echo yourls_esc_attr( $site . '/library' ); ?>">&larr; Back to Library</a>
</body>
</html>
<?php
}

/* -----------------------------------------------------------------------
 * Admin page: Manage > Books
 * --------------------------------------------------------------------- */

yourls_add_action( 'plugins_loaded', 'nfcbooks_register_admin_page' );
function nfcbooks_register_admin_page() {
	yourls_register_plugin_page( 'nfc-library', 'Library', 'nfcbooks_admin_page' );
}

function nfcbooks_admin_page() {
	$notice = '';

	if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
		yourls_verify_nonce( NFCBOOKS_NONCE );
		$action = isset( $_POST['nfcbooks_action'] ) ? $_POST['nfcbooks_action'] : '';

		if ( $action === 'add' ) {
			$notice = nfcbooks_handle_add();
		} elseif ( $action === 'edit' ) {
			$notice = nfcbooks_handle_edit();
		} elseif ( $action === 'delete' ) {
			$notice = nfcbooks_handle_delete();
		} elseif ( $action === 'settings' ) {
			$notice = nfcbooks_handle_settings();
		}
	}

	$books        = nfcbooks_get_all_books();
	$site         = yourls_get_yourls_site();
	$language     = nfcbooks_get_language();
	$amazon_link   = nfcbooks_amazon_link_enabled();
	$amazon_mode   = nfcbooks_get_amazon_mode();
	$library_title = nfcbooks_get_library_title();
	?>
	<h2>Library <small style="font-weight:normal;color:#888">v<?php echo yourls_esc_html( NFCBOOKS_VERSION ); ?></small></h2>
	<?php if ( !nfcbooks_password_encryption_available() ) : ?>
		<div class="notice" style="border-left-color:#dba617"><p><strong>NFC Library:</strong> Per-link NFC passwords are stored in plain text in the database because <code>NFCBOOKS_PASSWORD_KEY</code> is not set (or is not a valid 32-byte key) in your Yourls <code>user/config.php</code>. Define the constant to enable encryption at rest; until then the plugin keeps working as before. See the plugin README for how to generate a key.</p></div>
	<?php endif; ?>
	<p>Public listing page: <a href="<?php echo yourls_esc_attr( $site . '/library' ); ?>" target="_blank"><?php echo yourls_esc_html( $site . '/library' ); ?></a>
	&mdash; the keyword <code>library</code> is reserved for this page, don't reuse it for a regular short link.</p>
	<p style="color:#666;font-size:.9rem">Each book's short link (e.g. <code><?php echo yourls_esc_html( $site . '/isbn...' ); ?></code>) opens its own local page here &mdash; cover, title, author, your notes &mdash; with a "More info" link out to the Info URL. It doesn't redirect straight there.</p>

	<?php if ( $notice ) : ?>
		<div class="notice"><p><?php echo $notice; ?></p></div>
	<?php endif; ?>

	<h3>Settings</h3>
	<form method="post">
		<?php yourls_nonce_field( NFCBOOKS_NONCE ); ?>
		<input type="hidden" name="nfcbooks_action" value="settings">
		<p><label>Library page title:
			<input type="text" name="library_title" value="<?php echo yourls_esc_attr( yourls_get_option( NFCBOOKS_LIBRARY_TITLE_OPTION, '' ) ); ?>" size="30" placeholder="<?php echo yourls_esc_attr( NFCBOOKS_DEFAULT_LIBRARY_TITLE ); ?>">
		</label> <small style="color:#888">shown on the public <?php echo yourls_esc_html( $site . '/library' ); ?> page &mdash; leave blank to just use "<?php echo yourls_esc_html( NFCBOOKS_DEFAULT_LIBRARY_TITLE ); ?>"</small></p>
		<p><label>Smart Generate language filter (ISO 639-2 code, e.g. <code>eng</code>, <code>fre</code>, <code>spa</code> &mdash; leave blank to search all languages):
			<input type="text" name="language" value="<?php echo yourls_esc_attr( $language ); ?>" size="6" maxlength="3" placeholder="eng">
		</label></p>
		<p><label><input type="checkbox" name="amazon_link" value="1" <?php echo $amazon_link ? 'checked' : ''; ?>> Show an Amazon search link on each book's page</label></p>
		<p style="margin-left:1.4rem">Amazon link uses:
			<label style="margin-left:.5rem"><input type="radio" name="amazon_mode" value="title_author" <?php echo $amazon_mode === 'title_author' ? 'checked' : ''; ?>> Title &amp; Author</label>
			<label style="margin-left:1rem"><input type="radio" name="amazon_mode" value="isbn" <?php echo $amazon_mode === 'isbn' ? 'checked' : ''; ?>> ISBN number</label>
			<br><small style="color:#888">ISBN mode links straight to the Amazon product page (via ISBN-10) when it can, falling back to an ISBN search or Title &amp; Author as needed. Only works for books added via Smart Generate (their ISBN is recoverable from the keyword) &mdash; Random/Custom books fall back to Title &amp; Author automatically.</small>
		</p>
		<button type="submit" class="button">Save settings</button>
	</form>

	<h3>Add a book</h3>
	<form method="post" id="nfcbooks-add-form">
		<?php yourls_nonce_field( NFCBOOKS_NONCE ); ?>
		<input type="hidden" name="nfcbooks_action" value="add">
		<table>
			<tr>
				<td>Look up by ISBN</td>
				<td>
					<input type="text" id="nfcbooks-isbn-input" placeholder="e.g. 9780441172719" size="20" inputmode="numeric">
					<button type="button" id="nfcbooks-isbn-btn" class="button">&#128269; Look up</button>
					<div id="nfcbooks-isbn-status" style="font-size:.85rem;color:#666;margin-top:.3rem"></div>
					<small style="color:#888">Fills in Title, Author, Keyword, Info URL and Cover below &mdash; edit anything afterward if it's not quite right.</small>
				</td>
			</tr>
			<tr><td>Title</td><td><input type="text" id="nfcbooks-title" name="title" required></td></tr>
			<tr><td>Author</td><td><input type="text" id="nfcbooks-author" name="author"></td></tr>
			<tr>
				<td>Keyword (short URL slug)</td>
				<td>
					<div style="margin-bottom:.4rem">
						<label style="margin-right:1rem"><input type="radio" name="keyword_mode" value="smart" checked> Smart Generate (from ISBN)</label>
						<label style="margin-right:1rem"><input type="radio" name="keyword_mode" value="random"> Random</label>
						<label><input type="radio" name="keyword_mode" value="custom"> Custom</label>
					</div>
					<input type="text" id="nfcbooks-keyword" name="keyword" required placeholder="e.g. dune">
					<button type="button" id="nfcbooks-random-btn" class="button" style="display:none">🎲 Randomize</button>
					<button type="button" id="nfcbooks-smart-btn" class="button" style="display:none">✨ Smart Generate</button>
					<button type="button" id="nfcbooks-debug-btn" class="button" style="display:none">🐛 Debug lookup</button>
					<div id="nfcbooks-smart-status" style="font-size:.85rem;color:#666;margin-top:.3rem"></div>
					<ul id="nfcbooks-matches" style="display:none;list-style:none;padding:0;margin:.4rem 0;max-width:420px;border:1px solid #ddd;border-radius:4px;overflow:hidden"></ul>
					<pre id="nfcbooks-debug-output" style="display:none;background:#f5f5f5;border:1px solid #ddd;padding:.6rem;margin-top:.4rem;font-size:.8rem;max-width:600px;white-space:pre-wrap;overflow-x:auto"></pre>
				</td>
			</tr>
			<tr><td>Info URL <small style="font-weight:normal;color:#888">(external reference, e.g. Open Library &mdash; linked as "More info" on the book's page)</small></td><td><input type="url" id="nfcbooks-url" name="url" required size="50" placeholder="https://..."></td></tr>
			<tr><td>Cover image URL</td><td><input type="url" id="nfcbooks-cover" name="cover_url" size="50"></td></tr>
			<tr><td>Notes</td><td><textarea name="notes" rows="2" cols="40"></textarea></td></tr>
			<tr id="nfcbooks-amazon-row">
				<td>Amazon preview</td>
				<td><a id="nfcbooks-amazon-preview" href="#" target="_blank" rel="noopener" style="font-size:.85rem"></a></td>
			</tr>
		</table>
		<p><button type="submit" class="button">Add book</button></p>
	</form>
	<script>
	(function () {
		var NFCBOOKS_NONCE_VALUE = <?php echo json_encode( yourls_create_nonce( NFCBOOKS_NONCE ) ); ?>;
		var AJAX_URL = <?php echo json_encode( yourls_plugin_url( dirname( __FILE__ ) ) . '/ajax.php' ); ?>;
		var AMAZON_ENABLED = <?php echo json_encode( nfcbooks_amazon_link_enabled() ); ?>;
		var AMAZON_MODE = <?php echo json_encode( nfcbooks_get_amazon_mode() ); ?>;
		console.log( 'NFC Library: ajax endpoint is', AJAX_URL );

		var form         = document.getElementById( 'nfcbooks-add-form' );
		var keywordEl    = document.getElementById( 'nfcbooks-keyword' );
		var titleEl      = document.getElementById( 'nfcbooks-title' );
		var authorEl     = document.getElementById( 'nfcbooks-author' );
		var urlEl        = document.getElementById( 'nfcbooks-url' );
		var coverEl      = document.getElementById( 'nfcbooks-cover' );
		var randomBtn    = document.getElementById( 'nfcbooks-random-btn' );
		var smartBtn     = document.getElementById( 'nfcbooks-smart-btn' );
		var debugBtn     = document.getElementById( 'nfcbooks-debug-btn' );
		var statusEl     = document.getElementById( 'nfcbooks-smart-status' );
		var debugOutEl   = document.getElementById( 'nfcbooks-debug-output' );
		var matchesEl    = document.getElementById( 'nfcbooks-matches' );
		var isbnInputEl  = document.getElementById( 'nfcbooks-isbn-input' );
		var isbnBtn      = document.getElementById( 'nfcbooks-isbn-btn' );
		var isbnStatusEl = document.getElementById( 'nfcbooks-isbn-status' );
		var amazonRow    = document.getElementById( 'nfcbooks-amazon-row' );
		var amazonPreview = document.getElementById( 'nfcbooks-amazon-preview' );
		if ( !form ) { return; }

		// --- Amazon link preview (mirrors nfcbooks_amazon_urls() in PHP) ---
		function isbn13to10( isbn13 ) {
			isbn13 = ( isbn13 || '' ).replace( /[^0-9]/g, '' );
			if ( isbn13.length !== 13 || isbn13.slice( 0, 3 ) !== '978' ) { return ''; }
			var core = isbn13.slice( 3, 12 );
			var sum = 0;
			for ( var i = 0; i < 9; i++ ) { sum += ( 10 - i ) * parseInt( core[ i ], 10 ); }
			var remainder = sum % 11;
			var check = ( 11 - remainder ) % 11;
			return core + ( check === 10 ? 'X' : String( check ) );
		}
		function normalizeIsbn10( isbn ) {
			isbn = ( isbn || '' ).toUpperCase().replace( /[^0-9X]/g, '' );
			if ( isbn.length === 10 ) { return isbn; }
			if ( isbn.length === 13 ) { return isbn13to10( isbn ); }
			return '';
		}
		function extractIsbnFromKeyword( kw ) {
			var m = /^isbn([0-9Xx]{9,13})$/.exec( kw || '' );
			return m ? m[ 1 ] : '';
		}
		function amazonSearchUrl( title, author ) {
			var q = ( title + ' ' + author ).trim();
			return 'https://www.amazon.com/s?k=' + encodeURIComponent( q ) + '&i=stripbooks';
		}
		function updateAmazonPreview() {
			if ( !amazonRow || !amazonPreview ) { return; }
			if ( !AMAZON_ENABLED ) {
				amazonRow.style.display = 'none';
				return;
			}
			var title  = titleEl.value.trim();
			var author = authorEl.value.trim();
			var url    = null;
			var label  = '';

			if ( AMAZON_MODE === 'isbn' ) {
				var isbn = extractIsbnFromKeyword( keywordEl.value.trim() );
				if ( isbn ) {
					var isbn10 = normalizeIsbn10( isbn );
					if ( isbn10 ) {
						url = 'https://www.amazon.com/dp/' + isbn10;
						label = url + '  (direct product link)';
					} else {
						url = 'https://www.amazon.com/s?k=' + encodeURIComponent( isbn ) + '&i=stripbooks';
						label = url + '  (ISBN search — no ISBN-10 form)';
					}
				}
			}
			if ( url === null && title ) {
				url = amazonSearchUrl( title, author );
				label = url + '  (title & author search)';
			}

			amazonRow.style.display = '';
			if ( url ) {
				amazonPreview.href = url;
				amazonPreview.textContent = label;
			} else {
				amazonPreview.removeAttribute( 'href' );
				amazonPreview.textContent = 'Enter a title to preview the Amazon link';
			}
		}
		[ titleEl, authorEl, keywordEl ].forEach( function ( el ) {
			el.addEventListener( 'input', updateAmazonPreview );
		} );
		updateAmazonPreview();

		function currentMode() {
			var checked = form.querySelector( 'input[name="keyword_mode"]:checked' );
			return checked ? checked.value : 'custom';
		}

		function applyMode() {
			var mode = currentMode();
			randomBtn.style.display = ( mode === 'random' ) ? 'inline-block' : 'none';
			smartBtn.style.display  = ( mode === 'smart' ) ? 'inline-block' : 'none';
			debugBtn.style.display  = ( mode === 'smart' ) ? 'inline-block' : 'none';
			keywordEl.readOnly = ( mode !== 'custom' );
			statusEl.textContent = '';
			debugOutEl.style.display = 'none';
			debugOutEl.textContent = '';
			matchesEl.style.display = 'none';
			matchesEl.innerHTML = '';
			if ( mode === 'custom' ) {
				keywordEl.placeholder = 'e.g. dune';
			} else if ( mode === 'random' ) {
				keywordEl.placeholder = 'Click Randomize';
			} else {
				keywordEl.placeholder = 'Fill in Title (and Author), then click Smart Generate';
			}
		}

		Array.prototype.forEach.call( form.querySelectorAll( 'input[name="keyword_mode"]' ), function ( r ) {
			r.addEventListener( 'change', applyMode );
		} );
		applyMode();

		randomBtn.addEventListener( 'click', function () {
			keywordEl.value = Math.random().toString( 36 ).slice( 2, 8 );
			updateAmazonPreview();
		} );

		if ( isbnBtn ) {
			isbnBtn.addEventListener( 'click', function () {
				var isbn = ( isbnInputEl.value || '' ).replace( /[^0-9Xx]/g, '' );
				if ( !isbn ) {
					isbnStatusEl.textContent = 'Enter an ISBN first.';
					return;
				}
				isbnStatusEl.textContent = 'Looking up…';
				var qs = 'nonce=' + encodeURIComponent( NFCBOOKS_NONCE_VALUE )
					+ '&isbn=' + encodeURIComponent( isbn )
					+ '&_=' + Date.now();
				fetch( AJAX_URL + '?' + qs, { credentials: 'same-origin', cache: 'no-store' } )
					.then( function ( r ) { return r.text(); } )
					.then( function ( text ) {
						var data;
						try {
							data = JSON.parse( text );
						} catch ( e ) {
							isbnStatusEl.textContent = 'Server did not return JSON.';
							return;
						}
						if ( !data.found || !data.matches || !data.matches.length ) {
							isbnStatusEl.textContent = data.error ? ( 'Lookup problem: ' + data.error ) : 'No book found for that ISBN.';
							return;
						}
						var m = data.matches[0];
						if ( m.title ) { titleEl.value = m.title; }
						if ( m.author ) { authorEl.value = m.author; }
						if ( m.keyword ) { keywordEl.value = m.keyword; }
						if ( urlEl && m.page_url ) { urlEl.value = m.page_url; }
						if ( coverEl && m.cover_url ) { coverEl.value = m.cover_url; }
						updateAmazonPreview();
						isbnStatusEl.textContent = 'Found "' + m.title + '"' + ( m.cover_url ? ' (cover found)' : ' (no cover found)' );
					} )
					.catch( function ( err ) {
						isbnStatusEl.textContent = 'Lookup request failed (network error): ' + err.message;
					} );
			} );
		}

		function runLookup( debug ) {
			var title  = titleEl.value.trim();
			var author = authorEl.value.trim();
			if ( !title ) {
				statusEl.textContent = 'Enter a title first.';
				return;
			}
			statusEl.textContent = debug ? 'Looking up (debug)…' : 'Looking up…';
			debugOutEl.style.display = 'none';
			debugOutEl.textContent = '';
			matchesEl.style.display = 'none';
			matchesEl.innerHTML = '';
			var qs = 'nonce=' + encodeURIComponent( NFCBOOKS_NONCE_VALUE )
				+ '&title=' + encodeURIComponent( title )
				+ '&author=' + encodeURIComponent( author )
				+ ( debug ? '&debug=1' : '' )
				+ '&_=' + Date.now(); // cache-bust: avoid the browser replaying a stale cached response
			fetch( AJAX_URL + '?' + qs, { credentials: 'same-origin', cache: 'no-store' } )
				.then( function ( r ) {
					return r.text().then( function ( text ) {
						return { ok: r.ok, status: r.status, text: text };
					} );
				} )
				.then( function ( resp ) {
					var data;
					try {
						data = JSON.parse( resp.text );
					} catch ( e ) {
						statusEl.textContent = 'Server did not return JSON (HTTP ' + resp.status + ') — see debug output below.';
						debugOutEl.style.display = 'block';
						debugOutEl.textContent = 'HTTP ' + resp.status + '\n\n' + resp.text.slice( 0, 2000 );
						return;
					}

					if ( debug ) {
						debugOutEl.style.display = 'block';
						debugOutEl.textContent = JSON.stringify( data, null, 2 );
					}

					if ( !data.found || !data.matches || !data.matches.length ) {
						statusEl.textContent = data.error
							? ( 'Lookup problem: ' + data.error )
							: 'No ISBN match found — try Random or Custom instead.';
						return;
					}

					function applyMatch( m ) {
						keywordEl.value = m.keyword;
						if ( urlEl && !urlEl.value && m.page_url ) { urlEl.value = m.page_url; }
						if ( coverEl && !coverEl.value && m.cover_url ) { coverEl.value = m.cover_url; }
						if ( authorEl && !authorEl.value.trim() && m.author ) { authorEl.value = m.author; }
						updateAmazonPreview();
					}

					if ( data.matches.length === 1 ) {
						var only = data.matches[0];
						applyMatch( only );
						statusEl.textContent = 'Found ISBN ' + only.isbn + ( only.cover_url ? ' (cover found)' : ' (no cover found)' );
						return;
					}

					statusEl.textContent = 'Found ' + data.matches.length + ' editions — pick one:';
					matchesEl.style.display = 'block';
					data.matches.forEach( function ( m, i ) {
						var li = document.createElement( 'li' );
						li.style.cssText = 'display:flex;gap:.6rem;align-items:center;padding:.4rem .6rem;cursor:pointer;border-top:' + ( i === 0 ? '0' : '1px solid #eee' );
						li.addEventListener( 'mouseenter', function () { li.style.background = '#f5f5f5'; } );
						li.addEventListener( 'mouseleave', function () { li.style.background = ''; } );

						if ( m.cover_url ) {
							var img = document.createElement( 'img' );
							img.src = m.cover_url;
							img.style.cssText = 'width:32px;height:46px;object-fit:cover;border-radius:2px;flex-shrink:0';
							li.appendChild( img );
						}

						var label = document.createElement( 'div' );
						label.style.fontSize = '.9rem';
						label.textContent = ( m.title || '(untitled)' ) + ( m.author ? ' — ' + m.author : '' ) + ' (ISBN ' + m.isbn + ')';
						li.appendChild( label );

						li.addEventListener( 'click', function () {
							applyMatch( m );
							statusEl.textContent = 'Selected ISBN ' + m.isbn + ( m.cover_url ? ' (cover found)' : ' (no cover found)' );
							matchesEl.style.display = 'none';
						} );

						matchesEl.appendChild( li );
					} );
				} )
				.catch( function ( err ) {
					statusEl.textContent = 'Lookup request failed (network error): ' + err.message;
				} );
		}

		smartBtn.addEventListener( 'click', function () { runLookup( false ); } );
		debugBtn.addEventListener( 'click', function () { runLookup( true ); } );
	})();
	</script>

	<h3>Tagged books (<?php echo count( $books ); ?>)</h3>
	<?php if ( empty( $books ) ) : ?>
		<p>None yet.</p>
	<?php else : ?>
	<table class="tblSorter" style="width:100%">
		<thead>
			<tr><th>Keyword</th><th>Title</th><th>Author</th><th>Info URL</th><th>Clicks</th><th></th></tr>
		</thead>
		<tbody>
		<?php foreach ( $books as $b ) : ?>
			<tr>
				<form method="post">
					<?php yourls_nonce_field( NFCBOOKS_NONCE ); ?>
					<input type="hidden" name="nfcbooks_action" value="edit">
					<input type="hidden" name="orig_keyword" value="<?php echo yourls_esc_attr( $b->keyword ); ?>">
					<td>
						<input type="text" name="keyword" value="<?php echo yourls_esc_attr( $b->keyword ); ?>" size="14">
						<a href="<?php echo yourls_esc_attr( $site . '/' . $b->keyword ); ?>" target="_blank" rel="noopener" title="Open this book's page" style="margin-left:.4rem;text-decoration:none">🔗</a>
					</td>
					<td><input type="text" name="title" value="<?php echo yourls_esc_attr( $b->title ); ?>"></td>
					<td><input type="text" name="author" value="<?php echo yourls_esc_attr( $b->author ); ?>"></td>
					<td><input type="url" name="url" value="<?php echo yourls_esc_attr( $b->target_url ); ?>" size="30"></td>
					<td><?php echo (int) $b->clicks; ?></td>
					<td>
						<button type="submit" class="button">Save</button>
				</form>
				<form method="post" style="display:inline" onsubmit="return confirm('Delete this book and its short link?');">
					<?php yourls_nonce_field( NFCBOOKS_NONCE ); ?>
					<input type="hidden" name="nfcbooks_action" value="delete">
					<input type="hidden" name="keyword" value="<?php echo yourls_esc_attr( $b->keyword ); ?>">
					<button type="submit" class="button">Delete</button>
				</form>
					</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
	<?php
}

function nfcbooks_handle_add() {
	$keyword   = yourls_sanitize_string( $_POST['keyword'] );
	$url       = $_POST['url'];
	$title     = $_POST['title'];
	$author    = isset( $_POST['author'] ) ? $_POST['author'] : '';
	$cover_url = isset( $_POST['cover_url'] ) ? $_POST['cover_url'] : '';
	$notes     = isset( $_POST['notes'] ) ? $_POST['notes'] : '';

	if ( $keyword === 'library' ) {
		return 'The keyword "library" is reserved for the public listing page.';
	}

	$result = yourls_add_new_link( $url, $keyword, $title );
	if ( empty( $result['status'] ) || $result['status'] !== 'success' ) {
		return yourls_esc_html( isset( $result['message'] ) ? $result['message'] : 'Could not create the short link.' );
	}

	$actual_keyword = $result['url']['keyword'];
	$ydb = yourls_get_db();
	$ydb->fetchAffected(
		"INSERT INTO `" . NFCBOOKS_TABLE . "` (keyword, title, author, cover_url, notes, created) VALUES (:keyword, :title, :author, :cover_url, :notes, NOW())",
		array( 'keyword' => $actual_keyword, 'title' => $title, 'author' => $author, 'cover_url' => $cover_url, 'notes' => $notes )
	);

	$link = yourls_get_yourls_site() . '/' . $actual_keyword;
	return 'Added "' . yourls_esc_html( $title ) . '" as <a href="' . yourls_esc_attr( $link ) . '" target="_blank">' . yourls_esc_html( $link ) . '</a>';
}

function nfcbooks_handle_edit() {
	$keyword     = yourls_sanitize_string( $_POST['orig_keyword'] );
	$new_keyword = ( isset( $_POST['keyword'] ) && yourls_sanitize_string( $_POST['keyword'] ) !== '' )
		? yourls_sanitize_string( $_POST['keyword'] )
		: $keyword;
	$url         = $_POST['url'];
	$title       = $_POST['title'];
	$author      = isset( $_POST['author'] ) ? $_POST['author'] : '';

	if ( $new_keyword === 'library' ) {
		return 'The keyword "library" is reserved for the public listing page.';
	}
	if ( $new_keyword !== $keyword && yourls_get_keyword_longurl( $new_keyword ) !== false ) {
		return 'The keyword "' . yourls_esc_html( $new_keyword ) . '" is already in use.';
	}

	yourls_edit_link( $url, $keyword, $new_keyword, $title );

	$ydb = yourls_get_db();
	// Renaming moves this book's row to the new keyword (book_tags'
	// primary key) so its author/cover/notes/password stay attached to the
	// link instead of being orphaned under the old one.
	$ydb->fetchAffected(
		"UPDATE `" . NFCBOOKS_TABLE . "` SET keyword = :new_keyword, title = :title, author = :author WHERE keyword = :keyword",
		array( 'new_keyword' => $new_keyword, 'title' => $title, 'author' => $author, 'keyword' => $keyword )
	);

	return 'Saved "' . yourls_esc_html( $title ) . '"' . ( $new_keyword !== $keyword ? ' (renamed to "' . yourls_esc_html( $new_keyword ) . '")' : '' ) . '.';
}

function nfcbooks_handle_delete() {
	$keyword = yourls_sanitize_string( $_POST['keyword'] );

	yourls_delete_link_by_keyword( $keyword );

	$ydb = yourls_get_db();
	$ydb->fetchAffected(
		"DELETE FROM `" . NFCBOOKS_TABLE . "` WHERE keyword = :keyword",
		array( 'keyword' => $keyword )
	);

	return 'Deleted "' . yourls_esc_html( $keyword ) . '".';
}

function nfcbooks_handle_settings() {
	$library_title = isset( $_POST['library_title'] ) ? trim( $_POST['library_title'] ) : '';
	$library_title = substr( $library_title, 0, 100 );
	yourls_update_option( NFCBOOKS_LIBRARY_TITLE_OPTION, $library_title );

	$language = isset( $_POST['language'] ) ? strtolower( trim( $_POST['language'] ) ) : '';
	$language = preg_replace( '/[^a-z]/', '', $language );
	$language = substr( $language, 0, 3 );
	yourls_update_option( NFCBOOKS_LANGUAGE_OPTION, $language );

	// Checkboxes aren't sent at all when unchecked, so presence = on.
	$amazon_link = isset( $_POST['amazon_link'] ) ? '1' : '0';
	yourls_update_option( NFCBOOKS_AMAZON_OPTION, $amazon_link );

	$amazon_mode = isset( $_POST['amazon_mode'] ) ? $_POST['amazon_mode'] : NFCBOOKS_DEFAULT_AMAZON_MODE;
	if ( !in_array( $amazon_mode, array( 'title_author', 'isbn' ), true ) ) {
		$amazon_mode = NFCBOOKS_DEFAULT_AMAZON_MODE;
	}
	yourls_update_option( NFCBOOKS_AMAZON_MODE_OPTION, $amazon_mode );

	$language_msg = $language === ''
		? 'Smart Generate will search all languages.'
		: 'Smart Generate will filter to language "' . yourls_esc_html( $language ) . '".';
	$amazon_msg = $amazon_link === '1'
		? 'Amazon search links are on (using ' . ( $amazon_mode === 'isbn' ? 'ISBN' : 'Title &amp; Author' ) . ').'
		: 'Amazon search links are off.';
	$title_msg = $library_title === ''
		? 'Library page title reset to default ("' . yourls_esc_html( NFCBOOKS_DEFAULT_LIBRARY_TITLE ) . '").'
		: 'Library page title set to "' . yourls_esc_html( $library_title ) . '".';

	return 'Settings saved &mdash; ' . $title_msg . ' ' . $language_msg . ' ' . $amazon_msg;
}
