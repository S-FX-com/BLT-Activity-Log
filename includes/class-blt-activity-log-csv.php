<?php
/**
 * CSV cell escaping and row encoding.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds CSV lines for the export. The escaping is the part that matters: a
 * spreadsheet treats a cell starting with `=`, `+`, `-` or `@` as a formula,
 * and log labels are attacker-influenced text (a post title, a username).
 */
class BLT_Activity_Log_Csv {

	/**
	 * Characters that make a spreadsheet read a cell as a formula.
	 */
	const FORMULA_LEAD = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Neutralize a cell that would be read as a formula.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function escape_cell( $value ) {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], self::FORMULA_LEAD, true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * One CSV line, RFC 4180 quoting, every cell escaped.
	 *
	 * @param array $cells Cell values.
	 * @return string Line including the trailing newline.
	 */
	public static function line( array $cells ) {
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return '';
		}

		// An empty escape character: backslashes are ordinary text in CSV.
		fputcsv( $handle, array_map( array( __CLASS__, 'escape_cell' ), $cells ), ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		rewind( $handle );

		$line = (string) stream_get_contents( $handle );

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $line;
	}
}
