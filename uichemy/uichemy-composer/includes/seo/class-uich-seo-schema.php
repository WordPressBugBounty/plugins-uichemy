<?php
/**
 * Structured data (D8, 02 §8, PRD §8, 08 §6).
 *
 *   Tier 1  inferred: Organization/Person, WebSite, WebPage, BreadcrumbList (from
 *           breadcrumb markup), Article (posts), Product (WooCommerce)
 *   Tier 2  declared in markup: data-uich-schema / -item / -prop / -value
 *   Tier 3  site entity from the Site screen
 *   + page entities (`schema_extra`) and schema templates (Twig, with conditions)
 *
 * One @graph per page. Every entity is validated; an invalid one is suppressed
 * and reported, never emitted (invalid JSON-LD is worse than none).
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_SEO_Schema' ) ) {

	/**
	 * Schema graph.
	 */
	class Uich_SEO_Schema {

		const CPT = 'uichemy_seo_schema';

		/**
		 * Active templates, per request.
		 *
		 * @var array|null
		 */
		private static $active = null;

		/**
		 * Required properties per type (Google rich-result minimums).
		 * A list inside a list means "one of".
		 */
		const REQUIRED = array(
			'Article'        => array( 'headline' ),
			'BlogPosting'    => array( 'headline' ),
			'NewsArticle'    => array( 'headline' ),
			'Product'        => array( 'name', array( 'offers', 'review', 'aggregateRating' ) ),
			'FAQPage'        => array( 'mainEntity' ),
			'HowTo'          => array( 'name', 'step' ),
			'BreadcrumbList' => array( 'itemListElement' ),
			'Recipe'         => array( 'name', 'image' ),
			'Event'          => array( 'name', 'startDate', 'location' ),
			'Organization'   => array( 'name' ),
			'LocalBusiness'  => array( 'name', 'address' ),
			'Person'         => array( 'name' ),
			'VideoObject'    => array( 'name', 'thumbnailUrl', 'uploadDate' ),
			'Review'         => array( 'itemReviewed', 'author', 'reviewRating' ),
			'AggregateRating' => array( 'itemReviewed', 'ratingValue', array( 'ratingCount', 'reviewCount' ) ),
			'ItemList'       => array( 'itemListElement' ),
			'JobPosting'     => array( 'title', 'datePosted', 'description', 'hiringOrganization' ),
			'Course'         => array( 'name', 'description' ),
			'SoftwareApplication' => array( 'name', array( 'offers', 'aggregateRating', 'review' ) ),
			'Service'        => array( 'name' ),
			'Book'           => array( 'name', 'author' ),
			'Movie'          => array( 'name', 'image' ),
			'Dataset'        => array( 'name', 'description' ),
			'PodcastEpisode' => array( 'name', 'url' ),
			'ClaimReview'    => array( 'claimReviewed', 'reviewRating', 'author' ),
			'ProfilePage'    => array( 'mainEntity' ),
		);

		/**
		 * Types offered for templates, grouped (PRD §8.2 "Select, grouped").
		 *
		 * Only types that describe a WHOLE post and can be filled from its fields
		 * across many posts. Section-level types (SECTION_TYPES) belong next to the
		 * content they describe — the section's own JSON-LD (Schema in the UiChemy
		 * editor) — and the site Organization / Person belongs in Site Settings.
		 */
		const CATALOGUE = array(
			'Content'  => array( 'Article', 'BlogPosting', 'NewsArticle', 'VideoObject', 'PodcastEpisode', 'Course', 'Book', 'Movie', 'Dataset' ),
			'Commerce' => array( 'Product', 'SoftwareApplication', 'Service' ),
			'Business' => array( 'LocalBusiness', 'ProfilePage', 'Event', 'JobPosting' ),
			'Food'     => array( 'Recipe' ),
		);

		/**
		 * Types that describe one part of one page (an accordion, a list of steps, a
		 * review block). A template would stamp the same Q&A or steps onto every
		 * post, so they are never templates: put them in the section's JSON-LD.
		 */
		const SECTION_TYPES = array( 'FAQPage', 'QAPage', 'Question', 'HowTo', 'Review', 'ClaimReview', 'AggregateRating', 'BreadcrumbList' );

		/** Types the site itself owns (Site Settings → site head code), never templates. */
		const SITE_TYPES = array( 'Organization', 'Person', 'WebSite' );

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'init', array( __CLASS__, 'register_cpt' ) );
			add_filter( 'uichemy_seo_head_extra', array( __CLASS__, 'head' ), 10, 2 );
			add_filter( 'uichemy_seo_schema_errors', array( __CLASS__, 'audit_errors' ), 10, 3 );
			add_filter( 'uichemy_seo_published_types', array( __CLASS__, 'published_types' ), 10, 2 );
			// External mode: hand our entities to the plugin that owns the head (D7).
			add_filter( 'wpseo_schema_graph', array( __CLASS__, 'into_yoast' ), 20, 2 );
			add_filter( 'rank_math/json_ld', array( __CLASS__, 'into_rankmath' ), 99, 2 );
			// SureRank has no filter on its graph; print ours beside its meta.
			add_action( 'surerank_print_meta', array( __CLASS__, 'into_surerank' ), 20 );
		}

		/**
		 * SureRank owns the head: print only the entities SureRank cannot see
		 * (FAQ from the page, HowTo/Recipe/... from markup and templates). Its own
		 * WebSite / WebPage / Organization / Article / Person are left to it.
		 *
		 * @return void
		 */
		public static function into_surerank() {
			$own = Uich_SEO_Ownership::resolve();
			if ( 'off' === $own['owner'] || Uich_SEO_Ownership::we_emit() || ! self::enabled() || ! is_singular() ) {
				return;
			}
			$r = Uich_SEO_Resolver::for_request();
			if ( ! $r ) {
				return;
			}
			$out = self::build( $r, get_queried_object(), array( 'only_contributed' => true ) );
			if ( ! empty( $out['graph'] ) ) {
				echo self::script( $out['graph'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode inside script().
			}
		}

		/**
		 * Private store for schema templates.
		 *
		 * @return void
		 */
		public static function register_cpt() {
			register_post_type(
				self::CPT,
				array(
					'public'              => false,
					'show_ui'             => false,
					'show_in_rest'        => false,
					'exclude_from_search' => true,
					'rewrite'             => false,
					'supports'            => array( 'title' ),
					'label'               => __( 'Schema templates', 'uichemy' ),
					// Site-wide JSON-LD: the same gate as the REST and MCP routes that manage it. With
					// the default 'post' caps an Editor could change or delete templates through
					// XML-RPC (wp.editPost / wp.deletePost), which does not look at show_ui.
					'map_meta_cap'        => true,
					'capabilities'        => array_fill_keys(
						array( 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'create_posts' ),
						'manage_options'
					),
				)
			);
		}

		/**
		 * Is the JSON-LD graph on?
		 *
		 * @return bool
		 */
		public static function enabled() {
			return (bool) Uich_SEO_Settings::get( 'schema_enabled', true );
		}

		// ------------------------------------------------------------------
		// Graph
		// ------------------------------------------------------------------

		/**
		 * Build the graph for a resolved object.
		 *
		 * @param array        $r    Resolved values.
		 * @param WP_Post|null $post Post (singular objects).
		 * @param array        $opts { only_contributed: bool } — external mode.
		 * @return array{graph:array,errors:array,entities:array}
		 */
		public static function build( array $r, $post = null, array $opts = array() ) {
			$home   = home_url( '/' );
			$org_id = $home . '#organization';
			$web_id = $home . '#website';
			$canon  = '' !== (string) $r['canonical']['value'] ? (string) $r['canonical']['value'] : $home;
			$page_id = $canon . '#webpage';
			$lang   = str_replace( '_', '-', (string) $r['og_locale']['value'] );

			$auto        = array();
			$contributed = array();
			$record      = $post ? Uich_SEO_Record::get( $post->ID ) : null;
			// Per-item switches and extra details for the automatic entities.
			$cfg  = isset( $opts['auto'] ) && is_array( $opts['auto'] ) ? $opts['auto'] : self::auto_cfg();
			$on   = function ( $k ) use ( $cfg ) {
				return empty( $cfg[ $k ]['off'] );
			};
			$plus = function ( $k, array $e ) use ( $cfg ) {
				return self::with_extra( $e, isset( $cfg[ $k ]['extra'] ) ? (array) $cfg[ $k ]['extra'] : array() );
			};

			if ( empty( $opts['only_contributed'] ) ) {
				if ( $on( 'site' ) ) {
					$auto[] = array( 'source' => 'site', 'entity' => $plus( 'site', self::site_entity( $org_id ) ) );
				}
				$website = array(
					'@type'           => 'WebSite',
					'@id'             => $web_id,
					'url'             => $home,
					'name'            => Uich_SEO_Settings::site_name(),
					'description'     => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
					'publisher'       => array( '@id' => $org_id ),
					'inLanguage'      => $lang,
					'potentialAction' => array(
						'@type'       => 'SearchAction',
						'target'      => array(
							'@type'       => 'EntryPoint',
							'urlTemplate' => home_url( '/?s={search_term_string}' ),
						),
						'query-input' => 'required name=search_term_string',
					),
				);
				if ( ! $on( 'site' ) ) {
					unset( $website['publisher'] );
				}
				if ( $on( 'website' ) ) {
					$auto[] = array( 'source' => 'auto', 'entity' => $plus( 'website', $website ) );
				}

				$img     = isset( $r['share_image']['value'] ) && is_array( $r['share_image']['value'] ) ? $r['share_image']['value'] : null;
				$webpage = array(
					'@type'      => 'post' === $r['object']['kind'] || 'home' === $r['object']['kind'] ? 'WebPage' : 'CollectionPage',
					'@id'        => $page_id,
					'url'        => $canon,
					'name'       => isset( $r['title']['core'] ) && '' !== (string) $r['title']['core'] ? (string) $r['title']['core'] : (string) $r['title']['value'],
					'isPartOf'   => array( '@id' => $web_id ),
					'inLanguage' => $lang,
				);
				if ( '' !== (string) $r['description']['value'] ) {
					$webpage['description'] = (string) $r['description']['value'];
				}
				if ( $img && ! empty( $img['url'] ) ) {
					$webpage['primaryImageOfPage'] = self::image( $img, $canon . '#primaryimage' );
				}
				if ( $post ) {
					$webpage['datePublished'] = get_post_time( 'c', true, $post );
					$webpage['dateModified']  = get_post_modified_time( 'c', true, $post );
				}
				if ( $on( 'site' ) && ( 'home' === $r['object']['kind'] || ( $post && (int) get_option( 'page_on_front' ) === (int) $post->ID ) ) ) {
					$webpage['about'] = array( '@id' => $org_id );
				}
				if ( ! $on( 'website' ) ) {
					unset( $webpage['isPartOf'] );
				}

				// Breadcrumbs found in the page's navigation markup (tier 1); otherwise
				// built from where the page sits: Home › parent pages › this page.
				$crumbs = $record ? ( isset( $record['derived']['breadcrumbs'][0]['items'] ) ? $record['derived']['breadcrumbs'][0]['items'] : array() ) : array();
				if ( count( $crumbs ) < 2 && $post ) {
					$crumbs = self::path_crumbs( $post, $r );
				}
				if ( count( $crumbs ) >= 2 && $on( 'breadcrumbs' ) ) {
					$list = array();
					foreach ( array_values( $crumbs ) as $i => $c ) {
						$item = array(
							'@type'    => 'ListItem',
							'position' => $i + 1,
							'name'     => $c['name'],
						);
						$url = self::abs( $c['url'] );
						if ( '' !== $url ) {
							$item['item'] = $url;
						} elseif ( $i === count( $crumbs ) - 1 ) {
							$item['item'] = $canon;
						}
						$list[] = $item;
					}
					$webpage['breadcrumb'] = array( '@id' => $canon . '#breadcrumb' );
					$auto[]                = array(
						'source' => 'markup',
						'entity' => $plus(
							'breadcrumbs',
							array(
								'@type'           => 'BreadcrumbList',
								'@id'             => $canon . '#breadcrumb',
								'itemListElement' => $list,
							)
						),
					);
				}
				if ( $on( 'webpage' ) ) {
					$auto[] = array( 'source' => 'auto', 'entity' => $plus( 'webpage', $webpage ) );
				}

				if ( $post && 'article' === $r['content_type']['value'] && $on( 'article' ) ) {
					$article = array(
						'@type'            => 'post' === $post->post_type ? 'BlogPosting' : 'Article',
						'@id'              => $canon . '#article',
						'headline'         => Uich_SEO_Deriver::clip( isset( $r['title']['core'] ) ? $r['title']['core'] : (string) $r['title']['value'], 110 ),
						'datePublished'    => get_post_time( 'c', true, $post ),
						'dateModified'     => get_post_modified_time( 'c', true, $post ),
						'author'           => array(
							'@type' => 'Person',
							'name'  => get_the_author_meta( 'display_name', $post->post_author ),
							'url'   => get_author_posts_url( $post->post_author ),
						),
						'publisher'        => array( '@id' => $org_id ),
						'mainEntityOfPage' => array( '@id' => $page_id ),
						'inLanguage'       => $lang,
					);
					if ( '' !== (string) $r['description']['value'] ) {
						$article['description'] = (string) $r['description']['value'];
					}
					if ( $img && ! empty( $img['url'] ) ) {
						// The image object lives on the WebPage; without it, inline the image.
						$article['image'] = $on( 'webpage' ) ? array( '@id' => $canon . '#primaryimage' ) : self::image( $img, $canon . '#primaryimage' );
					}
					if ( ! $on( 'webpage' ) ) {
						unset( $article['mainEntityOfPage'] );
					}
					if ( ! $on( 'site' ) ) {
						unset( $article['publisher'] );
					}
					if ( $record && ! empty( $record['derived']['word_count'] ) ) {
						$article['wordCount'] = (int) $record['derived']['word_count'];
					}
					$auto[] = array( 'source' => 'auto', 'entity' => $plus( 'article', $article ) );
				}
				if ( $post && 'product' === $r['content_type']['value'] ) {
					$product = self::woo_product( $post, $canon, $img );
					if ( $product ) {
						$auto[] = array( 'source' => 'auto', 'entity' => $product );
					}
				}
			}

			// Site entities from the site head code (other than the site entity itself). Site
			// wide, so on every page that prints the graph — the home page and archives
			// have no record, and used to lose them.
			foreach ( Uich_SEO_Head::site_extra_entities() as $i => $e ) {
				if ( empty( $e['@type'] ) ) {
					continue;
				}
				$ent = self::normalize( $e );
				if ( empty( $ent['@id'] ) ) {
					$ent['@id'] = home_url( '/' ) . '#' . strtolower( self::type_name( $ent ) ) . '-site-' . ( $i + 1 );
				}
				$contributed[] = array( 'source' => 'site', 'entity' => $ent );
			}

			// Tier 2 — declared in markup. Not for a password-protected post (its markup is the
			// protected text). For authors who could not publish JSON-LD themselves, review and
			// rating markup is dropped: it is what earns a manual action when faked, and the
			// rule for hand-written JSON-LD (unfiltered_html) would otherwise be bypassed here.
			$markup_ok = ! ( $post && '' !== (string) $post->post_password );
			$untrusted = $post && class_exists( 'Uich_SEO_Head' ) && ! Uich_SEO_Head::author_trusted( $post->ID );
			if ( $record ) {
				foreach ( ( $markup_ok && isset( $record['derived']['entities'] ) ) ? (array) $record['derived']['entities'] : array() as $i => $e ) {
					if ( empty( $e['entity']['@type'] ) ) {
						continue;
					}
					if ( $untrusted ) {
						if ( in_array( self::type_name( $e['entity'] ), array( 'Review', 'AggregateRating', 'EmployerAggregateRating' ), true ) ) {
							continue;
						}
						unset( $e['entity']['aggregateRating'], $e['entity']['review'] );
					}
					$ent        = self::normalize( $e['entity'] );
					$ent['@id'] = $canon . '#' . strtolower( self::type_name( $ent ) ) . '-' . ( $i + 1 );
					$contributed[] = array( 'source' => 'markup', 'entity' => $ent );
				}
				// Page entities: JSON-LD in the page's before-</head> code.
				foreach ( isset( $record['fields']['schema_extra']['value'] ) ? (array) $record['fields']['schema_extra']['value'] : array() as $i => $e ) {
					if ( empty( $e['@type'] ) ) {
						continue;
					}
					$ent = self::normalize( $e );
					if ( empty( $ent['@id'] ) ) {
						$ent['@id'] = $canon . '#' . strtolower( self::type_name( $ent ) ) . '-page-' . ( $i + 1 );
					}
					// index: position in the page's JSON-LD list (the panel's page_entities).
					$contributed[] = array( 'source' => 'page', 'entity' => $ent, 'index' => (int) $i );
				}
			}
			// Templates (never on a password-protected post: a template can print its text).
			if ( $post && '' === (string) $post->post_password ) {
				foreach ( self::templates_for( $post ) as $tpl ) {
					$rendered = self::render_template( $tpl, $post );
					if ( is_array( $rendered['entity'] ) ) {
						$ent = self::normalize( $rendered['entity'] );
						if ( empty( $ent['@id'] ) ) {
							$ent['@id'] = $canon . '#tpl-' . $tpl['id'];
						}
						$contributed[] = array( 'source' => 'template', 'entity' => $ent, 'template' => $tpl['id'] );
					}
				}
			}

			// A declared / template / page entity of the same type replaces the automatic one.
			$declared_types = array();
			foreach ( $contributed as $c ) {
				$declared_types[ self::type_name( $c['entity'] ) ] = true;
			}
			// JSON-LD a section prints itself counts too: a section BreadcrumbList
			// (or Product) printed next to the automatic one gave Google two.
			if ( $record ) {
				foreach ( isset( $record['derived']['section_ld'] ) ? (array) $record['derived']['section_ld'] : array() as $row ) {
					if ( is_array( $row ) && isset( $row['entity'] ) && is_array( $row['entity'] ) && ! empty( $row['entity']['@type'] ) ) {
						$declared_types[ self::type_name( $row['entity'] ) ] = true;
					}
				}
			}
			// Article, BlogPosting and NewsArticle are one thing to a reader: a declared one
			// replaces the automatic one whichever of the three it is.
			$article_family = array( 'Article', 'BlogPosting', 'NewsArticle' );
			if ( array_intersect( $article_family, array_keys( $declared_types ) ) ) {
				$declared_types += array_fill_keys( $article_family, true );
			}
			$all     = array();
			$no_auto = array();
			foreach ( $auto as $a ) {
				$t = self::type_name( $a['entity'] );
				if ( isset( $declared_types[ $t ] ) && in_array( $t, array( 'Article', 'BlogPosting', 'NewsArticle', 'Product', 'BreadcrumbList' ), true ) ) {
					$no_auto[ $t ] = true;
					continue;
				}
				$all[] = $a;
			}
			// The page no longer points at an automatic breadcrumb that was replaced.
			if ( isset( $no_auto['BreadcrumbList'] ) ) {
				foreach ( $all as $k => $a ) {
					if ( isset( $a['entity']['breadcrumb']['@id'] ) && $a['entity']['breadcrumb']['@id'] === $canon . '#breadcrumb' ) {
						unset( $all[ $k ]['entity']['breadcrumb'] );
					}
				}
			}
			$all = array_merge( $all, $contributed );

			$graph  = array();
			$errors = array();
			foreach ( $all as $item ) {
				// Empty values ("serviceType": "", an author with no name) say nothing
				// and fail Google's checks: leave them out of what is printed.
				$item['entity'] = self::prune( $item['entity'] );
				$missing        = self::validate( $item['entity'] );
				if ( $missing ) {
					$err = array(
						'type'     => self::type_name( $item['entity'] ),
						'property' => $missing,
						'source'   => $item['source'],
						'entity'   => $item['entity'],
						'template' => isset( $item['template'] ) ? (int) $item['template'] : 0,
					);
					if ( isset( $item['index'] ) ) {
						$err['index'] = (int) $item['index'];
					}
					$errors[] = $err;
					continue;
				}
				$graph[] = $item['entity'];
			}

			// JSON-LD written into the sections: Composer prints it with the section,
			// so it is not part of our graph, but it is checked the same way.
			if ( $record && empty( $opts['only_contributed'] ) ) {
				foreach ( isset( $record['derived']['section_ld'] ) ? (array) $record['derived']['section_ld'] : array() as $i => $row ) {
					$base = array(
						'source'    => 'section',
						'template'  => 0,
						'index'     => (int) $i, // Position in the page's section JSON-LD list.
						'section'   => isset( $row['section'] ) ? (string) $row['section'] : '',
						'node_path' => isset( $row['path'] ) ? $row['path'] : null,
					);
					if ( ! isset( $row['entity'] ) || ! is_array( $row['entity'] ) ) {
						$errors[] = array_merge(
							$base,
							array(
								'type'     => 'JSON-LD',
								'property' => 'json',
								'entity'   => array(),
							)
						);
						continue;
					}
					$ent     = self::normalize( $row['entity'] );
					$missing = self::validate( $ent );
					$empty   = self::empties( $ent );
					if ( '' === $missing && ! $empty ) {
						continue;
					}
					$errors[] = array_merge(
						$base,
						array(
							'type'       => self::type_name( $ent ),
							'property'   => '' !== $missing ? $missing : $empty[0],
							'properties' => array_values( array_unique( array_merge( '' !== $missing ? array( $missing ) : array(), $empty ) ) ),
							'entity'     => $ent,
						)
					);
				}
			}

			$graph = (array) apply_filters( 'uichemy_seo_graph', $graph, $r, $post );
			return array(
				'graph'    => $graph,
				'errors'   => $errors,
				'entities' => array_map(
					function ( $i ) {
						return array(
							'type'   => self::type_name( $i['entity'] ),
							'source' => $i['source'],
						);
					},
					$all
				),
			);
		}

		/** Automatic entities a site owner can switch off or add details to. */
		const AUTO = array( 'site', 'website', 'webpage', 'breadcrumbs', 'article' );

		/**
		 * Stored per-item settings: { key: { off: bool, extra: object } }.
		 *
		 * @return array
		 */
		public static function auto_cfg() {
			$c = Uich_SEO_Settings::get( 'schema_auto', array() );
			return is_array( $c ) ? $c : array();
		}

		/**
		 * Merge the owner's extra details into an automatic entity. Their values
		 * win, except the @id, which other entities point at.
		 *
		 * @param array $e     Entity.
		 * @param array $extra Extra properties.
		 * @return array
		 */
		private static function with_extra( array $e, array $extra ) {
			if ( ! $extra ) {
				return $e;
			}
			$id = isset( $e['@id'] ) ? $e['@id'] : null;
			$e  = array_merge( $e, $extra );
			if ( null !== $id ) {
				$e['@id'] = $id;
			}
			return $e;
		}

		/**
		 * Sanitise the schema_auto setting.
		 *
		 * @param array    $value  Raw { key: { off, extra } }; extra may be a JSON string.
		 * @param WP_Error $errors Collected errors.
		 * @return array
		 */
		public static function sanitize_auto( array $value, WP_Error $errors ) {
			$stored = self::auto_cfg();
			$out    = array();
			foreach ( self::AUTO as $k ) {
				// The value replaces the stored map; callers pass every item they keep.
				if ( ! isset( $value[ $k ] ) || ! is_array( $value[ $k ] ) ) {
					continue;
				}
				$row   = $value[ $k ];
				$extra = isset( $row['extra'] ) ? $row['extra'] : array();
				if ( is_string( $extra ) ) {
					$extra = '' === trim( $extra ) ? array() : json_decode( $extra, true );
					if ( ! is_array( $extra ) || ( $extra && array_keys( $extra ) === range( 0, count( $extra ) - 1 ) ) ) {
						$errors->add( 'schema_auto', __( 'This is not valid JSON. Write the extra details between { and }, for example { "foundingDate": "2015" }.', 'uichemy' ) );
						continue;
					}
				}
				$extra = (array) self::clean_json( (array) $extra );
				$old   = isset( $stored[ $k ]['extra'] ) ? (array) $stored[ $k ]['extra'] : array();
				// Hand-written structured data needs unfiltered_html, like page details.
				if ( $extra !== $old && ! current_user_can( 'unfiltered_html' ) && ! ( defined( 'WP_CLI' ) && WP_CLI && ! get_current_user_id() ) ) {
					$errors->add( 'schema_auto', __( 'Only administrators who can add code to the site can change these details.', 'uichemy' ) );
					continue;
				}
				$out[ $k ] = array(
					'off'   => ! empty( $row['off'] ),
					'extra' => $extra,
				);
			}
			return $out;
		}

		/**
		 * Keep JSON-LD data only: no @id or @context, safe keys, plain text values.
		 *
		 * @param mixed $v     Value.
		 * @param int   $depth Depth.
		 * @return mixed
		 */
		private static function clean_json( $v, $depth = 0 ) {
			if ( $depth > 6 ) {
				return null;
			}
			if ( is_array( $v ) ) {
				$o = array();
				foreach ( $v as $k => $x ) {
					if ( is_string( $k ) && ( in_array( $k, array( '@id', '@context' ), true ) || ! preg_match( '/^@?[A-Za-z][A-Za-z0-9_-]*$/', $k ) ) ) {
						continue;
					}
					if ( '@type' === $k ) {
						$x = preg_replace( '/[^A-Za-z]/', '', is_string( $x ) ? $x : '' );
						if ( '' === $x ) {
							continue;
						}
					}
					$c = self::clean_json( $x, $depth + 1 );
					if ( null !== $c ) {
						$o[ $k ] = $c;
					}
				}
				return $o;
			}
			if ( is_bool( $v ) || is_int( $v ) || is_float( $v ) ) {
				return $v;
			}
			if ( is_string( $v ) ) {
				return sanitize_text_field( $v );
			}
			return null;
		}

		/**
		 * The automatic items with their settings and what they look like on a
		 * sample page, for the Schema screen.
		 *
		 * @param array|null $cfg Settings to use (default: stored).
		 * @return array
		 */
		public static function auto_list( $cfg = null ) {
			$cfg     = null === $cfg ? self::auto_cfg() : $cfg;
			$article = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
			$front   = (int) get_option( 'page_on_front' );
			$page    = $front ? array( $front ) : get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
			$suffix  = array(
				'site'        => '#organization',
				'website'     => '#website',
				'webpage'     => '#webpage',
				'breadcrumbs' => '#breadcrumb',
				'article'     => '#article',
			);
			$out = array();
			foreach ( self::AUTO as $k ) {
				$sample_id = 'article' === $k && $article ? (int) $article[0] : ( $page ? (int) $page[0] : ( $article ? (int) $article[0] : 0 ) );
				if ( 'breadcrumbs' === $k ) {
					$sample_id = self::breadcrumb_sample( $sample_id );
				}
				$json    = '';
				$missing = '';
				if ( $sample_id ) {
					$r = Uich_SEO_Resolver::for_post( $sample_id );
					if ( $r ) {
						$force = $cfg;
						unset( $force[ $k ]['off'] );
						$b = self::build( $r, get_post( $sample_id ), array( 'auto' => $force ) );
						foreach ( array_merge( $b['graph'], wp_list_pluck( $b['errors'], 'entity' ) ) as $e ) {
							if ( isset( $e['@id'] ) && substr( (string) $e['@id'], -strlen( $suffix[ $k ] ) ) === $suffix[ $k ] ) {
								$json    = (string) wp_json_encode( $e, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
								$missing = self::validate( $e );
								break;
							}
						}
					}
				}
				$extra = isset( $cfg[ $k ]['extra'] ) ? (array) $cfg[ $k ]['extra'] : array();
				$out[] = array(
					'key'     => $k,
					'off'     => ! empty( $cfg[ $k ]['off'] ),
					'extra'   => $extra ? (string) wp_json_encode( $extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '',
					'sample'  => $sample_id ? array( 'id' => $sample_id, 'title' => get_the_title( $sample_id ), 'url' => get_permalink( $sample_id ) ) : null,
					'json'    => $json,
					'missing' => $missing,
				);
			}
			return $out;
		}

		/**
		 * A page that has a breadcrumb trail, if any (falls back to $fallback).
		 *
		 * @param int $fallback Post id.
		 * @return int
		 */
		private static function breadcrumb_sample( $fallback ) {
			global $wpdb;
			// The record is serialised: a non-empty breadcrumbs list reads "breadcrumbs";a:N:{ with N > 0.
			$id = (int) $wpdb->get_var( "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_uichemy_seo' AND p.post_status = 'publish' AND m.meta_value LIKE '%\"breadcrumbs\";a:%' AND m.meta_value NOT LIKE '%\"breadcrumbs\";a:0:%' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $id ? $id : $fallback;
		}

		/**
		 * Organisation or Person (tier 3): the Organization / Person JSON-LD in the
		 * site's before-</head> code, given the graph's @id. Without one, a minimal
		 * Organization from the site name and the theme's custom logo.
		 *
		 * @param string $id @id.
		 * @return array
		 */
		private static function site_entity( $id ) {
			$declared = Uich_SEO_Head::site_entity();
			if ( $declared ) {
				$e        = self::normalize( $declared );
				$e['@id'] = $id;
				if ( empty( $e['name'] ) ) {
					$e['name'] = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				}
				if ( empty( $e['url'] ) ) {
					$e['url'] = home_url( '/' );
				}
				return $e;
			}
			$e       = array(
				'@type' => 'Organization',
				'@id'   => $id,
				'name'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'   => home_url( '/' ),
			);
			$logo_id = (int) get_theme_mod( 'custom_logo' );
			if ( $logo_id ) {
				$src = wp_get_attachment_image_src( $logo_id, 'full' );
				if ( $src ) {
					$e['logo'] = array(
						'@type'  => 'ImageObject',
						'url'    => $src[0],
						'width'  => (int) $src[1],
						'height' => (int) $src[2],
					);
				}
			}
			return $e;
		}

		/**
		 * WooCommerce product → Product with an Offer.
		 *
		 * @param WP_Post    $post  Post.
		 * @param string     $canon Canonical.
		 * @param array|null $img   Share image.
		 * @return array|null
		 */
		private static function woo_product( $post, $canon, $img ) {
			if ( ! function_exists( 'wc_get_product' ) ) {
				return null; // Non-Woo products declare themselves in markup (tier 2).
			}
			$p = wc_get_product( $post->ID );
			if ( ! $p ) {
				return null;
			}
			$e = array(
				'@type'       => 'Product',
				'@id'         => $canon . '#product',
				'name'        => $p->get_name(),
				'url'         => $canon,
				'description' => Uich_SEO_Html::squish( wp_strip_all_tags( $p->get_short_description() ?: $p->get_description() ) ),
			);
			if ( $p->get_sku() ) {
				$e['sku'] = $p->get_sku();
			}
			if ( $img && ! empty( $img['url'] ) ) {
				$e['image'] = $img['url'];
			}
			$price = $p->get_price();
			if ( '' !== (string) $price ) {
				$e['offers'] = array(
					'@type'         => 'Offer',
					'price'         => wc_format_decimal( $price, wc_get_price_decimals() ),
					'priceCurrency' => get_woocommerce_currency(),
					'availability'  => 'https://schema.org/' . ( $p->is_in_stock() ? 'InStock' : 'OutOfStock' ),
					'url'           => $canon,
				);
				if ( $p->is_on_sale() && $p->get_date_on_sale_to() ) {
					$e['offers']['priceValidUntil'] = $p->get_date_on_sale_to()->date( 'Y-m-d' );
				}
			}
			if ( $p->get_review_count() ) {
				$e['aggregateRating'] = array(
					'@type'       => 'AggregateRating',
					'ratingValue' => $p->get_average_rating(),
					'reviewCount' => $p->get_review_count(),
				);
			}
			return $e;
		}

		/**
		 * ImageObject.
		 *
		 * @param array  $img Image.
		 * @param string $id  @id.
		 * @return array
		 */
		private static function image( array $img, $id ) {
			$o = array(
				'@type' => 'ImageObject',
				'@id'   => $id,
				'url'   => $img['url'],
			);
			if ( ! empty( $img['w'] ) && ! empty( $img['h'] ) ) {
				$o['width']  = (int) $img['w'];
				$o['height'] = (int) $img['h'];
			}
			return $o;
		}

		/**
		 * Absolute URL for a relative one.
		 *
		 * @param string $url URL.
		 * @return string
		 */
		private static function abs( $url ) {
			$url = trim( (string) $url );
			if ( '' === $url || '#' === $url[0] ) {
				return '';
			}
			if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
				return home_url( $url );
			}
			return $url;
		}

		// ------------------------------------------------------------------
		// Normalisation + validation
		// ------------------------------------------------------------------

		/**
		 * Human strings → schema formats (08 §6): "30 min" → PT30M, "$1,299.00" →
		 * 1299.00, Question with a plain answer → acceptedAnswer object, relative
		 * URLs → absolute.
		 *
		 * @param array $e Entity.
		 * @return array
		 */
		public static function normalize( array $e ) {
			$type = self::type_name( $e );
			foreach ( $e as $k => $v ) {
				if ( is_array( $v ) ) {
					if ( isset( $v['@type'] ) ) {
						$e[ $k ] = self::normalize( $v );
					} else {
						foreach ( $v as $i => $item ) {
							if ( is_array( $item ) && isset( $item['@type'] ) ) {
								$e[ $k ][ $i ] = self::normalize( $item );
							}
						}
					}
				} elseif ( is_string( $v ) ) {
					$v = html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					if ( in_array( $k, array( 'cookTime', 'prepTime', 'totalTime', 'duration', 'performTime' ), true ) ) {
						$v = self::iso_duration( $v );
					} elseif ( in_array( $k, array( 'price', 'lowPrice', 'highPrice' ), true ) ) {
						$v = preg_replace( '/[^0-9.]/', '', str_replace( ',', '', $v ) );
					} elseif ( in_array( $k, array( 'url', 'image', 'item', 'thumbnailUrl', 'logo', 'contentUrl' ), true ) ) {
						$v = self::abs( $v );
					}
					$e[ $k ] = $v;
				}
			}
			if ( 'Question' === $type && ! isset( $e['acceptedAnswer'] ) && isset( $e['text'] ) ) {
				$e['acceptedAnswer'] = array(
					'@type' => 'Answer',
					'text'  => $e['text'],
				);
				unset( $e['text'] );
			}
			if ( 'Question' === $type && isset( $e['acceptedAnswer'] ) && is_string( $e['acceptedAnswer'] ) ) {
				$e['acceptedAnswer'] = array(
					'@type' => 'Answer',
					'text'  => $e['acceptedAnswer'],
				);
			}
			if ( 'HowTo' === $type && isset( $e['step'] ) && is_array( $e['step'] ) && ! isset( $e['step']['@type'] ) ) {
				foreach ( $e['step'] as $i => $s ) {
					if ( is_string( $s ) ) {
						$e['step'][ $i ] = array(
							'@type' => 'HowToStep',
							'text'  => $s,
						);
					}
				}
			}
			return $e;
		}

		/**
		 * "1 hr 30 min" / "45 minutes" / "PT30M" → ISO 8601 duration.
		 *
		 * @param string $s Text.
		 * @return string
		 */
		public static function iso_duration( $s ) {
			$s = trim( (string) $s );
			if ( preg_match( '/^P(T?\d)/i', $s ) ) {
				return strtoupper( $s );
			}
			$h = preg_match( '/(\d+(?:\.\d+)?)\s*(h|hr|hrs|hour|hours)\b/i', $s, $m ) ? (float) $m[1] : 0;
			$i = preg_match( '/(\d+)\s*(m|min|mins|minute|minutes)\b/i', $s, $m ) ? (int) $m[1] : 0;
			if ( ! $h && ! $i && is_numeric( $s ) ) {
				$i = (int) $s;
			}
			if ( ! $h && ! $i ) {
				return $s;
			}
			$total = (int) round( $h * 60 ) + $i;
			return 'PT' . ( $total >= 60 ? intdiv( $total, 60 ) . 'H' : '' ) . ( $total % 60 ? ( $total % 60 ) . 'M' : '' );
		}

		/**
		 * First missing required property, or '' when valid.
		 *
		 * @param array $e Entity.
		 * @return string
		 */
		public static function validate( array $e ) {
			$type = self::type_name( $e );
			if ( '' === $type ) {
				return '@type';
			}
			$req = isset( self::REQUIRED[ $type ] ) ? self::REQUIRED[ $type ] : array();
			foreach ( $req as $prop ) {
				if ( is_array( $prop ) ) {
					$any = false;
					foreach ( $prop as $p ) {
						if ( self::present( $e, $p ) ) {
							$any = true;
							break;
						}
					}
					if ( ! $any ) {
						return $prop[0];
					}
				} elseif ( ! self::present( $e, $prop ) ) {
					return $prop;
				}
			}
			if ( 'FAQPage' === $type ) {
				foreach ( (array) ( isset( $e['mainEntity']['@type'] ) ? array( $e['mainEntity'] ) : $e['mainEntity'] ) as $q ) {
					if ( ! is_array( $q ) || empty( $q['name'] ) ) {
						return 'mainEntity.name';
					}
					if ( empty( $q['acceptedAnswer']['text'] ) ) {
						return 'mainEntity.acceptedAnswer';
					}
				}
			}
			if ( 'BreadcrumbList' === $type ) {
				foreach ( (array) $e['itemListElement'] as $li ) {
					if ( empty( $li['name'] ) ) {
						return 'itemListElement.name';
					}
				}
			}
			if ( 'Review' === $type ) {
				foreach ( array( 'itemReviewed', 'author' ) as $p ) {
					if ( is_array( $e[ $p ] ) && ! self::filled( $e[ $p ], 'name' ) && ! isset( $e[ $p ]['@id'] ) ) {
						return $p . '.name';
					}
				}
				if ( is_array( $e['reviewRating'] ) && ! self::filled( $e['reviewRating'], 'ratingValue' ) ) {
					return 'reviewRating.ratingValue';
				}
			}
			if ( 'AggregateRating' === $type && is_array( $e['itemReviewed'] ) && ! self::filled( $e['itemReviewed'], 'name' ) && ! isset( $e['itemReviewed']['@id'] ) ) {
				return 'itemReviewed.name';
			}
			if ( 'HowTo' === $type ) {
				foreach ( (array) ( isset( $e['step']['@type'] ) ? array( $e['step'] ) : $e['step'] ) as $st ) {
					if ( is_array( $st ) && ! self::filled( $st, 'text' ) && ! self::filled( $st, 'itemListElement' ) && ! self::filled( $st, 'name' ) ) {
						return 'step.text';
					}
				}
			}
			if ( 'Event' === $type && is_array( $e['location'] ) && isset( $e['location']['@type'] ) ) {
				$lt = self::type_name( $e['location'] );
				if ( 'VirtualLocation' === $lt && ! self::filled( $e['location'], 'url' ) ) {
					return 'location.url';
				}
				if ( 'Place' === $lt && ! self::filled( $e['location'], 'address' ) ) {
					return 'location.address';
				}
			}
			if ( 'ItemList' === $type ) {
				foreach ( (array) ( isset( $e['itemListElement']['@type'] ) ? array( $e['itemListElement'] ) : $e['itemListElement'] ) as $li ) {
					if ( is_array( $li ) && 'ListItem' === self::type_name( $li ) ) {
						if ( ! self::filled( $li, 'position' ) ) {
							return 'itemListElement.position';
						}
						if ( ! self::filled( $li, 'url' ) && ! self::filled( $li, 'item' ) ) {
							return 'itemListElement.url';
						}
					}
				}
			}
			return '';
		}

		/**
		 * First @type of an entity, as a string.
		 *
		 * @param array $e Entity.
		 * @return string
		 */
		private static function type_name( array $e ) {
			$t = isset( $e['@type'] ) ? $e['@type'] : '';
			return is_array( $t ) ? (string) reset( $t ) : (string) $t;
		}

		/**
		 * Non-empty value at a key of a nested object?
		 *
		 * @param array  $o Object.
		 * @param string $p Property.
		 * @return bool
		 */
		private static function filled( array $o, $p ) {
			if ( ! isset( $o[ $p ] ) ) {
				return false;
			}
			$v = $o[ $p ];
			return is_string( $v ) ? '' !== trim( $v ) : ( array() !== $v );
		}

		/**
		 * Paths of the properties that are present but empty ("name": "", [] or an
		 * object holding only its @type), e.g. [ 'itemReviewed.name', 'reviewBody' ].
		 *
		 * @param array  $e      Entity (or list).
		 * @param string $prefix Path so far.
		 * @return string[]
		 */
		public static function empties( array $e, $prefix = '' ) {
			$out  = array();
			$list = array_keys( $e ) === range( 0, count( $e ) - 1 );
			foreach ( $e as $k => $v ) {
				if ( is_string( $k ) && '@' === substr( $k, 0, 1 ) ) {
					continue;
				}
				// Lists keep the parent's path: "mainEntity.name", not "mainEntity.0.name".
				$path = $list ? rtrim( $prefix, '.' ) : $prefix . $k;
				if ( is_string( $v ) && '' === trim( $v ) ) {
					$out[] = $path;
				} elseif ( is_array( $v ) ) {
					if ( array() === $v || array() === array_diff( array_keys( $v ), array( '@type' ) ) ) {
						$out[] = $path;
					} else {
						$out = array_merge( $out, self::empties( $v, $path . '.' ) );
					}
				}
			}
			return array_values( array_unique( $out ) );
		}

		/**
		 * Recursively drop empty values: '' strings, empty lists and objects left
		 * with nothing but their @type. @context, @type and @id are never dropped,
		 * and the entity itself is always kept.
		 *
		 * @param array $e     Entity.
		 * @param int   $depth Depth (0 = the entity).
		 * @return array
		 */
		public static function prune( array $e, $depth = 0 ) {
			if ( $depth > 12 ) {
				return $e;
			}
			$list = array() !== $e && array_keys( $e ) === range( 0, count( $e ) - 1 );
			foreach ( $e as $k => $v ) {
				if ( in_array( $k, array( '@context', '@type', '@id' ), true ) ) {
					continue;
				}
				if ( is_string( $v ) && '' === trim( $v ) ) {
					unset( $e[ $k ] );
				} elseif ( is_array( $v ) ) {
					$v = self::prune( $v, $depth + 1 );
					if ( array() === $v || ( isset( $v['@type'] ) && array() === array_diff( array_keys( $v ), array( '@type' ) ) ) ) {
						unset( $e[ $k ] );
					} else {
						$e[ $k ] = $v;
					}
				}
			}
			return $list ? array_values( $e ) : $e;
		}

		/**
		 * Non-empty property?
		 *
		 * @param array  $e Entity.
		 * @param string $p Property.
		 * @return bool
		 */
		private static function present( array $e, $p ) {
			return isset( $e[ $p ] ) && '' !== $e[ $p ] && array() !== $e[ $p ];
		}

		// ------------------------------------------------------------------
		// Output
		// ------------------------------------------------------------------

		/**
		 * `uichemy_seo_head_extra` — the JSON-LD block.
		 *
		 * @param string[] $lines Lines.
		 * @param array    $r     Resolved.
		 * @return string[]
		 */
		public static function head( $lines, $r ) {
			if ( ! self::enabled() || ! empty( $r['bare'] ) ) {
				return $lines;
			}
			$post = 'post' === $r['object']['kind'] ? get_post( $r['object']['id'] ) : null;
			$out  = self::build( $r, $post );
			if ( empty( $out['graph'] ) ) {
				return $lines;
			}
			$lines[] = self::script( $out['graph'] );
			return $lines;
		}

		/**
		 * JSON-LD values are text, not HTML: post titles arrive texturized
		 * ("&#8220;") and may carry markup ("<b>"). Decode entities everywhere and
		 * strip tags from the human-readable labels.
		 *
		 * @param mixed  $v   Value.
		 * @param string $key Property name.
		 * @return mixed
		 */
		private static function plain_text( $v, $key = '' ) {
			if ( is_array( $v ) ) {
				foreach ( $v as $k => $item ) {
					$v[ $k ] = self::plain_text( $item, is_string( $k ) ? $k : $key );
				}
				return $v;
			}
			if ( ! is_string( $v ) ) {
				return $v;
			}
			$v = html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( in_array( $key, array( 'name', 'headline', 'alternateName', 'description', 'caption' ), true ) ) {
				$v = trim( wp_strip_all_tags( $v ) );
			}
			return $v;
		}

		/**
		 * Encode safely: JSON_HEX_TAG means no string can close the script tag.
		 *
		 * @param array $graph Graph.
		 * @return string
		 */
		public static function script( array $graph ) {
			$json = wp_json_encode(
				array(
					'@context' => 'https://schema.org',
					'@graph'   => array_values( self::plain_text( $graph ) ),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
			);
			return '<script type="application/ld+json">' . $json . '</script>';
		}

		/**
		 * Audit #32 — entities suppressed by validation.
		 *
		 * @param array $errs    Errors.
		 * @param int   $post_id Post id.
		 * @param array $record  Record.
		 * @return array
		 */
		public static function audit_errors( $errs, $post_id, $record ) {
			if ( ! self::enabled() ) {
				return $errs;
			}
			$swap = function ( $value, $object_id, $meta_key ) use ( $post_id, $record ) {
				return ( (int) $object_id === (int) $post_id && Uich_SEO_Record::META === $meta_key ) ? array( $record ) : $value;
			};
			add_filter( 'get_post_metadata', $swap, 1, 3 );
			$r = Uich_SEO_Resolver::for_post( $post_id );
			$o = $r ? self::build( $r, get_post( $post_id ) ) : array( 'errors' => array() );
			remove_filter( 'get_post_metadata', $swap, 1 );
			return array_merge( (array) $errs, $o['errors'] );
		}

		/**
		 * Types the page publishes (so the audit stops asking for them).
		 *
		 * @param array $types  Types.
		 * @param array $record Record.
		 * @return array
		 */
		public static function published_types( $types, $record ) {
			if ( self::enabled() && ! empty( $record['derived']['breadcrumbs'] ) ) {
				$types['BreadcrumbList'] = true;
			}
			return $types;
		}

		/**
		 * Yoast owns the head: append our contributed entities to its graph.
		 *
		 * @param array $graph   Yoast pieces.
		 * @param mixed $context Yoast context.
		 * @return array
		 */
		public static function into_yoast( $graph, $context = null ) {
			$own = Uich_SEO_Ownership::resolve();
			if ( 'off' === $own['owner'] || Uich_SEO_Ownership::we_emit() || ! self::enabled() || ! is_singular() ) {
				return $graph;
			}
			$r = Uich_SEO_Resolver::for_request();
			if ( ! $r ) {
				return $graph;
			}
			$out = self::build( $r, get_queried_object(), array( 'only_contributed' => true ) );
			return array_merge( (array) $graph, $out['graph'] );
		}

		/**
		 * Rank Math owns the head: same, keyed.
		 *
		 * @param array $data   Rank Math entities.
		 * @param mixed $jsonld Rank Math object.
		 * @return array
		 */
		public static function into_rankmath( $data, $jsonld = null ) {
			$own = Uich_SEO_Ownership::resolve();
			if ( 'off' === $own['owner'] || Uich_SEO_Ownership::we_emit() || ! self::enabled() || ! is_singular() ) {
				return $data;
			}
			$r = Uich_SEO_Resolver::for_request();
			if ( ! $r ) {
				return $data;
			}
			$out = self::build( $r, get_queried_object(), array( 'only_contributed' => true ) );
			foreach ( $out['graph'] as $i => $e ) {
				$data[ 'uichemy-' . strtolower( self::type_name( $e ) ) . '-' . $i ] = $e;
			}
			return $data;
		}

		/**
		 * Breadcrumb trail from the page's place on the site, for pages that have no
		 * trail in their markup: Home › parent pages (or the blog page for posts) ›
		 * this page. The front page gets none: a one-item trail is not a trail.
		 *
		 * @param WP_Post $post Post.
		 * @param array   $r    Resolved values.
		 * @return array<int,array{name:string,url:string}>
		 */
		private static function path_crumbs( $post, array $r ) {
			if ( (int) get_option( 'page_on_front' ) === (int) $post->ID ) {
				return array();
			}
			$items = array(
				array(
					'name' => __( 'Home', 'uichemy' ),
					'url'  => home_url( '/' ),
				),
			);
			if ( 'post' === $post->post_type && (int) get_option( 'page_for_posts' ) ) {
				$blog    = (int) get_option( 'page_for_posts' );
				$items[] = array(
					'name' => Uich_SEO_Html::title( get_the_title( $blog ) ),
					'url'  => get_permalink( $blog ),
				);
			} elseif ( 'post' !== $post->post_type && 'page' !== $post->post_type ) {
				$obj  = get_post_type_object( $post->post_type );
				$link = get_post_type_archive_link( $post->post_type );
				if ( $obj && $link ) {
					$items[] = array(
						'name' => $obj->labels->name,
						'url'  => $link,
					);
				}
			}
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $aid ) {
				if ( 'publish' === get_post_status( $aid ) ) {
					$items[] = array(
						'name' => Uich_SEO_Html::title( get_the_title( $aid ) ),
						'url'  => get_permalink( $aid ),
					);
				}
			}
			$items[] = array(
				'name' => isset( $r['title']['core'] ) && '' !== $r['title']['core'] ? (string) $r['title']['core'] : Uich_SEO_Html::title( get_the_title( $post ) ),
				'url'  => '',
			);
			return $items;
		}

		/**
		 * What a post emits, for the panel (§13.4).
		 *
		 * @param int $post_id Post id.
		 * @return array{entities:array,errors:array,graph:array}
		 */
		public static function for_post( $post_id ) {
			$r = Uich_SEO_Resolver::for_post( $post_id );
			if ( ! $r ) {
				return array( 'entities' => array(), 'errors' => array(), 'graph' => array() );
			}
			$o = self::build( $r, get_post( $post_id ) );
			return $o;
		}

		// ------------------------------------------------------------------
		// Templates
		// ------------------------------------------------------------------

		/**
		 * All templates.
		 *
		 * @return array
		 */
		public static function templates() {
			$out = array();
			foreach ( get_posts( array( 'post_type' => self::CPT, 'post_status' => array( 'publish', 'draft' ), 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
				$out[] = self::template( $p );
			}
			return $out;
		}

		/**
		 * One template.
		 *
		 * @param WP_Post|int $p Post.
		 * @return array|null
		 */
		public static function template( $p ) {
			$p = get_post( $p );
			if ( ! $p || self::CPT !== $p->post_type ) {
				return null;
			}
			$cond = get_post_meta( $p->ID, '_uich_schema_conditions', true );
			return array(
				'id'         => (int) $p->ID,
				'name'       => $p->post_title,
				'type'       => (string) get_post_meta( $p->ID, '_uich_schema_type', true ),
				'conditions' => self::normalize_conditions( is_array( $cond ) ? $cond : array() ),
				'template'   => (string) $p->post_content,
				'active'     => 'publish' === $p->post_status,
			);
		}

		/**
		 * Templates whose conditions match a post.
		 *
		 * @param WP_Post $post Post.
		 * @return array
		 */
		public static function templates_for( $post ) {
			if ( null === self::$active ) {
				self::$active = array_values( array_filter( self::templates(), function ( $t ) {
					return $t['active'];
				} ) );
			}
			return array_values( array_filter( self::$active, function ( $t ) use ( $post ) {
				// Older section-level templates (an FAQ stamped on every page) never print.
				return ! self::is_section_type( isset( $t['type'] ) ? (string) $t['type'] : '' ) && self::matches( $t['conditions'], $post );
			} ) );
		}

		/**
		 * May this type not be a template?
		 *
		 * @param string $type Schema type.
		 * @return bool
		 */
		public static function is_section_type( $type ) {
			return in_array( $type, self::SECTION_TYPES, true ) || in_array( $type, self::SITE_TYPES, true );
		}

		/** Rule types a schema template can use (the theme builder's, minus visitor and archive rules). */
		const RULE_TYPES = array( 'entire', 'all_singulars', 'front_page', 'blog', 'post_type', 'taxonomy', 'singular', 'author' );

		/**
		 * Conditions in the theme builder's shape: { rules: [ { match, type, value?, taxonomy?, term? } ] }.
		 * Older templates stored { post_types, post_ids, terms }; those are converted
		 * to include rules so they keep matching exactly the same pages.
		 *
		 * @param array $c Raw conditions.
		 * @return array{rules:array}
		 */
		public static function normalize_conditions( array $c ) {
			$rules = array();
			if ( isset( $c['rules'] ) && is_array( $c['rules'] ) ) {
				foreach ( array_slice( $c['rules'], 0, 50 ) as $r ) {
					if ( ! is_array( $r ) ) {
						continue;
					}
					$type = isset( $r['type'] ) ? sanitize_key( $r['type'] ) : '';
					if ( ! in_array( $type, self::RULE_TYPES, true ) ) {
						continue;
					}
					$out = array(
						'match' => ( isset( $r['match'] ) && 'exclude' === $r['match'] ) ? 'exclude' : 'include',
						'type'  => $type,
					);
					if ( 'post_type' === $type ) {
						$out['value'] = sanitize_key( isset( $r['value'] ) ? (string) $r['value'] : '' );
						if ( '' === $out['value'] ) {
							continue;
						}
					} elseif ( 'singular' === $type ) {
						$out['value'] = absint( isset( $r['value'] ) ? $r['value'] : 0 );
						if ( ! $out['value'] ) {
							continue;
						}
					} elseif ( 'author' === $type ) {
						$out['value'] = absint( isset( $r['value'] ) ? $r['value'] : 0 );
					} elseif ( 'taxonomy' === $type ) {
						$out['taxonomy'] = sanitize_key( isset( $r['taxonomy'] ) ? (string) $r['taxonomy'] : '' );
						$out['term']     = absint( isset( $r['term'] ) ? $r['term'] : 0 );
						if ( '' === $out['taxonomy'] ) {
							continue;
						}
					}
					$rules[] = $out;
				}
				return array( 'rules' => $rules );
			}
			// Legacy shape.
			$types = isset( $c['post_types'] ) ? array_filter( array_map( 'sanitize_key', (array) $c['post_types'] ) ) : array();
			$terms = isset( $c['terms'] ) ? array_filter( array_map( 'absint', (array) $c['terms'] ) ) : array();
			$ids   = isset( $c['post_ids'] ) ? array_filter( array_map( 'absint', (array) $c['post_ids'] ) ) : array();
			foreach ( $types as $pt ) {
				if ( ! $terms ) {
					$rules[] = array( 'match' => 'include', 'type' => 'post_type', 'value' => $pt );
				}
			}
			foreach ( $terms as $tid ) {
				$t = get_term( $tid );
				if ( $t && ! is_wp_error( $t ) ) {
					$rules[] = array( 'match' => 'include', 'type' => 'taxonomy', 'taxonomy' => $t->taxonomy, 'term' => (int) $tid );
				}
			}
			foreach ( $ids as $id ) {
				$rules[] = array( 'match' => 'include', 'type' => 'singular', 'value' => (int) $id );
			}
			return array( 'rules' => $rules );
		}

		/**
		 * Whether one rule matches a post. Evaluated per post when its SEO is
		 * built, not per request, so visitor rules do not exist here.
		 *
		 * @param array   $r    Rule.
		 * @param WP_Post $post Post.
		 * @return bool
		 */
		private static function rule_matches( array $r, $post ) {
			switch ( $r['type'] ) {
				case 'entire':
				case 'all_singulars':
					return true;
				case 'front_page':
					return 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post->ID;
				case 'blog':
					return (int) get_option( 'page_for_posts' ) === (int) $post->ID;
				case 'post_type':
					return $post->post_type === $r['value'];
				case 'singular':
					return (int) $r['value'] === (int) $post->ID;
				case 'author':
					return 0 === (int) $r['value'] || (int) $post->post_author === (int) $r['value'];
				case 'taxonomy':
					if ( ! taxonomy_exists( $r['taxonomy'] ) ) {
						return false;
					}
					if ( $r['term'] > 0 ) {
						return has_term( (int) $r['term'], $r['taxonomy'], $post );
					}
					$t = get_the_terms( $post, $r['taxonomy'] );
					return is_array( $t ) && ! empty( $t );
			}
			return false;
		}

		/**
		 * Condition match, same logic as the theme builder: a matching Exclude
		 * always wins, otherwise any matching Include. No includes, no match.
		 *
		 * @param array   $c    Conditions (any shape).
		 * @param WP_Post $post Post.
		 * @return bool
		 */
		public static function matches( array $c, $post ) {
			$rules = self::normalize_conditions( $c )['rules'];
			$hit   = false;
			foreach ( $rules as $r ) {
				if ( ! self::rule_matches( $r, $post ) ) {
					continue;
				}
				if ( 'exclude' === $r['match'] ) {
					return false;
				}
				$hit = true;
			}
			return $hit;
		}

		/**
		 * Published posts that could match: narrowed by the include rules, then
		 * checked one by one so excludes are honoured. Capped for large sites.
		 *
		 * @param array $c     Conditions.
		 * @param int   $limit Max ids to return.
		 * @return int[]
		 */
		public static function matching_ids( array $c, $limit = 5000 ) {
			$rules = self::normalize_conditions( $c )['rules'];
			$types = array();
			$ids   = array();
			$wide  = false;
			foreach ( $rules as $r ) {
				if ( 'include' !== $r['match'] ) {
					continue;
				}
				switch ( $r['type'] ) {
					case 'entire':
					case 'all_singulars':
					case 'author':
						$wide = true;
						break;
					case 'post_type':
						$types[] = $r['value'];
						break;
					case 'taxonomy':
						$types = array_merge( $types, (array) ( taxonomy_exists( $r['taxonomy'] ) ? get_taxonomy( $r['taxonomy'] )->object_type : array() ) );
						break;
					case 'singular':
						$ids[] = (int) $r['value'];
						break;
					case 'front_page':
						$ids[] = (int) get_option( 'page_on_front' );
						break;
					case 'blog':
						$ids[] = (int) get_option( 'page_for_posts' );
						break;
				}
			}
			if ( $wide ) {
				$types = Uich_SEO_Settings::content_types();
			}
			$types = array_values( array_diff( array_unique( $types ), array( 'attachment' ) ) );
			$cand  = array_filter( $ids );
			if ( $types ) {
				$cand = array_merge( $cand, get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => $limit, 'fields' => 'ids', 'orderby' => 'modified', 'no_found_rows' => true ) ) );
			}
			$cand = array_values( array_unique( array_map( 'intval', $cand ) ) );
			if ( ! $cand ) {
				return array();
			}
			_prime_post_caches( $cand, false, false );
			update_object_term_cache( $cand, $types ? $types : 'post' );
			$out = array();
			foreach ( $cand as $id ) {
				$p = get_post( $id );
				if ( $p && 'publish' === $p->post_status && self::matches( array( 'rules' => $rules ), $p ) ) {
					$out[] = $id;
				}
			}
			return $out;
		}

		/**
		 * Pages a template currently matches.
		 *
		 * @param array $c Conditions.
		 * @return int
		 */
		public static function count_matches( array $c ) {
			return count( self::matching_ids( $c ) );
		}

		/**
		 * Rules with a readable label each, for the dialog and the summary chips.
		 *
		 * @param array $c Conditions.
		 * @return array
		 */
		public static function rules_labels( array $c ) {
			$names = array(
				'entire'        => __( 'Entire Website', 'uichemy' ),
				'all_singulars' => __( 'All Singulars', 'uichemy' ),
				'front_page'    => __( 'Front Page', 'uichemy' ),
				'blog'          => __( 'Blog / Posts Page', 'uichemy' ),
			);
			$out = array();
			foreach ( self::normalize_conditions( $c )['rules'] as $r ) {
				$label = isset( $names[ $r['type'] ] ) ? $names[ $r['type'] ] : $r['type'];
				if ( 'post_type' === $r['type'] ) {
					$o = get_post_type_object( $r['value'] );
					/* translators: %s: post type plural. */
					$label = sprintf( __( 'All %s', 'uichemy' ), $o ? $o->labels->name : $r['value'] );
				} elseif ( 'singular' === $r['type'] ) {
					$label = get_the_title( (int) $r['value'] );
					$label = '' !== $label ? $label : '#' . (int) $r['value'];
				} elseif ( 'author' === $r['type'] ) {
					$u = $r['value'] ? get_userdata( (int) $r['value'] ) : null;
					/* translators: %s: author name. */
					$label = $u ? sprintf( __( 'Author: %s', 'uichemy' ), $u->display_name ) : __( 'Any author', 'uichemy' );
				} elseif ( 'taxonomy' === $r['type'] ) {
					$tx    = get_taxonomy( $r['taxonomy'] );
					$label = $tx ? $tx->labels->singular_name : $r['taxonomy'];
					if ( $r['term'] ) {
						$t     = get_term( (int) $r['term'] );
						$label = $label . ': ' . ( $t && ! is_wp_error( $t ) ? $t->name : '#' . (int) $r['term'] );
					}
				}
				$out[] = $r + array( 'label' => $label );
			}
			return $out;
		}

		/**
		 * Render a template's Twig against a post → entity.
		 *
		 * @param array   $tpl  Template.
		 * @param WP_Post $post Post.
		 * @return array{entity:?array,error:string}
		 */
		public static function render_template( array $tpl, $post ) {
			$src = (string) $tpl['template'];
			if ( class_exists( 'Uich_Dynamic' ) && Uich_Dynamic::has_dynamic( $src ) ) {
				Uich_Dynamic::boot(); // post_provider() does not load its own class.
				$ctx = array(
					'post' => Uich_Dynamic::post_provider( $post ),
					'site' => Uich_SEO_Settings::twig_site(),
				);
				if ( 'product' === $post->post_type ) {
					$ctx['product'] = $ctx['post'];
				}
				$src = self::json_strings_safe( (string) Uich_Dynamic::render_html( $src, $ctx ) );
			}
			$data = json_decode( $src, true );
			if ( ! is_array( $data ) ) {
				return array( 'entity' => null, 'error' => __( 'Once the dynamic values are filled in, this is not valid JSON. Check for missing commas, quotes or brackets.', 'uichemy' ) );
			}
			if ( empty( $data['@type'] ) && ! empty( $tpl['type'] ) ) {
				$data = array( '@type' => $tpl['type'] ) + $data;
			}
			unset( $data['@context'] );
			return array( 'entity' => $data, 'error' => '' );
		}

		/**
		 * Make the JSON string literals of a rendered template legal. Dynamic values
		 * are HTML-escaped (so a quote cannot end a string), but a line break, tab or
		 * backslash in an excerpt or content was copied in raw, which JSON does not
		 * allow: the whole entity then failed and was silently left off the page.
		 *
		 * @param string $json Rendered template.
		 * @return string
		 */
		public static function json_strings_safe( $json ) {
			$out = preg_replace_callback(
				'/"(?:[^"\\\\]|\\\\.)*"/s',
				function ( $m ) {
					$inner = substr( $m[0], 1, -1 );
					// A backslash that does not start a JSON escape is a literal one.
					$inner = preg_replace( '/\\\\(?![\"\\\\\/bfnrtu])/', '\\\\\\\\', $inner );
					$inner = strtr( $inner, array( "\r\n" => '\\n', "\r" => '\\n', "\n" => '\\n', "\t" => '\\t' ) );
					$inner = preg_replace_callback(
						'/[\x00-\x1F]/',
						function ( $c ) {
							return sprintf( '\\u%04x', ord( $c[0] ) );
						},
						$inner
					);
					return '"' . $inner . '"';
				},
				(string) $json
			);
			return null === $out ? (string) $json : $out;
		}

		/**
		 * Validate + save a template (PRD §8.2 copy).
		 *
		 * @param array $in       { id?, name, type, conditions, template, active }.
		 * @param bool  $activate Save and activate.
		 * @return array|WP_Error
		 */
		public static function save_template( array $in, $activate = false ) {
			$errors   = array();
			$warnings = array();
			$name     = sanitize_text_field( isset( $in['name'] ) ? (string) $in['name'] : '' );
			$type     = preg_replace( '/[^A-Za-z]/', '', isset( $in['type'] ) ? (string) $in['type'] : '' );
			$tpl      = isset( $in['template'] ) ? (string) $in['template'] : '';
			$cond_in  = isset( $in['conditions'] ) && is_array( $in['conditions'] ) ? $in['conditions'] : array();
			$cond     = self::normalize_conditions( $cond_in );
			$active   = $activate || ! empty( $in['active'] );

			if ( '' === $name ) {
				$name = $type ? $type : __( 'Untitled template', 'uichemy' );
			}
			if ( '' === $type ) {
				$errors['type'] = __( 'Choose what kind of thing this describes.', 'uichemy' );
			} elseif ( in_array( $type, self::SITE_TYPES, true ) ) {
				$errors['type'] = __( 'Your organisation or person is set once in SEO → Site Settings, not as a template.', 'uichemy' );
			} elseif ( in_array( $type, self::SECTION_TYPES, true ) ) {
				/* translators: %s: schema type. */
				$errors['type'] = sprintf( __( '%s describes one part of one page, so it is not a template. Add it as JSON-LD in that section (Schema, in the editor) instead.', 'uichemy' ), $type );
			}
			if ( ! array_filter( $cond['rules'], function ( $r ) {
				return 'include' === $r['match'];
			} ) ) {
				$errors['conditions'] = __( 'Add at least one Include condition, or this template is never used.', 'uichemy' );
			}
			// Unknown dynamic values (warning, not error).
			if ( preg_match_all( '/\{\{\s*([a-z_]+)(?:\.[a-z_.]+)?/i', $tpl, $m ) ) {
				foreach ( array_unique( $m[1] ) as $root ) {
					if ( ! in_array( $root, array( 'post', 'site', 'product' ), true ) ) {
						/* translators: %s: token. */
						$warnings[] = sprintf( __( '{{ %s }} is not a known value, so it will be left empty.', 'uichemy' ), $root );
					}
				}
			}
			// Validate against a sample page so required properties are checked with real values.
			$sample = self::sample_post( $cond );
			if ( ! isset( $errors['type'] ) ) {
				$probe = array(
					'id'       => 0,
					'type'     => $type,
					'template' => $tpl,
				);
				$r     = $sample ? self::render_template( $probe, $sample ) : array( 'entity' => json_decode( preg_replace( '/\{\{.*?\}\}/s', 'x', $tpl ), true ), 'error' => '' );
				if ( $r['error'] || ! is_array( $r['entity'] ) ) {
					$errors['template'] = $r['error'] ? $r['error'] : __( 'Once the dynamic values are filled in, this is not valid JSON. Check for missing commas, quotes or brackets.', 'uichemy' );
				} elseif ( $active ) {
					$missing = self::validate( self::normalize( $r['entity'] ) );
					if ( $missing ) {
						/* translators: 1: property, 2: type. */
						$errors['template'] = sprintf( __( 'Google needs "%1$s" for this %2$s. Add it before turning this template on.', 'uichemy' ), $missing, $type );
					}
				}
			}
			if ( $errors ) {
				return new WP_Error( 'uich_seo_schema_invalid', __( 'This template could not be saved.', 'uichemy' ), array( 'status' => 400, 'fields' => $errors ) );
			}

			$postarr = array(
				'post_type'    => self::CPT,
				'post_title'   => $name,
				'post_content' => wp_slash( $tpl ),
				'post_status'  => $active ? 'publish' : 'draft',
			);
			if ( ! empty( $in['id'] ) && self::template( (int) $in['id'] ) ) {
				$postarr['ID'] = (int) $in['id'];
			}
			// Template JSON is data, not HTML: bypass kses so quotes survive for authors with unfiltered_html.
			kses_remove_filters();
			$id = wp_insert_post( $postarr, true );
			kses_init_filters();
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			update_post_meta( $id, '_uich_schema_type', $type );
			update_post_meta( $id, '_uich_schema_conditions', $cond );
			self::$active = null;
			$out             = self::template( $id );
			$out['warnings'] = $warnings;
			$out['pages']    = self::count_matches( $cond );
			return $out;
		}

		/**
		 * Forget the per-request template cache (after a delete).
		 *
		 * @return void
		 */
		public static function flush() {
			self::$active = null;
		}

		/**
		 * A published page the conditions match, for previews and validation.
		 *
		 * @param array $c Conditions.
		 * @return WP_Post|null
		 */
		public static function sample_post( array $c ) {
			$ids = self::matching_ids( $c, 200 );
			return $ids ? get_post( $ids[0] ) : null;
		}

		/**
		 * Starter templates: one click opens the editor pre-filled, inactive.
		 * Values use the dynamic tokens Free ships ({{ post.title }}, {{ post.url }},
		 * {{ post.thumbnail.src }}, {{ site.name }}, {{ site.url }}); empty strings
		 * are the details only the author knows (a price, a start date).
		 *
		 * Covers every type SureRank offers (free + Pro) plus a few more.
		 *
		 * @return array<int,array{key:string,name:string,type:string,desc:string,post_types:string[],template:string}>
		 */
		public static function presets() {
			$post_pt    = post_type_exists( 'post' ) ? array( 'post' ) : array();
			$page_pt    = post_type_exists( 'page' ) ? array( 'page' ) : array();
			$product_pt = post_type_exists( 'product' ) ? array( 'product' ) : $page_pt;
			$title      = '{{ post.title }}';
			$url        = '{{ post.url }}';
			$img        = '{{ post.thumbnail.src }}';
			$org        = array( '@type' => 'Organization', 'name' => '{{ site.name }}', 'url' => '{{ site.url }}' );

			$list = array(
				array( 'faq', __( 'FAQ page', 'uichemy' ), 'FAQPage', __( 'Questions and answers that can show as expandable answers in Google. This is already done for accordions on your pages.', 'uichemy' ), $page_pt, array(
					'mainEntity' => array(
						array( '@type' => 'Question', 'name' => '', 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => '' ) ),
						array( '@type' => 'Question', 'name' => '', 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => '' ) ),
					),
				) ),
				array( 'howto', __( 'How to guide', 'uichemy' ), 'HowTo', __( 'Instructions, step by step.', 'uichemy' ), $post_pt, array(
					'name' => $title, 'image' => $img, 'totalTime' => '',
					'step' => array( array( '@type' => 'HowToStep', 'name' => '', 'text' => '' ), array( '@type' => 'HowToStep', 'name' => '', 'text' => '' ) ),
				) ),
				array( 'recipe', __( 'Recipe', 'uichemy' ), 'Recipe', __( 'Cooking time, ingredients and steps for recipe cards in search.', 'uichemy' ), $post_pt, array(
					'name' => $title, 'image' => $img, 'author' => array( '@type' => 'Person', 'name' => '' ),
					'prepTime' => '', 'cookTime' => '', 'recipeYield' => '', 'recipeCategory' => '', 'recipeCuisine' => '',
					'recipeIngredient' => array( '' ), 'recipeInstructions' => array( array( '@type' => 'HowToStep', 'text' => '' ) ),
				) ),
				array( 'product', __( 'Product', 'uichemy' ), 'Product', __( 'Price, stock and code for products. WooCommerce products get this automatically.', 'uichemy' ), $product_pt, array(
					'name' => $title, 'image' => $img, 'url' => $url, 'sku' => '', 'brand' => array( '@type' => 'Brand', 'name' => '{{ site.name }}' ),
					'offers' => array( '@type' => 'Offer', 'price' => '', 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock', 'url' => $url ),
				) ),
				array( 'service', __( 'Service', 'uichemy' ), 'Service', __( 'A service you offer, such as consulting or repairs.', 'uichemy' ), $page_pt, array(
					'name' => $title, 'url' => $url, 'serviceType' => '', 'provider' => $org, 'areaServed' => '',
				) ),
				array( 'localbusiness', __( 'Local business', 'uichemy' ), 'LocalBusiness', __( 'Address, phone and opening hours for a shop or office.', 'uichemy' ), $page_pt, array(
					'name' => '{{ site.name }}', 'url' => '{{ site.url }}', 'image' => $img, 'telephone' => '', 'priceRange' => '',
					'address' => array( '@type' => 'PostalAddress', 'streetAddress' => '', 'addressLocality' => '', 'postalCode' => '', 'addressCountry' => '' ),
					'openingHours' => array( 'Mo-Fr 09:00-17:00' ),
				) ),
				array( 'event', __( 'Event', 'uichemy' ), 'Event', __( 'Date, place and tickets for an event.', 'uichemy' ), $post_pt, array(
					'name' => $title, 'image' => $img, 'url' => $url, 'startDate' => '', 'endDate' => '',
					'eventStatus' => 'https://schema.org/EventScheduled', 'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
					'location' => array( '@type' => 'Place', 'name' => '', 'address' => '' ), 'organizer' => $org,
					'offers' => array( '@type' => 'Offer', 'price' => '', 'priceCurrency' => 'USD', 'url' => $url, 'availability' => 'https://schema.org/InStock' ),
				) ),
				array( 'course', __( 'Course', 'uichemy' ), 'Course', __( 'A course taught online or in person.', 'uichemy' ), $post_pt, array(
					'name' => $title, 'description' => '', 'url' => $url, 'provider' => $org,
				) ),
				array( 'job', __( 'Job posting', 'uichemy' ), 'JobPosting', __( 'A job opening that can appear in Google for Jobs.', 'uichemy' ), $post_pt, array(
					'title' => $title, 'description' => '', 'datePosted' => '', 'validThrough' => '', 'employmentType' => 'FULL_TIME',
					'hiringOrganization' => $org,
					'jobLocation' => array( '@type' => 'Place', 'address' => array( '@type' => 'PostalAddress', 'addressLocality' => '', 'addressCountry' => '' ) ),
				) ),
				array( 'video', __( 'Video', 'uichemy' ), 'VideoObject', __( 'A video on the page, for video results.', 'uichemy' ), $post_pt, array(
					'name' => $title, 'description' => '', 'thumbnailUrl' => $img, 'uploadDate' => '', 'duration' => '', 'contentUrl' => '', 'embedUrl' => '',
				) ),
				array( 'podcast', __( 'Podcast episode', 'uichemy' ), 'PodcastEpisode', __( 'One episode of a podcast.', 'uichemy' ), $post_pt, array(
					'name' => $title, 'url' => $url, 'datePublished' => '', 'description' => '',
					'associatedMedia' => array( '@type' => 'MediaObject', 'contentUrl' => '' ),
					'partOfSeries' => array( '@type' => 'PodcastSeries', 'name' => '{{ site.name }}', 'url' => '{{ site.url }}' ),
				) ),
				array( 'software', __( 'Software or app', 'uichemy' ), 'SoftwareApplication', __( 'An app or program, with its price and rating.', 'uichemy' ), $page_pt, array(
					'name' => $title, 'url' => $url, 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => '',
					'offers' => array( '@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD' ),
				) ),
				array( 'review', __( 'Review', 'uichemy' ), 'Review', __( 'Your review of a product, place or thing, with a star rating.', 'uichemy' ), $post_pt, array(
					'name' => $title, 'itemReviewed' => array( '@type' => 'Thing', 'name' => '' ),
					'reviewRating' => array( '@type' => 'Rating', 'ratingValue' => '', 'bestRating' => '5' ),
					'author' => array( '@type' => 'Person', 'name' => '' ),
				) ),
				array( 'book', __( 'Book', 'uichemy' ), 'Book', __( 'A book, with its author and ISBN.', 'uichemy' ), $post_pt, array(
					'name' => $title, 'url' => $url, 'image' => $img, 'author' => array( '@type' => 'Person', 'name' => '' ), 'isbn' => '', 'numberOfPages' => '', 'bookFormat' => 'https://schema.org/Paperback',
				) ),
				array( 'movie', __( 'Movie', 'uichemy' ), 'Movie', __( 'A film, with its director and release date.', 'uichemy' ), $post_pt, array(
					'name' => $title, 'url' => $url, 'image' => $img, 'dateCreated' => '', 'director' => array( '@type' => 'Person', 'name' => '' ),
				) ),
				array( 'dataset', __( 'Dataset', 'uichemy' ), 'Dataset', __( 'A data set people can download, for Google Dataset Search.', 'uichemy' ), $page_pt, array(
					'name' => $title, 'description' => '', 'url' => $url, 'license' => '', 'creator' => $org,
					'distribution' => array( array( '@type' => 'DataDownload', 'encodingFormat' => 'CSV', 'contentUrl' => '' ) ),
				) ),
				array( 'claim', __( 'Fact check', 'uichemy' ), 'ClaimReview', __( 'Your verdict on a claim someone made.', 'uichemy' ), $post_pt, array(
					'url' => $url, 'claimReviewed' => '', 'author' => $org,
					'reviewRating' => array( '@type' => 'Rating', 'ratingValue' => '', 'bestRating' => '5', 'worstRating' => '1', 'alternateName' => '' ),
				) ),
				array( 'profile', __( 'Profile page', 'uichemy' ), 'ProfilePage', __( 'An about me or team member page.', 'uichemy' ), $page_pt, array(
					'mainEntity' => array( '@type' => 'Person', 'name' => '', 'jobTitle' => '', 'image' => $img, 'sameAs' => array( '' ) ),
				) ),
				array( 'news', __( 'News article', 'uichemy' ), 'NewsArticle', __( 'For news sites: marks posts as news instead of a regular article.', 'uichemy' ), $post_pt, array(
					'headline' => $title, 'image' => array( $img ), 'url' => $url,
					'author' => array( '@type' => 'Person', 'name' => '' ), 'publisher' => $org,
				) ),
			);

			$out = array();
			foreach ( $list as $p ) {
				if ( self::is_section_type( $p[2] ) ) {
					continue;
				}
				$out[] = array(
					'key'        => $p[0],
					'name'       => $p[1],
					'type'       => $p[2],
					'desc'       => $p[3],
					'post_types' => $p[4],
					'template'   => (string) wp_json_encode( array( '@type' => $p[2] ) + $p[5], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				);
			}
			/**
			 * Filter the ready-made schema templates.
			 *
			 * @param array $out Presets.
			 */
			return (array) apply_filters( 'uichemy_seo_schema_presets', $out );
		}

		/**
		 * Starter Twig for a type (editor convenience).
		 *
		 * @param string $type Type.
		 * @return string
		 */
		public static function starter( $type ) {
			foreach ( self::presets() as $p ) {
				if ( $p['type'] === $type ) {
					return $p['template'];
				}
			}
			$starters = array(
				'Recipe'      => "{\n  \"@type\": \"Recipe\",\n  \"name\": \"{{ post.title }}\",\n  \"image\": \"{{ post.thumbnail.src }}\",\n  \"cookTime\": \"30 min\",\n  \"recipeIngredient\": [\"\"],\n  \"recipeInstructions\": [{\"@type\": \"HowToStep\", \"text\": \"\"}]\n}",
				'Product'     => "{\n  \"@type\": \"Product\",\n  \"name\": \"{{ post.title }}\",\n  \"image\": \"{{ post.thumbnail.src }}\",\n  \"sku\": \"\",\n  \"offers\": {\"@type\": \"Offer\", \"price\": \"\", \"priceCurrency\": \"USD\", \"availability\": \"https://schema.org/InStock\"}\n}",
				'FAQPage'     => "{\n  \"@type\": \"FAQPage\",\n  \"mainEntity\": [\n    {\"@type\": \"Question\", \"name\": \"\", \"acceptedAnswer\": {\"@type\": \"Answer\", \"text\": \"\"}}\n  ]\n}",
				'HowTo'       => "{\n  \"@type\": \"HowTo\",\n  \"name\": \"{{ post.title }}\",\n  \"step\": [{\"@type\": \"HowToStep\", \"text\": \"\"}]\n}",
				'Event'       => "{\n  \"@type\": \"Event\",\n  \"name\": \"{{ post.title }}\",\n  \"startDate\": \"\",\n  \"location\": {\"@type\": \"Place\", \"name\": \"\", \"address\": \"\"}\n}",
				'LocalBusiness' => "{\n  \"@type\": \"LocalBusiness\",\n  \"name\": \"{{ site.name }}\",\n  \"address\": {\"@type\": \"PostalAddress\", \"streetAddress\": \"\", \"addressLocality\": \"\", \"postalCode\": \"\", \"addressCountry\": \"\"},\n  \"telephone\": \"\"\n}",
			);
			return isset( $starters[ $type ] ) ? $starters[ $type ] : "{\n  \"@type\": \"" . $type . "\",\n  \"name\": \"{{ post.title }}\"\n}";
		}
	}
}
