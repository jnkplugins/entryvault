<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads the source plugin's own entry row for one file field.

/**
 * File uploads on an entry, as links you can open.
 *
 * Each form plugin keeps uploads its own way:
 *   - a public URL in the media folder (WPForms, Fluent Forms, Forminator,
 *     Ninja Forms, Kadence, SureForms, MetForm): linked as it is, since the form
 *     plugin itself publishes it;
 *   - Gravity Forms: through Gravity Forms' own download link for the field;
 *   - Formidable: an attachment id, linked to the attachment;
 *   - Trinity Forms 2.9+: outside the web root. Trinity's own signed URL tells
 *     us which file (Private_Files::resolve() checks the signature and keeps it
 *     inside Trinity's folder), and EntryVault streams it;
 *   - CFDB7 (Contact Form 7): the saved name in uploads/cfdb7_uploads.
 * A file that has no public URL is only ever served through this class's
 * admin-post route, to someone who may view the entry's pipeline. The route
 * takes the entry id, field key and position; the path is looked up again on
 * the server, so a request can never name a file.
 *
 * The public table keeps showing file names only, and the AI privacy layer
 * keeps leaving file fields out (AI_Privacy::NEVER_TYPES).
 */
class File_Links {

	public const ACTION = 'entryvault_file';

	/** Shown inline (thumbnails). SVG is left out on purpose: it can carry script. */
	private const IMAGE_TYPES = [
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	];

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_download' ] );
		// From an email or a bookmark while logged out: log in, then come back.
		add_action( 'admin_post_nopriv_' . self::ACTION, static function (): void {
			auth_redirect();
		} );
	}

	/** Whether a stored field type is a file upload, in any form plugin's spelling. */
	public static function is_file_type( string $type ): bool {
		$type = strtolower( $type );
		return '' !== $type && ( 1 === preg_match( '/file|upload/', $type ) || 'input_image' === $type );
	}

	/**
	 * The files of one field, for the entry panel.
	 *
	 * @param array<string,mixed> $submission Submissions row.
	 * @param array<string,mixed> $field      submission_data row (field_key, field_type, value).
	 * @return array<int,array{name:string,url:string,thumb:string,image:bool}>
	 */
	public static function for_field( array $submission, array $field ): array {
		$field = self::normalize( $field );
		if ( ! self::is_file_type( (string) ( $field['field_type'] ?? '' ) ) ) {
			return [];
		}
		$out = [];
		foreach ( self::resolve( $submission, $field ) as $i => $file ) {
			$image = isset( self::IMAGE_TYPES[ self::ext( $file['name'] ) ] );
			if ( '' !== ( $file['url'] ?? '' ) ) {
				$url   = (string) $file['url'];
				$thumb = $image ? $url : '';
			} elseif ( '' !== ( $file['path'] ?? '' ) ) {
				$url   = self::download_url( (int) $submission['id'], (string) $field['field_key'], (int) $i );
				$thumb = $image ? self::download_url( (int) $submission['id'], (string) $field['field_key'], (int) $i, true ) : '';
			} else {
				continue;
			}
			$out[] = [
				'name'  => (string) $file['name'],
				'url'   => $url,
				'thumb' => $thumb,
				'image' => $image,
			];
		}
		return $out;
	}

	/** The authenticated route for a file kept off the public web. */
	public static function download_url( int $submission_id, string $field_key, int $index, bool $inline = false ): string {
		$args = [
			'action' => self::ACTION,
			'sid'    => $submission_id,
			'field'  => rawurlencode( $field_key ),
			'n'      => $index,
		];
		if ( $inline ) {
			$args['inline'] = 1;
		}
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * Every file in a field: [ name, url ] for a public file, [ name, path ]
	 * for one that must be streamed. Never sent to the browser as is.
	 *
	 * @return array<int,array{name:string,url?:string,path?:string}>
	 */
	public static function resolve( array $submission, array $field ): array {
		$field  = self::normalize( $field );
		$source = (string) ( $submission['source_plugin'] ?? '' );
		$files  = [];

		if ( 'trinity-forms' === $source ) {
			$files = self::trinity_files( $submission, (string) ( $field['field_key'] ?? '' ) );
		}
		if ( ! $files ) {
			foreach ( self::items( $field['value'] ?? '' ) as $item ) {
				$file = self::from_item( $item, $submission );
				if ( $file ) {
					$files[] = $file;
				}
			}
		}
		if ( 'gravityforms' === $source || 'gravity-forms' === $source || 'gravity' === $source ) {
			$files = self::gravity_links( $files, $submission, (string) ( $field['field_key'] ?? '' ) );
		}

		/**
		 * Filter the files resolved for one upload field.
		 *
		 * Each item: name, and either url (a public link) or path (an absolute
		 * path the authenticated route streams). Add a source this class does not
		 * know here.
		 *
		 * @param array $files      Resolved files.
		 * @param array $submission Submissions row.
		 * @param array $field      Field row.
		 */
		$files = (array) apply_filters( 'entryvault_resolve_files', $files, $submission, $field );
		return array_values( array_filter( $files, static fn( $f ) => is_array( $f ) && '' !== (string) ( $f['name'] ?? '' ) ) );
	}

	/**
	 * The stored value as a list of single files (string, URL or object).
	 *
	 * @param mixed $value
	 * @return array<int,mixed>
	 */
	private static function items( $value ): array {
		if ( is_string( $value ) ) {
			$trim = trim( $value );
			if ( '' === $trim ) {
				return [];
			}
			if ( '[' === $trim[0] || '{' === $trim[0] ) {
				$decoded = json_decode( $trim, true );
				if ( is_array( $decoded ) ) {
					$value = $decoded;
				}
			}
		}
		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) {
				return [ $value ];
			}
			$out = [];
			foreach ( $value as $v ) {
				$out = array_merge( $out, self::items( $v ) );
			}
			return $out;
		}
		if ( ! is_scalar( $value ) ) {
			return [];
		}
		// Several files arrive joined by new lines (WPForms) or ", " (most others).
		$parts = preg_split( '/\r\n|\n|,\s+(?=https?:\/\/)/', (string) $value );
		if ( count( (array) $parts ) === 1 && false !== strpos( (string) $value, ', ' ) && ! preg_match( '#^https?://#i', (string) $value ) ) {
			$parts = explode( ', ', (string) $value );
		}
		return array_values( array_filter( array_map( 'trim', (array) $parts ), 'strlen' ) );
	}

	/**
	 * @param mixed $item
	 * @return array{name:string,url?:string,path?:string}|null
	 */
	private static function from_item( $item, array $submission ): ?array {
		$source = (string) ( $submission['source_plugin'] ?? '' );
		if ( is_array( $item ) ) {
			foreach ( [ 'url', 'file_url', 'link', 'value' ] as $k ) {
				if ( isset( $item[ $k ] ) && is_string( $item[ $k ] ) && self::is_url( $item[ $k ] ) ) {
					$name = (string) ( $item['filename'] ?? $item['name'] ?? $item['file_name'] ?? self::url_name( $item[ $k ] ) );
					return [ 'name' => $name, 'url' => esc_url_raw( $item[ $k ] ) ];
				}
			}
			$name = (string) ( $item['filename'] ?? $item['name'] ?? '' );
			return '' !== $name ? self::local_file( $name, $submission ) : null;
		}
		$text = trim( (string) $item );
		if ( '' === $text ) {
			return null;
		}
		if ( self::is_url( $text ) ) {
			return [ 'name' => self::url_name( $text ), 'url' => esc_url_raw( $text ) ];
		}
		// Formidable stores the attachment id.
		if ( ctype_digit( $text ) && in_array( $source, [ 'formidable', 'formidable-forms' ], true ) ) {
			$url = wp_get_attachment_url( (int) $text );
			if ( $url ) {
				return [ 'name' => self::url_name( $url ), 'url' => $url ];
			}
		}
		return self::local_file( $text, $submission );
	}

	/**
	 * A bare file name. Contact Form 7 keeps no files; CFDB7 saves a copy in
	 * uploads/cfdb7_uploads as "<unix time>-<name>" (an entry brought in from
	 * CFDB7 already carries that saved name). Otherwise only the name is known.
	 */
	private static function local_file( string $name, array $submission ): array {
		$name = wp_basename( $name );
		if ( 'contact-form-7' === (string) ( $submission['source_plugin'] ?? '' ) ) {
			$base = wp_normalize_path( (string) ( wp_upload_dir( null, false )['basedir'] ?? '' ) ) . '/cfdb7_uploads';
			$path = self::inside( $base, $base . '/' . $name );
			if ( '' === $path ) {
				$path = self::cfdb7_copy( $base, $name, Dates::timestamp( (string) ( $submission['created_at'] ?? '' ) ) );
			}
			if ( '' !== $path ) {
				return [ 'name' => $name, 'path' => $path ];
			}
		}
		return [ 'name' => $name ];
	}

	/**
	 * CFDB7's copy saved closest to when the entry arrived (within 10 minutes).
	 * CFDB7 before 1.3 saved "<unix time>-<name>"; since then
	 * "<unix time><10 hex>-<field>-<name>".
	 */
	private static function cfdb7_copy( string $base, string $name, int $when ): string {
		if ( $when <= 0 || ! is_dir( $base ) ) {
			return '';
		}
		$best = '';
		$gap  = 600;
		foreach ( (array) scandir( $base ) as $file ) {
			$file = (string) $file;
			if ( ! preg_match( '/^(\d{10})(?:[0-9a-f]{10})?-(.+)$/', $file, $m ) ) {
				continue;
			}
			if ( $m[2] !== $name && substr( $m[2], -strlen( '-' . $name ) ) !== '-' . $name ) {
				continue;
			}
			$d = abs( (int) $m[1] - $when );
			if ( $d <= $gap ) {
				$gap  = $d;
				$best = $file;
			}
		}
		return '' !== $best ? self::inside( $base, $base . '/' . $best ) : '';
	}

	/**
	 * Trinity Forms keeps the file object (name, signed url, path) in its own
	 * entry meta. Its signed URL goes through Trinity's resolve(), which checks
	 * the signature and that the file is inside Trinity's folders.
	 *
	 * @return array<int,array{name:string,path:string}>
	 */
	private static function trinity_files( array $submission, string $field_key ): array {
		global $wpdb;
		$entry_id = (int) ( $submission['source_entry_id'] ?? 0 );
		if ( $entry_id <= 0 || '' === $field_key || ! class_exists( '\\TrinityForms\\Private_Files' ) ) {
			return [];
		}
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT value FROM {$wpdb->prefix}trinity_forms_entry_meta WHERE entry_id = %d AND field_id = %s LIMIT 1",
			$entry_id,
			$field_key
		) );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		$list = array_is_list( $decoded ) ? $decoded : [ $decoded ];
		$out  = [];
		foreach ( $list as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}
			$path = '';
			$url  = (string) ( $file['url'] ?? '' );
			if ( '' !== $url && method_exists( '\\TrinityForms\\Private_Files', 'resolve' ) ) {
				$q = [];
				wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
				if ( ! empty( $q['file'] ) && ! empty( $q['sig'] ) ) {
					$path = (string) \TrinityForms\Private_Files::resolve( (string) $q['file'], (string) $q['sig'] );
				}
			}
			// Signed with an older salt: fall back to the stored path, if Trinity manages it.
			if ( '' === $path && ! empty( $file['path'] ) && method_exists( '\\TrinityForms\\Private_Files', 'is_managed_path' )
				&& \TrinityForms\Private_Files::is_managed_path( (string) $file['path'] ) && is_file( (string) $file['path'] ) ) {
				$path = (string) $file['path'];
			}
			$name = (string) ( $file['filename'] ?? wp_basename( $path ) );
			if ( '' !== $name ) {
				$out[] = '' !== $path ? [ 'name' => $name, 'path' => $path ] : [ 'name' => $name ];
			}
		}
		return $out;
	}

	/**
	 * Gravity Forms protects its upload folder and serves files through its own
	 * download link; use that instead of the raw URL.
	 *
	 * @param array<int,array> $files
	 * @return array<int,array>
	 */
	private static function gravity_links( array $files, array $submission, string $field_key ): array {
		if ( ! class_exists( '\\GFAPI' ) || ! method_exists( '\\GFAPI', 'get_field' ) ) {
			return $files;
		}
		$field = \GFAPI::get_field( (int) ( $submission['source_form_id'] ?? 0 ), (int) $field_key );
		if ( ! is_object( $field ) || ! method_exists( $field, 'get_download_url' ) ) {
			return $files;
		}
		foreach ( $files as &$file ) {
			if ( ! empty( $file['url'] ) ) {
				$file['url'] = (string) $field->get_download_url( (string) $file['url'] );
			}
		}
		unset( $file );
		return $files;
	}

	public static function handle_download(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only download; access is the per-pipeline capability check below.
		$sid    = isset( $_GET['sid'] ) ? (int) $_GET['sid'] : 0;
		$key    = isset( $_GET['field'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['field'] ) ) : '';
		$index  = isset( $_GET['n'] ) ? max( 0, (int) $_GET['n'] ) : 0;
		$inline = ! empty( $_GET['inline'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$path = self::path_for_request( $sid, $key, $index );
		if ( is_wp_error( $path ) ) {
			wp_die( esc_html( $path->get_error_message() ), '', [ 'response' => (int) ( $path->get_error_data()['status'] ?? 404 ) ] );
		}
		self::stream( $path, $inline );
	}

	/**
	 * Check access and find the file for a download request.
	 *
	 * @return string|\WP_Error Absolute path.
	 */
	public static function path_for_request( int $sid, string $field_key, int $index ) {
		$submission = $sid > 0 ? Submission_Store::get( $sid ) : null;
		if ( ! $submission || ! Capabilities::current_user_can_view_pipeline( (int) $submission['pipeline_id'] ) ) {
			return new \WP_Error( 'entryvault_file_forbidden', __( 'You do not have permission to download this file.', 'entryvault' ), [ 'status' => 403 ] );
		}
		$field = null;
		foreach ( Submission_Store::fields_for( $sid ) as $row ) {
			$row = self::normalize( $row );
			if ( (string) $row['field_key'] === $field_key ) {
				$field = $row;
				break;
			}
		}
		$files = ( $field && self::is_file_type( (string) ( $field['field_type'] ?? '' ) ) ) ? self::resolve( $submission, $field ) : [];
		$path  = (string) ( $files[ $index ]['path'] ?? '' );
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'entryvault_file_missing', __( 'File not found.', 'entryvault' ), [ 'status' => 404 ] );
		}
		return $path;
	}

	private static function stream( string $path, bool $inline ): void {
		$ext   = self::ext( $path );
		$image = self::IMAGE_TYPES[ $ext ] ?? '';
		$type  = '' !== $image ? $image : ( wp_check_filetype( $path )['type'] ?: 'application/octet-stream' );
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		$disposition = ( $inline && '' !== $image ) ? 'inline' : 'attachment';
		header( 'Content-Disposition: ' . $disposition . '; filename="' . str_replace( [ '"', "\r", "\n" ], '', wp_basename( $path ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox" );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming an authorised download.
		exit;
	}

	/** Field rows come as field_key/field_type (REST) or key/type (Submission_Store::fields_for). */
	private static function normalize( array $field ): array {
		$field['field_key']  = (string) ( $field['field_key'] ?? $field['key'] ?? '' );
		$field['field_type'] = (string) ( $field['field_type'] ?? $field['type'] ?? '' );
		return $field;
	}

	private static function is_url( string $text ): bool {
		return 1 === preg_match( '#^https?://#i', trim( $text ) );
	}

	private static function url_name( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = rawurldecode( wp_basename( $path ) );
		return '' !== $name ? $name : $url;
	}

	private static function ext( string $name ): string {
		return strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
	}

	/** $path if it is a file inside $base (symlinks resolved), else ''. */
	private static function inside( string $base, string $path ): string {
		$real_base = realpath( $base );
		$real      = realpath( $path );
		if ( ! $real_base || ! $real || ! is_file( $real ) ) {
			return '';
		}
		$real_base = trailingslashit( wp_normalize_path( $real_base ) );
		return 0 === strpos( wp_normalize_path( $real ), $real_base ) ? $real : '';
	}
}
