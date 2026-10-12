<?php
/**
 * Site SEO settings.
 *
 * Two homes, one API:
 *   - Values that ARE head tags (title template, social fallbacks, verification
 *     codes, the site Organization / Person) live in the site's before-</head>
 *     code as real tags — see Uich_SEO_Head::SITE_KEYS. Reading them here parses
 *     that code; writing them rewrites those tags.
 *   - Behaviour switches with no tag (sitemap, robots.txt, redirects, AI formats,
 *     content-type rules) stay in the option `uichemy_seo_site`, keyed by language
 *     (`{ v, langs: { default: {...} } }`).
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Settings' ) ) {

	/**
	 * Site-wide SEO settings.
	 */
	class Uich_SEO_Settings {

		const OPTION = 'uichemy_seo_site';

		/**
		 * Request cache.
		 *
		 * @var array|null
		 */
		private static $cache = null;

		/**
		 * Defaults (PRD §6, §7, §10–§12, §19).
		 *
		 * @return array
		 */
		public static function defaults() {
			return array(
				// WordPress's own "Search engine visibility" (Settings → Reading), read and
				// written through to the `blog_public` option, never stored here.
				'blog_public'          => true,
				// WordPress's own Site Title and Tagline (Settings → General, `blogname`
				// and `blogdescription`): {{ site.title }} / {{ site.tagline }} in titles,
				// the WebSite schema, and og:site_name unless the site head code sets its
				// own. Read and written through, never stored here.
				'site_title'           => '',
				'site_tagline'         => '',

				// §12 Advanced.
				'module_enabled'       => true,
				'head_owner'           => 'auto', // auto | uichemy | external | off.

				// §6.1 Site identity.
				'entity_type'          => 'organization', // organization | person.
				'entity_name'          => '',
				'entity_logo'          => 0,
				'entity_description'   => '',
				'entity_email'         => '',
				'entity_phone'         => '',
				'social_profiles'      => array(),

				// §6.2 Titles and descriptions.
				// Titles (same options as SureRank's "Title and description", in Twig).
				'title_separator'      => '|',
				'title_template'       => '{{ title }} {{ site.separator }} {{ site.title }}',
				'home_description'     => '',
				// Link previews only: og:description for any page whose own text gives
				// none. Never used for search descriptions or titles.
				'default_social_description' => '',
				// Link previews only: og:title template for pages without their own
				// og:title; {{ title }} is the page's own title.
				'default_social_title' => '',
				'default_share_image'  => 0,

				// §6.3 + §19.1 Social sharing.
				'og_enabled'           => true,
				'twitter_enabled'      => true,
				'twitter_card'         => 'large', // large | small.
				'site_name_override'   => '',
				'og_locale'            => '',
				'twitter_handle'       => '',

				// §6.4 Archives and search pages.
				'noindex_author'       => true,
				'noindex_date'         => true,
				'noindex_search'       => true,
				'noindex_paginated'    => false,
				'redirect_attachments' => true,

				// 08 §3 S10 Webmaster verification.
				'verify_google'        => '',
				'verify_bing'          => '',
				'verify_yandex'        => '',
				'verify_baidu'         => '',
				'verify_pinterest'     => '',

				// §7 Content types — sparse: absent row = computed default.
				'types'                => array(),
				'taxonomies'           => array(),

				// §10 AI formats.
				'llms_enabled'         => false,
				'llms_limit'           => 100,
				'llms_intro'           => '',
				'llms_extra'           => '',
				'md_enabled'           => false,
				'md_template'          => '',

				// 08 §5.3 feature toggle.
				'schema_enabled'       => true,
				'schema_auto'          => array(), // { site|website|webpage|breadcrumbs|article: { off, extra } }.

				// §11 Crawling.
				'robots_mode'          => 'wordpress', // wordpress | custom.
				'robots_content'       => '',
				'sitemap_sync'         => true,

				// §9 Redirects.
				'redirects_enabled'    => true,
				'auto_redirect'        => true,
				'record_404'           => true,
			);
		}

		/**
		 * All settings for the default language, merged over defaults.
		 *
		 * @return array
		 */
		public static function all() {
			if ( null !== self::$cache ) {
				return self::$cache;
			}
			$raw  = get_option( self::OPTION, array() );
			$lang = ( is_array( $raw ) && isset( $raw['langs']['default'] ) && is_array( $raw['langs']['default'] ) ) ? $raw['langs']['default'] : array();
			// Tag values are never read from the option: the site head code is their only home.
			$lang        = array_diff_key( $lang, array_flip( Uich_SEO_Head::SITE_KEYS ), array( 'blog_public' => 1, 'site_title' => 1, 'site_tagline' => 1 ) );
			self::$cache = array_merge(
				self::defaults(),
				$lang,
				Uich_SEO_Head::site_settings(),
				array(
					'blog_public'  => self::site_public(),
					'site_title'   => self::site_name(),
					'site_tagline' => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
				)
			);
			return self::$cache;
		}

		/**
		 * One setting.
		 *
		 * @param string $key     Key.
		 * @param mixed  $default Fallback.
		 * @return mixed
		 */
		public static function get( $key, $default = null ) {
			$all = self::all();
			return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
		}

		/**
		 * Merge a patch into the stored settings. Unknown keys are dropped.
		 *
		 * @param array $patch Partial settings.
		 * @return array|WP_Error The saved settings, or errors keyed by field.
		 */
		public static function update( array $patch ) {
			$clean = self::sanitize( $patch );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			// Two saves at once (two admins, two tabs) each read the stored settings, merge
			// their own change and write it back; without a lock the later write erases the
			// earlier one while both report success.
			$locked = self::lock();
			try {
				if ( $locked ) {
					self::flush();
					wp_cache_delete( 'alloptions', 'options' );
					wp_cache_delete( self::OPTION, 'options' );
				}
				return self::apply( $clean );
			} finally {
				if ( $locked ) {
					self::unlock();
				}
			}
		}

		const LOCK = 'uichemy_seo_settings_lock';

		/**
		 * Take the settings write lock (atomic INSERT IGNORE; a lock older than 30 s is stale).
		 *
		 * @return bool Got it. False after ~5 s: write anyway rather than lose the save.
		 */
		private static function lock() {
			global $wpdb;
			$quiet = $wpdb->suppress_errors( true ); // A lock deadlock is retried, not reported.
			for ( $i = 0; $i < 50; $i++ ) {
				$won = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $won ) {
					$wpdb->suppress_errors( $quiet );
					return true;
				}
				$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $held && time() - $held > 30 ) {
					$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					continue;
				}
				usleep( 100000 );
			}
			$wpdb->suppress_errors( $quiet );
			return false;
		}

		/**
		 * Release the settings write lock.
		 *
		 * @return void
		 */
		private static function unlock() {
			global $wpdb;
			$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		/**
		 * The merge-and-write half of update().
		 *
		 * @param array $clean Sanitised patch.
		 * @return array
		 */
		private static function apply( array $clean ) {
			// WordPress's search engine visibility lives in its own option.
			if ( array_key_exists( 'blog_public', $clean ) ) {
				update_option( 'blog_public', $clean['blog_public'] ? '1' : '0' );
				unset( $clean['blog_public'] );
			}
			// …and so do WordPress's Site Title and Tagline.
			if ( array_key_exists( 'site_title', $clean ) ) {
				update_option( 'blogname', $clean['site_title'] );
				unset( $clean['site_title'] );
			}
			if ( array_key_exists( 'site_tagline', $clean ) ) {
				update_option( 'blogdescription', $clean['site_tagline'] );
				unset( $clean['site_tagline'] );
			}
			// Tag values go to the site head code as tags.
			$tags = array_intersect_key( $clean, array_flip( Uich_SEO_Head::SITE_KEYS ) );
			if ( $tags ) {
				Uich_SEO_Head::update_site( $tags );
			}
			$raw = get_option( self::OPTION, array() );
			if ( ! is_array( $raw ) ) {
				$raw = array();
			}
			$current = ( isset( $raw['langs']['default'] ) && is_array( $raw['langs']['default'] ) ) ? $raw['langs']['default'] : array();
			$current = array_diff_key( $current, array_flip( Uich_SEO_Head::SITE_KEYS ) );
			foreach ( array_diff_key( $clean, $tags ) as $k => $v ) {
				if ( in_array( $k, array( 'types', 'taxonomies' ), true ) ) {
					$merged = isset( $current[ $k ] ) && is_array( $current[ $k ] ) ? $current[ $k ] : array();
					foreach ( $v as $slug => $row ) {
						// Only real content types / taxonomies are stored.
						if ( 'types' === $k ? ! post_type_exists( $slug ) : ! taxonomy_exists( $slug ) ) {
							continue;
						}
						$merged[ $slug ] = array_merge( isset( $merged[ $slug ] ) ? $merged[ $slug ] : array(), $row );
					}
					$current[ $k ] = $merged;
				} else {
					$current[ $k ] = $v;
				}
			}
			$raw['v']                = 1;
			$raw['langs']['default'] = $current;
			update_option( self::OPTION, $raw, true );
			self::$cache = null;
			do_action( 'uichemy_seo_settings_updated', $clean );
			return self::all();
		}

		/**
		 * Does WordPress let search engines index this site (Settings → Reading)?
		 * Off means WordPress adds noindex, nofollow to every page and turns its
		 * sitemap off (robots.txt stays open so crawlers can see the noindex);
		 * UiChemy honours it.
		 *
		 * @return bool
		 */
		public static function site_public() {
			return (bool) get_option( 'blog_public', 1 );
		}

		/**
		 * Reset every site setting (PRD §12.2). Page records are untouched.
		 *
		 * @return void
		 */
		public static function reset() {
			delete_option( self::OPTION );
			// Reset clears what Site Settings manages (the tags and the organisation or
			// person). Other JSON-LD in the site head was written by hand or by an agent,
			// is not a setting, and has no other copy: keep it.
			$blank           = Uich_SEO_Head::blank();
			$blank['ldjson'] = Uich_SEO_Head::site_extra_entities();
			Uich_SEO_Head::write_site( $blank );
			self::$cache = null;
		}

		/**
		 * Forget the request cache (tests).
		 *
		 * @return void
		 */
		public static function flush() {
			self::$cache = null;
			Uich_SEO_Head::flush();
		}

		/**
		 * Sanitise a patch. Returns WP_Error with per-field messages (PRD copy)
		 * when a value is invalid.
		 *
		 * @param array $patch Patch.
		 * @return array|WP_Error
		 */
		public static function sanitize( array $patch ) {
			$defaults = self::defaults();
			$out      = array();
			$errors   = new WP_Error();

			foreach ( $patch as $key => $value ) {
				if ( ! array_key_exists( $key, $defaults ) ) {
					continue;
				}
				$def = $defaults[ $key ];
				// Wrong-typed input (an array for a text setting, an object for a list…)
				// is refused, never turned into the text "Array".
				if ( is_array( $def ) ? ( null !== $value && ! is_array( $value ) ) : ( null !== $value && ! is_scalar( $value ) ) ) {
					$errors->add( $key, is_array( $def ) ? __( 'This setting expects a list.', 'uichemy' ) : __( 'This setting expects text, a number or on/off.', 'uichemy' ) );
					continue;
				}
				// A bad enum value is a field error, never a silent fallback; null = default.
				$enums = array(
					'head_owner'   => array( 'auto', 'uichemy', 'external', 'off' ),
					'entity_type'  => array( 'organization', 'person' ),
					'twitter_card' => array( 'large', 'small' ),
					'robots_mode'  => array( 'wordpress', 'custom' ),
				);
				if ( isset( $enums[ $key ] ) ) {
					if ( null === $value ) {
						$value = $def;
					} elseif ( ! in_array( $value, $enums[ $key ], true ) ) {
						/* translators: %s: allowed values. */
						$errors->add( $key, sprintf( __( 'Choose one of: %s', 'uichemy' ), implode( ', ', $enums[ $key ] ) ) );
						continue;
					}
					$out[ $key ] = $value;
					continue;
				}
				switch ( $key ) {
					case 'entity_email':
						$raw   = trim( (string) $value );
						$value = sanitize_email( $raw );
						if ( '' !== $raw && ( '' === $value || ! is_email( $value ) ) ) {
							$errors->add( 'entity_email', __( 'Enter a full email address, for example hello@example.com', 'uichemy' ) );
						}
						break;
					case 'verify_google':
					case 'verify_bing':
					case 'verify_yandex':
					case 'verify_baidu':
					case 'verify_pinterest':
						// People paste the whole <meta … content="CODE"> tag: keep just the code.
						// Tokens are letters, digits, - and _ (Google's are base64url; Bing,
						// Yandex and Pinterest hex; Baidu "code-…"); older Google tokens may
						// also carry base64's + / = — nothing else survives.
						$raw = trim( (string) $value );
						if ( preg_match( '/content\s*=\s*["\']([^"\']*)["\']/i', $raw, $m ) ) {
							$raw = $m[1];
						}
						$value = preg_replace( '/[^A-Za-z0-9_\-.=+\/]/', '', trim( wp_strip_all_tags( $raw ) ) );
						break;
					case 'site_title':
						$value = trim( sanitize_text_field( (string) $value ) );
						if ( '' === $value ) {
							$errors->add( 'site_title', __( 'Your site needs a title.', 'uichemy' ) );
							continue 2;
						}
						break;
					case 'site_tagline':
						$value = trim( sanitize_text_field( (string) $value ) );
						break;
					case 'entity_phone':
						$value = trim( sanitize_text_field( (string) $value ) );
						if ( '' !== $value ) {
							$digits = strlen( preg_replace( '/\D/', '', $value ) );
							if ( ! preg_match( '/^\+?[0-9\s().\-]+$/', $value ) ) {
								$errors->add( 'entity_phone', __( 'Use only numbers, spaces and + ( ) - . For example +44 20 7946 0000', 'uichemy' ) );
							} elseif ( $digits < 7 || $digits > 15 ) {
								$errors->add( 'entity_phone', __( 'A phone number has 7 to 15 digits. Include the country code, for example +44 20 7946 0000', 'uichemy' ) );
							}
						}
						break;
					case 'twitter_handle':
						$value = preg_replace( '/[^A-Za-z0-9_]/', '', ltrim( sanitize_text_field( (string) $value ), '@' ) );
						$value = '' === $value ? '' : '@' . $value; // Nothing valid left: no handle, not a bare "@".
						break;
					case 'social_profiles':
						$list = array();
						if ( count( (array) $value ) > 20 ) {
							$errors->add( 'social_profiles', __( 'Add at most 20 social profiles.', 'uichemy' ) );
							continue 2;
						}
						foreach ( (array) $value as $url ) {
							if ( ! is_scalar( $url ) ) {
								$errors->add( 'social_profiles', __( 'Enter a full link, starting with https://', 'uichemy' ) );
								continue;
							}
							$url = trim( (string) $url );
							if ( '' === $url ) {
								continue;
							}
							if ( strlen( $url ) > 2048 ) {
								$errors->add( 'social_profiles', __( 'A link can be at most 2048 characters.', 'uichemy' ) );
								continue;
							}
							// Syntax only: wp_http_validate_url() resolves the host over DNS, so a valid link on a
							// new, internal or .test domain was refused depending on the server.
							$host = wp_parse_url( $url, PHP_URL_HOST );
							if ( ! preg_match( '#^https://#i', $url ) || ! $host || preg_match( '/\s/', $url ) || '' === esc_url_raw( $url ) ) {
								$errors->add( 'social_profiles', __( 'Enter a full link, starting with https://', 'uichemy' ) );
								continue;
							}
							$list[] = esc_url_raw( $url );
						}
						$value = array_values( array_unique( $list ) );
						break;
					case 'llms_limit':
						if ( ! is_numeric( $value ) ) {
							$errors->add( $key, __( 'Enter a number from 1 to 500.', 'uichemy' ) );
							continue 2;
						}
						$value = max( 1, min( 500, (int) $value ) );
						break;
					case 'entity_logo':
					case 'default_share_image':
						// A media-library id, or a full image link.
						$value = is_numeric( $value ) ? absint( $value ) : esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
						break;
					case 'default_social_title':
						$value = trim( sanitize_text_field( (string) $value ) );
						$bad   = self::template_error( $value );
						if ( $bad ) {
							$errors->add( $key, $bad );
							continue 2;
						}
						$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 300 ) : substr( $value, 0, 300 );
						break;
					case 'default_social_description':
						$value = sanitize_textarea_field( (string) $value );
						$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 600 ) : substr( $value, 0, 600 );
						break;
					case 'entity_description':
					case 'home_description':
					case 'llms_intro':
					case 'llms_extra':
					case 'md_template':
						$value = sanitize_textarea_field( (string) $value );
						break;
					case 'robots_content':
						// robots.txt is plain text; keep line breaks, strip tags.
						$value = sanitize_textarea_field( (string) $value );
						break;
					case 'schema_auto':
						$value = Uich_SEO_Schema::sanitize_auto( (array) $value, $errors );
						break;
					case 'types':
					case 'taxonomies':
						$value = self::sanitize_matrix( (array) $value, 'types' === $key );
						break;
					case 'title_separator':
						if ( ! in_array( (string) $value, self::separators(), true ) ) {
							$errors->add( $key, __( 'Choose one of the listed separators.', 'uichemy' ) );
							continue 2;
						}
						$value = (string) $value;
						break;
					case 'title_template':
						$value = trim( wp_strip_all_tags( (string) $value ) );
						$bad   = self::template_error( $value );
						if ( $bad ) {
							$errors->add( $key, $bad );
							continue 2;
						}
						break;
					default:
						if ( is_bool( $def ) ) {
							// Only real on/off values: "no" or "maybe" must not turn a switch on.
							$flag = is_string( $value ) ? strtolower( trim( $value ) ) : $value;
							if ( ! in_array( $flag, array( true, false, 1, 0, '1', '0', 'true', 'false', '' ), true ) ) {
								$errors->add( $key, __( 'This setting expects on or off.', 'uichemy' ) );
								continue 2;
							}
							$value = in_array( $flag, array( true, 1, '1', 'true' ), true );
						} elseif ( is_int( $def ) ) {
							if ( ! is_numeric( $value ) ) {
								$errors->add( $key, __( 'This setting expects a number.', 'uichemy' ) );
								continue 2;
							}
							$value = (int) $value;
						} else {
							$value = sanitize_text_field( (string) $value );
						}
				}
				// Size caps: a settings save must not store megabytes in an autoloaded option.
				$caps = array(
					'site_title' => 200, 'site_tagline' => 300, 'title_template' => 300, 'home_description' => 600,
					'entity_description' => 600, 'entity_email' => 254, 'entity_phone' => 40, 'llms_intro' => 2000,
					'llms_extra' => 10000, 'md_template' => 10000, 'robots_content' => 65536,
					'entity_logo' => 2048, 'default_share_image' => 2048,
				);
				if ( is_string( $value ) ) {
					$max = isset( $caps[ $key ] ) ? $caps[ $key ] : 200;
					$len = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
					if ( $len > $max ) {
						/* translators: %d: maximum characters. */
						$errors->add( $key, sprintf( __( 'Keep this to %d characters or fewer.', 'uichemy' ), $max ) );
						continue;
					}
				}
				if ( 'home_description' === $key ) {
					$bad = self::template_error( (string) $value );
					if ( $bad ) {
						$errors->add( $key, $bad );
						continue;
					}
				}
				$out[ $key ] = $value;
			}

			if ( $errors->has_errors() ) {
				return $errors;
			}
			return $out;
		}

		/**
		 * Sanitise the content-type matrix (PRD §7).
		 *
		 * @param array $rows     slug => row.
		 * @param bool  $is_types Post types (true) or taxonomies.
		 * @return array
		 */
		private static function sanitize_matrix( array $rows, $is_types ) {
			$bools = $is_types ? array( 'seo', 'index', 'sitemap', 'llms', 'markdown' ) : array( 'seo', 'index', 'sitemap' );
			$out   = array();
			foreach ( $rows as $slug => $row ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' === $slug || ! is_array( $row ) ) {
					continue;
				}
				$clean = array();
				foreach ( $bools as $b ) {
					if ( array_key_exists( $b, $row ) ) {
						$clean[ $b ] = (bool) rest_sanitize_boolean( $row[ $b ] );
					}
				}
				if ( $is_types && array_key_exists( 'page_type', $row ) ) {
					$clean['page_type'] = in_array( $row['page_type'], array( 'webpage', 'article', 'product', 'none' ), true ) ? $row['page_type'] : 'webpage';
				}
				$out[ $slug ] = $clean;
			}
			return $out;
		}

		/**
		 * Post types that are builder internals, not content: templates, kits,
		 * popups, floating elements, reusable blocks. They are "public" so their
		 * editors can preview them, but they must never get SEO, a sitemap entry
		 * or a place in the Content types screen.
		 *
		 * @return string[]
		 */
		public static function internal_types() {
			$types = array(
				'attachment',
				'uichemy_template',
				'uichemy_seo_schema',
				'elementor_library',
				'e-floating-buttons',
				'elementor_snippet',
				'elementor_font',
				'elementor_icons',
				'bricks_template',
				'wp_block',
				'wp_template',
				'wp_template_part',
				'wp_navigation',
				'ct_template',
				'nxt_builder',
				'oxy_user_library',
				'brizy_template',
				'fl-builder-template',
				'et_pb_layout',
			);
			/**
			 * Filter: post types UiChemy SEO treats as builder internals, not content.
			 *
			 * @param string[] $types Post type slugs.
			 */
			return (array) apply_filters( 'uichemy_seo_internal_post_types', $types );
		}

		/**
		 * Is this post type real content?
		 *
		 * @param string $post_type Post type.
		 * @return bool
		 */
		public static function is_content_type( $post_type ) {
			$obj = get_post_type_object( $post_type );
			return $obj && ! empty( $obj->public ) && ! in_array( $post_type, self::internal_types(), true );
		}

		/**
		 * Content post types (public, not builder internals).
		 *
		 * @param string $output 'names' | 'objects'.
		 * @return array
		 */
		public static function content_types( $output = 'names' ) {
			$out = array();
			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $obj ) {
				if ( self::is_content_type( $name ) ) {
					$out[ $name ] = 'objects' === $output ? $obj : $name;
				}
			}
			return $out;
		}

		/**
		 * Effective matrix row for a post type, defaults filled in (PRD §7).
		 *
		 * @param string $post_type Post type.
		 * @return array{seo:bool,index:bool,sitemap:bool,llms:bool,markdown:bool,page_type:string}
		 */
		public static function type_row( $post_type ) {
			$public = self::is_content_type( $post_type );
			$types  = (array) self::get( 'types', array() );
			$row    = isset( $types[ $post_type ] ) && is_array( $types[ $post_type ] ) ? $types[ $post_type ] : array();

			$default_type = 'webpage';
			if ( 'post' === $post_type ) {
				$default_type = 'article';
			} elseif ( 'product' === $post_type ) {
				$default_type = 'product';
			}

			// Builder R6.2/R6.3: the template CPT is never indexed.
			if ( class_exists( 'UiChemy_Template_CPT' ) && UiChemy_Template_CPT::POST_TYPE === $post_type ) {
				$public = false;
			}

			if ( ! $public ) {
				return array(
					'seo'       => false,
					'index'     => false,
					'sitemap'   => false,
					'llms'      => false,
					'markdown'  => false,
					'page_type' => 'webpage',
				);
			}
			return array(
				'seo'       => array_key_exists( 'seo', $row ) ? (bool) $row['seo'] : $public,
				'index'     => array_key_exists( 'index', $row ) ? (bool) $row['index'] : $public,
				'sitemap'   => array_key_exists( 'sitemap', $row ) ? (bool) $row['sitemap'] : $public,
				'llms'      => array_key_exists( 'llms', $row ) ? (bool) $row['llms'] : in_array( $post_type, array( 'post', 'page' ), true ),
				// On by default for content types, so the global "Markdown versions"
				// switch works on its own; a stored per-type choice always wins.
				'markdown'  => array_key_exists( 'markdown', $row ) ? (bool) $row['markdown'] : $public,
				'page_type' => isset( $row['page_type'] ) ? (string) $row['page_type'] : $default_type,
			);
		}

		/**
		 * Effective matrix row for a taxonomy.
		 *
		 * @param string $taxonomy Taxonomy.
		 * @return array{seo:bool,index:bool,sitemap:bool}
		 */
		public static function taxonomy_row( $taxonomy ) {
			$obj    = get_taxonomy( $taxonomy );
			$public = $obj && ! empty( $obj->public );
			$rows   = (array) self::get( 'taxonomies', array() );
			$row    = isset( $rows[ $taxonomy ] ) && is_array( $rows[ $taxonomy ] ) ? $rows[ $taxonomy ] : array();
			return array(
				'seo'     => array_key_exists( 'seo', $row ) ? (bool) $row['seo'] : $public,
				'index'   => array_key_exists( 'index', $row ) ? (bool) $row['index'] : $public,
				'sitemap' => array_key_exists( 'sitemap', $row ) ? (bool) $row['sitemap'] : $public,
			);
		}

		/**
		 * The `site` array handed to Twig for SEO strings. Free ships only the
		 * `post` provider, so `{{ site.name }}` would otherwise be empty.
		 *
		 * @return array
		 */
		/**
		 * Is a per-page value limited to the documented tokens?
		 *
		 * Page fields (title, description, social title/description) are written by whoever can
		 * edit the post, Contributors included, and render through the dynamic engine. Its
		 * functions (get_post, get_posts…) read any post regardless of status or password, so a
		 * page value from someone without `unfiltered_html` may only use the Insert tokens.
		 *
		 * @param string $tpl Template.
		 * @return bool
		 */
		public static function page_template_safe( $tpl ) {
			$tpl = (string) $tpl;
			if ( false === strpos( $tpl, '{' ) ) {
				return true;
			}
			// Tags ({% %}) and comments ({# #}) are never needed for a page value.
			if ( false !== strpos( $tpl, '{%' ) || false !== strpos( $tpl, '{#' ) ) {
				return false;
			}
			$count = substr_count( $tpl, '{{' );
			if ( ! $count ) {
				return true;
			}
			if ( preg_match_all( '/\{\{(.*?)\}\}/s', $tpl, $m ) !== $count ) {
				return false;
			}
			// Only the documented values, optionally piped through argument-less filters. Filter
			// arguments and calls are refused: `{{ title|default( get_post( 5 ).content ) }}` is
			// how the plain token check above was bypassed.
			$known = 'title|site\.name|site\.title|site\.tagline|site\.separator|site\.url|post\.title|post\.url';
			foreach ( $m[1] as $expr ) {
				if ( ! preg_match( '/^\s*(?:' . $known . ')(?:\s*\|\s*[a-z_]+)*\s*$/', $expr ) ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * What is wrong with a Twig title/description template, or '' when fine.
		 * Catches a missing "}}" (which would leak raw braces into page titles)
		 * and values UiChemy does not know.
		 *
		 * @param string $tpl Template.
		 * @return string
		 */
		public static function template_error( $tpl ) {
			$tpl = (string) $tpl;
			if ( '' === $tpl ) {
				return '';
			}
			// Twig statements and comments are not supported; refuse rather than print them raw.
			if ( false !== strpos( $tpl, '{%' ) || false !== strpos( $tpl, '{#' ) ) {
				return __( 'Only {{ value }} tokens are supported, not {% %} statements.', 'uichemy' );
			}
			if ( substr_count( $tpl, '{{' ) !== substr_count( $tpl, '}}' ) || preg_match( '/\}\}[^{]*\}\}|\{\{[^}]*\{\{/', $tpl ) ) {
				return __( 'Each {{ needs a matching }}. Use the Insert buttons to add a value.', 'uichemy' );
			}
			if ( preg_match_all( '/\{\{\s*([^}|]+?)\s*(?:\|[^}]*)?\}\}/', $tpl, $m ) ) {
				$known = array( 'title', 'site.name', 'site.title', 'site.tagline', 'site.separator', 'site.url', 'post.title', 'post.url' );
				foreach ( $m[1] as $tok ) {
					if ( ! in_array( trim( $tok ), $known, true ) ) {
						/* translators: %s: token. */
						return sprintf( __( '{{ %s }} is not a known value. Use the Insert buttons to add a value.', 'uichemy' ), trim( $tok ) );
					}
				}
			}
			return '';
		}

		/**
		 * Title separators offered (the same set SureRank offers).
		 *
		 * @return string[]
		 */
		public static function separators() {
			return array( '-', '–', '—', '|', '/', ':', '·', '•', '~', '*', '«', '»', '>', '<' );
		}

		public static function twig_site() {
			return array(
				'separator' => (string) self::get( 'title_separator', '|' ),
				// WordPress calls it the Site Title; site.name is kept as an alias.
				'title'   => self::site_name(),
				'name'    => self::site_name(),
				'tagline' => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
				'url'     => home_url( '/' ),
			);
		}

		/**
		 * WordPress's Site Title (Settings → General): {{ site.title }} (and its
		 * older alias {{ site.name }}) in titles, and the WebSite schema name.
		 *
		 * @return string
		 */
		public static function site_name() {
			return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}

		/**
		 * og:site_name: the site name, unless the site head code carries its own
		 * og:site_name tag (a tag in head code wins, for link previews only).
		 *
		 * @return string
		 */
		public static function share_site_name() {
			$tag = trim( (string) self::get( 'site_name_override', '' ) );
			return '' !== $tag ? $tag : self::site_name();
		}
	}
}
