<?php
/**
 * Minimal WordPress shims so the pure-logic classes load without a WP install.
 *
 * Only what the classes under test actually touch is shimmed. Anything that
 * needs real WordPress behavior belongs in the manual smoke checklist in
 * tasks/todo.md, not here.
 *
 * @package Blt_Activity_Log
 */

define( 'ABSPATH', __DIR__ . '/fake-abspath/' );
define( 'BLT_ACTIVITY_LOG_DIR', dirname( __DIR__ ) . '/' );
define( 'BLT_ACTIVITY_LOG_VERSION', 'tests' );
define( 'BLT_ACTIVITY_LOG_BASENAME', 'blt-activity-log/blt-activity-log.php' );

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}

// The same computed autoloader the main plugin file registers.
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'BLT_Activity_Log';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$rest = substr( $class_name, strlen( $prefix ) );

		if ( '' !== $rest && '_' !== $rest[0] ) {
			return;
		}

		$file = 'class-blt-activity-log' . strtolower( str_replace( '_', '-', $rest ) ) . '.php';

		foreach ( array( BLT_ACTIVITY_LOG_DIR . 'includes/' . $file, BLT_ACTIVITY_LOG_DIR . 'admin/' . $file ) as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

// In-memory options and filters, reset per test through blt_test_reset().
$GLOBALS['blt_test_options'] = array();
$GLOBALS['blt_test_filters'] = array();
$GLOBALS['blt_test_actions'] = array();

/**
 * Reset the shimmed WordPress state between tests.
 *
 * @return void
 */
function blt_test_reset() {
	$GLOBALS['blt_test_options'] = array();
	$GLOBALS['blt_test_filters'] = array();
	$GLOBALS['blt_test_actions'] = array();
}

if ( ! function_exists( '__' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $single Singular.
	 * @param string $plural Plural.
	 * @param int    $number Number.
	 * @param string $domain Domain.
	 * @return string
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) { // phpcs:ignore
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'wp_sprintf' ) ) {
	/**
	 * WP shim: only the `%l` list placeholder the formatter uses.
	 *
	 * @param string $pattern Pattern.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	function wp_sprintf( $pattern, ...$args ) { // phpcs:ignore
		$list = isset( $args[0] ) && is_array( $args[0] ) ? array_values( $args[0] ) : array();

		if ( count( $list ) <= 1 ) {
			return (string) ( $list[0] ?? '' );
		}

		$last = array_pop( $list );

		return implode( ', ', $list ) . ' and ' . $last;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * WP shim: runs any callback registered with add_filter().
	 *
	 * @param string $hook  Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) { // phpcs:ignore
		$args = func_get_args();

		array_shift( $args );

		if ( ! empty( $GLOBALS['blt_test_filters'][ $hook ] ) ) {
			foreach ( $GLOBALS['blt_test_filters'][ $hook ] as $callback ) {
				$args[0] = call_user_func_array( $callback, $args );
			}
		}

		return $args[0];
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @return bool
	 */
	function add_filter( $hook, $callback ) { // phpcs:ignore
		$GLOBALS['blt_test_filters'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * WP shim: remembers the registration so a test can see it happened.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @return bool
	 */
	function add_action( $hook, $callback ) { // phpcs:ignore
		$GLOBALS['blt_test_actions'][ $hook ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'has_action' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $hook Hook.
	 * @return bool
	 */
	function has_action( $hook ) {
		return ! empty( $GLOBALS['blt_test_actions'][ $hook ] );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * WP shim: calls every callback registered with add_action().
	 *
	 * @param string $hook Hook.
	 * @return void
	 */
	function do_action( $hook ) { // phpcs:ignore
		$args = func_get_args();

		array_shift( $args );

		if ( ! empty( $GLOBALS['blt_test_actions'][ $hook ] ) ) {
			foreach ( $GLOBALS['blt_test_actions'][ $hook ] as $callback ) {
				call_user_func_array( $callback, $args );
			}
		}
	}
}

if ( ! function_exists( 'did_action' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $hook Hook.
	 * @return int
	 */
	function did_action( $hook ) { // phpcs:ignore
		return 0;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * WP shim.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $text Value.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $text  Value.
	 * @param int    $quote Quote style.
	 * @return string
	 */
	function wp_specialchars_decode( $text, $quote = ENT_NOQUOTES ) {
		return htmlspecialchars_decode( (string) $text, $quote );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $str Value.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $str ) ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * WP shim.
	 *
	 * @param mixed $maybeint Value.
	 * @return int
	 */
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $name          Option name.
	 * @param mixed  $default_value Default.
	 * @return mixed
	 */
	function get_option( $name, $default_value = false ) {
		return array_key_exists( $name, $GLOBALS['blt_test_options'] ) ? $GLOBALS['blt_test_options'][ $name ] : $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Autoload flag (recorded in blt_test_autoload).
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['blt_test_options'][ $name ]  = $value;
		$GLOBALS['blt_test_autoload'][ $name ] = $autoload;

		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * WP shim.
	 *
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * WP shim.
	 *
	 * @param string $text Value.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	/**
	 * WP shim: knows two post types.
	 *
	 * @param string $type Post type.
	 * @return object|null
	 */
	function get_post_type_object( $type ) {
		$names = array(
			'post' => 'Post',
			'page' => 'Page',
		);

		if ( ! isset( $names[ $type ] ) ) {
			return null;
		}

		return (object) array( 'labels' => (object) array( 'singular_name' => $names[ $type ] ) );
	}
}

if ( ! function_exists( 'get_taxonomy' ) ) {
	/**
	 * WP shim: knows one taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return object|false
	 */
	function get_taxonomy( $taxonomy ) {
		if ( 'category' !== $taxonomy ) {
			return false;
		}

		return (object) array(
			'labels' => (object) array(
				'name'          => 'Categories',
				'singular_name' => 'Category',
			),
		);
	}
}

if ( ! function_exists( 'get_post_status_object' ) ) {
	/**
	 * WP shim: knows the common statuses.
	 *
	 * @param string $status Status.
	 * @return object|null
	 */
	function get_post_status_object( $status ) {
		$labels = array(
			'draft'   => 'Draft',
			'publish' => 'Published',
			'trash'   => 'Trash',
		);

		return isset( $labels[ $status ] ) ? (object) array( 'label' => $labels[ $status ] ) : null;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	/**
	 * WP shim.
	 *
	 * @param int $id User ID.
	 * @return false
	 */
	function get_userdata( $id ) { // phpcs:ignore
		return false;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * WP shim.
	 *
	 * @param int $id Post ID.
	 * @return null
	 */
	function get_post( $id ) { // phpcs:ignore
		return null;
	}
}

if ( ! function_exists( 'size_format' ) ) {
	/**
	 * WP shim.
	 *
	 * @param int $bytes    Bytes.
	 * @param int $decimals Decimals.
	 * @return string
	 */
	function size_format( $bytes, $decimals = 0 ) { // phpcs:ignore
		return number_format( $bytes / 1024, 1 ) . ' KB';
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * WP shim: UTC.
	 *
	 * @param string $format    Format.
	 * @param int    $timestamp Time.
	 * @return string
	 */
	function wp_date( $format, $timestamp ) {
		return gmdate( $format, $timestamp );
	}
}
