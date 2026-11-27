<?php
/**
 * Built-in site MCP tools: what every agent-ready site offers without setup.
 *
 * All read-only and safe for anonymous callers: they return only what a
 * logged-out visitor can already see. Each one can be switched off in the MCP
 * tools settings (mcp.disabled_builtins). WooCommerce tools appear only when
 * WooCommerce is active; payment always happens on the store's own checkout.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Builtin_Tools' ) ) {

	/**
	 * Built-in tools.
	 */
	class Uich_Agent_Builtin_Tools {

		/**
		 * Every built-in name, whether or not it is available here.
		 *
		 * @return string[]
		 */
		public static function names() {
			return array( 'get_site_info', 'search_site', 'get_page', 'list_languages', 'search_products', 'get_product', 'build_checkout_link' );
		}

		/**
		 * The built-in tools that are available and switched on.
		 *
		 * @param bool $include_off Also return core tools the owner switched off (for the dashboard list).
		 * @return array name => tool
		 */
		public static function tools( $include_off = false ) {
			$off   = $include_off ? array() : (array) Uich_Agent_Settings::value( 'mcp.disabled_builtins', array() );
			$read  = array(
				'readOnlyHint'    => true,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			);
			$tools = array();

			$tools['get_site_info'] = array(
				'title'        => 'Site information',
				'description'  => 'Who this site is: name, tagline, address, languages, contact details and what an agent can do here. Call this first.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => new stdClass(),
				),
				'run'          => array( __CLASS__, 'site_info' ),
			);

			$types = self::search_types();
			$tools['search_site'] = array(
				'title'        => 'Search the site',
				'description'  => 'Search this site\'s public pages and posts. Returns titles, links, short excerpts and the agent (Markdown) link of each result.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => 100,
							'description' => 'Words to search for.',
						),
						'type'  => array(
							'type'        => 'string',
							'enum'        => $types,
							'description' => 'Only this kind of content.',
						),
						'page'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 50,
							'description' => 'Result page, from 1.',
						),
					),
					'required'   => array( 'query' ),
				),
				'run'          => array( __CLASS__, 'search_site' ),
			);

			$tools['get_page'] = array(
				'title'        => 'Read a page',
				'description'  => 'Read any public page of this site as clean Markdown (the same text a visitor sees, without menus or scripts).',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array(
							'type'        => 'string',
							'maxLength'   => 500,
							'description' => 'Page URL or path on this site, e.g. "/about/".',
						),
					),
					'required'   => array( 'url' ),
				),
				'run'          => array( __CLASS__, 'get_page' ),
			);

			$tools['list_languages'] = array(
				'title'        => 'Languages',
				'description'  => 'The languages this site is available in, with the address of each language\'s home page.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => new stdClass(),
				),
				'run'          => array( __CLASS__, 'languages' ),
			);

			if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) ) {
				$tools['search_products'] = array(
					'title'        => 'Search products',
					'description'  => 'Search the shop: product names, prices, stock and links. Filter by category slug, price range or in-stock only.',
					'input_schema' => array(
						'type'       => 'object',
						'properties' => array(
							'query'     => array(
								'type'        => 'string',
								'maxLength'   => 100,
								'description' => 'Words to search for.',
							),
							'category'  => array(
								'type'        => 'string',
								'maxLength'   => 100,
								'description' => 'Product category slug.',
							),
							'min_price' => array(
								'type'        => 'number',
								'description' => 'Lowest price.',
							),
							'max_price' => array(
								'type'        => 'number',
								'description' => 'Highest price.',
							),
							'in_stock'  => array(
								'type'        => 'boolean',
								'description' => 'Only products in stock.',
							),
							'page'      => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => 50,
								'description' => 'Result page, from 1.',
							),
						),
					),
					'run'          => array( __CLASS__, 'search_products' ),
				);
				$tools['get_product'] = array(
					'title'        => 'Product details',
					'description'  => 'Full details of one product: price, stock, description, attributes and variations.',
					'input_schema' => array(
						'type'       => 'object',
						'properties' => array(
							'id'   => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'Product ID.',
							),
							'slug' => array(
								'type'        => 'string',
								'maxLength'   => 200,
								'description' => 'Product slug (instead of id).',
							),
						),
					),
					'run'          => array( __CLASS__, 'get_product' ),
				);
				$tools['build_checkout_link'] = array(
					'title'        => 'Checkout link',
					'description'  => 'Make a link that opens this shop\'s checkout with the chosen products already in the cart. Nothing is bought: the person reviews and pays on the shop\'s own checkout page.',
					'input_schema' => array(
						'type'       => 'object',
						'properties' => array(
							'items' => array(
								'type'        => 'array',
								'maxItems'    => 10,
								'description' => 'Products and quantities.',
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'product_id' => array(
											'type'    => 'integer',
											'minimum' => 1,
										),
										'quantity'   => array(
											'type'    => 'integer',
											'minimum' => 1,
											'maximum' => 20,
										),
									),
									'required'   => array( 'product_id' ),
								),
							),
						),
						'required'   => array( 'items' ),
					),
					'run'          => array( __CLASS__, 'checkout_link' ),
				);
			}

			$out = array();
			foreach ( $tools as $name => $tool ) {
				if ( in_array( $name, $off, true ) ) {
					continue;
				}
				$out[ $name ] = array_merge(
					$tool,
					array(
						'name'        => $name,
						'annotations' => $read,
						'source'      => 'builtin',
						'write'       => false,
					)
				);
			}

			/**
			 * Filter the built-in tools (later parts add check_open_now, write tools).
			 *
			 * @param array $out name => tool.
			 */
			return apply_filters( 'uichemy_agent_ready_builtin_tools', $out );
		}

		/**
		 * Post types agents may search: public, not blocked, and included in agent pages.
		 *
		 * @return string[]
		 */
		private static function search_types() {
			$types = array();
			foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
				if ( ! Uich_Agent_Tools::listing_blocked( $type ) ) {
					$types[] = $type;
				}
			}
			return $types ? array_values( $types ) : array( 'page', 'post' );
		}

		/* -------------------------------------------------------------
		 * Runners
		 * ----------------------------------------------------------- */

		/**
		 * Tool: get_site_info.
		 *
		 * @return array
		 */
		public static function site_info() {
			$info = array(
				'name'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'tagline'      => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
				'url'          => home_url( '/' ),
				'language'     => str_replace( '_', '-', get_locale() ),
				'agent_guide'  => home_url( '/llms.txt' ),
				'agent_pages'  => 'Add .md to any page address (e.g. ' . home_url( '/index.md' ) . ' for the home page) or send Accept: text/markdown to read it as Markdown.',
				'shop'         => class_exists( 'WooCommerce' ),
				'tools'        => array_keys( Uich_Agent_Tools::enabled() ),
			);

			/**
			 * Add facts to get_site_info (the site profile adds contact, hours, locations).
			 *
			 * @param array $info Site information.
			 */
			return apply_filters( 'uichemy_agent_ready_site_info', $info );
		}

		/**
		 * Tool: search_site.
		 *
		 * @param array $args Arguments.
		 * @return array
		 */
		public static function search_site( array $args ) {
			$words = isset( $args['query'] ) ? trim( (string) $args['query'] ) : '';
			if ( '' === $words ) {
				return new WP_Error( 'uich_agent_bad_args', 'Send one or more words to search for.' );
			}
			$args['query'] = $words;
			$types         = self::search_types();
			if ( ! empty( $args['type'] ) && ! in_array( (string) $args['type'], $types, true ) ) {
				return new WP_Error( 'uich_agent_bad_args', sprintf( 'Unknown type. Use one of: %s.', implode( ', ', $types ) ) );
			}
			$query = new WP_Query(
				array(
					's'                   => $args['query'],
					'post_type'           => ! empty( $args['type'] ) ? $args['type'] : $types,
					'post_status'         => 'publish',
					'has_password'        => false,
					'ignore_sticky_posts' => true,
					'posts_per_page'      => (int) Uich_Agent_Settings::value( 'mcp.max_results', 20 ),
					'paged'               => isset( $args['page'] ) ? (int) $args['page'] : 1,
					'meta_query'          => Uich_Agent_Access::meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- hidden-from-agents flags.
				)
			);
			$items = array();
			// Same rules as the agent page: noindex, exclusions, hidden, members-only.
			foreach ( Uich_Agent_Access::filter_listable( $query->posts ) as $post ) {
				$url     = get_permalink( $post );
				$items[] = array(
					'title'     => get_the_title( $post ),
					'type'      => $post->post_type,
					'url'       => $url,
					'agent_url' => Uich_Agent_Urls::md_url( $url ),
					'excerpt'   => Uich_Agent_Access::public_text( $post, 30, true ),
					'date'      => gmdate( 'c', strtotime( $post->post_date_gmt . ' UTC' ) ),
				);
			}
			return array(
				'items' => $items,
				'total' => (int) $query->found_posts,
				'pages' => (int) $query->max_num_pages,
			);
		}

		/**
		 * Tool: get_page.
		 *
		 * @param array $args Arguments.
		 * @return array|WP_Error
		 */
		public static function get_page( array $args ) {
			$url = trim( $args['url'] );
			if ( '/' === $url[0] ) {
				$url = home_url( $url );
			}
			if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				return new WP_Error( 'uich_agent_bad_args', 'Only pages on this site can be read.' );
			}
			$md   = Uich_Agent_Urls::md_url( $url );
			$resp = Uich_Agent_Urls::fetch_own( $md );
			if ( is_wp_error( $resp ) ) {
				return new WP_Error( 'uich_agent_page_failed', 'The page could not be read right now.' );
			}
			$status = (int) wp_remote_retrieve_response_code( $resp );
			if ( 200 !== $status ) {
				return new WP_Error( 'uich_agent_page_missing', 'There is no public page at that address.' );
			}
			$body = (string) wp_remote_retrieve_body( $resp );
			return array(
				'url'       => $url,
				'agent_url' => $md,
				'markdown'  => mb_substr( $body, 0, 50000 ),
				'truncated' => mb_strlen( $body ) > 50000,
			);
		}

		/**
		 * Readable language name for a locale code ("en_US" to "English (United States)").
		 *
		 * @param string $code Locale.
		 * @return string
		 */
		private static function language_name( $code ) {
			if ( '' === $code || 'en_US' === $code ) {
				return 'English';
			}
			// No network lookup: use PHP intl when present, else the code.
			return class_exists( 'Locale' ) ? (string) Locale::getDisplayName( $code, 'en' ) : $code;
		}

		/**
		 * Tool: list_languages.
		 *
		 * @return array
		 */
		public static function languages() {
			$items = array();
			if ( class_exists( 'Uich_L10n_Languages' ) && method_exists( 'Uich_L10n_Languages', 'all' ) ) {
				foreach ( (array) Uich_L10n_Languages::all() as $code => $lang ) {
					$items[] = array(
						'code' => (string) $code,
						'name' => is_array( $lang ) && isset( $lang['name'] ) ? (string) $lang['name'] : self::language_name( (string) $code ),
						'url'  => is_array( $lang ) && ! empty( $lang['slug'] ) ? home_url( '/' . $lang['slug'] . '/' ) : home_url( '/' ),
					);
				}
			}
			if ( ! $items ) {
				$items[] = array(
					'code' => str_replace( '_', '-', get_locale() ),
					'name' => self::language_name( get_locale() ),
					'url'  => home_url( '/' ),
				);
			}
			return array( 'items' => $items );
		}

		/**
		 * Tool: search_products.
		 *
		 * @param array $args Arguments.
		 * @return array
		 */
		public static function search_products( array $args ) {
			$query = array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => (int) Uich_Agent_Settings::value( 'mcp.max_results', 20 ),
				'paged'          => isset( $args['page'] ) ? (int) $args['page'] : 1,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- catalogue visibility.
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => array( 'exclude-from-catalog', 'exclude-from-search' ),
						'operator' => 'NOT IN',
					),
				),
				'meta_query'     => Uich_Agent_Access::meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- hidden flags, price and stock filters.
			);
			if ( ! empty( $args['query'] ) ) {
				$query['s'] = $args['query'];
			}
			if ( ! empty( $args['category'] ) ) {
				$query['tax_query'][] = array(
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => sanitize_title( $args['category'] ),
				);
			}
			if ( isset( $args['min_price'] ) || isset( $args['max_price'] ) ) {
				$query['meta_query'][] = array(
					'key'     => '_price',
					'value'   => array( isset( $args['min_price'] ) ? (float) $args['min_price'] : 0, isset( $args['max_price'] ) ? (float) $args['max_price'] : PHP_INT_MAX ),
					'compare' => 'BETWEEN',
					'type'    => 'DECIMAL(20,4)',
				);
			}
			if ( ! empty( $args['in_stock'] ) ) {
				$query['meta_query'][] = array(
					'key'   => '_stock_status',
					'value' => 'instock',
				);
			}
			$q     = new WP_Query( $query );
			$items = array();
			foreach ( Uich_Agent_Access::filter( $q->posts ) as $post ) {
				$product = wc_get_product( $post );
				if ( $product ) {
					$items[] = self::product_summary( $product );
				}
			}
			return array(
				'items'    => $items,
				'total'    => (int) $q->found_posts,
				'pages'    => (int) $q->max_num_pages,
				'currency' => get_woocommerce_currency(),
			);
		}

		/**
		 * Tool: get_product.
		 *
		 * @param array $args Arguments.
		 * @return array|WP_Error
		 */
		public static function get_product( array $args ) {
			$product = null;
			if ( ! empty( $args['id'] ) ) {
				$product = wc_get_product( (int) $args['id'] );
			} elseif ( ! empty( $args['slug'] ) ) {
				$post    = get_page_by_path( sanitize_title( $args['slug'] ), OBJECT, 'product' );
				$product = $post ? wc_get_product( $post ) : null;
			}
			if ( ! $product || ! self::visible( $product ) ) {
				return new WP_Error( 'uich_agent_not_found', 'No such product.' );
			}
			$data                = self::product_summary( $product );
			$data['description'] = Uich_Agent_Convert::plain_text( $product->get_description(), 300 );
			$data['sku']         = $product->get_sku();
			$data['attributes']  = array();
			foreach ( $product->get_attributes() as $attr ) {
				if ( ! $attr->get_visible() ) {
					continue;
				}
				$data['attributes'][ wc_attribute_label( $attr->get_name() ) ] = $attr->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) ) : $attr->get_options();
			}
			if ( $product->is_type( 'variable' ) ) {
				$data['variations'] = array();
				foreach ( array_slice( $product->get_children(), 0, 50 ) as $vid ) {
					$v = wc_get_product( $vid );
					if ( $v && 'publish' === $v->get_status() ) {
						$data['variations'][] = array(
							'id'           => $v->get_id(),
							'attributes'   => $v->get_attributes(),
							'price'        => $v->get_price(),
							'stock_status' => $v->get_stock_status(),
						);
					}
				}
			}
			return $data;
		}

		/**
		 * Tool: build_checkout_link.
		 *
		 * @param array $args Arguments.
		 * @return array|WP_Error
		 */
		public static function checkout_link( array $args ) {
			$parts = array();
			$lines = array();
			foreach ( $args['items'] as $item ) {
				$product = wc_get_product( (int) $item['product_id'] );
				if ( ! $product || ! self::visible( $product ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
					return new WP_Error( 'uich_agent_bad_args', sprintf( 'Product %d cannot be bought right now.', (int) $item['product_id'] ) );
				}
				if ( $product->is_type( 'variable' ) ) {
					return new WP_Error( 'uich_agent_bad_args', sprintf( 'Product %d has options; pick a variation ID from get_product.', $product->get_id() ) );
				}
				$qty     = isset( $item['quantity'] ) ? max( 1, min( 20, (int) $item['quantity'] ) ) : 1;
				$parts[] = $product->get_id() . ':' . $qty;
				$lines[] = array(
					'product_id' => $product->get_id(),
					'name'       => $product->get_name(),
					'quantity'   => $qty,
					'price'      => $product->get_price(),
				);
			}
			// WooCommerce's shareable checkout links (core since 10.0).
			$url = add_query_arg( 'products', implode( ',', $parts ), home_url( '/checkout-link/' ) );
			return array(
				'url'      => $url,
				'items'    => $lines,
				'currency' => get_woocommerce_currency(),
				'note'     => 'Opening this link fills the cart and shows the checkout. The person reviews and pays there; nothing is bought until they do.',
			);
		}

		/**
		 * Summary fields of a product.
		 *
		 * @param WC_Product $product Product.
		 * @return array
		 */
		private static function product_summary( $product ) {
			$url   = $product->get_permalink();
			$image = $product->get_image_id();
			return array(
				'id'                => $product->get_id(),
				'name'              => $product->get_name(),
				'type'              => $product->get_type(),
				'url'               => $url,
				'agent_url'         => Uich_Agent_Urls::md_url( $url ),
				'price'             => $product->get_price(),
				'regular_price'     => $product->get_regular_price(),
				'sale_price'        => $product->get_sale_price(),
				'on_sale'           => $product->is_on_sale(),
				'stock_status'      => $product->get_stock_status(),
				'purchasable'       => $product->is_purchasable(),
				'short_description' => Uich_Agent_Convert::plain_text( $product->get_short_description(), 60 ),
				'categories'        => wp_list_pluck( (array) get_the_terms( $product->get_id(), 'product_cat' ), 'name' ),
				'image'             => $image ? array(
					'url' => wp_get_attachment_image_url( $image, 'large' ),
					'alt' => (string) get_post_meta( $image, '_wp_attachment_image_alt', true ),
				) : null,
			);
		}

		/**
		 * Whether a product is publicly listed.
		 *
		 * @param WC_Product $product Product.
		 * @return bool
		 */
		private static function visible( $product ) {
			if ( 'hidden' === $product->get_catalog_visibility() || ! Uich_Agent_Access::visible( $product->get_id() ) ) {
				return false;
			}
			// A variation's own post stays "publish" whatever its product is: the parent's
			// status, password, hidden flag and membership rules decide.
			$parent = (int) $product->get_parent_id();
			if ( $parent && ( $product->is_type( 'variation' ) || 'product_variation' === get_post_type( $product->get_id() ) ) ) {
				$parent_product = wc_get_product( $parent );
				return $parent_product && 'hidden' !== $parent_product->get_catalog_visibility() && Uich_Agent_Access::visible( $parent );
			}
			return true;
		}
	}
}
