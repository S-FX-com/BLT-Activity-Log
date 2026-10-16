<?php
/**
 * Field diffs, key-path diffs and block / element histograms. WordPress-free.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns "before" and "after" into the `changes`, `content`, `bricks` and
 * `terms` pieces of an entry's details.
 *
 * Everything here is a pure function of its arguments, so the comparison
 * rules (the part most likely to be subtly wrong) are unit-tested without a
 * WordPress install.
 */
class BLT_Activity_Log_Diff {

	/**
	 * Deepest key-path level an array is walked to.
	 */
	const MAX_DEPTH = 3;

	/**
	 * Block name given to unnamed blocks that carry real HTML.
	 */
	const FREEFORM = 'core/freeform';

	/**
	 * A value as the string it is compared and stored as.
	 *
	 * Scalars compare as strings, so `1` and `'1'` are equal. Structures are
	 * encoded to JSON after sensitive keys inside them are masked.
	 *
	 * @param mixed $value Any value.
	 * @return string
	 */
	public static function to_string( $value ) {
		if ( null === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return BLT_Activity_Log_Redactor::encode( (array) BLT_Activity_Log_Redactor::scrub( $value ) );
	}

	/**
	 * Whether two values are the same for logging purposes.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool
	 */
	public static function equal( $a, $b ) {
		if ( is_scalar( $a ) || null === $a ) {
			if ( is_scalar( $b ) || null === $b ) {
				return self::to_string( $a ) === self::to_string( $b );
			}

			return false;
		}

		if ( is_scalar( $b ) || null === $b ) {
			return false;
		}

		// Unmasked comparison: a change to a secret must still register.
		return self::raw_encode( $a ) === self::raw_encode( $b );
	}

	/**
	 * Compare named fields of two records.
	 *
	 * @param array    $before Field map before.
	 * @param array    $after  Field map after.
	 * @param string[] $keys   Fields to compare.
	 * @return array List of change items.
	 */
	public static function fields( array $before, array $after, array $keys ) {
		$changes = array();

		foreach ( $keys as $key ) {
			$old = array_key_exists( $key, $before ) ? $before[ $key ] : '';
			$new = array_key_exists( $key, $after ) ? $after[ $key ] : '';

			if ( self::equal( $old, $new ) ) {
				continue;
			}

			$changes[] = array(
				'field'  => $key,
				'before' => self::to_string( $old ),
				'after'  => self::to_string( $new ),
			);
		}

		return $changes;
	}

	/**
	 * Compare two values of one named field, walking arrays by key path.
	 *
	 * Associative arrays are walked to MAX_DEPTH key levels and each leaf is
	 * recorded as `field[key][subkey]`. A list (sequential keys) is compared
	 * as a unit: unchanged members in a new order are recorded as "order
	 * changed" rather than as a pile of index shifts.
	 *
	 * @param string $field  Field name, e.g. an option name.
	 * @param mixed  $before Value before.
	 * @param mixed  $after  Value after.
	 * @param int    $depth  Current key-path depth (internal).
	 * @return array List of change items.
	 */
	public static function values( $field, $before, $after, $depth = 0 ) {
		if ( self::equal( $before, $after ) ) {
			return array();
		}

		if ( is_object( $before ) ) {
			$before = get_object_vars( $before );
		}

		if ( is_object( $after ) ) {
			$after = get_object_vars( $after );
		}

		// A map that appears or disappears is reported key by key, the same as
		// one that changes, so a secret inside it is still named and hidden.
		if ( ( '' === $before || null === $before ) && is_array( $after ) && self::is_map( $after ) ) {
			$before = array();
		} elseif ( ( '' === $after || null === $after ) && is_array( $before ) && self::is_map( $before ) ) {
			$after = array();
		}

		if ( is_array( $before ) && is_array( $after ) ) {
			// An empty array is a list and a map at once, so it is walkable: an
			// option that did not exist yet compares as an empty map, and its new
			// keys are reported one by one, not as one blob.
			$before_list = array() !== $before && self::is_list( $before );
			$after_list  = array() !== $after && self::is_list( $after );

			if ( $before_list && $after_list ) {
				return self::list_change( $field, $before, $after );
			}

			if ( $depth < self::MAX_DEPTH && ! $before_list && ! $after_list ) {
				$changes = array();
				$keys    = array_merge( array_keys( $before ), array_diff( array_keys( $after ), array_keys( $before ) ) );

				foreach ( $keys as $key ) {
					$path = $field . '[' . $key . ']';
					$old  = array_key_exists( $key, $before ) ? $before[ $key ] : '';
					$new  = array_key_exists( $key, $after ) ? $after[ $key ] : '';

					$changes = array_merge( $changes, self::values( $path, $old, $new, $depth + 1 ) );
				}

				return $changes;
			}
		}

		return array(
			array(
				'field'  => $field,
				'before' => self::to_string( $before ),
				'after'  => self::to_string( $after ),
			),
		);
	}

	/**
	 * Count blocks by name, recursively.
	 *
	 * Takes the output of parse_blocks(). Whitespace-only freeform blocks (the
	 * newlines between blocks) are ignored; a freeform block with real HTML
	 * counts as `core/freeform`.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array<string,int> Block name => count, sorted by name.
	 */
	public static function block_histogram( array $blocks ) {
		$counts = array();

		self::count_blocks( $blocks, $counts );
		ksort( $counts );

		return $counts;
	}

	/**
	 * Count Bricks elements by `name`.
	 *
	 * Bricks stores a flat list of elements, each with a `name`. Nested
	 * element arrays under `children` are walked too, so either shape works.
	 *
	 * @param array $elements Bricks elements.
	 * @return array<string,int> Element name => count, sorted by name.
	 */
	public static function elements_histogram( array $elements ) {
		$counts = array();

		self::count_elements( $elements, $counts );
		ksort( $counts );

		return $counts;
	}

	/**
	 * What a histogram gained and lost.
	 *
	 * @param array<string,int> $before Histogram before.
	 * @param array<string,int> $after  Histogram after.
	 * @return array{added:array<string,int>,removed:array<string,int>}
	 */
	public static function histogram_delta( array $before, array $after ) {
		$added   = array();
		$removed = array();
		$names   = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );

		sort( $names );

		foreach ( $names as $name ) {
			$delta = ( isset( $after[ $name ] ) ? (int) $after[ $name ] : 0 ) - ( isset( $before[ $name ] ) ? (int) $before[ $name ] : 0 );

			if ( $delta > 0 ) {
				$added[ $name ] = $delta;
			} elseif ( $delta < 0 ) {
				$removed[ $name ] = -$delta;
			}
		}

		return array(
			'added'   => $added,
			'removed' => $removed,
		);
	}

	/**
	 * What a list of term names gained and lost.
	 *
	 * @param string[] $before Names before.
	 * @param string[] $after  Names after.
	 * @return array{added:string[],removed:string[]}
	 */
	public static function names( array $before, array $after ) {
		return array(
			'added'   => array_values( array_diff( $after, $before ) ),
			'removed' => array_values( array_diff( $before, $after ) ),
		);
	}

	/**
	 * Whether an array is a list (keys 0..n-1 in order).
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	public static function is_list( array $value ) {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Whether an array is a non-empty map (not a list).
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private static function is_map( array $value ) {
		return array() !== $value && ! self::is_list( $value );
	}

	/**
	 * Change item for two lists.
	 *
	 * @param string $field  Field name.
	 * @param array  $before List before.
	 * @param array  $after  List after.
	 * @return array
	 */
	private static function list_change( $field, array $before, array $after ) {
		$old = array_map( array( __CLASS__, 'raw_encode' ), $before );
		$new = array_map( array( __CLASS__, 'raw_encode' ), $after );

		$sorted_old = $old;
		$sorted_new = $new;

		sort( $sorted_old );
		sort( $sorted_new );

		if ( $sorted_old === $sorted_new ) {
			return array(
				array(
					'field'         => $field,
					'order_changed' => true,
				),
			);
		}

		return array(
			array(
				'field'  => $field,
				'before' => self::to_string( $before ),
				'after'  => self::to_string( $after ),
			),
		);
	}

	/**
	 * Recursive worker for block_histogram().
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $counts Running counts (by reference).
	 * @return void
	 */
	private static function count_blocks( array $blocks, array &$counts ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			if ( '' === $name ) {
				$html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';

				if ( '' !== trim( $html ) ) {
					$name = self::FREEFORM;
				}
			}

			if ( '' !== $name ) {
				$counts[ $name ] = ( isset( $counts[ $name ] ) ? $counts[ $name ] : 0 ) + 1;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::count_blocks( $block['innerBlocks'], $counts );
			}
		}
	}

	/**
	 * Recursive worker for elements_histogram().
	 *
	 * @param array $elements Bricks elements.
	 * @param array $counts   Running counts (by reference).
	 * @return void
	 */
	private static function count_elements( array $elements, array &$counts ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['name'] ) && is_string( $element['name'] ) && '' !== $element['name'] ) {
				$counts[ $element['name'] ] = ( isset( $counts[ $element['name'] ] ) ? $counts[ $element['name'] ] : 0 ) + 1;
			}

			if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
				$first = reset( $element['children'] );

				if ( is_array( $first ) ) {
					self::count_elements( $element['children'], $counts );
				}
			}
		}
	}

	/**
	 * Stable, unmasked encoding used only for equality.
	 *
	 * @param mixed $value Any value.
	 * @return string
	 */
	private static function raw_encode( $value ) {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		return is_string( $json ) ? $json : '';
	}
}
