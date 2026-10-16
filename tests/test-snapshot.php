<?php
/**
 * Snapshot: detecting what changed outside WordPress.
 *
 * @package Blt_Activity_Log
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for BLT_Activity_Log_Snapshot::diff().
 */
class Test_Snapshot extends TestCase {

	/**
	 * A snapshot with the shape the plugin stores.
	 *
	 * @param string $core    Core version.
	 * @param array  $plugins Plugin file => version.
	 * @param array  $themes  Stylesheet => version.
	 * @return array
	 */
	private function snap( $core, array $plugins = array(), array $themes = array() ) {
		return array(
			'core'    => $core,
			'plugins' => $plugins,
			'themes'  => $themes,
		);
	}

	/**
	 * Nothing changed: nothing to report.
	 *
	 * @return void
	 */
	public function test_identical_snapshots_have_no_changes() {
		$snap = $this->snap( '6.8', array( 'a/a.php' => '1.0' ), array( 'tt' => '1.0' ) );

		$this->assertSame( array(), BLT_Activity_Log_Snapshot::diff( $snap, $snap ) );
	}

	/**
	 * A plugin folder replaced by hand shows as a version change.
	 *
	 * @return void
	 */
	public function test_plugin_version_change() {
		$changes = BLT_Activity_Log_Snapshot::diff(
			$this->snap( '6.8', array( 'a/a.php' => '1.4.2' ) ),
			$this->snap( '6.8', array( 'a/a.php' => '1.4.3' ) )
		);

		$this->assertSame(
			array(
				array(
					'kind'   => 'plugin',
					'change' => 'version',
					'key'    => 'a/a.php',
					'from'   => '1.4.2',
					'to'     => '1.4.3',
				),
			),
			$changes
		);
	}

	/**
	 * A plugin that appeared or vanished.
	 *
	 * @return void
	 */
	public function test_plugins_added_and_removed() {
		$changes = BLT_Activity_Log_Snapshot::diff(
			$this->snap( '6.8', array( 'gone/gone.php' => '2.0' ) ),
			$this->snap( '6.8', array( 'new/new.php' => '1.0' ) )
		);

		$this->assertCount( 2, $changes );

		$by_key = array();

		foreach ( $changes as $change ) {
			$by_key[ $change['key'] ] = $change;
		}

		$this->assertSame( 'added', $by_key['new/new.php']['change'] );
		$this->assertSame( '1.0', $by_key['new/new.php']['to'] );
		$this->assertSame( 'removed', $by_key['gone/gone.php']['change'] );
		$this->assertSame( '2.0', $by_key['gone/gone.php']['from'] );
	}

	/**
	 * Themes work the same way.
	 *
	 * @return void
	 */
	public function test_theme_changes() {
		$changes = BLT_Activity_Log_Snapshot::diff(
			$this->snap( '6.8', array(), array( 'tt' => '1.0' ) ),
			$this->snap( '6.8', array(), array( 'tt' => '1.1' ) )
		);

		$this->assertSame( 'theme', $changes[0]['kind'] );
		$this->assertSame( 'version', $changes[0]['change'] );
	}

	/**
	 * A core upgrade done outside the updater (a Composer deploy).
	 *
	 * @return void
	 */
	public function test_core_version_change() {
		$changes = BLT_Activity_Log_Snapshot::diff( $this->snap( '6.7.1' ), $this->snap( '6.8' ) );

		$this->assertSame(
			array(
				array(
					'kind'   => 'core',
					'change' => 'version',
					'key'    => 'wordpress',
					'from'   => '6.7.1',
					'to'     => '6.8',
				),
			),
			$changes
		);
	}

	/**
	 * With no stored core there is no baseline, so core is not reported.
	 *
	 * @return void
	 */
	public function test_missing_core_baseline_reports_nothing_for_core() {
		$this->assertSame( array(), BLT_Activity_Log_Snapshot::diff( $this->snap( '' ), $this->snap( '6.8' ) ) );
	}

	/**
	 * Numeric-looking versions compare as strings, so 1.10 is not 1.1.
	 *
	 * @return void
	 */
	public function test_versions_compare_as_strings() {
		$changes = BLT_Activity_Log_Snapshot::diff(
			$this->snap( '6.8', array( 'a/a.php' => '1.1' ) ),
			$this->snap( '6.8', array( 'a/a.php' => '1.10' ) )
		);

		$this->assertCount( 1, $changes );
	}

	/**
	 * Malformed input never raises.
	 *
	 * @return void
	 */
	public function test_malformed_snapshots_are_tolerated() {
		$this->assertSame( array(), BLT_Activity_Log_Snapshot::diff( array(), array() ) );
		$this->assertSame( array(), BLT_Activity_Log_Snapshot::diff( array( 'plugins' => 'x' ), array( 'plugins' => null ) ) );
	}
}
