<?php
/**
 * Target resolution, capability checks and the meta denylist for the field layer.
 *
 * Every read and every write in includes/fields/ passes through here first. It
 * exists because the two ways this subsystem can do real damage are both quiet:
 *
 *   1. A meta key that WordPress or another plugin owns. `_elementor_data` is a
 *      serialised page; writing a string into it does not error, it erases the
 *      page. So an unknown key is REFUSED rather than written - the opposite of
 *      the usual update_post_meta() forgiveness.
 *   2. A capability check against the current user in general rather than
 *      against THIS object. `manage_options` is the ability gate; it is not a
 *      licence to edit an arbitrary post id, and the field layer is reachable
 *      with an object id chosen by the caller.
 *
 * Addressing follows WordPress's own triple - object_type + object_subtype +
 * object_id - the same vocabulary as register_meta(). Never "record" or "item".
 *
 * @link       https://posimyth.com/
 * @since      5.1.0
 *
 * @package    UiChemy
 * @subpackage UiChemy/includes/fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Field_Guard' ) ) {

	/**
	 * Resolves and authorises a field-layer target.
	 */
	final class Uich_Field_Guard {

		/**
		 * Object types the field layer can address.
		 *
		 * @return array<int,string>
		 */
		public static function object_types() {
			return array( 'post', 'term', 'user', 'options' );
		}

		/**
		 * Resolve { object_type, object_subtype, object_id } into a real object.
		 *
		 * Returns a target array rather than the object itself, because every
		 * provider needs the subtype (post type / taxonomy) as much as the id -
		 * ACF locates a field group by it and JetEngine keys its whole field
		 * store by it.
		 *
		 * A type/id mismatch is rejected loudly. Passing a term id as
		 * object_type="post" would otherwise resolve to whatever post happens to
		 * carry that id, and write real values onto the wrong object.
		 *
		 * @param array $args { object_type, object_subtype?, object_id? }.
		 * @return array{object_type:string,object_subtype:string,object_id:int,acf_id:string,label:string}|WP_Error
		 */
		public static function resolve_target( $args ) {
			$args = is_array( $args ) ? $args : array();

			$type = isset( $args['object_type'] ) ? sanitize_key( (string) $args['object_type'] ) : '';
			$sub  = isset( $args['object_subtype'] ) ? sanitize_key( (string) $args['object_subtype'] ) : '';
			$id   = isset( $args['object_id'] ) ? $args['object_id'] : 0;

			// A bare object_id with no type is the commonest call shape, and a
			// post is what it always means.
			if ( '' === $type ) {
				$type = 'post';
			}

			if ( ! in_array( $type, self::object_types(), true ) ) {
				return new WP_Error(
					'uich_field_bad_object_type',
					sprintf(
						'Unknown object_type "%s". Valid: %s. These are WordPress\'s own object types (register_meta), not UiChemy names.',
						$type,
						implode( ', ', self::object_types() )
					)
				);
			}

			if ( 'options' === $type ) {
				// ACF / JetEngine options pages: site-wide values with no object
				// id at all. ACF addresses them by the literal string "option".
				return array(
					'object_type'    => 'options',
					'object_subtype' => '' !== $sub ? $sub : 'option',
					'object_id'      => 0,
					'acf_id'         => 'option',
					'label'          => 'site options',
				);
			}

			$id = is_numeric( $id ) ? (int) $id : 0;

			if ( $id < 1 ) {
				return new WP_Error(
					'uich_field_missing_object_id',
					sprintf( 'object_id is required for object_type "%s". Call uichemy-composer/describe-site (action="entities") to resolve real ids.', $type )
				);
			}

			if ( 'post' === $type ) {
				$post = get_post( $id );
				if ( ! $post ) {
					return new WP_Error( 'uich_field_no_object', sprintf( 'No post with id %d exists.', $id ) );
				}
				if ( '' !== $sub && $sub !== $post->post_type ) {
					return new WP_Error(
						'uich_field_subtype_mismatch',
						sprintf( 'Post %d is a "%s", not a "%s". Fix object_subtype or the id - this is refused rather than guessed because the wrong one writes real values onto the wrong object.', $id, $post->post_type, $sub )
					);
				}

				return array(
					'object_type'    => 'post',
					'object_subtype' => $post->post_type,
					'object_id'      => $id,
					'acf_id'         => (string) $id,
					'label'          => sprintf( '%s %d ("%s")', $post->post_type, $id, get_the_title( $post ) ),
				);
			}

			if ( 'term' === $type ) {
				$term = '' !== $sub ? get_term( $id, $sub ) : get_term( $id );
				if ( ! $term || is_wp_error( $term ) ) {
					return new WP_Error( 'uich_field_no_object', sprintf( 'No term with id %d exists%s.', $id, '' !== $sub ? ' in taxonomy "' . $sub . '"' : '' ) );
				}

				return array(
					'object_type'    => 'term',
					'object_subtype' => $term->taxonomy,
					'object_id'      => $id,
					'acf_id'         => 'term_' . $id,
					'label'          => sprintf( '%s term %d ("%s")', $term->taxonomy, $id, $term->name ),
				);
			}

			// user.
			$user = get_userdata( $id );
			if ( ! $user ) {
				return new WP_Error( 'uich_field_no_object', sprintf( 'No user with id %d exists.', $id ) );
			}

			return array(
				'object_type'    => 'user',
				'object_subtype' => 'user',
				'object_id'      => $id,
				'acf_id'         => 'user_' . $id,
				'label'          => sprintf( 'user %d ("%s")', $id, $user->user_login ),
			);
		}

		/**
		 * Whether the current user may edit THIS object.
		 *
		 * The ability's own permission_callback has already run and answered "may
		 * this user use UiChemy's MCP surface at all". This answers the different
		 * question the ability cannot: may they edit the specific object the
		 * caller named.
		 *
		 * @param array  $target Resolved target from resolve_target().
		 * @param string $mode   'read' | 'write'.
		 * @return true|WP_Error
		 */
		public static function can( $target, $mode = 'write' ) {
			$type = isset( $target['object_type'] ) ? $target['object_type'] : '';
			$id   = isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;

			$allowed = false;

			switch ( $type ) {
				case 'post':
					$allowed = 'read' === $mode
						? current_user_can( 'read_post', $id ) || current_user_can( 'edit_post', $id )
						: current_user_can( 'edit_post', $id );
					break;

				case 'term':
					$tax = isset( $target['object_subtype'] ) ? $target['object_subtype'] : '';
					$obj = $tax ? get_taxonomy( $tax ) : null;
					$cap = $obj && isset( $obj->cap->edit_terms ) ? $obj->cap->edit_terms : 'manage_categories';
					$allowed = 'read' === $mode ? current_user_can( 'read' ) : current_user_can( $cap );
					break;

				case 'user':
					$allowed = 'read' === $mode
						? ( get_current_user_id() === $id || current_user_can( 'list_users' ) )
						: current_user_can( 'edit_user', $id );
					break;

				case 'options':
					$allowed = current_user_can( 'manage_options' );
					break;
			}

			if ( ! $allowed ) {
				return new WP_Error(
					'uich_field_forbidden',
					sprintf(
						'The current user cannot %s %s. This is a per-object check on top of the ability\'s own permission, so a user who can call UiChemy can still be refused one object.',
						'read' === $mode ? 'read' : 'edit',
						isset( $target['label'] ) ? $target['label'] : 'that object'
					)
				);
			}

			return true;
		}

		/**
		 * Meta keys no field write may ever touch, whatever provider claims them.
		 *
		 * Two classes, and the distinction matters for the error message:
		 *   - Structural: another plugin's serialised state. A write corrupts a
		 *     page or a template and nothing reports it.
		 *   - Owned elsewhere: WooCommerce derived state, which has a correct
		 *     door (uichemy-composer/store). Refusing without naming that door
		 *     is how an agent ends up writing `_price` a second time.
		 *
		 * @return array<string,string> Meta key => the reason, phrased for a model.
		 */
		public static function denylist() {
			$deny = array(
				'_elementor_data'            => 'Elementor\'s serialised page tree. A write here erases the page and nothing errors. Edit sections with uichemy-composer/page instead.',
				'_elementor_page_settings'   => 'Elementor page settings. Owned by Elementor.',
				'_elementor_controls_usage'  => 'Elementor internal bookkeeping.',
				'_elementor_css'             => 'Elementor generated CSS cache.',
				'_elementor_edit_mode'       => 'Elementor internal flag.',
				'_elementor_template_type'   => 'Elementor internal flag.',
				'_edit_lock'                 => 'WordPress edit lock.',
				'_edit_last'                 => 'WordPress bookkeeping.',
				'_wp_page_template'          => 'The theme template assignment. Use uichemy-composer/page.',
				'_wp_attached_file'          => 'Attachment storage path. Use uichemy-composer/media.',
				'_wp_attachment_metadata'    => 'Attachment metadata. Use uichemy-composer/media.',
				'_thumbnail_id'              => 'The featured image. It is protected meta and cannot ride on a field write. UiChemy has no ability that sets it on a plain post - set it in the WordPress editor, or, on a WooCommerce product, with uichemy-composer/store (action="set-images"). An ACF or JetEngine image FIELD is a different thing and is writable here.',
			);

			// UiChemy's own theme-builder template state.
			if ( class_exists( 'UiChemy_Template_CPT' ) ) {
				foreach ( array( 'META_TYPE', 'META_CONDITIONS', 'META_STATUS', 'META_EDITOR', 'META_TARGET' ) as $const ) {
					$name = 'UiChemy_Template_CPT::' . $const;
					if ( defined( $name ) ) {
						$deny[ constant( $name ) ] = 'UiChemy theme-builder template state. Use uichemy-composer/theme-builder.';
					}
				}
			}

			foreach ( self::woo_derived_keys() as $key ) {
				$deny[ $key ] = 'WooCommerce derived state. Writing it as meta produces a product that looks right and misbehaves (variable pricing, stock logic and the wc_product_meta_lookup table are all computed). Use uichemy-composer/store.';
			}

			/**
			 * Filter the meta keys the field layer refuses to write.
			 *
			 * @since 5.1.0
			 *
			 * @param array<string,string> $deny Meta key => reason.
			 */
			return (array) apply_filters( 'uichemy/fields/denylist', $deny );
		}

		/**
		 * WooCommerce state that is computed, cached or mirrored into its own
		 * lookup table - never plain meta, even though it is stored as meta.
		 *
		 * @return array<int,string>
		 */
		public static function woo_derived_keys() {
			return array(
				'_price',
				'_regular_price',
				'_sale_price',
				'_sale_price_dates_from',
				'_sale_price_dates_to',
				'_stock',
				'_stock_status',
				'_manage_stock',
				'_backorders',
				'_low_stock_amount',
				'_sku',
				'_tax_status',
				'_tax_class',
				'total_sales',
				'_wc_average_rating',
				'_wc_review_count',
				'_wc_rating_count',
				'_downloadable_files',
				'_product_attributes',
				'_children',
				'_default_attributes',
			);
		}

		/**
		 * Whether a meta key may be written at all.
		 *
		 * @param string $key    Meta key.
		 * @param array  $target Resolved target.
		 * @return true|WP_Error
		 */
		public static function meta_allowed( $key, $target = array() ) {
			$key  = (string) $key;
			$deny = self::denylist();

			if ( isset( $deny[ $key ] ) ) {
				return new WP_Error( 'uich_field_denied_key', sprintf( '"%s" cannot be written here. %s', $key, $deny[ $key ] ) );
			}

			// Protected meta is protected for a reason, and the leading underscore
			// is the convention every plugin relies on. There is deliberately no
			// parameter that turns this off.
			$object_type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';
			$subtype     = isset( $target['object_subtype'] ) ? $target['object_subtype'] : '';

			if ( 0 === strpos( $key, '_' ) || is_protected_meta( $key, $object_type ) ) {
				return new WP_Error(
					'uich_field_protected_key',
					sprintf(
						'"%s" is protected meta%s. Protected keys belong to the plugin that registered them; UiChemy will not write them. If this really is an ACF or JetEngine field, its name will not start with an underscore - pass that name.',
						$key,
						$subtype ? ' on ' . $subtype : ''
					)
				);
			}

			return true;
		}

		/**
		 * Whether a value should be withheld from a read response.
		 *
		 * Field values are returned to a model that may forward them anywhere. A
		 * key named like a credential is reported by NAME with its value withheld
		 * - the model still learns the field exists, which is what discovery is
		 * for, without the secret entering the transcript.
		 *
		 * @param string $key Field or meta key.
		 * @return bool
		 */
		public static function is_sensitive( $key ) {
			$key = strtolower( (string) $key );

			foreach ( array( 'password', 'passwd', 'secret', 'api_key', 'apikey', 'private_key', 'token', 'client_secret', 'access_key', 'salt', 'nonce' ) as $needle ) {
				if ( false !== strpos( $key, $needle ) ) {
					return true;
				}
			}

			/**
			 * Filter whether a field value is treated as sensitive and withheld.
			 *
			 * @since 5.1.0
			 *
			 * @param bool   $sensitive Whether to withhold the value.
			 * @param string $key       Field key.
			 */
			return (bool) apply_filters( 'uichemy/fields/is_sensitive', false, $key );
		}
	}
}
