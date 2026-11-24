<?php
/**
 * The public view of a user, returned by a User field to a viewer who may not see the full account.
 *
 * @package Uichemy
 * @subpackage Content_Types
 * @since 5.8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Fields_Public_User' ) && class_exists( 'WP_User' ) ) {

	/**
	 * A WP_User carrying only profile properties WordPress itself shows on the front end.
	 *
	 * It stays a WP_User so existing templates (instanceof checks, ->ID, ->display_name, get_avatar())
	 * keep working. Login, email, roles, capabilities, registration data and any other user meta read
	 * as empty: WP_User::__get() would otherwise load any meta key on demand.
	 */
	class Uich_Fields_Public_User extends WP_User {

		/**
		 * Properties copied from the real user.
		 *
		 * @return string[]
		 */
		public static function public_keys() {
			/**
			 * Filter the user properties a User field returns to a viewer who cannot list users.
			 *
			 * @param string[] $keys Property names.
			 */
			$keys = (array) apply_filters(
				'uich/user_field_public_properties',
				array( 'display_name', 'user_nicename', 'user_url', 'nickname', 'first_name', 'last_name', 'description' )
			);
			return array_values( array_diff( array_map( 'strval', $keys ), array( 'ID', 'id', 'user_pass', 'user_activation_key', 'session_tokens' ) ) );
		}

		/**
		 * Build from the real user without loading capabilities.
		 *
		 * @param WP_User $user Real user.
		 */
		public function __construct( $user = null ) {
			$this->data = new stdClass();
			if ( ! $user instanceof WP_User ) {
				return;
			}
			$this->ID       = (int) $user->ID;
			$this->data->ID = (int) $user->ID;
			foreach ( self::public_keys() as $key ) {
				$value            = $user->$key;
				$this->data->$key = is_scalar( $value ) ? (string) $value : '';
			}
		}

		/**
		 * Only the copied properties; anything else reads as empty.
		 *
		 * @param string $key Property.
		 * @return mixed
		 */
		public function __get( $key ) {
			if ( 'id' === $key ) {
				return $this->ID;
			}
			return isset( $this->data->$key ) ? $this->data->$key : '';
		}

		/**
		 * Whether a copied property is set.
		 *
		 * @param string $key Property.
		 * @return bool
		 */
		public function __isset( $key ) {
			return isset( $this->data->$key );
		}

		/**
		 * Capabilities are never loaded for this view.
		 *
		 * @param int $site_id Site ID.
		 */
		public function for_site( $site_id = 0 ) {
			unset( $site_id );
		}

		/**
		 * Avatars resolve from the user ID, the same image the full user would show.
		 *
		 * @param array|null $args        Avatar args.
		 * @param mixed      $id_or_email What get_avatar_data() was given.
		 * @return array|null
		 */
		public static function avatar_data( $args, $id_or_email ) {
			if ( $id_or_email instanceof self && $id_or_email->ID ) {
				return get_avatar_data( (int) $id_or_email->ID, is_array( $args ) ? $args : array() );
			}
			return $args;
		}
	}

	add_filter( 'pre_get_avatar_data', array( 'Uich_Fields_Public_User', 'avatar_data' ), 10, 2 );
}
