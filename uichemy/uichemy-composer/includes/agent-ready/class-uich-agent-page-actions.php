<?php
/**
 * Agent Ready ability actions for agent pages: preview, rebuild, hand-written copy, hide.
 *
 * Registered on the uichemy-composer/agent-ready ability through its action
 * filter. Notes are plain text (Markdown allowed, HTML stripped) because they
 * are published to agents as-is.
 *
 * @package UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Agent_Page_Actions' ) ) {

	/**
	 * Page-level actions.
	 */
	class Uich_Agent_Page_Actions {

		/**
		 * Longest note accepted, in characters.
		 */
		const NOTES_MAX = 4000;

		/**
		 * Longest hand-written agent file accepted, in characters.
		 */
		const MD_MAX = 200000;

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
			$actions['get-agent-file'] = array(
				'description' => 'Fetch the exact Markdown agents receive for a page on this site, with its HTTP status. Use it to check what an agent sees before and after a change.',
				'params'      => array(
					'url' => array(
						'type'        => 'string',
						'description' => 'Page URL or path on this site, e.g. "/menu/".',
					),
					'saved_only' => array(
						'type'        => 'boolean',
						'description' => 'Only read the saved file. When none is saved yet, answer saved:false with agent_url instead of requesting the page, so the caller can open agent_url itself.',
					),
				),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'get_agent_file' ),
			);
			$actions['list-agent-pages'] = array(
				'description' => 'Published public pages and posts with their agent page address, whether they are hidden from agents and whether they have agent notes. 50 per page, most recently changed first.',
				'params'      => array(
					'search' => array(
						'type'        => 'string',
						'description' => 'Optional words in the title.',
					),
					'page'   => array(
						'type'        => 'integer',
						'description' => 'Page of results, from 1.',
					),
					'type'   => array(
						'type'        => 'string',
						'description' => 'Optional post type slug, e.g. "page", "post" or a custom post type.',
					),
				),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'list_pages' ),
			);
			$actions['list-agent-types'] = array(
				'description' => 'The kinds of content agents can read (pages, posts and custom post types) that have at least one published public item, with a count and where each custom type comes from (UiChemy, ACF, JetEngine or other).',
				'params'      => array(),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'list_types' ),
			);
			$actions['regenerate']     = array(
				'description' => 'Throw away every cached agent file so each is rebuilt on its next request, and queue a warm-up of the most important pages.',
				'params'      => array(),
				'readonly'    => false,
				'callback'    => array( __CLASS__, 'regenerate' ),
			);
			$actions['get-page-notes'] = array(
				'description' => 'Read the hand-written agent file and the hide-from-agents flag of one or more posts or pages.',
				'params'      => array(
					'post_ids' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'Post IDs (max 100).',
					),
				),
				'readonly'    => true,
				'callback'    => array( __CLASS__, 'get_page_notes' ),
			);
			$actions['set-page-notes'] = array(
				'description' => 'Set the hide-from-agents flag and/or a hand-written agent file ("markdown", replaces the generated one; empty string goes back to the generated file) for up to 100 posts at once.',
				'params'      => array(
					'items' => array(
						'type'        => 'array',
						'description' => 'List of { post_id, hide?, markdown? }.',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'post_id' => array( 'type' => 'integer' ),
								'hide'    => array( 'type' => 'boolean' ),
								'markdown' => array( 'type' => 'string' ),
							),
							'required'   => array( 'post_id' ),
						),
					),
				),
				'readonly'    => false,
				'callback'    => array( __CLASS__, 'set_page_notes' ),
			);
			return $actions;
		}

		/**
		 * Action: get-agent-file.
		 *
		 * @param array $params { url, saved_only? }.
		 * @return array|WP_Error
		 */
		public static function get_agent_file( array $params ) {
			$url = isset( $params['url'] ) ? trim( (string) $params['url'] ) : '';
			if ( '' === $url ) {
				return new WP_Error( 'uich_agent_url', 'Pass "url": a page URL or path on this site.' );
			}
			if ( '/' === $url[0] ) {
				$url = home_url( $url );
			}
			if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				return new WP_Error( 'uich_agent_url', 'Only pages on this site can be fetched.' );
			}
			$md = Uich_Agent_Urls::md_url( $url );
			// Saved file first: no web request, so it is instant and never waits on another PHP worker.
			$saved = class_exists( 'Uich_Agent_Pages' ) ? Uich_Agent_Pages::cached_markdown( $md ) : false;
			if ( is_string( $saved ) ) {
				return array(
					'agent_url'    => $md,
					'status'       => 200,
					'content_type' => 'text/markdown; charset=utf-8',
					'bytes'        => strlen( $saved ),
					'markdown'     => mb_substr( $saved, 0, 60000 ),
					'truncated'    => strlen( $saved ) > 60000,
				);
			}
			// The dashboard opens the page from the browser instead: a request from PHP to
			// its own site waits for a free PHP worker, and on hosts with only one or two
			// workers it can wait until it times out.
			if ( ! empty( $params['saved_only'] ) ) {
				return array(
					'agent_url' => $md,
					'saved'     => false,
				);
			}
			$resp = Uich_Agent_Urls::fetch_own( $md );
			if ( is_wp_error( $resp ) ) {
				return new WP_Error( 'uich_agent_fetch', 'Could not fetch ' . $md . ': ' . $resp->get_error_message() );
			}
			$body = (string) wp_remote_retrieve_body( $resp );
			return array(
				'agent_url'    => $md,
				'status'       => (int) wp_remote_retrieve_response_code( $resp ),
				'content_type' => (string) wp_remote_retrieve_header( $resp, 'content-type' ),
				'bytes'        => strlen( $body ),
				'markdown'     => mb_substr( $body, 0, 60000 ),
				'truncated'    => strlen( $body ) > 60000,
			);
		}

		/**
		 * Action: list-agent-pages.
		 *
		 * @param array $params { search?, page? }.
		 * @return array
		 */
		public static function list_pages( array $params ) {
			$types = Uich_Agent_Tools::candidate_types();
			$page  = isset( $params['page'] ) ? max( 1, absint( $params['page'] ) ) : 1;
			$only  = isset( $params['type'] ) ? sanitize_key( (string) $params['type'] ) : '';
			if ( '' !== $only ) {
				// One kind of content; anything that is not a readable public type gives an empty list.
				$types = in_array( $only, $types, true ) ? array( $only ) : array();
				if ( ! $types ) {
					return array(
						'items' => array(),
						'total' => 0,
						'pages' => 0,
					);
				}
			}
			$args  = array(
				'post_type'           => $types ? $types : array( 'page' ),
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => 50,
				'paged'               => $page,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'fields'              => 'ids',
			);
			$search = isset( $params['search'] ) ? trim( sanitize_text_field( (string) $params['search'] ) ) : '';
			if ( '' !== $search ) {
				$args['s'] = $search;
			}
			$q   = new WP_Query( $args );
			$out = array();
			foreach ( $q->posts as $id ) {
				$type  = get_post_type_object( get_post_type( $id ) );
				$out[] = array(
					'post_id'   => (int) $id,
					'title'     => get_the_title( $id ),
					'type'      => $type ? $type->labels->singular_name : '',
					'url'       => get_permalink( $id ),
					'agent_url' => Uich_Agent_Urls::md_url( get_permalink( $id ) ),
					'hide'      => (bool) get_post_meta( $id, Uich_Agent_Pages::META_HIDE, true ),
					'edited'    => '' !== (string) get_post_meta( $id, Uich_Agent_Pages::META_MD, true ),
					'shared'    => Uich_Agent_Access::visible( $id ),
				);
			}
			return array(
				'items' => $out,
				'total' => (int) $q->found_posts,
				'pages' => (int) $q->max_num_pages,
			);
		}

		/**
		 * Action: list-agent-types.
		 *
		 * Only kinds of content that have something to show are returned, so the
		 * dashboard never lists an empty type. Order: Pages, Posts, then custom post
		 * types grouped by where they come from (UiChemy first, then ACF, JetEngine,
		 * other).
		 *
		 * @return array
		 */
		public static function list_types() {
			$types    = Uich_Agent_Tools::candidate_types();
			$included = (array) Uich_Agent_Settings::value( 'agent_pages.post_types', array() );
			$owned    = array();
			if ( class_exists( 'Uich_Model' ) ) {
				$index = Uich_Model::ownership_index();
				$owned = isset( $index['post_types'] ) ? (array) $index['post_types'] : array();
			}
			$rank = array(
				'core'      => 0,
				'uichemy'   => 1,
				'acf'       => 2,
				'jetengine' => 3,
				'other'     => 4,
			);
			$out  = array();
			foreach ( $types as $slug ) {
				$obj = get_post_type_object( $slug );
				if ( ! $obj ) {
					continue;
				}
				$q = new WP_Query(
					array(
						'post_type'      => $slug,
						'post_status'    => 'publish',
						'has_password'   => false,
						'posts_per_page' => 1,
						'fields'         => 'ids',
					)
				);
				if ( ! $q->found_posts ) {
					continue;
				}
				// How many of them AI may read: the rest are hidden by the owner or the SEO exclude tag.
				$open = new WP_Query(
					array(
						'post_type'      => $slug,
						'post_status'    => 'publish',
						'has_password'   => false,
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_query'     => Uich_Agent_Access::meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small admin count.
					)
				);
				if ( in_array( $slug, array( 'page', 'post' ), true ) ) {
					$source = 'core';
				} else {
					$source = isset( $owned[ $slug ] ) && isset( $rank[ $owned[ $slug ] ] ) ? $owned[ $slug ] : 'other';
				}
				$out[] = array(
					'slug'   => $slug,
					'label'  => $obj->labels->name,
					'count'  => (int) $q->found_posts,
					// Whether this type's Markdown pages are on (empty list = every type).
					'enabled' => ! $included || in_array( $slug, $included, true ),
					'shared' => (int) $open->found_posts,
					'source' => $source,
				);
			}
			usort(
				$out,
				function ( $a, $b ) use ( $rank ) {
					if ( $rank[ $a['source'] ] !== $rank[ $b['source'] ] ) {
						return $rank[ $a['source'] ] - $rank[ $b['source'] ];
					}
					// Pages before Posts; the rest by name.
					if ( 'core' === $a['source'] ) {
						return 'page' === $a['slug'] ? -1 : 1;
					}
					return strcasecmp( $a['label'], $b['label'] );
				}
			);
			return array( 'types' => $out );
		}

		/**
		 * Action: regenerate.
		 *
		 * @return array
		 */
		public static function regenerate() {
			Uich_Agent_Pages::bump();
			return array(
				'done'       => true,
				'generation' => (int) get_option( Uich_Agent_Pages::GEN_OPTION, 1 ),
				'note'       => 'Cached agent files will rebuild on their next request; a warm-up of key pages is queued.',
			);
		}

		/**
		 * Action: get-page-notes.
		 *
		 * @param array $params { post_ids }.
		 * @return array
		 */
		public static function get_page_notes( array $params ) {
			$ids = array_slice( array_filter( array_map( 'absint', isset( $params['post_ids'] ) ? (array) $params['post_ids'] : array() ) ), 0, 100 );
			$out = array();
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post ) {
					$out[] = array(
						'post_id' => $id,
						'error'   => 'not found',
					);
					continue;
				}
				$out[] = array(
					'post_id'   => $id,
					'title'     => get_the_title( $post ),
					'agent_url' => Uich_Agent_Urls::md_url( get_permalink( $post ) ),
					'hide'      => (bool) get_post_meta( $id, Uich_Agent_Pages::META_HIDE, true ),
					'markdown'  => (string) get_post_meta( $id, Uich_Agent_Pages::META_MD, true ),
				);
			}
			return array( 'items' => $out );
		}

		/**
		 * Action: set-page-notes.
		 *
		 * Each post also needs edit_post for the current user, so the admin ability
		 * cannot be used to change a post the user could not edit by hand.
		 *
		 * @param array $params { items }.
		 * @return array|WP_Error
		 */
		public static function set_page_notes( array $params ) {
			$items = isset( $params['items'] ) && is_array( $params['items'] ) ? array_slice( $params['items'], 0, 100 ) : array();
			if ( ! $items ) {
				return new WP_Error( 'uich_agent_items', 'Pass "items": a list of { post_id, hide?, markdown? }.' );
			}
			$out = array();
			foreach ( $items as $item ) {
				$id = isset( $item['post_id'] ) ? absint( $item['post_id'] ) : 0;
				if ( ! $id || ! get_post( $id ) ) {
					$out[] = array(
						'post_id' => $id,
						'error'   => 'not found',
					);
					continue;
				}
				if ( ! current_user_can( 'edit_post', $id ) ) {
					$out[] = array(
						'post_id' => $id,
						'error'   => 'you cannot edit this post',
					);
					continue;
				}
				if ( array_key_exists( 'markdown', $item ) ) {
					// Served as text/markdown, never as HTML; tags are still removed.
					$md = mb_substr( wp_strip_all_tags( wp_check_invalid_utf8( (string) $item['markdown'] ) ), 0, self::MD_MAX );
					if ( '' === trim( $md ) ) {
						delete_post_meta( $id, Uich_Agent_Pages::META_MD );
					} else {
						update_post_meta( $id, Uich_Agent_Pages::META_MD, wp_slash( $md ) );
					}
				}
				if ( array_key_exists( 'hide', $item ) ) {
					if ( filter_var( $item['hide'], FILTER_VALIDATE_BOOLEAN ) ) {
						update_post_meta( $id, Uich_Agent_Pages::META_HIDE, 1 );
					} else {
						delete_post_meta( $id, Uich_Agent_Pages::META_HIDE );
					}
				}
				$out[] = array(
					'post_id' => $id,
					'saved'   => true,
				);
			}
			Uich_Agent_Pages::bump();
			return array( 'items' => $out );
		}
	}
}
