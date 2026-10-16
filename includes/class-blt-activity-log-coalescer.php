<?php
/**
 * Merge rule for repeated saves of one object. WordPress-free.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Merges the details of two entries about the same object: the earliest
 * "before" and the latest "after" survive, and a field that ended up where it
 * started is dropped.
 *
 * Used both within a request (a post save that also fires term and meta
 * hooks) and across requests (the block editor saves a post, then saves its
 * legacy meta boxes in a second request).
 */
class BLT_Activity_Log_Coalescer {

	/**
	 * Actions that are only a detail of another event when they fire first.
	 */
	const DETAIL_ACTIONS = array( 'updated', 'role_changed' );

	/**
	 * Detail keys that make an `updated` entry worth keeping.
	 */
	const CONTENT_KEYS = array( 'changes', 'content', 'bricks', 'terms', 'versions', 'summary', 'method', 'via' );

	/**
	 * Whether a new entry may be merged into an existing one.
	 *
	 * Only a repeated `updated` merges, and only into another `updated` or
	 * into the `created` entry for the same object.
	 *
	 * @param string $existing_action Action of the entry already stored.
	 * @param string $new_action      Action of the entry being added.
	 * @return bool
	 */
	public static function can_absorb( $existing_action, $new_action ) {
		return 'updated' === $new_action && in_array( $existing_action, array( 'updated', 'created' ), true );
	}

	/**
	 * Whether details carry anything worth recording.
	 *
	 * @param array $details Details.
	 * @return bool
	 */
	public static function has_content( array $details ) {
		foreach ( self::CONTENT_KEYS as $key ) {
			if ( ! empty( $details[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An `updated` entry that records no change is not an entry.
	 *
	 * @param array $entry Entry with `action` and `details`.
	 * @return bool
	 */
	public static function is_empty_update( array $entry ) {
		if ( ! isset( $entry['action'] ) || 'updated' !== $entry['action'] ) {
			return false;
		}

		$details = isset( $entry['details'] ) && is_array( $entry['details'] ) ? $entry['details'] : array();

		return ! self::has_content( $details );
	}

	/**
	 * Merge two details structures about the same object.
	 *
	 * @param array $earlier Details of the earlier entry.
	 * @param array $later   Details of the later entry.
	 * @return array
	 */
	public static function merge_details( array $earlier, array $later ) {
		$merged = array_merge( $earlier, $later );

		unset( $merged['changes'], $merged['content'], $merged['bricks'], $merged['terms'], $merged['versions'] );

		$changes = self::merge_changes(
			isset( $earlier['changes'] ) ? (array) $earlier['changes'] : array(),
			isset( $later['changes'] ) ? (array) $later['changes'] : array()
		);

		if ( ! empty( $changes ) ) {
			$merged['changes'] = $changes;
		}

		$content = self::merge_content(
			isset( $earlier['content'] ) ? (array) $earlier['content'] : array(),
			isset( $later['content'] ) ? (array) $later['content'] : array()
		);

		if ( ! empty( $content ) ) {
			$merged['content'] = $content;
		}

		$bricks = self::merge_bricks(
			isset( $earlier['bricks'] ) ? (array) $earlier['bricks'] : array(),
			isset( $later['bricks'] ) ? (array) $later['bricks'] : array()
		);

		if ( ! empty( $bricks ) ) {
			$merged['bricks'] = $bricks;
		}

		$terms = self::merge_terms(
			isset( $earlier['terms'] ) ? (array) $earlier['terms'] : array(),
			isset( $later['terms'] ) ? (array) $later['terms'] : array()
		);

		if ( ! empty( $terms ) ) {
			$merged['terms'] = $terms;
		}

		$versions = self::merge_versions(
			isset( $earlier['versions'] ) ? (array) $earlier['versions'] : array(),
			isset( $later['versions'] ) ? (array) $later['versions'] : array()
		);

		if ( ! empty( $versions ) ) {
			$merged['versions'] = $versions;
		}

		return $merged;
	}

	/**
	 * Merge two entries buffered in one request.
	 *
	 * The earlier entry keeps its identity. Its action is replaced when the
	 * later one is more specific than a plain `updated` (a save that also
	 * published the post).
	 *
	 * @param array $earlier Earlier buffered entry.
	 * @param array $later   Later buffered entry.
	 * @return array
	 */
	public static function merge_buffered( array $earlier, array $later ) {
		$merged = $earlier;

		$merged['details'] = self::merge_details(
			isset( $earlier['details'] ) && is_array( $earlier['details'] ) ? $earlier['details'] : array(),
			isset( $later['details'] ) && is_array( $later['details'] ) ? $later['details'] : array()
		);

		// An earlier `updated` or `role_changed` is only a detail of whatever
		// event it turns out to belong to; any other earlier action stays.
		if ( in_array( $earlier['action'], self::DETAIL_ACTIONS, true ) && ! in_array( $later['action'], self::DETAIL_ACTIONS, true ) ) {
			$merged['action'] = $later['action'];
		}

		if ( isset( $later['object_label'] ) && '' !== $later['object_label'] ) {
			$merged['object_label'] = $later['object_label'];
		}

		return $merged;
	}

	/**
	 * Merge a new entry into a stored row.
	 *
	 * @param array $row {
	 *     The stored row.
	 *
	 *     @type string $action      Stored action.
	 *     @type int    $occurrences Saves already merged into it.
	 *     @type array  $details     Decoded details.
	 * }
	 * @param array $incoming Entry being added (`details`, `object_label`, `created_at`).
	 * @return array {
	 *     @type array  $details     Merged details.
	 *     @type int    $occurrences New count.
	 *     @type string $last_at     Time of the newest merged save.
	 *     @type string $object_label Latest label.
	 *     @type bool   $drop        True when nothing is left to record, so the
	 *                               stored `updated` row should be deleted.
	 * }
	 */
	public static function merge_row( array $row, array $incoming ) {
		$details = self::merge_details(
			isset( $row['details'] ) && is_array( $row['details'] ) ? $row['details'] : array(),
			isset( $incoming['details'] ) && is_array( $incoming['details'] ) ? $incoming['details'] : array()
		);

		$occurrences = min( 65535, ( isset( $row['occurrences'] ) ? (int) $row['occurrences'] : 1 ) + 1 );

		return array(
			'details'      => $details,
			'occurrences'  => $occurrences,
			'last_at'      => isset( $incoming['created_at'] ) ? (string) $incoming['created_at'] : gmdate( 'Y-m-d H:i:s' ),
			'object_label' => isset( $incoming['object_label'] ) ? (string) $incoming['object_label'] : '',
			'drop'         => 'updated' === $row['action'] && ! self::has_content( $details ),
		);
	}

	/**
	 * Merge two `changes` lists: earliest before, latest after, reverts dropped.
	 *
	 * @param array $earlier Earlier changes.
	 * @param array $later   Later changes.
	 * @return array
	 */
	public static function merge_changes( array $earlier, array $later ) {
		$by_field = array();

		foreach ( $earlier as $change ) {
			if ( is_array( $change ) && isset( $change['field'] ) ) {
				$by_field[ $change['field'] ] = $change;
			}
		}

		foreach ( $later as $change ) {
			if ( ! is_array( $change ) || ! isset( $change['field'] ) ) {
				continue;
			}

			$field = $change['field'];

			if ( ! isset( $by_field[ $field ] ) ) {
				$by_field[ $field ] = $change;
				continue;
			}

			$by_field[ $field ] = self::merge_change( $by_field[ $field ], $change );
		}

		$out = array();

		foreach ( $by_field as $change ) {
			if ( null !== $change ) {
				$out[] = $change;
			}
		}

		return $out;
	}

	/**
	 * Merge two changes to one field.
	 *
	 * @param array $first  Earlier change.
	 * @param array $second Later change.
	 * @return array|null The merged change, or null when the field ended up
	 *                    where it started.
	 */
	private static function merge_change( array $first, array $second ) {
		// A redacted side cannot be compared, so the field stays on record as changed.
		if ( ! empty( $first['redacted'] ) || ! empty( $second['redacted'] ) ) {
			$item = array(
				'field'    => $first['field'],
				'redacted' => true,
			);

			$note = isset( $second['note'] ) ? $second['note'] : ( isset( $first['note'] ) ? $first['note'] : null );

			if ( null !== $note ) {
				$item['note'] = $note;
			}

			return $item;
		}

		// A change recorded only as a flag (reordered, edited) has no values to compare.
		foreach ( array( 'order_changed', 'edited' ) as $flag ) {
			if ( ! empty( $first[ $flag ] ) && ! empty( $second[ $flag ] ) ) {
				return $first;
			}
		}

		$merged = array( 'field' => $first['field'] );

		if ( array_key_exists( 'before', $first ) ) {
			$merged['before'] = $first['before'];
		}

		if ( array_key_exists( 'after', $second ) ) {
			$merged['after'] = $second['after'];
		}

		$truncated = array();

		if ( isset( $first['truncated']['before'] ) ) {
			$truncated['before'] = $first['truncated']['before'];
		}

		if ( isset( $second['truncated']['after'] ) ) {
			$truncated['after'] = $second['truncated']['after'];
		}

		if ( ! empty( $truncated ) ) {
			$merged['truncated'] = $truncated;
		}

		// Reverted: same text on both ends. A shortened value cannot prove it,
		// so a truncated side keeps the field on record.
		if (
			empty( $truncated )
			&& isset( $merged['before'], $merged['after'] )
			&& (string) $merged['before'] === (string) $merged['after']
		) {
			return null;
		}

		return $merged;
	}

	/**
	 * Merge two `content` summaries.
	 *
	 * @param array $earlier Earlier content summary.
	 * @param array $later   Later content summary.
	 * @return array
	 */
	private static function merge_content( array $earlier, array $later ) {
		if ( empty( $earlier ) ) {
			return $later;
		}

		if ( empty( $later ) ) {
			return $earlier;
		}

		$delta = self::net_delta(
			isset( $earlier['blocks_added'] ) ? (array) $earlier['blocks_added'] : array(),
			isset( $earlier['blocks_removed'] ) ? (array) $earlier['blocks_removed'] : array(),
			isset( $later['blocks_added'] ) ? (array) $later['blocks_added'] : array(),
			isset( $later['blocks_removed'] ) ? (array) $later['blocks_removed'] : array()
		);

		$first_chars = isset( $earlier['chars'][0] ) ? (int) $earlier['chars'][0] : null;
		$last_chars  = isset( $later['chars'][1] ) ? (int) $later['chars'][1] : null;

		$merged = array();

		if ( null !== $first_chars && null !== $last_chars ) {
			$merged['chars'] = array( $first_chars, $last_chars );
		}

		if ( ! empty( $delta['added'] ) ) {
			$merged['blocks_added'] = $delta['added'];
		}

		if ( ! empty( $delta['removed'] ) ) {
			$merged['blocks_removed'] = $delta['removed'];
		}

		// Edited and edited back: same length and no block movement means no content change.
		if ( empty( $delta['added'] ) && empty( $delta['removed'] ) && $first_chars === $last_chars ) {
			return array();
		}

		if ( isset( $later['revision_id'] ) ) {
			$merged['revision_id'] = $later['revision_id'];
		} elseif ( isset( $earlier['revision_id'] ) ) {
			$merged['revision_id'] = $earlier['revision_id'];
		}

		return $merged;
	}

	/**
	 * Merge two Bricks element summaries.
	 *
	 * @param array $earlier Earlier summary.
	 * @param array $later   Later summary.
	 * @return array
	 */
	private static function merge_bricks( array $earlier, array $later ) {
		if ( empty( $earlier ) ) {
			return $later;
		}

		if ( empty( $later ) ) {
			return $earlier;
		}

		$delta = self::net_delta(
			isset( $earlier['elements_added'] ) ? (array) $earlier['elements_added'] : array(),
			isset( $earlier['elements_removed'] ) ? (array) $earlier['elements_removed'] : array(),
			isset( $later['elements_added'] ) ? (array) $later['elements_added'] : array(),
			isset( $later['elements_removed'] ) ? (array) $later['elements_removed'] : array()
		);

		return array(
			'elements_added'   => $delta['added'],
			'elements_removed' => $delta['removed'],
		);
	}

	/**
	 * Net effect of two successive add/remove histograms.
	 *
	 * @param array $added_a   First pass: added.
	 * @param array $removed_a First pass: removed.
	 * @param array $added_b   Second pass: added.
	 * @param array $removed_b Second pass: removed.
	 * @return array{added:array<string,int>,removed:array<string,int>}
	 */
	private static function net_delta( array $added_a, array $removed_a, array $added_b, array $removed_b ) {
		$net   = array();
		$lists = array(
			array( $added_a, 1 ),
			array( $removed_a, -1 ),
			array( $added_b, 1 ),
			array( $removed_b, -1 ),
		);

		foreach ( $lists as $pair ) {
			foreach ( $pair[0] as $name => $count ) {
				$net[ $name ] = ( isset( $net[ $name ] ) ? $net[ $name ] : 0 ) + ( $pair[1] * (int) $count );
			}
		}

		ksort( $net );

		$added   = array();
		$removed = array();

		foreach ( $net as $name => $count ) {
			if ( $count > 0 ) {
				$added[ $name ] = $count;
			} elseif ( $count < 0 ) {
				$removed[ $name ] = -$count;
			}
		}

		return array(
			'added'   => $added,
			'removed' => $removed,
		);
	}

	/**
	 * Merge two `terms` summaries, taxonomy by taxonomy.
	 *
	 * A term added and then removed (or the reverse) cancels out.
	 *
	 * @param array $earlier Earlier terms.
	 * @param array $later   Later terms.
	 * @return array
	 */
	private static function merge_terms( array $earlier, array $later ) {
		$out        = array();
		$taxonomies = array_unique( array_merge( array_keys( $earlier ), array_keys( $later ) ) );

		foreach ( $taxonomies as $taxonomy ) {
			$added   = isset( $earlier[ $taxonomy ]['added'] ) ? (array) $earlier[ $taxonomy ]['added'] : array();
			$removed = isset( $earlier[ $taxonomy ]['removed'] ) ? (array) $earlier[ $taxonomy ]['removed'] : array();

			$later_added   = isset( $later[ $taxonomy ]['added'] ) ? (array) $later[ $taxonomy ]['added'] : array();
			$later_removed = isset( $later[ $taxonomy ]['removed'] ) ? (array) $later[ $taxonomy ]['removed'] : array();

			foreach ( $later_added as $name ) {
				if ( in_array( $name, $removed, true ) ) {
					$removed = array_values( array_diff( $removed, array( $name ) ) );
				} elseif ( ! in_array( $name, $added, true ) ) {
					$added[] = $name;
				}
			}

			foreach ( $later_removed as $name ) {
				if ( in_array( $name, $added, true ) ) {
					$added = array_values( array_diff( $added, array( $name ) ) );
				} elseif ( ! in_array( $name, $removed, true ) ) {
					$removed[] = $name;
				}
			}

			if ( ! empty( $added ) || ! empty( $removed ) ) {
				$out[ $taxonomy ] = array(
					'added'   => array_values( $added ),
					'removed' => array_values( $removed ),
				);
			}
		}

		return $out;
	}

	/**
	 * Merge two version ranges: earliest "from", latest "to".
	 *
	 * @param array $earlier Earlier versions.
	 * @param array $later   Later versions.
	 * @return array
	 */
	private static function merge_versions( array $earlier, array $later ) {
		if ( empty( $earlier ) ) {
			return $later;
		}

		if ( empty( $later ) ) {
			return $earlier;
		}

		$from = isset( $earlier['from'] ) ? $earlier['from'] : '';
		$to   = isset( $later['to'] ) ? $later['to'] : '';

		if ( (string) $from === (string) $to ) {
			return array();
		}

		return array(
			'from' => $from,
			'to'   => $to,
		);
	}
}
