<?php
/**
 * Uich_Dynamic — facade for the dynamic-data engine.
 *
 * Loads the engine + providers + filters + functions, builds the auto-detected context
 * (post/site/user/request, product on Woo singles, loop item where relevant), and renders a
 * template string. The Composer/Atom widget calls Uich_Dynamic::render_html() inside render().
 *
 * Swap-out point: to move to Symfony Twig later, replace the body of render() with a Twig
 * Environment call using the same $context — nothing else in the plugin changes.
 *
 * @package Uichemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Dynamic' ) ) {
	class Uich_Dynamic {

		private static $loaded = false;

		/** Register the editor-preview + field-introspection REST routes. */
		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'register_preview_route' ) );
			add_action( 'rest_api_init', array( __CLASS__, 'register_fields_route' ) );
			add_action( 'rest_api_init', array( __CLASS__, 'register_entities_route' ) );
		}

		public static function register_preview_route() {
			register_rest_route(
				'uichemy/v1',
				'/atom-preview',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'render_preview' ),
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' ); },
				)
			);
		}

		public static function register_fields_route() {
			register_rest_route(
				'uichemy/v1',
				'/atom-fields',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_fields' ),
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' ); },
				)
			);
		}

		public static function register_entities_route() {
			register_rest_route(
				'uichemy/v1',
				'/atom-entities',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_entities' ),
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' ); },
				)
			);
		}

		/**
		 * Search real posts or terms for the Loop tab's visual pickers (Only-these-IDs / Exclude /
		 * Category). Returns [{ id, label, slug }]. `include` (CSV of ids/slugs) resolves already-
		 * selected values to labels so chips survive a reload. Read-only, capped, public types only.
		 */
		public static function get_entities( $request ) {
			return new WP_REST_Response(
				self::entities(
					array(
						'kind'      => (string) $request->get_param( 'kind' ),
						'search'    => (string) $request->get_param( 'search' ),
						'include'   => (string) $request->get_param( 'include' ),
						'taxonomy'  => (string) $request->get_param( 'taxonomy' ),
						'post_type' => (string) $request->get_param( 'post_type' ),
						'limit'     => (int) $request->get_param( 'limit' ),
					)
				),
				200
			);
		}

		/**
		 * Resolve concrete records to real ids — the engine behind both the picker REST route and
		 * describe_site( action="entities" ). Params: kind (posts|terms|users), search, include
		 * (CSV of ids/slugs), taxonomy, post_type, limit.
		 *
		 * @param array $params Lookup parameters.
		 * @return array<int,array{id:int,label:string,slug:string}>
		 */
		public static function entities( array $params = array() ) {
			$raw_kind = isset( $params['kind'] ) ? (string) $params['kind'] : '';
			$kind     = in_array( $raw_kind, array( 'terms', 'users' ), true ) ? $raw_kind : 'posts';
			$search   = sanitize_text_field( isset( $params['search'] ) ? (string) $params['search'] : '' );
			$include  = array_filter( array_map( 'trim', explode( ',', isset( $params['include'] ) ? (string) $params['include'] : '' ) ) );
			$limit    = isset( $params['limit'] ) ? (int) $params['limit'] : 0;
			$out      = array();

			if ( 'users' === $kind ) {
				$args = array(
					'number'  => $limit > 0 ? $limit : 30,
					'orderby' => 'display_name',
					'order'   => 'ASC',
				);
				if ( $search ) {
					$args['search']         = '*' . $search . '*';
					$args['search_columns'] = array( 'display_name', 'user_login', 'user_nicename', 'user_email' );
				}
				if ( $include ) {
					$args = array(
						'include' => array_map( 'intval', array_filter( $include, 'is_numeric' ) ),
						'number'  => 0,
					);
				}
				foreach ( get_users( $args ) as $u ) {
					$out[] = array(
						'id'    => (int) $u->ID,
						'label' => $u->display_name ? $u->display_name : $u->user_login,
						'slug'  => $u->user_nicename,
					);
				}
				return $out;
			}

			if ( 'terms' === $kind ) {
				$taxonomy = sanitize_key( isset( $params['taxonomy'] ) ? (string) $params['taxonomy'] : '' );
				if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
					$taxonomy = 'category';
				}
				$args = array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => $limit > 0 ? $limit : 50,
				);
				if ( $search ) {
					$args['search'] = $search;
				}
				if ( $include ) {
					// Resolve selected values (slugs or ids) regardless of search.
					$args  = array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'number'     => 0,
					);
					$ids   = array_filter( $include, 'is_numeric' );
					$slugs = array_values( array_diff( $include, $ids ) );
					if ( $ids ) {
						$args['include'] = array_map( 'intval', $ids );
					}
					if ( $slugs ) {
						$args['slug'] = $slugs;
					}
				}
				$terms = get_terms( $args );
				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $t ) {
						$out[] = array(
							'id'    => (int) $t->term_id,
							'label' => $t->name,
							'slug'  => $t->slug,
						);
					}
				}
			} else {
				$post_type = sanitize_key( isset( $params['post_type'] ) ? (string) $params['post_type'] : '' );
				if ( ! $post_type || ! post_type_exists( $post_type ) ) {
					$post_type = 'post';
				}
				$args = array(
					'post_type'        => $post_type,
					'post_status'      => array( 'publish', 'private', 'draft', 'pending' ),
					'posts_per_page'   => $limit > 0 ? $limit : 30,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- Intentional; VIP-only advisory, acceptable for this query.
				);
				if ( $search ) {
					$args['s'] = $search;
				}
				if ( $include ) {
					$args['post__in']       = array_map( 'intval', array_filter( $include, 'is_numeric' ) );
					$args['posts_per_page'] = count( $args['post__in'] );
					$args['orderby']        = 'post__in';
					unset( $args['s'] );
				}
				$q = new WP_Query( $args );
				foreach ( $q->posts as $p ) {
					$out[] = array(
						'id'    => (int) $p->ID,
						'label' => $p->post_title ? $p->post_title : '#' . $p->ID,
						'slug'  => $p->post_name,
					);
				}
				wp_reset_postdata();
			}

			return $out;
		}

		/**
		 * Introspect the site's real custom fields (ACF / JetEngine / registered meta)
		 * per provider, plus the list of public post types - so the visual picker can
		 * list actual field names instead of making the user type meta('key'). Each
		 * field carries a `metaKey` the picker compiles to post.meta('key').
		 */
		public static function get_fields() {
			return new WP_REST_Response( self::fields_map(), 200 );
		}

		/**
		 * The field map itself — shared by the picker REST route and describe_site().
		 *
		 * Buckets are PROVIDER KINDS, not object types: they name the Twig provider a
		 * field is reachable through, which is what the picker and a model both need.
		 * `options` is one of them and is NOT `post`: an options-page field belongs to
		 * no post, so filing it under `post` advertises a token that can never resolve
		 * - and bind-field then confirms it, which is worse than not listing it.
		 *
		 * Sourced from includes/fields/ rather than reading ACF directly, so the
		 * picker, describe-site and uichemy-composer/custom-fields all report the same
		 * types, choices, sub-fields and return formats. They used to drift: the
		 * picker's own map typed link, taxonomy, post_object, google_map and
		 * flexible_content all as "text", and dropped every sub-field, so a model
		 * could build a repeater loop and had no way to learn its body's field names.
		 *
		 * @return array{post:array,product:array,user:array,term:array,options:array,postTypes:array}
		 */
		public static function fields_map() {
			$out = array(
				'post'      => array(),
				'product'   => array(),
				'user'      => array(),
				'term'      => array(),
				'options'   => array(),
				'postTypes' => array(),
			);

			// Public post types → for the loop "Post type" select.
			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
				$out['postTypes'][] = array(
					'value' => $pt->name,
					'label' => $pt->labels->singular_name,
				);
			}

			// Custom fields (ACF / JetEngine / registered meta) are a Pro tag: Free
			// resolves nothing but the five free fields, so listing them in the picker
			// would only offer bindings that render empty. Post types stay — the Loop
			// tab's "Post type" select is a Free feature and holds no field data.
			//
			// Returns the ARRAY. It used to return a WP_REST_Response here, which
			// get_fields() then wrapped a second time and describe_site() handed to a
			// model where it expected { post: [], product: [] } - invisible on Pro,
			// broken on every Free install.
			if ( ! uichemy_is_pro() ) {
				return $out;
			}

			$out = self::merge_provider_fields( $out );

			/** Let extensions add provider fields (Meta Box, Pods, custom). */
			return apply_filters( 'uich_dynamic_introspected_fields', $out );
		}

		/**
		 * Fold every active field provider's definitions into the picker buckets.
		 *
		 * @param array $out The map being built.
		 * @return array
		 */
		private static function merge_provider_fields( $out ) {
			if ( ! class_exists( 'Uich_Field_Registry' ) ) {
				return $out;
			}

			foreach ( self::introspection_targets() as $row ) {
				$bucket = $row['bucket'];
				$target = $row['target'];

				foreach ( Uich_Field_Registry::flat_definitions( $target ) as $def ) {
					$entry = self::picker_entry( $def );

					// A field on `product` is also on `post` as far as the Twig
					// engine is concerned (Uich_Product_Provider extends the post
					// provider), but the picker wants it under Products.
					$in = $bucket;

					if ( 'post' === $bucket && ! empty( $def['object_subtypes'] ) && in_array( 'product', (array) $def['object_subtypes'], true ) ) {
						$in = 'product';
					}

					if ( ! isset( $out[ $in ] ) ) {
						$out[ $in ] = array();
					}

					// First provider wins on a name collision, and the collision is
					// reported rather than hidden - with ACF and JetEngine both
					// defining a name, whichever the template reads through decides
					// the value, and that is worth knowing before binding it.
					foreach ( $out[ $in ] as $existing ) {
						if ( $existing['key'] === $entry['key'] ) {
							continue 2;
						}
					}

					$out[ $in ][] = $entry;
				}
			}

			return $out;
		}

		/**
		 * The object types the picker introspects, keyed by the bucket they land in.
		 *
		 * Every registered post type is walked rather than only `post`, because a
		 * single flat unbounded bucket offers a `jet_case` field as a candidate on a
		 * `movie` template, where it can never resolve.
		 *
		 * @return array<int,array{bucket:string,target:array}>
		 */
		private static function introspection_targets() {
			$targets = array();

			foreach ( get_post_types( array(), 'names' ) as $post_type ) {
				$targets[] = array(
					'bucket' => 'post',
					'target' => array(
						'object_type'    => 'post',
						'object_subtype' => $post_type,
						'object_id'      => 0,
						'acf_id'         => '',
						'label'          => 'post / ' . $post_type,
					),
				);
			}

			foreach ( get_taxonomies( array(), 'names' ) as $taxonomy ) {
				$targets[] = array(
					'bucket' => 'term',
					'target' => array(
						'object_type'    => 'term',
						'object_subtype' => $taxonomy,
						'object_id'      => 0,
						'acf_id'         => '',
						'label'          => 'term / ' . $taxonomy,
					),
				);
			}

			$targets[] = array(
				'bucket' => 'user',
				'target' => array(
					'object_type'    => 'user',
					'object_subtype' => 'user',
					'object_id'      => 0,
					'acf_id'         => '',
					'label'          => 'user',
				),
			);

			$targets[] = array(
				'bucket' => 'options',
				'target' => array(
					'object_type'    => 'options',
					'object_subtype' => 'option',
					'object_id'      => 0,
					'acf_id'         => 'option',
					'label'          => 'site options',
				),
			);

			return $targets;
		}

		/**
		 * One normalised field definition as the picker's entry shape.
		 *
		 * The first four keys are the contract the picker JS reads (uich-dd-schema.js
		 * => extend()). Everything after them is additive: a model reading
		 * describe-site gets the type detail it needs, and the JS ignores keys it does
		 * not know.
		 *
		 * @param array $def Normalised definition from includes/fields/.
		 * @return array
		 */
		private static function picker_entry( $def ) {
			$entry = array(
				'key'      => 'meta_' . $def['name'],
				'label'    => '' !== (string) $def['label'] ? $def['label'] : $def['name'],
				'type'     => self::picker_type( $def ),
				'metaKey'  => $def['name'],
				'group'    => (string) $def['group'],
				'provider' => $def['provider'],
			);

			// The owning plugin's own type name, alongside our normalised shape.
			// A model that knows a field is an ACF `link` can print
			// {{ post.meta('cta').url }}; one told only "text" prints the whole
			// object and gets nothing.
			$entry['fieldType'] = $def['type'];

			if ( ! empty( $def['shape'] ) ) {
				$entry['shape'] = $def['shape'];
			}

			// Carried because it is NOT cosmetic. Verified:
			// {{ post.meta('hero').src('large') }} resolves for an Image ID and an
			// Image Array field and renders EMPTY for Image URL - while
			// describe-site's own binding hint recommends exactly that token.
			if ( null !== $def['return_format'] && '' !== (string) $def['return_format'] ) {
				$entry['returnFormat'] = $def['return_format'];
			}

			if ( ! empty( $def['required'] ) ) {
				$entry['required'] = true;
			}

			if ( ! empty( $def['choices'] ) ) {
				$entry['choices'] = $def['choices'];
			}

			if ( ! empty( $def['object_subtypes'] ) ) {
				$entry['postTypes'] = array_values( (array) $def['object_subtypes'] );
			}

			// acf_get_fields() and JetEngine's field store both return the top
			// level only. Without the sub-fields a model can build the repeater
			// loop and cannot write its body - it has no way to learn the names.
			if ( ! empty( $def['sub_fields'] ) ) {
				$entry['subFields'] = array();

				foreach ( (array) $def['sub_fields'] as $sub ) {
					$entry['subFields'][] = array(
						'metaKey'   => $sub['name'],
						'label'     => $sub['label'],
						'type'      => self::picker_type( $sub ),
						'fieldType' => $sub['type'],
					);
				}

				$entry['loopExample'] = sprintf(
					"{%% for row in post.meta('%s') %%}{{ row.%s }}{%% endfor %%}",
					$def['name'],
					isset( $entry['subFields'][0]['metaKey'] ) ? $entry['subFields'][0]['metaKey'] : 'field'
				);
			}

			// Flexible content: each layout has its own body, so the layout names
			// are the only way to know what a row can be.
			if ( ! empty( $def['layouts'] ) ) {
				$entry['layouts'] = array();

				foreach ( (array) $def['layouts'] as $layout ) {
					$entry['layouts'][] = array(
						'name'      => $layout['name'],
						'label'     => $layout['label'],
						'subFields' => wp_list_pluck( (array) $layout['sub_fields'], 'name' ),
					);
				}
			}

			if ( empty( $def['writable'] ) && '' !== (string) $def['reason'] ) {
				$entry['writable'] = false;
				$entry['reason']   = $def['reason'];
			}

			return $entry;
		}

		/**
		 * The picker's coarse value-type for a normalised definition.
		 *
		 * The picker only branches on a handful of these (it needs to know whether
		 * to offer a size selector for an image and a loop for an array), so the
		 * shape vocabulary is collapsed rather than passed through - the precise
		 * type travels as `fieldType`.
		 *
		 * @param array $def Normalised definition.
		 * @return string
		 */
		private static function picker_type( $def ) {
			$shape = isset( $def['value_shape'] ) ? $def['value_shape'] : 'string';

			$map = array(
				'string'   => 'text',
				'email'    => 'text',
				'choice'   => 'text',
				'html'     => 'html',
				'number'   => 'number',
				'bool'     => 'bool',
				'date'     => 'date',
				'datetime' => 'date',
				'time'     => 'text',
				'url'      => 'url',
				'image'    => 'image',
				'file'     => 'url',
				'array'    => 'array',
				'choices'  => 'array',
				'relation' => 'array',
				'rows'     => 'array',
				'object'   => 'object',
				'unknown'  => 'text',
			);

			return isset( $map[ $shape ] ) ? $map[ $shape ] : 'text';
		}

		// ============================================================
		// SITE INTROSPECTION (uichemy-composer/describe-site)
		// ============================================================

		/**
		 * Describe this site's CONTENT MODEL so an AI binds real data instead of guessing names.
		 *
		 * The picker already introspects fields and resolves entities for a human choosing from a
		 * dropdown; this is the same knowledge served to a model that has no dropdown. It is the
		 * mandatory first call before any dynamic loop, field binding, or template condition —
		 * every metaKey, taxonomy slug and id in the response is one that actually exists.
		 *
		 * action="schema" (default) returns the whole model in one payload: build readiness
		 * (`platform` — what check-config used to answer on its own), public post types,
		 * taxonomies, the ACF / registered-meta field map, nav menus + locations, and WooCommerce.
		 * action="entities" resolves concrete records (kind=posts|terms|users) to real ids.
		 *
		 * Read-only, no side effects.
		 *
		 * @param array $args Ability input.
		 * @return array
		 */
		public static function describe_site( $args = array() ) {
			$args   = is_array( $args ) ? $args : array();
			$action = isset( $args['action'] ) ? sanitize_key( (string) $args['action'] ) : 'schema';

			if ( 'entities' === $action ) {
				$kind = isset( $args['kind'] ) ? (string) $args['kind'] : 'posts';

				return array(
					'action'   => 'entities',
					'kind'     => in_array( $kind, array( 'terms', 'users' ), true ) ? $kind : 'posts',
					'entities' => self::entities( $args ),
				);
			}

			return self::describe_schema();
		}

		/**
		 * Every dynamic token this build can resolve, for an agent that would
		 * otherwise guess field names.
		 *
		 * describe_site() reports CUSTOM fields (ACF / registered meta) — the ones
		 * that differ per site. This reports the BUILT-IN ones, which don't: the
		 * accessors on each provider. Both matter, and a binding written from a
		 * guessed accessor renders empty rather than erroring, so there is nothing
		 * in the output to tell the author it was wrong.
		 *
		 * The catalog itself is generated from the picker schema, so the tokens
		 * offered to a human and the tokens offered to an agent are one list.
		 *
		 * @param array $args { provider?: string }
		 * @return array
		 */
		public static function list_fields( $args = array() ) {
			$args = is_array( $args ) ? $args : array();
			$only = isset( $args['provider'] ) ? sanitize_key( (string) $args['provider'] ) : '';

			if ( ! class_exists( 'Uich_DD_Catalog' ) ) {
				return array(
					'providers' => array(),
					'message'   => 'The token catalog is unavailable in this build.',
				);
			}

			$catalog = Uich_DD_Catalog::providers();
			$keys    = ( '' !== $only && isset( $catalog[ $only ] ) ) ? array( $only ) : array_keys( $catalog );

			$out = array();
			foreach ( $keys as $key ) {
				$entry = array(
					'label'  => $catalog[ $key ]['label'],
					'tokens' => Uich_DD_Catalog::tokens( $key ),
				);
				if ( ! empty( $catalog[ $key ]['extends'] ) ) {
					$entry['extends'] = $catalog[ $key ]['extends'];
				}
				$out[ $key ] = $entry;
			}

			$woo = class_exists( 'WooCommerce' );

			return array(
				'providers'     => $out,
				// Site-specific fields, so the agent doesn't have to make a second
				// call to describe-site just to learn a meta key.
				'custom_fields' => self::fields_map(),
				'notes'         => array(
					'usage'    => 'Print a token as-is. A field with an argument takes it in quotes, e.g. {{ post.meta(\'price\') }}.',
					'chaining' => 'A token whose "chains_to" is set returns another provider {{ post.author.name }}, {{ post.thumbnail.src(\'large\') }}.',
					'arrays'   => 'A type of "array" is what a {% for %} loop repeats over {% for c in product.categories %}{{ c.name }}{% endfor %}.',
					'custom'   => 'Anything under custom_fields is meta: reach it with meta(), never as a bare accessor.',
					'product'  => $woo
						? 'WooCommerce is active, so the "product" provider resolves on a product.'
						: 'WooCommerce is NOT active the "product" provider resolves to nothing on this site.',
				),
			);
		}

		/**
		 * The whole content model in one payload. See describe_site().
		 *
		 * @return array
		 */
		private static function describe_schema() {
			$fields = self::fields_map();

			return array(
				'action'          => 'schema',
				'site'            => array(
					'name'        => get_bloginfo( 'name' ),
					'url'         => home_url( '/' ),
					'description' => get_bloginfo( 'description' ),
					'language'    => get_bloginfo( 'language' ),
				),
				'platform'        => self::describe_platform(),
				'post_types'      => self::describe_post_types(),
				'taxonomies'      => self::describe_taxonomies(),
				'fields'          => $fields,
				// Which field-modelling plugins are here, and their edition. Without
				// this an empty `fields` map is indistinguishable from "this site has
				// no field plugin", and a model reads the first as the second and
				// hardcodes the page.
				'field_providers' => class_exists( 'Uich_Field_Registry' ) ? Uich_Field_Registry::status() : array(),
				'meta_keys'       => self::describe_registered_meta(),
				'menus'           => self::describe_menus(),
				'woocommerce'     => self::describe_woocommerce(),
				'binding_hints'   => array(
					'build'    => 'Route header/footer work by platform.header_footer_system, and STOP if platform.checks.elementor_active is false.',
					'field'    => "{{ post.meta('metaKey') }} use the metaKey from fields.post / fields.product / fields.user / fields.term / fields.options, never a guessed name. An unknown name renders EMPTY rather than failing, so a guess yields a page that looks built and is blank.",
					'tokens'   => 'For the BUILT-IN accessors (post.title, product.price, product.sale_percentage, …) call uichemy-composer/dynamic (action="list-fields"). This payload only carries the custom fields.',
					'image'    => "{{ post.meta('metaKey').src('large') }} for an image field - but ONLY when the field stores an attachment ID or array. Check the entry's returnFormat: on an ACF image field set to \"Image URL\" this token renders EMPTY, because there is no id to derive a size from.",
					'repeater' => 'A repeater entry carries subFields and a ready loopExample. Its body binds row.<subFieldName>, not post.meta().',
					'options'  => 'fields.options are site-wide values on an ACF or JetEngine options page. They belong to no post, so a post.meta() token can never reach them.',
					'loop'     => 'Build listings with uichemy-composer/dynamic (action="create-loop") using a post type from post_types[].name.',
					'taxonomy' => 'Filter by taxonomies[].name and a real term resolve terms with action="entities", kind="terms".',
					'model'    => 'To CREATE a post type, taxonomy or field, call uichemy-composer/cpt. To read or write field VALUES, call uichemy-composer/custom-fields.',
				),
			);
		}

		/**
		 * Build readiness — what the retired check-config ability used to report on its own:
		 * Elementor / Elementor Pro / Nexter detection, the active kit, header_footer_system,
		 * atomic globals mode, the active header/footer templates, and branding.
		 *
		 * Sourced from the Composer server's own handler rather than re-derived here, so the v1
		 * `check_config` tool and this block can never drift apart.
		 *
		 * @return array
		 */
		private static function describe_platform() {
			if ( ! class_exists( 'UiChemy_Composer_MCP_Server' )
				|| ! method_exists( 'UiChemy_Composer_MCP_Server', 'execute_check_config' ) ) {
				return array( 'available' => false );
			}

			$config = UiChemy_Composer_MCP_Server::execute_check_config();

			// nav_menus is dropped: the `menus` block below carries the same menus with
			// their ids, item counts and theme locations, so keeping both would ship two
			// answers to one question.
			unset( $config['nav_menus'], $config['message'] );

			return $config;
		}

		/** Public post types with their supports, taxonomies and published counts. */
		private static function describe_post_types() {
			$out = array();

			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
				if ( 'attachment' === $pt->name ) {
					continue;
				}

				$counts = wp_count_posts( $pt->name );

				$out[] = array(
					'name'         => $pt->name,
					'label'        => $pt->labels->name,
					'singular'     => $pt->labels->singular_name,
					'hierarchical' => (bool) $pt->hierarchical,
					'has_archive'  => (bool) $pt->has_archive,
					'rest_base'    => $pt->rest_base ? $pt->rest_base : $pt->name,
					'supports'     => array_keys( get_all_post_type_supports( $pt->name ) ),
					'taxonomies'   => array_values( get_object_taxonomies( $pt->name ) ),
					'published'    => isset( $counts->publish ) ? (int) $counts->publish : 0,
				);
			}

			return $out;
		}

		/** Public taxonomies, which post types they apply to, and how many terms exist. */
		private static function describe_taxonomies() {
			$out = array();

			foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
				$count = wp_count_terms(
					array(
						'taxonomy'   => $tax->name,
						'hide_empty' => false,
					)
				);

				$out[] = array(
					'name'         => $tax->name,
					'label'        => $tax->labels->name,
					'hierarchical' => (bool) $tax->hierarchical,
					'post_types'   => array_values( (array) $tax->object_type ),
					'terms'        => is_wp_error( $count ) ? 0 : (int) $count,
				);
			}

			return $out;
		}

		/**
		 * Meta keys registered through register_meta() / register_post_meta(), per object type.
		 * These are bindable with meta('key') exactly like ACF fields, but never show up in the
		 * ACF field map, so a model that only read `fields` would miss them.
		 */
		private static function describe_registered_meta() {
			$out = array();

			foreach ( array( 'post', 'term', 'user' ) as $object_type ) {
				$subtypes = array( '' );

				if ( 'post' === $object_type ) {
					$subtypes = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
				}

				foreach ( $subtypes as $subtype ) {
					$registered = get_registered_meta_keys( $object_type, $subtype );

					foreach ( $registered as $key => $config ) {
						// Protected keys are not addressable through meta('key').
						if ( is_protected_meta( $key, $object_type ) ) {
							continue;
						}

						$out[] = array(
							'object_type' => $object_type,
							'subtype'     => $subtype,
							'metaKey'     => $key,
							'type'        => isset( $config['type'] ) ? (string) $config['type'] : 'string',
							'single'      => ! empty( $config['single'] ),
						);
					}
				}
			}

			return $out;
		}

		/** Nav menus, their item counts, and which theme locations they are assigned to. */
		private static function describe_menus() {
			$assigned = get_nav_menu_locations();
			$menus    = array();

			foreach ( wp_get_nav_menus() as $menu ) {
				$menus[] = array(
					'id'        => (int) $menu->term_id,
					'name'      => $menu->name,
					'slug'      => $menu->slug,
					'items'     => (int) $menu->count,
					'locations' => array_values( array_keys( $assigned, (int) $menu->term_id, true ) ),
				);
			}

			$locations = array();
			foreach ( get_registered_nav_menus() as $slug => $label ) {
				$locations[] = array(
					'slug'     => $slug,
					'label'    => $label,
					'menu_id'  => isset( $assigned[ $slug ] ) ? (int) $assigned[ $slug ] : 0,
					'assigned' => ! empty( $assigned[ $slug ] ),
				);
			}

			return array(
				'menus'     => $menus,
				'locations' => $locations,
			);
		}

		/** WooCommerce presence and the bits a product loop needs. */
		private static function describe_woocommerce() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return array( 'active' => false );
			}

			$counts = wp_count_posts( 'product' );

			return array(
				'active'       => true,
				'products'     => isset( $counts->publish ) ? (int) $counts->publish : 0,
				'taxonomies'   => array_values( get_object_taxonomies( 'product' ) ),
				'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'shop_page_id' => function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0,
			);
		}

		/**
		 * Render a raw_html template with the engine for the EDITOR canvas preview, so loops,
		 * values and conditions show real data instead of raw {% %} / {{ }} text. Uses the
		 * edited post as `post` when it's a post; otherwise falls back to the latest published
		 * post so {{ post.* }} bindings demo with real content.
		 */
		public static function render_preview( $request ) {
			$html    = (string) $request->get_param( 'html' );
			$post_id = (int) $request->get_param( 'post_id' );

			// Accept any public post type the editor picks (post/page/product/CPT) that the
			// requesting user is actually allowed to edit; fall back to the latest published
			// post when nothing valid/authorized was chosen. The route's permission_callback
			// only checks the generic 'edit_posts' capability, so without this per-post check
			// any post-editing user could pass an arbitrary post_id (someone else's private or
			// draft post) and read its content back through the preview response.
			$preview_post = $post_id ? get_post( $post_id ) : null;
			if ( $preview_post ) {
				$pt_object = get_post_type_object( $preview_post->post_type );
				$allowed   = ! empty( $pt_object ) && ! empty( $pt_object->public )
					&& current_user_can( 'edit_post', $preview_post->ID );
				if ( ! $allowed ) {
					$preview_post = null;
				}
			}
			if ( ! $preview_post ) {
				$latest = get_posts(
					array(
						'numberposts' => 1,
						'post_status' => 'publish',
					)
				);
				if ( ! empty( $latest ) ) {
					$preview_post = $latest[0];
				}
			}

			$overrides = array();
			if ( $preview_post instanceof WP_Post ) {
				self::boot();
				// Same factory the render context uses, so the preview gets whatever
				// provider this build ships (Pro upgrades it to the Pro/Product one).
				// `author` is a Pro provider and is added by Pro's context filter.
				$overrides['post'] = self::post_provider( $preview_post );
			}

			return new WP_REST_Response( array( 'html' => self::render_html( $html, $overrides ) ), 200 );
		}

		/** Require engine files once. */
		public static function boot() {
			if ( self::$loaded ) {
				return;
			}
			$dir = UICHEMY_PATH . 'includes/dynamic/';
			require_once $dir . 'class-uich-twig.php';
			require_once $dir . 'class-uich-providers.php';
			require_once $dir . 'class-uich-filters.php';
			require_once $dir . 'class-uich-functions.php';
			self::$loaded = true;
		}

		/**
		 * Does this string contain any dynamic syntax worth processing?
		 *
		 * Percent-encoded delimiters count: a token that ended up inside a URL
		 * attribute can reach storage encoded (see restore_encoded_tokens()), and
		 * such a template still needs rendering even though it has no literal `{{`.
		 */
		public static function has_dynamic( $html ) {
			if ( ! is_string( $html ) ) {
				return false;
			}
			if ( false !== strpos( $html, '{{' ) || false !== strpos( $html, '{%' ) ) {
				return true;
			}
			return false !== stripos( $html, '%7b%7b' ) || false !== stripos( $html, '%7b%25' );
		}

		/**
		 * Undo percent-encoded Twig delimiters so an encoded token still resolves.
		 *
		 * A token used as an attribute VALUE — `src="{{ post.thumbnail.src('large') }}"`
		 * — can be run through a URL encoder before it is stored (the browser's
		 * encodeURI() turns it into "%7B%7B%20post.thumbnail.src('large')%20%7D%7D").
		 * The engine then finds no `{{`, leaves the value verbatim, and the browser
		 * requests a URL that cannot exist — the image simply never appears. No
		 * WordPress escaper produces that form, so the encoding happens client-side
		 * and the damaged value is what reaches the database; decoding here repairs
		 * existing content without a migration.
		 *
		 * Deliberately narrow: only a COMPLETE encoded token is touched, so an
		 * ordinary URL that merely contains %7B is left exactly as it is.
		 *
		 * @param string $html Template.
		 * @return string
		 */
		public static function restore_encoded_tokens( $html ) {
			if ( ! is_string( $html ) || false === stripos( $html, '%7b' ) ) {
				return $html;
			}
			return preg_replace_callback(
				'/%7b%7b.*?%7d%7d|%7b%25.*?%25%7d/is',
				function ( $m ) {
					$t = preg_replace( '/%7b%7b/i', '{{', $m[0] );
					$t = preg_replace( '/%7d%7d/i', '}}', $t );
					$t = preg_replace( '/%7b%25/i', '{%', $t );
					$t = preg_replace( '/%25%7d/i', '%}', $t );
					// Spaces inside the token were encoded by the same pass.
					return str_replace( '%20', ' ', $t );
				},
				$html
			);
		}

		/**
		 * Render a template string with the auto-built WordPress context merged with overrides.
		 *
		 * @param string $html      Template (raw_html).
		 * @param array  $overrides Extra context (e.g. a loop item, widget id).
		 * @return string
		 */
		public static function render_html( $html, array $overrides = array() ) {
			if ( ! self::has_dynamic( $html ) ) {
				return $html;
			}
			$html = self::restore_encoded_tokens( $html );
			self::boot();
			$context = array_merge( self::build_context(), $overrides );
			return Uich_Twig::render( $html, $context );
		}

		/**
		 * Build the root context based on the current query/page (Timber/UnblockWP style).
		 */
		/**
		 * Wrap a post in the provider this build ships.
		 *
		 * Free returns Uich_Post_Provider, which answers only the four free fields.
		 * Pro's Uich_Providers_Pro swaps in Uich_Post_Provider_Pro — or
		 * Uich_Product_Provider on a WooCommerce product. Both the render context and
		 * the editor preview go through here, so a previewed product behaves exactly
		 * like a rendered one.
		 *
		 * @param WP_Post $post Post to wrap.
		 * @return Uich_Post_Provider
		 */
		public static function post_provider( $post ) {
			/**
			 * Filter the provider used for a post.
			 *
			 * @param Uich_Post_Provider $provider The base (Free) provider.
			 * @param WP_Post            $post     The post being wrapped.
			 */
			return apply_filters( 'uich_dynamic_post_provider', new Uich_Post_Provider( $post ), $post );
		}

		public static function build_context() {
			// Free supplies `post` only. `site`, `request`, `user`, `author`, `term` and
			// the `product` alias are Pro providers added through the filter below by
			// includes/dynamic/class-uich-providers-pro.php, which Free does not ship —
			// so in Free those tokens resolve to nothing because the provider is simply
			// not in the context. See docs/free-pro-split-plan.md.
			$ctx = array();

			$post = get_post();
			if ( $post instanceof WP_Post ) {
				$ctx['post'] = self::post_provider( $post );
			}

			/**
			 * Allow extensions to add/replace root providers (e.g. cart on Woo pages).
			 *
			 * @param array $ctx Context map.
			 */
			return apply_filters( 'uich_dynamic_context', $ctx );
		}
	}
}
