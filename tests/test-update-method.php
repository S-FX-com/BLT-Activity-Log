<?php
/**
 * Module_Base::update_method(): how an install or update was started.
 *
 * The upgrader skin classes below stand in for WordPress's, with the same
 * inheritance. That inheritance is the point: WP_Ajax_Upgrader_Skin extends
 * Automatic_Upgrader_Skin, and a check written without knowing that reports
 * every update started from the Plugins screen as automatic.
 *
 * @package Blt_Activity_Log
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
	/**
	 * Stand-in for WordPress's automatic-updater skin.
	 */
	class Automatic_Upgrader_Skin {} // phpcs:ignore
}

if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
	/**
	 * Stand-in for WordPress's AJAX skin, which extends the automatic one.
	 */
	class WP_Ajax_Upgrader_Skin extends Automatic_Upgrader_Skin {} // phpcs:ignore
}

/**
 * Exposes the protected method under test.
 */
class Blt_Test_Module extends BLT_Activity_Log_Module_Base {

	/** {@inheritdoc} */
	public function id() {
		return 'test';
	}

	/** {@inheritdoc} */
	public function label() {
		return 'Test';
	}

	/** {@inheritdoc} */
	public function description() {
		return 'Test';
	}

	/** {@inheritdoc} */
	public function default_enabled() {
		return true;
	}

	/** {@inheritdoc} */
	public function register() {}

	/**
	 * Call update_method().
	 *
	 * @param object|null $upgrader Upgrader.
	 * @return string
	 */
	public function method( $upgrader = null ) {
		return $this->update_method( $upgrader );
	}
}

/**
 * Tests for the update-method detection.
 */
class Test_Update_Method extends TestCase {

	/**
	 * An upgrader holding a skin.
	 *
	 * @param object $skin Skin.
	 * @return object
	 */
	private function upgrader( $skin ) {
		return (object) array( 'skin' => $skin );
	}

	/**
	 * An update started from the Plugins screen is "screen", not "automatic".
	 *
	 * @return void
	 */
	public function test_ajax_skin_is_a_screen_update() {
		$this->assertTrue( new WP_Ajax_Upgrader_Skin() instanceof Automatic_Upgrader_Skin, 'The inheritance this test depends on.' );
		$this->assertSame( 'screen', ( new Blt_Test_Module() )->method( $this->upgrader( new WP_Ajax_Upgrader_Skin() ) ) );
	}

	/**
	 * The real automatic updater's skin is "automatic".
	 *
	 * @return void
	 */
	public function test_automatic_skin_is_automatic() {
		$this->assertSame( 'automatic', ( new Blt_Test_Module() )->method( $this->upgrader( new Automatic_Upgrader_Skin() ) ) );
	}

	/**
	 * An installer skin that says it was an upload.
	 *
	 * @return void
	 */
	public function test_upload_skin() {
		$skin       = new stdClass();
		$skin->type = 'upload';

		$this->assertSame( 'upload', ( new Blt_Test_Module() )->method( $this->upgrader( $skin ) ) );

		$web       = new stdClass();
		$web->type = 'web';

		$this->assertSame( 'screen', ( new Blt_Test_Module() )->method( $this->upgrader( $web ) ), 'A package fetched from the directory is a screen install.' );
	}

	/**
	 * With nothing to go on it is a screen action.
	 *
	 * @return void
	 */
	public function test_default_is_screen() {
		$this->assertSame( 'screen', ( new Blt_Test_Module() )->method() );
		$this->assertSame( 'screen', ( new Blt_Test_Module() )->method( (object) array() ) );
		$this->assertSame( 'screen', ( new Blt_Test_Module() )->method( (object) array( 'skin' => 'not an object' ) ) );
	}
}
