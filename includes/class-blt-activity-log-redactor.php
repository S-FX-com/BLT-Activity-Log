<?php
/**
 * Sensitive-key detection, redaction and size caps. WordPress-free.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides which values must never be written to the log, and keeps what is
 * written within bounds.
 *
 * Redaction is by key, never by guesswork about a value: an option name, meta
 * key or array key matching the pattern is recorded as changed with the value
 * replaced by a flag. A handful of keys are redacted whatever the pattern says.
 */
class BLT_Activity_Log_Redactor {

	/**
	 * Default sensitive-key pattern. Anchored on word boundaries made of `_`,
	 * `-` and brackets, so `mailserver_pass` and `cloudflare_token` hit while
	 * `bypass_cache`, `passage` and `tokenizer` do not.
	 */
	const DEFAULT_PATTERN = '/(^|[_\-\[])(passwords?|pass|pwd|secrets?|tokens?|api[_-]?keys?|(?:private|auth|license|nonce|logged[_-]?in|signing|encryption)[_-]?keys?|salts?|credentials?)($|[_\-\]])/i';

	/**
	 * Keys redacted regardless of the pattern.
	 */
	const ALWAYS = array(
		'user_pass',
		'user_activation_key',
		'session_tokens',
		'post_password',
		'application_passwords',
		'_application_passwords',
	);

	/**
	 * Marker substituted for a redacted value when a structure is rendered.
	 */
	const MASK = '[redacted]';

	/**
	 * Default per-value character cap.
	 */
	const DEFAULT_MAX_LENGTH = 1000;

	/**
	 * Default per-entry cap on the encoded details, in bytes.
	 */
	const DEFAULT_MAX_DETAILS_BYTES = 65536;

	/**
	 * Most changes kept on one entry.
	 */
	const MAX_CHANGES = 200;

	/**
	 * The active pattern, after the filter, validated once per request.
	 *
	 * @var string|null
	 */
	private static $pattern = null;

	/**
	 * Forget the cached pattern (tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$pattern = null;
	}

	/**
	 * The sensitive-key pattern in force.
	 *
	 * @return string
	 */
	public static function pattern() {
		if ( null !== self::$pattern ) {
			return self::$pattern;
		}

		/**
		 * Filter the regular expression that marks a key as sensitive.
		 *
		 * @param string $pattern PCRE pattern.
		 */
		$pattern = apply_filters( 'blt_activity_log_redact_pattern', self::DEFAULT_PATTERN );

		// A broken pattern from a filter must never take logging down with it.
		if ( ! is_string( $pattern ) || '' === $pattern || false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$pattern = self::DEFAULT_PATTERN;
		}

		self::$pattern = $pattern;

		return self::$pattern;
	}

	/**
	 * Whether a key (or a `name[sub][key]` path) names a sensitive value.
	 *
	 * @param string $key Option name, meta key, array key or key path.
	 * @return bool
	 */
	public static function is_sensitive( $key ) {
		$key = trim( (string) $key );

		if ( '' === $key ) {
			return false;
		}

		// accessToken, clientSecret, "Client Secret" and smtp.password read as access_token, client_secret,
		// client_secret and smtp_password, which is what the pattern's word boundaries expect.
		$key = strtolower( (string) preg_replace( array( '/(?<=[a-z0-9])(?=[A-Z])/', '/[\s.]+/' ), '_', $key ) );

		if ( in_array( $key, self::ALWAYS, true ) ) {
			return true;
		}

		// Every segment of a path counts, so `meta[session_tokens]` is caught.
		$segments = preg_split( '/[\[\]]+/', $key, -1, PREG_SPLIT_NO_EMPTY );

		if ( is_array( $segments ) ) {
			foreach ( $segments as $segment ) {
				if ( in_array( $segment, self::ALWAYS, true ) ) {
					return true;
				}
			}
		}

		return 1 === preg_match( self::pattern(), $key );
	}

	/**
	 * Replace the value of every sensitive key inside a structure.
	 *
	 * @param mixed $value Any value.
	 * @param int   $depth Recursion guard.
	 * @return mixed
	 */
	public static function scrub( $value, $depth = 0 ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( $depth > 8 ) {
			return self::MASK;
		}

		$out = array();

		foreach ( $value as $key => $item ) {
			$out[ $key ] = ( is_string( $key ) && self::is_sensitive( $key ) )
				? self::MASK
				: self::scrub( $item, $depth + 1 );
		}

		return $out;
	}

	/**
	 * The per-value character cap.
	 *
	 * @return int
	 */
	public static function max_length() {
		/**
		 * Filter the number of characters kept for one stored value.
		 *
		 * @param int $max Character cap. Default 1000.
		 */
		$max = (int) apply_filters( 'blt_activity_log_value_max_length', self::DEFAULT_MAX_LENGTH );

		return max( 1, $max );
	}

	/**
	 * Make a string safe to JSON-encode: valid UTF-8, nothing else touched.
	 *
	 * @param string $text Raw string.
	 * @return string
	 */
	public static function utf8( $text ) {
		$text = (string) $text;

		if ( 1 === preg_match( '//u', $text ) ) {
			return $text;
		}

		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}

		return (string) preg_replace( '/[\x80-\xFF]/', '?', $text );
	}

	/**
	 * Character length, multibyte-aware.
	 *
	 * @param string $text String.
	 * @return int
	 */
	public static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Cut a string at a character count.
	 *
	 * @param string $text Raw string.
	 * @param int    $max  Character cap.
	 * @return array{0:string,1:int|null} The (possibly shortened) string and the
	 *                                    original length when it was shortened.
	 */
	public static function truncate( $text, $max ) {
		$text   = self::utf8( $text );
		$length = self::length( $text );

		if ( $length <= $max ) {
			return array( $text, null );
		}

		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );

		return array( $cut, $length );
	}

	/**
	 * Redact, shorten and cap one `changes` list.
	 *
	 * @param array $changes List of change items.
	 * @param int   $max     Per-value character cap.
	 * @return array
	 */
	public static function sanitize_changes( array $changes, $max ) {
		$out = array();

		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) || ! isset( $change['field'] ) ) {
				continue;
			}

			$field = self::utf8( (string) $change['field'] );

			if ( self::is_sensitive( $field ) ) {
				$item = array(
					'field'    => $field,
					'redacted' => true,
				);

				if ( isset( $change['note'] ) ) {
					$item['note'] = (string) $change['note'];
				}

				$out[] = $item;
				continue;
			}

			$item = array( 'field' => $field );

			foreach ( array( 'before', 'after' ) as $side ) {
				if ( ! array_key_exists( $side, $change ) ) {
					continue;
				}

				list( $text, $original ) = self::truncate( (string) $change[ $side ], $max );

				$item[ $side ] = $text;

				if ( null !== $original ) {
					$item['truncated'][ $side ] = $original;
				}
			}

			// Keep a truncation record that an earlier pass already made, and the larger length when both
			// passes cut the value: the first one knows how long it really was.
			if ( isset( $change['truncated'] ) && is_array( $change['truncated'] ) ) {
				foreach ( $change['truncated'] as $side => $length ) {
					$item['truncated'][ $side ] = isset( $item['truncated'][ $side ] ) ? max( (int) $item['truncated'][ $side ], (int) $length ) : (int) $length;
				}
			}

			foreach ( array( 'order_changed', 'edited', 'note' ) as $flag ) {
				if ( isset( $change[ $flag ] ) ) {
					$item[ $flag ] = $change[ $flag ];
				}
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Redact, shorten and cap a whole `details` structure.
	 *
	 * @param array $details   Details as built by a module.
	 * @param int   $max       Per-value character cap.
	 * @param int   $max_bytes Cap on the encoded size.
	 * @return array
	 */
	public static function sanitize_details( array $details, $max = self::DEFAULT_MAX_LENGTH, $max_bytes = self::DEFAULT_MAX_DETAILS_BYTES ) {
		// Entries from other plugins arrive in whatever shape the caller built.
		if ( isset( $details['changes'] ) && is_array( $details['changes'] ) ) {
			$details['changes'] = self::sanitize_changes( $details['changes'], $max );
		} else {
			unset( $details['changes'] );
		}

		if ( isset( $details['changes'] ) && empty( $details['changes'] ) ) {
			unset( $details['changes'] );
		}

		if ( isset( $details['summary'] ) ) {
			if ( is_scalar( $details['summary'] ) ) {
				list( $summary )    = self::truncate( (string) $details['summary'], $max );
				$details['summary'] = $summary;
			} else {
				unset( $details['summary'] );
			}
		}

		return self::cap_details( $details, $max_bytes );
	}

	/**
	 * Bring a details structure under the byte cap and the change-count cap.
	 *
	 * Values are shortened first, then trailing changes are dropped and
	 * counted, so the entry always says that something was left out.
	 *
	 * @param array $details   Details.
	 * @param int   $max_bytes Cap on the encoded size.
	 * @return array
	 */
	public static function cap_details( array $details, $max_bytes = self::DEFAULT_MAX_DETAILS_BYTES ) {
		if ( isset( $details['changes'] ) && count( $details['changes'] ) > self::MAX_CHANGES ) {
			$omitted            = count( $details['changes'] ) - self::MAX_CHANGES;
			$details['changes'] = array_slice( $details['changes'], 0, self::MAX_CHANGES );

			$details['changes_omitted'] = ( isset( $details['changes_omitted'] ) ? (int) $details['changes_omitted'] : 0 ) + $omitted;
		}

		if ( self::encoded_size( $details ) <= $max_bytes ) {
			return $details;
		}

		// Step 1: shorten every remaining value hard.
		if ( isset( $details['changes'] ) ) {
			$details['changes'] = self::sanitize_changes( $details['changes'], 200 );
		}

		// Step 2: drop trailing changes until it fits.
		while ( self::encoded_size( $details ) > $max_bytes && ! empty( $details['changes'] ) ) {
			array_pop( $details['changes'] );

			$details['changes_omitted'] = ( isset( $details['changes_omitted'] ) ? (int) $details['changes_omitted'] : 0 ) + 1;
		}

		// Step 3: anything still too big is a runaway structure; keep the flag.
		if ( self::encoded_size( $details ) > $max_bytes ) {
			$details = array( 'details_omitted' => true );
		}

		return $details;
	}

	/**
	 * Encoded size of a details structure, in bytes.
	 *
	 * @param array $details Details.
	 * @return int
	 */
	public static function encoded_size( array $details ) {
		return strlen( self::encode( $details ) );
	}

	/**
	 * JSON-encode details the way they are stored.
	 *
	 * @param array $details Details.
	 * @return string
	 */
	public static function encode( array $details ) {
		$json = json_encode( $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		return is_string( $json ) ? $json : '{}';
	}
}
