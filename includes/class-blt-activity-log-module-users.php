<?php
/**
 * Module: user accounts.
 *
 * @package Blt_Activity_Log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records accounts created, edited and deleted, role changes, password changes
 * and resets, and application passwords created or revoked.
 *
 * No secret value is ever read: a password change is recorded as the fact of
 * it, and an application password by its name only.
 */
class BLT_Activity_Log_Module_Users extends BLT_Activity_Log_Module_Base {

	/**
	 * Account fields compared on `profile_update`.
	 */
	const FIELDS = array( 'user_email', 'display_name', 'user_nicename', 'user_url' );

	/**
	 * Profile meta tracked beside the account fields.
	 */
	const META = array( 'first_name', 'last_name', 'nickname' );

	/**
	 * Profile meta seen just before an update, keyed `{user}:{key}`.
	 *
	 * @var array<string,mixed>
	 */
	private $meta_before = array();

	/**
	 * {@inheritdoc}
	 */
	public function id() {
		return 'user';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return __( 'Users', 'blt-activity-log' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function description() {
		return __( 'Accounts created, edited or deleted, role changes, password changes and application passwords.', 'blt-activity-log' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function default_enabled() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function register() {
		$this->listen( 'user_register', 'on_registered', 10, 2 );
		$this->listen( 'profile_update', 'on_profile_update', 10, 3 );
		$this->listen( 'delete_user', 'on_before_delete', 10, 3 );
		$this->listen( 'after_password_reset', 'on_password_reset', 10, 2 );
		$this->listen( 'wp_create_application_password', 'on_app_password_created', 10, 4 );
		$this->listen( 'wp_delete_application_password', 'on_app_password_deleted', 10, 2 );
		$this->listen( 'update_user_meta', 'on_meta_before', 10, 4 );
		$this->listen( 'added_user_meta', 'on_meta_added', 10, 4 );
		$this->listen( 'updated_user_meta', 'on_meta_updated', 10, 4 );
	}

	/**
	 * An account was created.
	 *
	 * With no signed-in user the actor is empty, which is how a
	 * self-registration is told from an account an administrator created.
	 *
	 * @param int        $user_id  User ID.
	 * @param array|null $userdata Data the account was created with.
	 * @return void
	 */
	public function on_registered( $user_id, $userdata = null ) {
		unset( $userdata );

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'user',
				'action'       => 'created',
				'object_type'  => 'user',
				'object_id'    => (int) $user_id,
				'object_label' => (string) $user->user_login,
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'user_email',
							'before' => '',
							'after'  => (string) $user->user_email,
						),
						array(
							'field'  => 'role',
							'before' => '',
							'after'  => implode( ', ', array_map( 'strval', (array) $user->roles ) ),
						),
					),
				),
				'absorb'       => array( 'updated', 'role_changed' ),
			)
		);
	}

	/**
	 * An account was edited.
	 *
	 * @param int          $user_id       User ID.
	 * @param WP_User|null $old_user_data The user before the edit.
	 * @param array|null   $userdata      Data passed to wp_insert_user().
	 * @return void
	 */
	public function on_profile_update( $user_id, $old_user_data = null, $userdata = null ) {
		unset( $userdata );

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User || ! $old_user_data instanceof WP_User ) {
			return;
		}

		$before = get_object_vars( $old_user_data->data );
		$after  = get_object_vars( $user->data );

		if ( (string) $before['user_pass'] !== (string) $after['user_pass'] ) {
			// The hash itself is never read into an entry.
			BLT_Activity_Log_Logger::record(
				array(
					'category'     => 'user',
					'action'       => 'password_changed',
					'object_type'  => 'user',
					'object_id'    => (int) $user_id,
					'object_label' => (string) $user->user_login,
				)
			);
		}

		$changes = BLT_Activity_Log_Diff::fields( $before, $after, self::FIELDS );

		$this->record_updated( $user, $changes );
	}

	/**
	 * An account is about to be deleted. Read it while it exists.
	 *
	 * @param int          $user_id  User ID.
	 * @param int|null     $reassign User the content goes to, if any.
	 * @param WP_User|null $user     The user being deleted.
	 * @return void
	 */
	public function on_before_delete( $user_id, $reassign = null, $user = null ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_userdata( $user_id );
		}

		if ( ! $user instanceof WP_User ) {
			return;
		}

		$target  = $reassign ? get_userdata( (int) $reassign ) : false;
		$changes = array(
			array(
				'field'  => 'user_email',
				'before' => (string) $user->user_email,
				'after'  => '',
			),
			array(
				'field'  => 'role',
				'before' => implode( ', ', array_map( 'strval', (array) $user->roles ) ),
				'after'  => '',
			),
		);

		if ( $target instanceof WP_User ) {
			$changes[] = array(
				'field'  => 'content_reassigned_to',
				'before' => '',
				'after'  => (string) $target->user_login,
			);
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'user',
				'action'       => 'deleted',
				'object_type'  => 'user',
				'object_id'    => (int) $user_id,
				'object_label' => (string) $user->user_login,
				'details'      => array( 'changes' => $changes ),
			)
		);
	}

	/**
	 * A password was reset through the lost-password flow.
	 *
	 * @param WP_User     $user     The user.
	 * @param string|null $new_pass The new password. Never read.
	 * @return void
	 */
	public function on_password_reset( $user, $new_pass = null ) {
		unset( $new_pass );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'user',
				'action'       => 'password_reset',
				'object_type'  => 'user',
				'object_id'    => (int) $user->ID,
				'object_label' => (string) $user->user_login,
			)
		);
	}

	/**
	 * An application password was created.
	 *
	 * @param int        $user_id      User ID.
	 * @param array      $new_item     The stored item.
	 * @param string     $new_password The plain password. Never read.
	 * @param array|null $args         Creation arguments.
	 * @return void
	 */
	public function on_app_password_created( $user_id, $new_item = array(), $new_password = null, $args = null ) {
		unset( $new_password, $args );

		$this->record_app_password( 'app_password_created', (int) $user_id, is_array( $new_item ) ? $new_item : array() );
	}

	/**
	 * An application password was revoked.
	 *
	 * @param int   $user_id User ID.
	 * @param array $item    The item removed.
	 * @return void
	 */
	public function on_app_password_deleted( $user_id, $item = array() ) {
		$this->record_app_password( 'app_password_revoked', (int) $user_id, is_array( $item ) ? $item : array() );
	}

	/**
	 * Remember a profile field just before it is updated.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $user_id    User ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	public function on_meta_before( $meta_id, $user_id, $meta_key, $meta_value = null ) {
		unset( $meta_id, $meta_value );

		if ( in_array( $meta_key, self::META, true ) || self::is_caps_key( $meta_key ) ) {
			$this->meta_before[ $user_id . ':' . $meta_key ] = get_user_meta( $user_id, $meta_key, true );
		}
	}

	/**
	 * A profile field was added.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $user_id    User ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value.
	 * @return void
	 */
	public function on_meta_added( $meta_id, $user_id, $meta_key, $meta_value = null ) {
		unset( $meta_id );

		if ( self::is_caps_key( $meta_key ) ) {
			$this->record_roles( (int) $user_id, array(), self::roles_in( $meta_value ) );
		} elseif ( in_array( $meta_key, self::META, true ) ) {
			$this->record_meta( (int) $user_id, (string) $meta_key, '', $meta_value );
		}
	}

	/**
	 * A profile field was updated.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $user_id    User ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	public function on_meta_updated( $meta_id, $user_id, $meta_key, $meta_value = null ) {
		unset( $meta_id );

		$is_caps = self::is_caps_key( $meta_key );

		if ( ! $is_caps && ! in_array( $meta_key, self::META, true ) ) {
			return;
		}

		$slot      = $user_id . ':' . $meta_key;
		$old_value = array_key_exists( $slot, $this->meta_before ) ? $this->meta_before[ $slot ] : '';

		unset( $this->meta_before[ $slot ] );

		if ( $is_caps ) {
			$this->record_roles( (int) $user_id, self::roles_in( $old_value ), self::roles_in( $meta_value ) );

			return;
		}

		$this->record_meta( (int) $user_id, (string) $meta_key, $old_value, $meta_value );
	}

	/**
	 * Fold a profile field change into the user's `updated` entry.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $old_value      Value before.
	 * @param mixed  $new_value      Value after.
	 * @return void
	 */
	private function record_meta( $user_id, $meta_key, $old_value, $new_value ) {
		if ( BLT_Activity_Log_Diff::equal( $old_value, $new_value ) ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		$this->record_updated(
			$user,
			array(
				array(
					'field'  => $meta_key,
					'before' => BLT_Activity_Log_Diff::to_string( $old_value ),
					'after'  => BLT_Activity_Log_Diff::to_string( $new_value ),
				),
			)
		);
	}

	/**
	 * Record an `updated` entry for a user, merging with any buffered one.
	 *
	 * @param WP_User $user    The user.
	 * @param array   $changes Change items.
	 * @return void
	 */
	private function record_updated( WP_User $user, array $changes ) {
		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'user',
				'action'       => 'updated',
				'object_type'  => 'user',
				'object_id'    => (int) $user->ID,
				'object_label' => (string) $user->user_login,
				'details'      => empty( $changes ) ? array() : array( 'changes' => $changes ),
				'absorb'       => array( 'created', 'updated' ),
			)
		);
	}

	/**
	 * Record a role change, unless the set of roles is the same.
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $before  Roles before.
	 * @param string[] $after   Roles after.
	 * @return void
	 */
	private function record_roles( $user_id, array $before, array $after ) {
		$sorted_before = $before;
		$sorted_after  = $after;

		sort( $sorted_before );
		sort( $sorted_after );

		if ( $sorted_before === $sorted_after ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'user',
				'action'       => 'role_changed',
				'object_type'  => 'user',
				'object_id'    => $user_id,
				'object_label' => (string) $user->user_login,
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'role',
							'before' => implode( ', ', $before ),
							'after'  => implode( ', ', $after ),
						),
					),
				),
			)
		);
	}

	/**
	 * Record an application password event. Only its name is stored.
	 *
	 * @param string $action  Action key.
	 * @param int    $user_id User ID.
	 * @param array  $item    The application password item.
	 * @return void
	 */
	private function record_app_password( $action, $user_id, array $item ) {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return;
		}

		$name = isset( $item['name'] ) ? (string) $item['name'] : '';

		BLT_Activity_Log_Logger::record(
			array(
				'category'     => 'user',
				'action'       => $action,
				'object_type'  => 'user',
				'object_id'    => $user_id,
				'object_label' => (string) $user->user_login,
				'details'      => array(
					'changes' => array(
						array(
							'field'  => 'application_name',
							'before' => 'app_password_revoked' === $action ? $name : '',
							'after'  => 'app_password_created' === $action ? $name : '',
						),
					),
				),
			)
		);
	}

	/**
	 * Whether a meta key is where this site stores a user's roles.
	 *
	 * WordPress sets, adds and removes roles by rewriting this one meta value,
	 * after its own role hooks have already seen the new roles. Reading the
	 * value before and after is the only place the true "before" exists.
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	private static function is_caps_key( $meta_key ) {
		global $wpdb;

		return $wpdb->get_blog_prefix() . 'capabilities' === $meta_key;
	}

	/**
	 * The role names inside a capabilities value.
	 *
	 * @param mixed $caps Capabilities map as stored (name => granted).
	 * @return string[]
	 */
	private static function roles_in( $caps ) {
		if ( ! is_array( $caps ) ) {
			return array();
		}

		$roles = array();

		foreach ( $caps as $name => $granted ) {
			if ( $granted && wp_roles()->is_role( (string) $name ) ) {
				$roles[] = (string) $name;
			}
		}

		return $roles;
	}
}
