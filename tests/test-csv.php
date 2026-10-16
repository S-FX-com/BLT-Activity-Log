<?php
/**
 * Csv: formula-injection escaping and RFC 4180 quoting.
 *
 * @package Blt_Activity_Log
 */

use PHPUnit\Framework\TestCase;

/**
 * Known-vector tests for BLT_Activity_Log_Csv.
 */
class Test_Csv extends TestCase {

	/**
	 * Cells a spreadsheet would run as formulas.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function dangerous_cells() {
		return array(
			'formula'            => array( '=SUM(A1)', "'=SUM(A1)" ),
			'plus'               => array( '+1', "'+1" ),
			'minus'              => array( '-1', "'-1" ),
			'at'                 => array( '@cmd', "'@cmd" ),
			'leading tab'        => array( "\tcmd", "'\tcmd" ),
			'leading CR'         => array( "\rcmd", "'\rcmd" ),
			'dde style'          => array( "=cmd|' /C calc'!A0", "'=cmd|' /C calc'!A0" ),
			'hyperlink formula'  => array( '=HYPERLINK("http://evil","x")', "'=HYPERLINK(\"http://evil\",\"x\")" ),
		);
	}

	/**
	 * @dataProvider dangerous_cells
	 *
	 * @param string $cell     Cell.
	 * @param string $expected Escaped cell.
	 * @return void
	 */
	public function test_formula_cells_are_neutralized( $cell, $expected ) {
		$this->assertSame( $expected, BLT_Activity_Log_Csv::escape_cell( $cell ) );
	}

	/**
	 * Ordinary text, including symbols that are only dangerous at the start.
	 *
	 * @return void
	 */
	public function test_ordinary_text_is_unchanged() {
		foreach ( array( 'About Us', 'a=b', 'x+y', 'user@example.com', 'a-b', '', '0', '12:30', "tab\tinside" ) as $cell ) {
			$this->assertSame( $cell, BLT_Activity_Log_Csv::escape_cell( $cell ), $cell );
		}
	}

	/**
	 * Non-strings are cast.
	 *
	 * @return void
	 */
	public function test_non_strings_are_cast() {
		$this->assertSame( '12', BLT_Activity_Log_Csv::escape_cell( 12 ) );
		$this->assertSame( '', BLT_Activity_Log_Csv::escape_cell( null ) );
		$this->assertSame( "'-5", BLT_Activity_Log_Csv::escape_cell( -5 ), 'A negative number is indistinguishable from a formula lead.' );
	}

	/**
	 * Read one CSV line back the way a spreadsheet would.
	 *
	 * @param string $line A line from BLT_Activity_Log_Csv::line().
	 * @return array
	 */
	private function parse( $line ) {
		return str_getcsv( rtrim( $line, "\n" ), ',', '"', '' );
	}

	/**
	 * A line ends in a newline and survives a round trip, quoting included.
	 *
	 * @return void
	 */
	public function test_line_round_trips() {
		$cells = array( '1', 'plain', 'has,comma', 'has "quote"', 'has space', 'C:\\temp\\file', 'say "hi\\"' );
		$line  = BLT_Activity_Log_Csv::line( $cells );

		$this->assertSame( "\n", substr( $line, -1 ) );
		$this->assertSame( $cells, $this->parse( $line ) );
	}

	/**
	 * Escaping happens before quoting, so the quote character survives intact.
	 *
	 * @return void
	 */
	public function test_formula_cells_are_escaped_in_a_line() {
		$this->assertSame( array( "'=SUM(A1)", 'ok', "'@cmd", "'+1" ), $this->parse( BLT_Activity_Log_Csv::line( array( '=SUM(A1)', 'ok', '@cmd', '+1' ) ) ) );
	}

	/**
	 * A newline inside a cell stays inside its quotes, on one record.
	 *
	 * @return void
	 */
	public function test_embedded_newlines_stay_in_one_record() {
		$line = BLT_Activity_Log_Csv::line( array( "a\nb", 'c' ) );

		$this->assertSame( 2, substr_count( $line, "\n" ), 'One newline inside the cell, one ending the record.' );
		$this->assertSame( array( "a\nb", 'c' ), $this->parse( $line ) );
	}
}
