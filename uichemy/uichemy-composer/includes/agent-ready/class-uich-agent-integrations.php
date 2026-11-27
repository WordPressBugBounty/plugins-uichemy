<?php
/**
 * Agent Ready integrations: find what other plugins on the site could become
 * site MCP tools, and describe one source in enough detail to write a tool.
 *
 * This is the "find" step of the tool lifecycle. Claude (with the MCP tool
 * builder skill) reads scan-integrations, picks a source, reads
 * describe-source, then calls save-tool. Every suggestion here is passed
 * through Uich_Agent_Tools::validate(), so nothing is suggested that the
 * registry would refuse, and every "public" flag is checked as a logged-out
 * visitor.
 *
 * Nothing here reads plugin code: sources come from WordPress registries
 * (post types, taxonomies, abilities, REST routes).
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Integrations' ) ) {

	/**
	 * Integration discovery actions.
	 */
	class Uich_Agent_Integrations {

		/**
		 * REST namespaces never worth listing: core admin, our own, MCP adapters,
		 * page builders' internals (templates, editor data), SEO, cache and
		 * security plugins, and form plugins (their forms are handled as forms).
		 */
		const SKIP_NAMESPACES = '#^(wp/v2|wp-site-health|wp-block-editor|wp-abilities|uichemy[a-z0-9\-]*|mcp|oembed|batch|application-passwords|bricks|elementor[a-z0-9\-]*|nexter[a-z0-9\-]*|tpae|theplus[a-z0-9\-]*|jet-[a-z0-9\-]+|yoast|rankmath|aioseo|litespeed|wp-rocket|wordfence|jetpack|akismet|contact-form-7|wpforms|gf|fluentform|forminator|redirection|wp-statistics|google-site-kit|wc-admin|wc-analytics)(/|$)#';

		/**
		 * Request args that are plumbing, not search inputs.
		 */
		const SKIP_ARGS = array( 'context', '_fields', '_embed', '_locale', '_envelope', '_method', 'nonce' );

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'uichemy_agent_ready_actions', array( __CLASS__, 'actions' ) );
		}

		/**
		 * Add the actions.
		 *
		 * @param array $actions Existing actions.
		 * @return array
		 */
		public static function actions( array $actions ) {
			$actions['scan-integrations'] = array(
				'description' => 'List what this site\'s plugins could offer visitors\' AI agents: public post types, abilities a logged-out visitor may call, and public REST GET routes. Each comes with a drafted tool definition (already validated) and whether an existing tool covers it. Start here before writing a tool for a third-party or custom plugin.',
				'params'      => array(),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'scan' ),
			);
			$actions['describe-source']   = array(
				'description' => 'Details for one source from scan-integrations, to write a good tool: for a post type, its taxonomies, top terms, sample items and the custom fields found on them (private-looking ones flagged); for an ability, its schemas and annotations; for a REST route, its arguments and the shape of a real response. Use the drafted definition as a starting point for save-tool.',
				'params'      => array(
					'kind' => array(
						'type' => 'string',
						'enum' => array( 'post_type', 'ability', 'route' ),
					),
					'name' => array(
						'type'        => 'string',
						'description' => 'Post type slug, ability name, or REST route exactly as scan-integrations listed it.',
					),
				),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'describe' ),
			);
			return $actions;
		}

		/**
		 * Action: scan-integrations.
		 *
		 * @return array
		 */
		public static function scan() {
			$covered = self::covered();
			return array(
				'post_types' => self::scan_post_types( $covered ),
				'abilities'  => self::scan_abilities( $covered ),
				'routes'     => self::scan_routes( $covered ),
				'plugins'    => self::active_plugins(),
				'forms'      => array(
					'composer' => class_exists( 'Uich_Forms' ),
					'next'     => 'UiChemy forms are handled by scan-forms and set-form-agent. For other form or booking plugins with no public route or ability, use a link tool to the page that holds the form.',
				),
				'next_steps' => 'Pick a source, call describe-source, adjust the drafted definition (clear description, only the fields visitors need), save-tool, test-tool, then ask the site owner before set-tool-state.',
			);
		}

		/**
		 * Action: describe-source.
		 *
		 * @param array $p Parameters.
		 * @return array|WP_Error
		 */
		public static function describe( array $p ) {
			$kind = isset( $p['kind'] ) ? (string) $p['kind'] : '';
			$name = isset( $p['name'] ) ? (string) $p['name'] : '';
			if ( 'post_type' === $kind ) {
				return self::describe_post_type( sanitize_key( $name ) );
			}
			if ( 'ability' === $kind ) {
				return self::describe_ability( strtolower( $name ) );
			}
			if ( 'route' === $kind ) {
				return self::describe_route( $name );
			}
			return new WP_Error( 'uich_agent_bad_kind', 'kind must be post_type, ability or route.' );
		}

		/* ------------------------------------------------------------------ */
		/* Post types                                                          */
		/* ------------------------------------------------------------------ */

		/**
		 * Public post types that could become collection tools.
		 *
		 * @param array $covered What existing tools cover.
		 * @return array
		 */
		private static function scan_post_types( array $covered ) {
			$out = array();
			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $obj ) {
				if ( in_array( $slug, Uich_Agent_Tools::BLOCKED_TYPES, true ) ) {
					continue;
				}
				$count = wp_count_posts( $slug );
				$row   = array(
					'name'       => $slug,
					'label'      => $obj->label,
					'published'  => isset( $count->publish ) ? (int) $count->publish : 0,
					'taxonomies' => array_values( array_keys( self::public_taxonomies( $slug ) ) ),
					'covered_by' => self::covered_by_builtin( $slug, isset( $covered['post_type'][ $slug ] ) ? $covered['post_type'][ $slug ] : array() ),
				);
				if ( ! $row['covered_by'] && $row['published'] > 0 ) {
					$row['draft'] = self::checked( self::draft_collection( $slug, $obj ) );
				}
				$out[] = $row;
			}
			return $out;
		}

		/**
		 * Built-in or saved tools that already serve a post type.
		 *
		 * @param string $slug  Post type.
		 * @param array  $saved Saved tool names.
		 * @return string[]
		 */
		private static function covered_by_builtin( $slug, array $saved ) {
			$by = $saved;
			if ( in_array( $slug, array( 'post', 'page' ), true ) ) {
				$by[] = 'search_site';
			}
			if ( 'product' === $slug && class_exists( 'WooCommerce' ) ) {
				$by[] = 'search_products';
			}
			return array_values( array_unique( $by ) );
		}

		/**
		 * Public taxonomies attached to a post type.
		 *
		 * @param string $slug Post type.
		 * @return WP_Taxonomy[]
		 */
		private static function public_taxonomies( $slug ) {
			$out = array();
			foreach ( get_object_taxonomies( $slug, 'objects' ) as $tax => $t ) {
				if ( $t->public && 'post_format' !== $tax ) {
					$out[ $tax ] = $t;
				}
			}
			return $out;
		}

		/**
		 * Draft collection definition.
		 *
		 * @param string       $slug Post type.
		 * @param WP_Post_Type $obj  Object.
		 * @param array        $meta Optional meta keys.
		 * @return array
		 */
		private static function draft_collection( $slug, $obj, array $meta = array() ) {
			$label = strtolower( $obj->label );
			return array(
				'name'        => self::tool_name( 'search_' . $slug ),
				'title'       => 'Search ' . $label,
				'description' => sprintf( 'Find %s on this site by keyword%s, with links. DRAFT: rewrite to say when an agent should use it.', $label, self::public_taxonomies( $slug ) ? ' or category' : '' ),
				'type'        => 'collection',
				'config'      => array(
					'post_type'  => $slug,
					'fields'     => array( 'title', 'url', 'agent_url', 'excerpt' ),
					'taxonomies' => array_slice( array_keys( self::public_taxonomies( $slug ) ), 0, 3 ),
					'meta'       => $meta,
				),
			);
		}

		/**
		 * Describe a post type.
		 *
		 * @param string $slug Post type.
		 * @return array|WP_Error
		 */
		private static function describe_post_type( $slug ) {
			$obj = get_post_type_object( $slug );
			if ( ! $obj || ! $obj->public || in_array( $slug, Uich_Agent_Tools::BLOCKED_TYPES, true ) ) {
				return new WP_Error( 'uich_agent_no_source', 'Not a public post type that can be exposed.' );
			}

			$taxonomies = array();
			foreach ( self::public_taxonomies( $slug ) as $tax => $t ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $tax,
						'hide_empty' => true,
						'number'     => 10,
						'orderby'    => 'count',
						'order'      => 'DESC',
					)
				);
				$taxonomies[] = array(
					'name'      => $tax,
					'label'     => $t->label,
					'top_terms' => is_wp_error( $terms ) ? array() : array_map(
						function ( $term ) {
							return array(
								'slug'  => $term->slug,
								'name'  => $term->name,
								'count' => (int) $term->count,
							);
						},
						$terms
					),
				);
			}

			$posts = Uich_Agent_Access::filter(
				get_posts(
					array(
						'post_type'        => $slug,
						'post_status'      => 'publish',
						'has_password'     => false,
						'numberposts'      => 8,
						'suppress_filters' => false,
					)
				)
			);
			$posts = array_slice( $posts, 0, 3 );

			$samples = array();
			$fields  = array();
			foreach ( $posts as $post ) {
				$samples[] = array(
					'id'    => $post->ID,
					'title' => get_the_title( $post ),
					'url'   => get_permalink( $post ),
				);
				foreach ( get_post_meta( $post->ID ) as $key => $values ) {
					if ( isset( $fields[ $key ] ) ) {
						++$fields[ $key ]['seen_on'];
						continue;
					}
					$fields[ $key ] = self::describe_field( (string) $key, isset( $values[0] ) ? $values[0] : '' );
				}
			}
			ksort( $fields );

			// Suggest fields that look public and have a short readable value.
			$suggest = array();
			foreach ( $fields as $key => $f ) {
				if ( $f['exposable'] && 'text' === $f['kind'] && $f['seen_on'] === count( $posts ) && count( $suggest ) < 5 && ! preg_match( '/^_(edit|wp_|thumbnail|oembed|menu_item|elementor|uich)/', $key ) ) {
					$suggest[] = array(
						'key'   => $key,
						'label' => sanitize_key( ltrim( $key, '_' ) ),
					);
				}
			}

			return array(
				'kind'       => 'post_type',
				'name'       => $slug,
				'label'      => $obj->label,
				'published'  => (int) wp_count_posts( $slug )->publish,
				'taxonomies' => $taxonomies,
				'samples'    => $samples,
				'fields'     => array_values( $fields ),
				'draft'      => self::checked( self::draft_collection( $slug, $obj, $suggest ) ),
				'notes'      => 'Fields marked exposable:false are refused by save-tool. Only add fields a visitor would want (dates, prices, locations); leave internal plugin keys out.',
			);
		}

		/**
		 * One custom field as seen on a sample post. Values of private-looking
		 * keys are never returned.
		 *
		 * @param string $key   Meta key.
		 * @param mixed  $value First value.
		 * @return array
		 */
		private static function describe_field( $key, $value ) {
			$sensitive = (bool) preg_match( Uich_Agent_Tools::SENSITIVE_META, $key );
			$valid     = (bool) preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $key );
			$kind      = 'text';
			$preview   = '';
			if ( is_serialized( $value ) || ( is_string( $value ) && preg_match( '/^\s*[\[{]/', $value ) ) ) {
				$kind = 'structured';
			} elseif ( '' === (string) $value ) {
				$kind = 'empty';
			} elseif ( ! $sensitive ) {
				$preview = wp_html_excerpt( wp_strip_all_tags( (string) $value ), 60, '…' );
			}
			return array(
				'key'       => $key,
				'kind'      => $kind,
				'preview'   => $preview,
				'internal'  => '_' === substr( $key, 0, 1 ),
				'exposable' => $valid && ! $sensitive,
				'seen_on'   => 1,
			);
		}

		/* ------------------------------------------------------------------ */
		/* Abilities                                                           */
		/* ------------------------------------------------------------------ */

		/**
		 * Abilities that could become ability tools.
		 *
		 * @param array $covered What existing tools cover.
		 * @return array
		 */
		private static function scan_abilities( array $covered ) {
			if ( ! function_exists( 'wp_get_abilities' ) ) {
				return array(
					'available' => false,
					'items'     => array(),
				);
			}
			$items   = array();
			$refused = 0;
			foreach ( wp_get_abilities() as $ability ) {
				$name  = $ability->get_name();
				$draft = self::draft_ability( $ability );
				$check = Uich_Agent_Tools::validate( $draft );
				if ( is_wp_error( $check ) ) {
					++$refused;
					continue;
				}
				$annotations = self::annotations( $ability );
				$anon        = self::anon_ability( $ability );
				$row         = array(
					'name'        => $name,
					'label'       => $ability->get_label(),
					'description' => wp_html_excerpt( $ability->get_description(), 200, '…' ),
					'readonly'    => true === $annotations['readonly'],
					'anonymous'   => $anon,
					'covered_by'  => isset( $covered['ability'][ $name ] ) ? $covered['ability'][ $name ] : array(),
				);
				if ( 'allowed' === $anon && ! $row['covered_by'] ) {
					$row['draft'] = $check;
					if ( ! $row['readonly'] ) {
						$row['write_note'] = 'This ability may change data. It only runs on the site MCP after the site owner allows writes on the Agent Ready screen.';
					}
				}
				$items[] = $row;
			}
			return array(
				'available'    => true,
				'items'        => $items,
				'not_eligible' => $refused,
			);
		}

		/**
		 * Normalised annotations.
		 *
		 * @param WP_Ability $ability Ability.
		 * @return array
		 */
		private static function annotations( $ability ) {
			$meta = (array) $ability->get_meta();
			$ann  = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
			return array(
				'readonly'    => isset( $ann['readonly'] ) ? $ann['readonly'] : null,
				'destructive' => isset( $ann['destructive'] ) ? $ann['destructive'] : null,
				'idempotent'  => isset( $ann['idempotent'] ) ? $ann['idempotent'] : null,
			);
		}

		/**
		 * Whether a logged-out visitor may call an ability, checked with no input.
		 *
		 * @param WP_Ability $ability Ability.
		 * @return string allowed | refused | needs-input
		 */
		private static function anon_ability( $ability ) {
			$result = self::as_visitor(
				function () use ( $ability ) {
					return $ability->check_permissions( null );
				}
			);
			if ( true === $result ) {
				return 'allowed';
			}
			if ( is_wp_error( $result ) && 'uich_agent_check_failed' === $result->get_error_code() ) {
				return 'needs-input';
			}
			return 'refused';
		}

		/**
		 * Draft ability definition.
		 *
		 * @param WP_Ability $ability Ability.
		 * @return array
		 */
		private static function draft_ability( $ability ) {
			$name = $ability->get_name();
			return array(
				'name'        => self::tool_name( str_replace( array( '/', '-' ), '_', $name ) ),
				'title'       => $ability->get_label(),
				'description' => self::draft_text( $ability->get_description(), $ability->get_label() ),
				'type'        => 'ability',
				'config'      => array(
					'ability' => $name,
					'output'  => self::schema_paths( $ability->get_output_schema() ),
				),
			);
		}

		/**
		 * Describe an ability.
		 *
		 * @param string $name Ability name.
		 * @return array|WP_Error
		 */
		private static function describe_ability( $name ) {
			$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
			if ( ! $ability ) {
				return new WP_Error( 'uich_agent_no_source', 'No ability with that name.' );
			}
			$draft = Uich_Agent_Tools::validate( self::draft_ability( $ability ) );
			if ( is_wp_error( $draft ) ) {
				return new WP_Error( 'uich_agent_no_source', 'This ability cannot be exposed on the public site MCP.' );
			}
			$annotations = self::annotations( $ability );
			return array(
				'kind'          => 'ability',
				'name'          => $name,
				'label'         => $ability->get_label(),
				'description'   => $ability->get_description(),
				'category'      => $ability->get_category(),
				'input_schema'  => $ability->get_input_schema(),
				'output_schema' => $ability->get_output_schema(),
				'annotations'   => $annotations,
				'anonymous'     => self::anon_ability( $ability ),
				'draft'         => $draft,
				'notes'         => true === $annotations['readonly']
					? 'Read-only: it can be enabled once tested.'
					: 'Not marked read-only: it will refuse to run on the site MCP until the site owner allows writes on the Agent Ready screen.',
			);
		}

		/* ------------------------------------------------------------------ */
		/* REST routes                                                         */
		/* ------------------------------------------------------------------ */

		/**
		 * Public GET routes without path parameters.
		 *
		 * @param array $covered What existing tools cover.
		 * @return array
		 */
		private static function scan_routes( array $covered ) {
			$by_ns   = array();
			$refused = 0;
			$indexes = array_map(
				function ( $ns ) {
					return '/' . $ns;
				},
				rest_get_server()->get_namespaces()
			);
			foreach ( rest_get_server()->get_routes() as $route => $endpoints ) {
				$endpoint = self::get_endpoint( $endpoints );
				// Namespace index routes only list other routes.
				if ( ! $endpoint || ! self::static_route( $route ) || in_array( $route, $indexes, true ) ) {
					continue;
				}
				$ns = trim( (string) strtok( ltrim( $route, '/' ), '/' ) . '/' . (string) strtok( '/' ), '/' );
				if ( preg_match( self::SKIP_NAMESPACES, ltrim( $route, '/' ) ) ) {
					continue;
				}
				$draft = self::draft_route( $route, $endpoint );
				$check = Uich_Agent_Tools::validate( $draft );
				if ( is_wp_error( $check ) ) {
					++$refused;
					continue;
				}
				$anon = self::anon_route( $route, $endpoint );
				$key  = strtolower( '/' . trim( $route, '/' ) );
				$row  = array(
					'route'      => $route,
					'anonymous'  => $anon,
					'params'     => array_keys( $check['config']['params'] ),
					'covered_by' => isset( $covered['route'][ $key ] ) ? $covered['route'][ $key ] : array(),
				);
				if ( 'allowed' === $anon && ! $row['covered_by'] ) {
					$row['draft'] = $check;
				}
				if ( preg_match( '#(review|comment|feedback|testimonial)#i', $route ) ) {
					$row['caution'] = 'Returns text written by visitors. It can carry names and instructions aimed at agents; expose it only if the owner wants it, and keep output to ratings and short fields.';
				}
				$by_ns[ $ns ][] = $row;
			}
			$out = array();
			foreach ( $by_ns as $ns => $routes ) {
				$public = count(
					array_filter(
						$routes,
						function ( $r ) {
							return 'allowed' === $r['anonymous'];
						}
					)
				);
				$out[] = array(
					'namespace' => $ns,
					'public'    => $public,
					'routes'    => array_slice( $routes, 0, 25 ),
					'more'      => max( 0, count( $routes ) - 25 ),
				);
			}
			return array(
				'namespaces'   => $out,
				'not_eligible' => $refused,
			);
		}

		/**
		 * The GET endpoint of a route, if any.
		 *
		 * @param array $endpoints Route endpoints.
		 * @return array|null
		 */
		private static function get_endpoint( $endpoints ) {
			foreach ( (array) $endpoints as $endpoint ) {
				if ( ! empty( $endpoint['methods']['GET'] ) ) {
					return $endpoint;
				}
			}
			return null;
		}

		/**
		 * Whether a route has no regex path parameters (tool routes are fixed).
		 *
		 * @param string $route Route.
		 * @return bool
		 */
		private static function static_route( $route ) {
			return (bool) preg_match( '#^/[A-Za-z0-9_\-./]+$#', $route );
		}

		/**
		 * Whether a logged-out visitor may GET a route (empty request).
		 *
		 * @param string $route    Route.
		 * @param array  $endpoint Endpoint.
		 * @return string allowed | refused | needs-input
		 */
		private static function anon_route( $route, array $endpoint ) {
			$cb = isset( $endpoint['permission_callback'] ) ? $endpoint['permission_callback'] : null;
			if ( null === $cb || '__return_true' === $cb ) {
				return 'allowed';
			}
			if ( ! is_callable( $cb ) ) {
				return 'refused';
			}
			$result = self::as_visitor(
				function () use ( $cb, $route ) {
					return call_user_func( $cb, new WP_REST_Request( 'GET', $route ) );
				}
			);
			if ( true === $result ) {
				return 'allowed';
			}
			if ( is_wp_error( $result ) && 'uich_agent_check_failed' === $result->get_error_code() ) {
				return 'needs-input';
			}
			return 'refused';
		}

		/**
		 * Draft REST definition from the endpoint's declared args.
		 *
		 * @param string $route    Route.
		 * @param array  $endpoint Endpoint.
		 * @return array
		 */
		private static function draft_route( $route, array $endpoint ) {
			$params = array();
			$fixed  = array();
			foreach ( isset( $endpoint['args'] ) ? (array) $endpoint['args'] : array() as $arg => $spec ) {
				if ( in_array( $arg, self::SKIP_ARGS, true ) || ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/', (string) $arg ) || ! is_array( $spec ) ) {
					continue;
				}
				$type = isset( $spec['type'] ) ? $spec['type'] : 'string';
				$type = is_array( $type ) ? reset( $type ) : $type;
				if ( ! in_array( $type, array( 'string', 'integer', 'number', 'boolean' ), true ) ) {
					continue;
				}
				// Paging stays pinned so one call cannot ask for everything.
				if ( in_array( $arg, array( 'per_page', 'limit', 'page', 'offset' ), true ) ) {
					if ( in_array( $arg, array( 'per_page', 'limit' ), true ) ) {
						$fixed[ $arg ] = '10';
					}
					continue;
				}
				$param = array(
					'type'        => $type,
					'description' => isset( $spec['description'] ) ? wp_strip_all_tags( (string) $spec['description'] ) : '',
					'required'    => ! empty( $spec['required'] ),
				);
				if ( isset( $spec['enum'] ) && is_array( $spec['enum'] ) ) {
					$param['enum'] = $spec['enum'];
				}
				foreach ( array( 'minimum', 'maximum', 'maxLength' ) as $k ) {
					if ( isset( $spec[ $k ] ) ) {
						$param[ $k ] = $spec[ $k ];
					}
				}
				$params[ $arg ] = $param;
			}
			$slug = trim( preg_replace( '#[^a-z0-9]+#', '_', strtolower( preg_replace( '#/v[0-9]+(?=/|$)#', '', $route ) ) ), '_' );
			return array(
				'name'        => self::tool_name( 'get_' . $slug ),
				'title'       => ucfirst( str_replace( '_', ' ', $slug ) ),
				'description' => sprintf( 'Read %s from this site. DRAFT: rewrite to say what it returns and when an agent should use it.', $route ),
				'type'        => 'rest',
				'config'      => array(
					'route'  => $route,
					'params' => array_slice( $params, 0, 15, true ),
					'fixed'  => $fixed,
					'output' => array(),
				),
			);
		}

		/**
		 * Describe a route, with the shape of a real visitor response.
		 *
		 * @param string $route Route.
		 * @return array|WP_Error
		 */
		private static function describe_route( $route ) {
			$routes   = rest_get_server()->get_routes();
			$endpoint = isset( $routes[ $route ] ) ? self::get_endpoint( $routes[ $route ] ) : null;
			if ( ! $endpoint || ! self::static_route( $route ) ) {
				return new WP_Error( 'uich_agent_no_source', 'No public GET route with that exact path.' );
			}
			$draft = Uich_Agent_Tools::validate( self::draft_route( $route, $endpoint ) );
			if ( is_wp_error( $draft ) ) {
				return new WP_Error( 'uich_agent_no_source', 'This route cannot be exposed on the public site MCP.' );
			}
			$anon  = self::anon_route( $route, $endpoint );
			$shape = array();
			if ( 'allowed' === $anon ) {
				$shape = self::as_visitor(
					function () use ( $route, $draft ) {
						if ( is_wp_error( apply_filters( 'rest_authentication_errors', null ) ) ) {
							return array();
						}
						$request = new WP_REST_Request( 'GET', $route );
						foreach ( $draft['config']['fixed'] as $k => $v ) {
							$request->set_param( $k, $v );
						}
						$response = rest_do_request( $request );
						if ( $response->is_error() ) {
							return array();
						}
						return Uich_Agent_Integrations::shape_of( rest_get_server()->response_to_data( $response, false ) );
					}
				);
				$shape = is_array( $shape ) ? $shape : array();
			}
			return array(
				'kind'           => 'route',
				'name'           => $route,
				'anonymous'      => $anon,
				'args'           => $draft['config']['params'],
				'response_paths' => $shape,
				'draft'          => $draft,
				'notes'          => 'Set config.output to the response paths a visitor needs (for lists, paths are per item). Leave out ids and internal fields.',
			);
		}

		/**
		 * Dotted paths found in a response, two levels deep, with a sample type.
		 * For a list, the first item is described.
		 *
		 * @param mixed $data Response data.
		 * @return array
		 */
		public static function shape_of( $data ) {
			$list = is_array( $data ) && array_keys( $data ) === range( 0, count( $data ) - 1 );
			$row  = $list ? ( isset( $data[0] ) ? $data[0] : array() ) : $data;
			$out  = array();
			if ( ! is_array( $row ) ) {
				return array();
			}
			foreach ( $row as $k => $v ) {
				if ( is_array( $v ) && $v && array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) {
					foreach ( $v as $k2 => $v2 ) {
						$out[ $k . '.' . $k2 ] = gettype( $v2 );
						if ( count( $out ) >= 60 ) {
							break 2;
						}
					}
				} else {
					$out[ (string) $k ] = gettype( $v );
				}
				if ( count( $out ) >= 60 ) {
					break;
				}
			}
			return array(
				'is_list' => $list,
				'paths'   => $out,
			);
		}

		/* ------------------------------------------------------------------ */
		/* Shared                                                              */
		/* ------------------------------------------------------------------ */

		/**
		 * Run a permission check as a logged-out visitor, restoring the current
		 * user afterwards. A callback that throws or warns is "needs-input".
		 *
		 * @param callable $fn Check.
		 * @return mixed
		 */
		private static function as_visitor( $fn ) {
			$user = get_current_user_id();
			if ( 0 !== $user ) {
				wp_set_current_user( 0 );
			}
			try {
				$result = call_user_func( $fn );
			} catch ( Throwable $e ) {
				$result = new WP_Error( 'uich_agent_check_failed', 'The check needs real input.' );
			}
			if ( 0 !== $user ) {
				wp_set_current_user( $user );
			}
			return $result;
		}

		/**
		 * What existing saved tools already cover.
		 *
		 * @return array { post_type: {slug: [names]}, ability: {...}, route: {...} }
		 */
		private static function covered() {
			$out = array(
				'post_type' => array(),
				'ability'   => array(),
				'route'     => array(),
			);
			foreach ( Uich_Agent_Tools::definitions() as $name => $def ) {
				$c = isset( $def['config'] ) ? (array) $def['config'] : array();
				if ( ! empty( $c['post_type'] ) ) {
					$out['post_type'][ $c['post_type'] ][] = $name;
				} elseif ( ! empty( $c['ability'] ) ) {
					$out['ability'][ $c['ability'] ][] = $name;
				} elseif ( ! empty( $c['route'] ) ) {
					$out['route'][ strtolower( $c['route'] ) ][] = $name;
				}
			}
			return $out;
		}

		/**
		 * Validate a draft; return the normalised draft or the reason it fails.
		 *
		 * @param array $draft Draft.
		 * @return array
		 */
		private static function checked( array $draft ) {
			$valid = Uich_Agent_Tools::validate( $draft );
			return is_wp_error( $valid ) ? array( 'error' => $valid->get_error_message() ) : $valid;
		}

		/**
		 * A free, valid tool name from a base.
		 *
		 * @param string $base Base.
		 * @return string
		 */
		private static function tool_name( $base ) {
			$base = trim( preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $base ) ), '_' );
			$base = preg_match( '/^[a-z]/', $base ) ? $base : 't_' . $base;
			$base = substr( $base, 0, 44 );
			$base = strlen( $base ) < 3 ? $base . '_tool' : $base;

			// Names drafted earlier in this scan count as taken too, so two routes
			// that read alike (/wc/store/x and /wc/store/v1/x) get different names.
			static $drafted = array();
			$taken = array_merge( array_keys( Uich_Agent_Tools::definitions() ), Uich_Agent_Builtin_Tools::names(), array_keys( Uich_Agent_Builtin_Tools::tools( true ) ), $drafted );
			$name  = $base;
			for ( $i = 2; in_array( $name, $taken, true ) && $i < 100; $i++ ) {
				$name = $base . '_' . $i;
			}
			$drafted[] = $name;
			return $name;
		}

		/**
		 * Draft description from a plugin's own text.
		 *
		 * @param string $text  Description.
		 * @param string $label Label.
		 * @return string
		 */
		private static function draft_text( $text, $label ) {
			$text = trim( wp_strip_all_tags( (string) $text ) );
			$text = '' === $text ? (string) $label : $text;
			return wp_html_excerpt( $text, 400, '…' ) . ' DRAFT: rewrite for visitors\' agents.';
		}

		/**
		 * Top-level output paths from a JSON schema (object properties, or the
		 * properties of list items).
		 *
		 * @param array $schema Schema.
		 * @return string[]
		 */
		private static function schema_paths( $schema ) {
			$schema = (array) $schema;
			if ( isset( $schema['items']['properties'] ) ) {
				$schema = $schema['items'];
			}
			if ( empty( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
				return array();
			}
			$out = array();
			foreach ( array_keys( $schema['properties'] ) as $key ) {
				if ( ! preg_match( Uich_Agent_Tools::SENSITIVE_META, (string) $key ) ) {
					$out[] = (string) $key;
				}
			}
			return array_slice( $out, 0, 20 );
		}

		/**
		 * Active plugins: name, slug and version only.
		 *
		 * @return array
		 */
		private static function active_plugins() {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all = get_plugins();
			$out = array();
			foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
				if ( isset( $all[ $file ] ) ) {
					$out[] = array(
						'name'    => $all[ $file ]['Name'],
						'slug'    => dirname( $file ),
						'version' => $all[ $file ]['Version'],
					);
				}
			}
			return $out;
		}
	}
}
