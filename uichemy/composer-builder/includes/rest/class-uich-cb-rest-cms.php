<?php
/**
 * Uich_CB_Rest_Cms — the editor's CMS workspace (top bar › CMS): the posts
 * and custom post type items built with UiChemy (not pages) — not a second
 * wp-admin.
 *
 *   GET  …/cms                       the content types the builder edits, with
 *                                    counts of their UiChemy-built items
 *   POST …/cms/posts                 a new draft, already a UiChemy post
 *   GET  …/cms/posts/<id>            one item for the side panel: its title and its
 *                                    custom field groups (UiChemy Custom Fields), drawn
 *   POST …/cms/posts/<id>            save them — the form the post screen posts —
 *                                    and, with `status=publish|draft`, publish or draft it
 *   POST …/cms/posts/<id>/duplicate  copy a post, its terms and its meta, as a draft
 *   POST …/cms/posts/<id>/restore    take it out of the trash (wp_untrash_post)
 *   GET  …/cms/visibility?ids=1,2    each post's visibility rules (Uich_CB_Visibility)
 *   POST …/cms/posts/<id>/visibility set them; an empty list shows it to everyone
 *
 * Lists come from `wp/v2/<type>` with `uich_built=1`, which only_built()
 * narrows to the posts carrying the builder's flag (Uich_CB_Content::META_ENABLED);
 * `uich_visibility=restricted|everyone` narrows them by whether the post has
 * visibility rules (Uich_CB_Visibility), and `uich_fields[<field key>]=<value>`
 * by a choice field of the type's UiChemy Custom Fields groups (field_filters()).
 * Taxonomy, author and date filters are wp/v2's own query parameters.
 * The `uich_built` narrowing is for Posts only: a custom post type — made with
 * UiChemy Custom Fields, ACF, Custom Post Type UI, a theme or any other plugin —
 * is content as a whole and lists every item (`allItems`). A public custom type
 * registered without REST support gets it (rest_for_custom_types()), since every
 * list and action here goes through wp/v2; its wp-admin screen is unchanged.
 *
 * An item's custom fields are UiChemy Custom Fields' own: the field groups
 * whose location rules match the post are rendered by that module
 * (Uich_Fields_Field_Groups_Render::render_meta_box, with its per-post nonces)
 * and saved by its save_post handler, so the side panel edits exactly what the
 * post screen's field boxes edit, with the same sanitising and required checks.
 *
 * Everything else the workspace does — list, rename, publish, trash and
 * delete — goes through WordPress's own REST API (`wp/v2/*`) with the same
 * nonce, so WordPress's rules, capabilities and hooks apply exactly as they
 * do in wp-admin.
 *
 * WooCommerce's post types are left out on purpose for now (see
 * is_woocommerce_type()).
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Rest_Cms' ) ) {

	/**
	 * REST routes for the CMS workspace.
	 *
	 * @since 5.4.1
	 */
	final class Uich_CB_Rest_Cms implements Uich_CB_Rest_Controller {

		/**
		 * Post types the workspace never lists: WordPress internals that have
		 * their own screen here (attachment → Media, nav_menu_item → Menus) or
		 * none at all.
		 *
		 * @var string[]
		 */
		private static $internal_types = array(
			// Pages are the site's structure, built and managed in the
			// builder itself (its page menu); the CMS is for posts and
			// custom post type items.
			'page',
			'attachment',
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'wp_font_family',
			'wp_font_face',
		);

		/**
		 * Page builders' own storage types — saved templates, popups, floating
		 * buttons — that wp-admin shows but that are not site content. Each
		 * builder edits them in its own library, so they stay out of the list.
		 *
		 * @var string[]
		 */
		private static $builder_internal_types = array(
			// Elementor: My Templates, Floating Elements.
			'elementor_library',
			'e-floating-buttons',
			// Bricks, Oxygen, Beaver Builder, Divi, Breakdance.
			'bricks_template',
			'ct_template',
			'fl-builder-template',
			'et_pb_layout',
			'et_template',
			'breakdance_template',
			'breakdance_header',
			'breakdance_footer',
			'breakdance_popup',
			// Nexter, ElementsKit, JetThemeCore, Kadence.
			'nxt_builder',
			'elementskit_template',
			'jet-theme-core',
			'kadence_element',
		);

		/**
		 * Custom post types given REST support by rest_for_custom_types().
		 *
		 * @var array<string,true>
		 */
		private static $rest_added = array();

		/**
		 * Register hooks that must run before post types are registered.
		 *
		 * @return void
		 */
		public static function boot() {
			add_filter( 'register_post_type_args', array( __CLASS__, 'rest_for_custom_types' ), 20, 2 );
			add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'keep_classic_editor' ), 20, 2 );
		}

		/**
		 * `register_post_type_args`: REST support for a public custom post type
		 * registered without it (`register_post_type()` defaults it to off, so
		 * a hand-written type in a theme, or an older plugin's, has none). The
		 * CMS lists, saves, publishes and trashes through wp/v2, so without it
		 * such a type could not be listed at all.
		 *
		 * Public types only: a published item of one is on the site already, so
		 * its wp/v2 route shows nothing that was not public. Internal types,
		 * page builders' template types and WooCommerce's are left as they are.
		 *
		 * @param array  $args      Post type arguments.
		 * @param string $post_type Post type slug.
		 * @return array
		 */
		public static function rest_for_custom_types( $args, $post_type ) {
			$args = (array) $args;

			if ( ! empty( $args['show_in_rest'] ) || ! empty( $args['_builtin'] ) || empty( $args['public'] ) ) {
				return $args;
			}
			if ( isset( $args['show_ui'] ) && ! $args['show_ui'] ) {
				return $args;
			}
			if ( in_array( $post_type, self::$internal_types, true ) || in_array( $post_type, self::$builder_internal_types, true ) || self::is_woocommerce_type( $post_type ) ) {
				return $args;
			}
			if ( class_exists( 'Uich_CB_Post_Types' ) && Uich_CB_Post_Types::is_builder_type( $post_type ) ) {
				return $args;
			}

			/**
			 * Filter whether the CMS gives REST support to a custom post type
			 * registered without it.
			 *
			 * @since 5.4.1
			 *
			 * @param bool   $add       Whether to add it.
			 * @param string $post_type Post type slug.
			 * @param array  $args      Post type arguments.
			 */
			if ( ! apply_filters( 'uich_cb/cms_rest_for_type', true, $post_type, $args ) ) {
				return $args;
			}

			$args['show_in_rest']           = true;
			self::$rest_added[ $post_type ] = true;

			return $args;
		}

		/**
		 * `use_block_editor_for_post_type`: a type given REST support above
		 * keeps the classic edit screen it was registered with — WordPress
		 * would otherwise switch it to the block editor on that flag alone.
		 *
		 * @param bool   $use       Whether the block editor is used.
		 * @param string $post_type Post type slug.
		 * @return bool
		 */
		public static function keep_classic_editor( $use, $post_type ) {
			return isset( self::$rest_added[ $post_type ] ) ? false : $use;
		}

		/**
		 * Register the routes.
		 *
		 * @return void
		 */
		public static function register() {
			$any = array( 'Uich_CB_Policy', 'rest_edit_any' );

			// `?uich_built=1` / `?uich_visibility=` on a listed type's wp/v2
			// collection (only_built()). Without them the route is untouched.
			foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $type ) {
				if ( self::is_listed_type( $type ) ) {
					add_filter( "rest_{$type}_query", array( __CLASS__, 'only_built' ), 10, 2 );
				}
			}

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/cms/posts' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create' ),
					'permission_callback' => array( __CLASS__, 'can_create' ),
					'args'                => array(
						'type'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'title' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						// A blank item for the side panel to fill (see create()).
						'blank' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/cms' ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'bootstrap' ),
					'permission_callback' => $any,
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/cms/visibility' ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_visibility' ),
					'permission_callback' => $any,
					'args'                => array(
						'ids' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/cms/posts/(?P<id>\d+)/visibility' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set_visibility' ),
					'permission_callback' => array( __CLASS__, 'can_edit_item' ),
					'args'                => Uich_CB_Rest::id_arg(),
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/cms/posts/(?P<id>\d+)' ),
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'get_item' ),
						'permission_callback' => array( __CLASS__, 'can_edit_item' ),
						'args'                => Uich_CB_Rest::id_arg(),
					),
					array(
						// Multipart: the field controls post plain form names.
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( __CLASS__, 'save_item' ),
						'permission_callback' => array( __CLASS__, 'can_edit_item' ),
						'args'                => Uich_CB_Rest::id_arg(),
					),
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/cms/posts/(?P<id>\d+)/restore' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'restore' ),
					'permission_callback' => array( __CLASS__, 'can_restore' ),
					'args'                => Uich_CB_Rest::id_arg(),
				)
			);

			register_rest_route(
				Uich_CB_Rest::NS,
				Uich_CB_Rest::route( '/cms/posts/(?P<id>\d+)/duplicate' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'duplicate' ),
					'permission_callback' => array( __CLASS__, 'can_duplicate' ),
					'args'                => Uich_CB_Rest::id_arg(),
				)
			);
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Permissions
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Creating needs a type the builder edits, and the right to create in it.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return true|WP_Error
		 */
		public static function can_create( $request ) {
			$type = get_post_type_object( (string) $request['type'] );

			if ( ! $type || ! self::is_cms_type( $type->name ) ) {
				return Uich_CB_Rest::error( 'uich_cb_unsupported', __( 'That content type is not managed here.', 'uichemy' ), 400 );
			}
			if ( ! current_user_can( $type->cap->create_posts ) ) {
				return Uich_CB_Rest::error( 'uich_cb_forbidden', __( 'You are not allowed to create this content.', 'uichemy' ), rest_authorization_required_code() );
			}

			return true;
		}

		/**
		 * Changing an item needs a post of a type the CMS lists, that the user
		 * may edit.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return true|WP_Error
		 */
		public static function can_edit_item( $request ) {
			$post = get_post( absint( $request['id'] ) );

			if ( ! $post instanceof WP_Post || ! self::is_cms_type( $post->post_type ) ) {
				return Uich_CB_Rest::error( 'uich_cb_not_found', __( 'That item no longer exists.', 'uichemy' ), 404 );
			}
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return Uich_CB_Rest::error( 'uich_cb_forbidden', __( 'You are not allowed to edit this item.', 'uichemy' ), rest_authorization_required_code() );
			}

			return true;
		}

		/**
		 * Restoring is the trash's own capability, as on wp-admin's list.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return true|WP_Error
		 */
		public static function can_restore( $request ) {
			$allowed = self::can_edit_item( $request );
			if ( true !== $allowed ) {
				return $allowed;
			}
			if ( ! current_user_can( 'delete_post', absint( $request['id'] ) ) ) {
				return Uich_CB_Rest::error( 'uich_cb_forbidden', __( 'You are not allowed to restore this item.', 'uichemy' ), rest_authorization_required_code() );
			}

			return true;
		}

		/**
		 * Duplicating reads the source and creates a post of the same type.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return true|WP_Error
		 */
		public static function can_duplicate( $request ) {
			$post = get_post( absint( $request['id'] ) );

			// Pages too: the builder's own pages were refused as "no longer exists" (B-05).
			if ( ! $post instanceof WP_Post || ( 'page' !== $post->post_type && ! self::is_cms_type( $post->post_type ) ) ) {
				return Uich_CB_Rest::error( 'uich_cb_not_found', __( 'That item no longer exists.', 'uichemy' ), 404 );
			}

			$type = get_post_type_object( $post->post_type );
			if ( ! current_user_can( 'edit_post', $post->ID ) || ! $type || ! current_user_can( $type->cap->create_posts ) ) {
				return Uich_CB_Rest::error( 'uich_cb_forbidden', __( 'You are not allowed to duplicate this item.', 'uichemy' ), rest_authorization_required_code() );
			}

			return true;
		}

		/* ─────────────────────────────────────────────────────────────────
		 * What is listed
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * Is this a WooCommerce post type or taxonomy? Those are out of scope.
		 *
		 * @param string $slug Post type or taxonomy slug.
		 * @return bool
		 */
		public static function is_woocommerce_type( $slug ) {
			$slug = (string) $slug;

			if ( in_array( $slug, array( 'product', 'product_variation', 'product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class' ), true ) ) {
				return true;
			}

			return (bool) preg_match( '/^(shop_|wc_|wcs_|woocommerce_|pa_)/', $slug );
		}

		/**
		 * Is this post type one the workspace manages?
		 *
		 * Anything with an admin screen that the REST API can read, other than
		 * WordPress internals, the builder's own templates and WooCommerce.
		 *
		 * @param string $post_type Post type slug.
		 * @return bool
		 */
		public static function is_listed_type( $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( ! $object || ! $object->show_ui || empty( $object->show_in_rest ) ) {
				return false;
			}
			if ( in_array( $post_type, self::$internal_types, true ) || in_array( $post_type, self::$builder_internal_types, true ) || self::is_woocommerce_type( $post_type ) ) {
				return false;
			}
			if ( class_exists( 'Uich_CB_Post_Types' ) && Uich_CB_Post_Types::is_builder_type( $post_type ) ) {
				return false;
			}

			/**
			 * Filter whether a post type shows in the editor's CMS workspace.
			 *
			 * @since 5.4.1
			 *
			 * @param bool   $listed    Whether it is listed.
			 * @param string $post_type Post type slug.
			 */
			return (bool) apply_filters( 'uich_cb/cms_post_type', true, $post_type );
		}

		/**
		 * The UiChemy Custom Fields definition behind each type made with it:
		 * post type key => definition id.
		 *
		 * @return array<string,int>
		 */
		private static function uichemy_type_definitions() {
			static $ids = null;
			if ( null !== $ids ) {
				return $ids;
			}

			$ids = array();
			if ( ! class_exists( 'Uich_Fields_Definitions_Repository' ) || ! defined( 'UICH_FIELDS_BOOTED' ) ) {
				return $ids;
			}

			$rows = Uich_Fields_Definitions_Repository::get_instance()->by_kind();
			foreach ( (array) $rows['cpt'] as $row ) {
				$meta = ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) ? $row['meta'] : array();
				if ( ! empty( $meta['post_type_key'] ) ) {
					$ids[ (string) $meta['post_type_key'] ] = isset( $row['id'] ) ? (int) $row['id'] : 0;
				}
			}

			return $ids;
		}

		/**
		 * Is this a custom post type — not one of WordPress's own — whatever
		 * registered it?
		 *
		 * @param string $post_type Post type slug.
		 * @return bool
		 */
		private static function is_custom_type( $post_type ) {
			$object = get_post_type_object( $post_type );
			return $object && ! $object->_builtin;
		}

		/**
		 * Does the workspace list this type?
		 *
		 * Any listed custom post type (is_listed_type()), whoever registered it
		 * and whether or not the UiChemy builder can open its items (no Content /
		 * editor support only stops that). Of WordPress's own types, one the
		 * builder edits (Posts), or one that already holds UiChemy items.
		 *
		 * @param string $post_type Post type slug.
		 * @return bool
		 */
		public static function is_cms_type( $post_type ) {
			if ( ! self::is_listed_type( $post_type ) ) {
				return false;
			}
			if ( self::is_custom_type( $post_type ) || in_array( $post_type, Uich_CB_Content::supported_post_types(), true ) ) {
				return true;
			}

			return array_sum( self::built_counts( $post_type ) ) > 0;
		}

		/* ─────────────────────────────────────────────────────────────────
		 * The whole picture
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * GET the content types the workspace lists, with their UiChemy items' counts, in one call.
		 *
		 * @return WP_REST_Response
		 */
		public static function bootstrap() {
			$builder_types = class_exists( 'Uich_CB_Content' ) ? Uich_CB_Content::supported_post_types() : array();

			$types = array();
			foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $slug => $object ) {
				if ( ! current_user_can( $object->cap->edit_posts ) || ! self::is_cms_type( $slug ) ) {
					continue;
				}

				// A custom post type is content as a whole: every item is listed,
				// however it was added. Posts lists only those built with UiChemy.
				$all_items = ! $object->_builtin;
				$origin    = self::type_origin( $slug );

				$types[] = array(
					'slug'          => $slug,
					'restBase'      => $object->rest_base ? $object->rest_base : $slug,
					'restNamespace' => ! empty( $object->rest_namespace ) ? $object->rest_namespace : 'wp/v2',
					'label'         => $object->labels->name,
					'singular'      => $object->labels->singular_name,
					'hierarchical'  => (bool) $object->hierarchical,
					// Posts heads the navigation; everything else is a custom
					// post type and is listed under its own heading.
					'builtin'       => (bool) $object->_builtin,
					'viewable'      => is_post_type_viewable( $object ),
					// No author support: the list leaves out its Author column and filter.
					'authors'       => post_type_supports( $slug, 'author' ),
					// Its custom fields (name, label, type), for "How to use" —
					// the markup that shows the type on a page (lib/cms/usage.js).
					'fields'        => self::usage_fields( $slug ),
					// What registered it — 'UiChemy', 'ACF', 'Custom Post Type UI',
					// or '' (a theme, a plugin, code) — and, for someone who may
					// change it there, where the type's definition is edited
					// (labels, supports, URLs, taxonomies): "Advanced edit".
					'source'        => $origin['source'],
					'editTypeUrl'   => $origin['url'],
					// False when the UiChemy builder cannot open its items (no
					// Content / editor support); the list says so.
					'builder'       => in_array( $slug, $builder_types, true ),
					// Every item, or only those built with UiChemy (`uich_built=1`).
					'allItems'      => $all_items,
					'counts'        => $all_items ? self::all_counts( $slug ) : self::built_counts( $slug ),
					// What the list can be filtered by: its taxonomies, and its
					// custom fields that have a fixed set of values.
					'taxonomies'    => self::filter_taxonomies( $slug ),
					'fieldFilters'  => array_map(
						static function ( $f ) {
							unset( $f['metaKey'], $f['multiple'] );
							return $f;
						},
						array_values( self::field_filters( $slug ) )
					),
					'caps'          => array(
						'create'  => current_user_can( $object->cap->create_posts ),
						'publish' => current_user_can( $object->cap->publish_posts ),
					),
				);
			}

			// Posts first, then the custom post types by name.
			usort(
				$types,
				static function ( $a, $b ) {
					$rank = array( 'post' => 0 );
					$ra   = isset( $rank[ $a['slug'] ] ) ? $rank[ $a['slug'] ] : 1;
					$rb   = isset( $rank[ $b['slug'] ] ) ? $rank[ $b['slug'] ] : 1;
					return $ra === $rb ? strcasecmp( $a['label'], $b['label'] ) : $ra - $rb;
				}
			);

			$payload = array(
				'site'       => array(
					'title'    => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
					'url'      => home_url( '/' ),
					'adminUrl' => admin_url(),
				),
				'types'      => $types,
				'cptCreator' => self::cpt_creator(),
				'visibility' => self::visibility_options(),
				// Date, excerpt, terms and custom fields print on a page only
				// with UiChemy Pro ("How to use" says so).
				'pro'        => function_exists( 'uichemy_is_pro' ) && uichemy_is_pro(),
			);

			/**
			 * Filter what the editor's CMS workspace is told about the site.
			 *
			 * @since 5.4.1
			 *
			 * @param array $payload Workspace bootstrap.
			 */
			return rest_ensure_response( (array) apply_filters( 'uich_cb/cms_bootstrap', $payload ) );
		}

		/**
		 * What registered a custom post type, as the list names it, and where
		 * its definition is edited for someone who may change it there ('' if
		 * nowhere they can reach): UiChemy Custom Fields, ACF (6.1+) or Custom
		 * Post Type UI. A type from a theme, another plugin or code has no
		 * source here and no such screen.
		 *
		 * @param string $post_type Post type slug.
		 * @return array{source:string,url:string}
		 */
		private static function type_origin( $post_type ) {
			$ids = self::uichemy_type_definitions();
			if ( ! empty( $ids[ $post_type ] ) ) {
				return array(
					'source' => 'UiChemy',
					'url'    => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=uichemy#/content-fields/cpt/' . $ids[ $post_type ] ) : '',
				);
			}

			if ( function_exists( 'acf_get_acf_post_types' ) ) {
				foreach ( (array) acf_get_acf_post_types() as $acf_type ) {
					if ( isset( $acf_type['post_type'] ) && $acf_type['post_type'] === $post_type ) {
						$can = function_exists( 'acf_get_setting' ) && current_user_can( acf_get_setting( 'capability' ) );
						return array(
							'source' => 'ACF',
							'url'    => $can && ! empty( $acf_type['ID'] ) ? admin_url( 'post.php?post=' . (int) $acf_type['ID'] . '&action=edit' ) : '',
						);
					}
				}
			}

			if ( function_exists( 'cptui_get_post_type_data' ) ) {
				$cptui = (array) cptui_get_post_type_data();
				if ( isset( $cptui[ $post_type ] ) ) {
					return array(
						'source' => 'Custom Post Type UI',
						'url'    => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=cptui_manage_post_types&action=edit&cptui_post_type=' . rawurlencode( $post_type ) ) : '',
					);
				}
			}

			return array(
				'source' => '',
				'url'    => '',
			);
		}

		/**
		 * How many UiChemy-built posts of a type there are, per status.
		 *
		 * One grouped query on the builder's flag, the counts wp_count_posts()
		 * would give if it could filter by meta. Private posts by others are
		 * counted only for someone who may read them, as wp_count_posts() does.
		 *
		 * @param string $post_type Post type slug.
		 * @return array<string,int>
		 */
		private static function built_counts( $post_type ) {
			global $wpdb;

			$out = array_fill_keys( array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ), 0 );
			$obj = get_post_type_object( $post_type );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A grouped count WP_Query cannot express; editor-only route.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.post_status AS status, COUNT(*) AS n
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = '1'
					WHERE p.post_type = %s
					AND ( p.post_status <> 'private' OR p.post_author = %d OR %d = 1 )
					GROUP BY p.post_status",
					Uich_CB_Content::META_ENABLED,
					$post_type,
					get_current_user_id(),
					( $obj && current_user_can( $obj->cap->read_private_posts ) ) ? 1 : 0
				)
			);

			foreach ( (array) $rows as $row ) {
				if ( isset( $out[ $row->status ] ) ) {
					$out[ $row->status ] = (int) $row->n;
				}
			}

			return $out;
		}

		/**
		 * The taxonomies a type's list can be filtered by: those of the type
		 * with an admin screen and a REST route, minus WordPress internals and
		 * WooCommerce's.
		 *
		 * @param string $post_type Post type slug.
		 * @return array<int,array{slug:string,restBase:string,restNamespace:string,label:string,hierarchical:bool}>
		 */
		private static function filter_taxonomies( $post_type ) {
			$out = array();
			foreach ( get_object_taxonomies( $post_type, 'objects' ) as $slug => $tax ) {
				if ( ! $tax->show_ui || empty( $tax->show_in_rest ) || in_array( $slug, array( 'post_format', 'nav_menu', 'link_category' ), true ) || 0 === strpos( $slug, 'wp_' ) || self::is_woocommerce_type( $slug ) ) {
					continue;
				}
				$out[] = array(
					'slug'          => $slug,
					'restBase'      => $tax->rest_base ? $tax->rest_base : $slug,
					'restNamespace' => ! empty( $tax->rest_namespace ) ? $tax->rest_namespace : 'wp/v2',
					'label'         => $tax->labels->name,
					'hierarchical'  => (bool) $tax->hierarchical,
				);
			}
			return $out;
		}

		/**
		 * The custom fields a type's list can be filtered by: the select, radio,
		 * checkbox and true / false fields of the UiChemy Custom Fields groups
		 * shown on a new item of the type — the fields with a fixed set of
		 * values. Keyed by field key; `metaKey` and `multiple` stay on the
		 * server (only_built() reads them), so a request can only name a field
		 * listed here, never an arbitrary meta key.
		 *
		 * @param string $post_type Post type slug.
		 * @return array<string,array{id:string,label:string,options:array,metaKey:string,multiple:bool}>
		 */
		private static function field_filters( $post_type ) {
			static $cache = array();
			if ( isset( $cache[ $post_type ] ) ) {
				return $cache[ $post_type ];
			}
			$out = array();
			if ( ! class_exists( 'Uich_Fields_Field_Groups_Location' ) || ! function_exists( 'uich_build_meta_key' ) ) {
				$cache[ $post_type ] = $out;
				return $out;
			}

			$groups = Uich_Fields_Field_Groups_Location::get_matching_groups(
				array(
					'post_id'   => 0,
					'post_type' => $post_type,
				)
			);
			foreach ( (array) $groups as $group ) {
				foreach ( isset( $group['fields'] ) ? (array) $group['fields'] : array() as $field ) {
					$type = isset( $field['type'] ) ? $field['type'] : '';
					if ( empty( $field['key'] ) || empty( $field['name'] ) || ! in_array( $type, array( 'select', 'radio', 'checkbox', 'true_false' ), true ) ) {
						continue;
					}
					$options = 'true_false' === $type
						? array(
							array(
								'value' => '1',
								'label' => __( 'Yes', 'uichemy' ),
							),
							array(
								'value' => '0',
								'label' => __( 'No', 'uichemy' ),
							),
						)
						: self::choice_options( isset( $field['choices'] ) ? $field['choices'] : array() );
					if ( ! $options ) {
						continue;
					}
					$id         = sanitize_key( $field['key'] );
					$out[ $id ] = array(
						'id'       => $id,
						'label'    => ! empty( $field['label'] ) ? wp_strip_all_tags( $field['label'] ) : $field['name'],
						'options'  => $options,
						'type'     => $type,
						'metaKey'  => uich_build_meta_key( 'post', $group['key'], $field['name'] ),
						'multiple' => 'checkbox' === $type || ( 'select' === $type && ! empty( $field['multiple'] ) ),
					);
				}
			}
			$cache[ $post_type ] = $out;
			return $out;
		}

		/**
		 * The top-level fields of the UiChemy Custom Fields groups shown on a
		 * new item of the type, in group order: what "How to use" prints.
		 *
		 * @param string $post_type Post type slug.
		 * @return array<int,array{name:string,label:string,type:string,multiple:bool,choices:array,group:string}>
		 */
		private static function usage_fields( $post_type ) {
			if ( ! class_exists( 'Uich_Fields_Field_Groups_Location' ) || ! defined( 'UICH_FIELDS_BOOTED' ) ) {
				return array();
			}
			$out    = array();
			$groups = Uich_Fields_Field_Groups_Location::get_matching_groups(
				array(
					'post_id'   => 0,
					'post_type' => $post_type,
				)
			);
			foreach ( (array) $groups as $group ) {
				foreach ( isset( $group['fields'] ) ? (array) $group['fields'] : array() as $field ) {
					if ( empty( $field['name'] ) ) {
						continue;
					}
					$out[] = array(
						'name'     => (string) $field['name'],
						'label'    => ! empty( $field['label'] ) ? wp_strip_all_tags( $field['label'] ) : (string) $field['name'],
						'type'     => isset( $field['type'] ) ? (string) $field['type'] : 'text',
						'multiple' => ! empty( $field['multiple'] ),
						// So a choice prints by its label, not its stored value.
						'choices'  => in_array( isset( $field['type'] ) ? $field['type'] : '', array( 'select', 'radio', 'checkbox' ), true ) && isset( $field['choices'] ) ? self::choice_options( $field['choices'] ) : array(),
						'group'    => isset( $group['title'] ) ? (string) $group['title'] : '',
					);
				}
			}
			return $out;
		}

		/**
		 * A choice field's choices as `[{ value, label }]`, from either shape
		 * Custom Fields stores: "value : label" lines, or an array.
		 *
		 * @param mixed $choices Raw choices.
		 * @return array<int,array{value:string,label:string}>
		 */
		private static function choice_options( $choices ) {
			$map = array();
			if ( is_string( $choices ) ) {
				foreach ( preg_split( '/\r\n|\r|\n/', $choices ) as $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					if ( preg_match( '/^\s*(.+?)\s*:\s*(.+)\s*$/', $line, $m ) ) {
						$map[ trim( $m[1] ) ] = trim( $m[2] );
					} else {
						$map[ $line ] = $line;
					}
				}
			} elseif ( is_array( $choices ) ) {
				foreach ( $choices as $k => $v ) {
					if ( is_array( $v ) && isset( $v['value'] ) ) {
						$map[ (string) $v['value'] ] = isset( $v['label'] ) ? (string) $v['label'] : (string) $v['value'];
					} elseif ( is_scalar( $v ) ) {
						$map[ is_int( $k ) ? (string) $v : (string) $k ] = (string) $v;
					}
				}
			}
			$out = array();
			foreach ( $map as $value => $label ) {
				$out[] = array(
					'value' => (string) $value,
					'label' => wp_strip_all_tags( $label ),
				);
			}
			return $out;
		}

		/**
		 * How many posts of a type there are, per status (every item).
		 *
		 * @param string $post_type Post type slug.
		 * @return array<string,int>
		 */
		private static function all_counts( $post_type ) {
			$counts = wp_count_posts( $post_type, 'readable' );
			$out    = array();
			foreach ( array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ) as $status ) {
				$out[ $status ] = isset( $counts->$status ) ? (int) $counts->$status : 0;
			}
			return $out;
		}

		/**
		 * `rest_{type}_query`: the CMS list's own filters.
		 *
		 *   uich_built=1                    only posts built with UiChemy
		 *   uich_visibility=restricted      only posts with visibility rules
		 *   uich_visibility=everyone        only posts without them
		 *   uich_fields[<field key>]=<v>    only posts whose choice field has v
		 *                                   (one of field_filters(); others are ignored)
		 *
		 * @param array           $args    WP_Query arguments.
		 * @param WP_REST_Request $request Request.
		 * @return array
		 */
		public static function only_built( $args, $request ) {
			$meta = array();

			if ( rest_sanitize_boolean( $request->get_param( 'uich_built' ) ) ) {
				$meta[] = array(
					'key'   => Uich_CB_Content::META_ENABLED,
					'value' => '1',
				);
			}

			$visibility = sanitize_key( (string) $request->get_param( 'uich_visibility' ) );
			if ( 'restricted' === $visibility || 'everyone' === $visibility ) {
				$meta[] = array(
					'key'     => Uich_CB_Visibility::META,
					'compare' => 'restricted' === $visibility ? 'EXISTS' : 'NOT EXISTS',
				);
			}

			$wanted = $request->get_param( 'uich_fields' );
			if ( is_array( $wanted ) && $wanted ) {
				$fields = self::field_filters( isset( $args['post_type'] ) && is_string( $args['post_type'] ) ? $args['post_type'] : '' );
				foreach ( $wanted as $id => $value ) {
					$id = sanitize_key( $id );
					if ( ! isset( $fields[ $id ] ) || ! is_scalar( $value ) || '' === (string) $value ) {
						continue;
					}
					$field = $fields[ $id ];
					$value = (string) $value;
					if ( ! in_array( $value, wp_list_pluck( $field['options'], 'value' ), true ) ) {
						continue;
					}
					if ( 'true_false' === $field['type'] ) {
						// Yes is stored as '1'; No as '' / '0', or never saved at all.
						$meta[] = '1' === $value
							? array(
								'key'   => $field['metaKey'],
								'value' => '1',
							)
							: array(
								'relation' => 'OR',
								array(
									'key'     => $field['metaKey'],
									'compare' => 'NOT EXISTS',
								),
								array(
									'key'     => $field['metaKey'],
									'value'   => '1',
									'compare' => '!=',
								),
							);
					} elseif ( $field['multiple'] ) {
						// A list is stored serialized: match the quoted value.
						$meta[] = array(
							'key'     => $field['metaKey'],
							'value'   => '"' . $value . '"',
							'compare' => 'LIKE',
						);
					} else {
						$meta[] = array(
							'key'   => $field['metaKey'],
							'value' => $value,
						);
					}
				}
			}

			if ( ! $meta ) {
				return $args;
			}

			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Flag keys, paged, editor-only.
			$args['meta_query'] = array_merge( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array(), $meta );

			return $args;
		}

		/**
		 * POST a new draft that is a UiChemy post from the start.
		 *
		 * Only a title is asked for; the layout is the builder's. The flag is
		 * set here (as opening it in the builder would), so the draft is in the
		 * workspace's list straight away.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function create( $request ) {
			$title = trim( (string) $request['title'] );
			// `blank`: an auto-draft, as wp-admin's "Add New" makes, so the side
			// panel can draw the item's custom fields before it has a title.
			// It joins the list only when Publish or Save draft gives it a
			// status; one left as it is, WordPress deletes after a week.
			$blank = rest_sanitize_boolean( $request['blank'] );
			if ( '' === $title && ! $blank ) {
				return Uich_CB_Rest::error( 'uich_cb_no_title', __( 'Give it a title first.', 'uichemy' ), 400 );
			}

			$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'   => (string) $request['type'],
						// WordPress refuses a post with nothing in it, so a blank one
						// is named as wp-admin names its own (get_default_post_to_edit).
						'post_title'  => '' === $title ? __( 'Auto Draft' ) : $title, // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string.
						'post_status' => $blank ? 'auto-draft' : 'draft',
						'post_author' => get_current_user_id(),
					)
				),
				true
			);

			if ( is_wp_error( $id ) ) {
				return Uich_CB_Rest::error( 'uich_cb_create_failed', $id->get_error_message(), 500 );
			}

			Uich_CB_Content::set_enabled( $id, true );

			return rest_ensure_response( array( 'id' => (int) $id ) );
		}

		/* ─────────────────────────────────────────────────────────────────
		 * One item and its custom fields (the side panel)
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * GET an item: its title and its custom field groups.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function get_item( $request ) {
			return rest_ensure_response( self::item_payload( get_post( absint( $request['id'] ) ) ) );
		}

		/**
		 * POST an item back as one multipart form: `title` and the field
		 * groups' own inputs (`uich_gf[...]`, each group with its nonce).
		 *
		 * Saved with wp_update_post(), whose `save_post` is where Custom Fields
		 * stores its values — the same path as the post screen. A value its
		 * checks refuse (a required field left empty, a malformed one) keeps its
		 * old value and is named in `fieldErrors`.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function save_item( $request ) {
			$post   = get_post( absint( $request['id'] ) );
			$update = array( 'ID' => $post->ID );

			$title = $request->get_param( 'title' );
			if ( null !== $title ) {
				$title = sanitize_text_field( (string) $title );
				if ( '' === $title ) {
					return Uich_CB_Rest::error( 'uich_cb_no_title', __( 'Give it a title first.', 'uichemy' ), 400 );
				}
				$update['post_title'] = $title;
			}

			// The panel's Publish / Save draft: the same capability wp-admin asks.
			$status = $request->get_param( 'status' );
			if ( null !== $status && '' !== $status ) {
				$status = sanitize_key( (string) $status );
				$object = get_post_type_object( $post->post_type );
				if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
					return Uich_CB_Rest::error( 'uich_cb_bad_status', __( 'That status cannot be set here.', 'uichemy' ), 400 );
				}
				if ( 'publish' === $status && ( ! $object || ! current_user_can( $object->cap->publish_posts ) ) ) {
					return Uich_CB_Rest::error( 'uich_cb_forbidden', __( 'You are not allowed to publish this item.', 'uichemy' ), rest_authorization_required_code() );
				}
				$update['post_status'] = $status;
			}

			// Make sure Custom Fields' save_post handler is bound (it reads the
			// submitted `uich_gf` and nonces from $_POST), then save.
			$render = self::fields_renderer();
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				return Uich_CB_Rest::error( 'uich_cb_save_failed', $result->get_error_message(), 500 );
			}

			// The featured image: an image attachment's id, or 0 to remove it.
			$featured = $request->get_param( 'featured_media' );
			if ( null !== $featured && '' !== $featured && post_type_supports( $post->post_type, 'thumbnail' ) ) {
				$featured = absint( $featured );
				if ( 0 === $featured ) {
					delete_post_thumbnail( $post->ID );
				} elseif ( wp_attachment_is_image( $featured ) && current_user_can( 'read_post', $featured ) ) {
					set_post_thumbnail( $post->ID, $featured );
				}
			}

			$payload                = self::item_payload( get_post( $post->ID ) );
			$payload['fieldErrors'] = $render ? self::take_field_errors( $post->ID ) : array();

			return rest_ensure_response( $payload );
		}

		/**
		 * Custom Fields' renderer, or null when that module is switched off.
		 *
		 * @return Uich_Fields_Field_Groups_Render|null
		 */
		private static function fields_renderer() {
			return class_exists( 'Uich_Fields_Field_Groups_Render' ) && defined( 'UICH_FIELDS_BOOTED' )
				? Uich_Fields_Field_Groups_Render::get_instance()
				: null;
		}

		/**
		 * An item as the side panel shows it.
		 *
		 * `fields.groups` are the field groups whose location rules match this
		 * post, each rendered exactly as the post screen's box renders it: the
		 * rows, a JSON config per field for the design-system controls, and the
		 * group's nonce. The panel mounts the controls into that markup.
		 *
		 * @param WP_Post $post Post.
		 * @return array
		 */
		private static function item_payload( $post ) {
			$groups = array();
			$render = self::fields_renderer();

			if ( $render && function_exists( 'uich_build_context_for_object' ) && class_exists( 'Uich_Fields_Field_Groups_Location' ) ) {
				$context                = uich_build_context_for_object( 'post', $post->ID, array( 'post_type' => $post->post_type ) );
				$context['post']        = $post;
				$context['post_status'] = $post->post_status;

				foreach ( (array) Uich_Fields_Field_Groups_Location::get_matching_groups( $context ) as $group ) {
					if ( ! is_array( $group ) || empty( $group['fields'] ) ) {
						continue;
					}
					ob_start();
					$render->render_meta_box(
						$post,
						array(
							'args' => array(
								'uichemy_gf_group' => $group,
								'uich_gf_style'    => isset( $group['style'] ) ? $group['style'] : 'default',
							),
						)
					);
					$groups[] = array(
						'key'   => isset( $group['key'] ) ? (string) $group['key'] : 'group_' . ( isset( $group['id'] ) ? (int) $group['id'] : 0 ),
						'title' => isset( $group['title'] ) ? (string) $group['title'] : '',
						'html'  => (string) ob_get_clean(),
					);
				}
			}

			return array(
				'id'       => (int) $post->ID,
				'type'     => $post->post_type,
				// The post's own edit screen, where fields this panel does not
				// draw (other_field_groups()) are edited.
				'editUrl'  => current_user_can( 'edit_post', $post->ID ) ? (string) get_edit_post_link( $post->ID, 'raw' ) : '',
				'title'    => (string) $post->post_title,
				'status'   => (string) $post->post_status,
				// Its featured image, or null when its type has none.
				'thumbnail' => self::thumbnail_payload( $post ),
				'link'     => (string) get_permalink( $post ),
				'modified' => (string) $post->post_modified,
				'fields'   => array(
					'available'    => null !== $render,
					'groups'       => $groups,
					'dashboardUrl' => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=uichemy#/content-fields' ) : '',
					'other'        => self::other_field_groups( $post ),
				),
			);
		}

		/**
		 * An item's featured image for the side panel: `{ id, url, alt }`, with
		 * id 0 when none is set; null when the item's type has no featured image.
		 *
		 * @param WP_Post $post Post.
		 * @return array{id:int,url:string,alt:string}|null
		 */
		private static function thumbnail_payload( $post ) {
			if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
				return null;
			}
			$id  = (int) get_post_thumbnail_id( $post );
			$url = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
			return array(
				'id'  => $id,
				'url' => $url ? (string) $url : '',
				'alt' => $id ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : '',
			);
		}

		/**
		 * Field groups from another fields plugin shown on this post's edit
		 * screen — ACF's today — as `[{ source, title }]`. The panel names them
		 * and links to that screen: their inputs need the plugin's own scripts,
		 * which the editor does not load.
		 *
		 * @param WP_Post $post Post.
		 * @return array<int,array{source:string,title:string}>
		 */
		private static function other_field_groups( $post ) {
			$out = array();

			if ( function_exists( 'acf_get_field_groups' ) ) {
				$groups = acf_get_field_groups(
					array(
						'post_id'   => $post->ID,
						'post_type' => $post->post_type,
					)
				);
				foreach ( (array) $groups as $group ) {
					if ( isset( $group['active'] ) && ! $group['active'] ) {
						continue;
					}
					$out[] = array(
						'source' => 'ACF',
						'title'  => isset( $group['title'] ) ? wp_strip_all_tags( (string) $group['title'] ) : '',
					);
				}
			}

			/**
			 * Filter the other plugins' field groups the CMS side panel names for a post.
			 *
			 * @since 5.4.1
			 *
			 * @param array   $out  `[{ source, title }]`.
			 * @param WP_Post $post Post.
			 */
			return (array) apply_filters( 'uich_cb/cms_other_field_groups', $out, $post );
		}

		/**
		 * The values Custom Fields refused on this save, for this user — the
		 * same short-lived record its post-screen notice reads — and clear it.
		 *
		 * @param int $post_id Post ID.
		 * @return array<int,array{label:string,code:string}>
		 */
		private static function take_field_errors( $post_id ) {
			$key   = 'uich_gf_required_err_' . get_current_user_id() . '_post_' . (int) $post_id;
			$stash = get_transient( $key );
			$out   = array();

			if ( is_array( $stash ) && $stash ) {
				delete_transient( $key );
				foreach ( $stash as $entry ) {
					$out[] = array(
						'label' => isset( $entry['label'] ) ? (string) $entry['label'] : '',
						'code'  => isset( $entry['code'] ) ? (string) $entry['code'] : '',
					);
				}
			}

			return $out;
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Visibility (Uich_CB_Visibility)
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * GET the visibility rules of the given posts the user may edit:
		 * `{ items: { [id]: rules[] } }`, posts without rules left out.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function get_visibility( $request ) {
			$ids   = array_slice( array_filter( array_map( 'absint', explode( ',', (string) $request['ids'] ) ) ), 0, 100 );
			$items = array();

			foreach ( $ids as $id ) {
				if ( ! current_user_can( 'edit_post', $id ) ) {
					continue;
				}
				$rules = Uich_CB_Visibility::get( $id );
				if ( $rules ) {
					$items[ (string) $id ] = $rules;
				}
			}

			return rest_ensure_response( array( 'items' => (object) $items ) );
		}

		/**
		 * POST `{ rules: [...] }` — a post's visibility rules. Only the visitor
		 * and time rules are kept (Uich_CB_Visibility::RULE_TYPES).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function set_visibility( $request ) {
			if ( ! Uich_CB_Visibility::available() ) {
				return Uich_CB_Rest::error( 'uich_cb_no_conditions', __( 'Visibility rules need the UiChemy Theme Builder, which is not loaded.', 'uichemy' ), 501 );
			}
			$body  = $request->get_json_params();
			$rules = ( is_array( $body ) && isset( $body['rules'] ) ) ? $body['rules'] : array();

			return rest_ensure_response( array( 'rules' => Uich_CB_Visibility::set( absint( $request['id'] ), $rules ) ) );
		}

		/**
		 * The lists the visibility rules choose from, in the shape the Theme
		 * Builder's conditions dialog reads (its `tb_condition_options`), so the
		 * CMS can hand them over instead of the dialog fetching them through an
		 * administrator-only action.
		 *
		 * @return array
		 */
		private static function visibility_options() {
			$roles = array();
			foreach ( wp_roles()->get_names() as $slug => $name ) {
				$roles[] = array(
					'slug'  => $slug,
					'label' => translate_user_role( $name ),
				);
			}
			$days = array();
			global $wp_locale;
			for ( $n = 1; $n <= 7; $n++ ) {
				$days[] = array(
					'slug'  => (string) $n,
					'label' => $wp_locale->get_weekday( $n % 7 ),
				);
			}

			return array(
				'available' => Uich_CB_Visibility::available(),
				'roles'     => $roles,
				'days'      => $days,
				'osList'    => array(
					array( 'slug' => 'windows', 'label' => 'Windows' ),
					array( 'slug' => 'macos', 'label' => 'macOS' ),
					array( 'slug' => 'linux', 'label' => 'Linux' ),
					array( 'slug' => 'android', 'label' => 'Android' ),
					array( 'slug' => 'ios', 'label' => 'iOS' ),
				),
				'browsers'  => array(
					array( 'slug' => 'chrome', 'label' => 'Chrome' ),
					array( 'slug' => 'firefox', 'label' => 'Firefox' ),
					array( 'slug' => 'safari', 'label' => 'Safari' ),
					array( 'slug' => 'edge', 'label' => 'Edge' ),
					array( 'slug' => 'opera', 'label' => 'Opera' ),
				),
			);
		}

		/**
		 * Where "Create custom post type" saves.
		 *
		 * The workspace does not register post types itself: it saves through
		 * UiChemy's own Custom Fields module (`uichemy-fields/v1/cpt`), the same
		 * definition store and the same checks as Dashboard › Custom Fields, so
		 * a type made here is edited and deleted there like any other. That
		 * module can be switched off (Settings › Features) and only an
		 * administrator may save to it, which `available` reflects.
		 *
		 * @return array{available:bool, enabled:bool, canManage:bool, url:string, dashboardUrl:string}
		 */
		private static function cpt_creator() {
			$enabled    = defined( 'UICH_FIELDS_BOOTED' ) && defined( 'UICH_FIELDS_REST_NS' );
			$can_manage = current_user_can( 'manage_options' );

			return array(
				'available'    => $enabled && $can_manage,
				'enabled'      => $enabled,
				'canManage'    => $can_manage,
				'url'          => $enabled ? esc_url_raw( rest_url( UICH_FIELDS_REST_NS . '/cpt' ) ) : '',
				'dashboardUrl' => $can_manage ? admin_url( 'admin.php?page=uichemy#/content-fields' ) : '',
			);
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Restore
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * POST: take an item out of the trash with wp_untrash_post().
		 *
		 * The status update wp/v2 offers would bring it back but leave the trash
		 * bookkeeping behind (`_wp_trash_meta_status`, `_wp_trash_meta_time`,
		 * `_wp_desired_post_slug`); untrashing clears it and runs the hooks
		 * other plugins listen for. It comes back as a draft, WordPress's own
		 * default (filterable with `wp_untrash_post_status`).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function restore( $request ) {
			$post_id = absint( $request['id'] );
			if ( 'trash' !== get_post_status( $post_id ) ) {
				return Uich_CB_Rest::error( 'uich_cb_not_trashed', __( 'That item is not in the trash.', 'uichemy' ), 400 );
			}
			if ( ! wp_untrash_post( $post_id ) ) {
				return Uich_CB_Rest::error( 'uich_cb_restore_failed', __( 'The item could not be restored.', 'uichemy' ), 500 );
			}

			return rest_ensure_response(
				array(
					'id'     => $post_id,
					'status' => (string) get_post_status( $post_id ),
				)
			);
		}

		/* ─────────────────────────────────────────────────────────────────
		 * Duplicate
		 * ────────────────────────────────────────────────────────────── */

		/**
		 * POST a copy of a post: fields, terms and meta, saved as a draft.
		 *
		 * The meta is copied as stored, so a page built with the builder keeps
		 * its document — it was stored through the code policy once already.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function duplicate( $request ) {
			$source = get_post( absint( $request['id'] ) );

			$new_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'      => $source->post_type,
						/* translators: %s: the original title. */
						'post_title'     => sprintf( __( '%s (copy)', 'uichemy' ), $source->post_title ),
						'post_content'   => $source->post_content,
						'post_excerpt'   => $source->post_excerpt,
						'post_status'    => 'draft',
						'post_author'    => get_current_user_id(),
						'post_parent'    => $source->post_parent,
						'menu_order'     => $source->menu_order,
						'comment_status' => $source->comment_status,
						'ping_status'    => $source->ping_status,
						'post_password'  => $source->post_password,
					)
				),
				true
			);

			if ( is_wp_error( $new_id ) ) {
				return Uich_CB_Rest::error( 'uich_cb_duplicate_failed', $new_id->get_error_message(), 500 );
			}

			foreach ( get_object_taxonomies( $source->post_type ) as $taxonomy ) {
				$terms = wp_get_object_terms( $source->ID, $taxonomy, array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $terms ) && $terms ) {
					wp_set_object_terms( $new_id, $terms, $taxonomy );
				}
			}

			$skip = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time' );
			foreach ( get_post_meta( $source->ID ) as $key => $values ) {
				if ( in_array( $key, $skip, true ) ) {
					continue;
				}
				foreach ( $values as $value ) {
					add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
				}
			}

			return rest_ensure_response( array( 'id' => (int) $new_id ) );
		}
	}
}
