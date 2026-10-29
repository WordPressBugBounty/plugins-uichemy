<?php
/**
 * Uich_CB_Post_Types — the builder's own post types.
 *
 * EVERY content item the builder touches is a WordPress post. There is no
 * bespoke table and no options blob: a page is a `page`, a post is a `post`, a
 * custom post type is itself, and anything the BUILDER owns gets a post type of
 * its own here. That is what keeps revisions, autosave, capabilities, trash,
 * search, multisite, import/export, WP-CLI and the REST API working for free —
 * the version history in Uich_CB_Revisions is core's revision system, not a
 * reimplementation of one, and it only works because the rows are real posts.
 *
 * Templates get a DEDICATED post type rather than living as ordinary pages.
 * Mixing them into `page` is the mistake that makes template rows show up in
 * the Pages list, in menus, in sitemaps and in search results, and it leaves no
 * way to ask "give me the templates" without filtering by meta.
 *
 * That type is `uichemy_template` — the Theme Builder's, which already exists.
 * The builder once registered a `uich_cb_template` of its own; it was a second
 * CPT for the same idea, so templates lived in two lists with two sets of
 * conditions. There is one now. This class does NOT register it — the Theme
 * Builder does (UiChemy_Template_CPT, on `init` at 10) — it only declares that
 * the builder stores documents on it, and makes sure it keeps revisions.
 *
 * WHY A REGISTRY. The types live in one filterable map instead of a series of
 * register_post_type() calls. Adding the next builder-owned type — reusable
 * layouts, a global-styles record, a saved-section library — is then one entry
 * in that map (or one `uich_cb/post_types` filter from another file), and the
 * storage model underneath it does not change at all: Uich_CB_Content reads and
 * writes any post ID regardless of its type, and Uich_CB_Revisions picks the new
 * type up automatically because it derives its list from this one.
 *
 * @package Uichemy
 * @subpackage Uichemy/composer-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CB_Post_Types' ) ) {

	/**
	 * Registers and describes the post types the builder owns.
	 *
	 * @since 5.2.1
	 */
	final class Uich_CB_Post_Types {

		/**
		 * Post type: a template (header, footer, single, archive, …).
		 *
		 * The Theme Builder's CPT, NOT one of ours — must match
		 * UiChemy_Template_CPT::POST_TYPE. Spelled out rather than read from that
		 * class so this file does not fatal on a site where the merged runtime
		 * stood down and the class is absent.
		 */
		const TEMPLATE = 'uichemy_template';

		/**
		 * Register hooks.
		 *
		 * Priority 5 so the types exist before anything that reads them on
		 * `init` — Uich_CB_Content::register_meta() runs at 20 and asks this
		 * class which types to attach the builder meta to.
		 *
		 * @return void
		 */
		public static function boot() {
			add_action( 'init', array( __CLASS__, 'register' ), 5 );

			// After the Theme Builder registers TEMPLATE at priority 10.
			add_action( 'init', array( __CLASS__, 'add_template_revisions' ), 11 );
		}

		/**
		 * Give the template post type revision support.
		 *
		 * `uichemy_template` does not declare `revisions` (it was built for
		 * Elementor, which keeps its own history), and wp_save_post_revision()
		 * returns early for a type without it — so a template's builder document
		 * would have had no version history at all. Added rather than assumed,
		 * and only if the type actually exists on this request.
		 *
		 * @return void
		 */
		public static function add_template_revisions() {
			if ( post_type_exists( self::TEMPLATE ) && ! post_type_supports( self::TEMPLATE, 'revisions' ) ) {
				add_post_type_support( self::TEMPLATE, 'revisions' );
			}
		}

		/**
		 * The builder-owned post types, as a map of slug => register_post_type args.
		 *
		 * Shared traits, and the reasoning for each:
		 *
		 *   public              false — a template is not a URL. It renders INTO
		 *                       a request (a header slot, a single view); giving
		 *                       it its own permalink would publish a fragment.
		 *   show_ui             true  — so the list table, trash and the revision
		 *                       browser all work while the builder's own
		 *                       management screens are still being built.
		 *   show_in_menu        false — the builder surfaces templates in its own
		 *                       screen; a second top-level menu is noise.
		 *   supports revisions  REQUIRED. wp_save_post_revision() returns early
		 *                       for a post type without it, so leaving it off
		 *                       silently disables version history for templates.
		 *   supports editor     keeps post_content in play (see Uich_CB_WPContent).
		 *   show_in_rest        true  — the revision browser and our own REST
		 *                       layer both need it; the AST itself is NOT exposed
		 *                       as raw meta (see Uich_CB_Content::register_meta).
		 *   capability_type     page  — templates are site structure, so they
		 *                       follow the page capabilities an editor already
		 *                       has rather than inventing a new set.
		 *
		 * @return array<string,array>
		 */
		public static function definitions() {
			/**
			 * Filter the post types the builder REGISTERS.
			 *
			 * Empty by default: the builder's only owned type today is
			 * TEMPLATE, which the Theme Builder registers. This is still the
			 * extension point for a new builder-owned content kind — reusable
			 * layouts, a saved-section library, a global-styles record. A type
			 * added here is registered and becomes a first-class builder post
			 * immediately: the storage model in Uich_CB_Content is post-type
			 * agnostic and Uich_CB_Revisions derives its list from slugs().
			 *
			 * @since 5.2.1
			 *
			 * @param array<string,array> $definitions Slug => register_post_type args.
			 */
			return (array) apply_filters( 'uich_cb/post_types', array() );
		}

		/**
		 * Register every builder-owned post type.
		 *
		 * @return void
		 */
		public static function register() {
			foreach ( self::definitions() as $slug => $args ) {
				if ( ! is_string( $slug ) || '' === $slug || post_type_exists( $slug ) ) {
					continue;
				}

				register_post_type( $slug, (array) $args );
			}
		}

		/**
		 * The slugs of every builder-owned post type.
		 *
		 * @return string[]
		 */
		public static function slugs() {
			$registered = array_filter( array_keys( self::definitions() ), 'is_string' );

			// TEMPLATE is owned by the builder but registered elsewhere, so it is
			// not in definitions() — it is added here, which is the list every
			// storage and revision decision reads.
			return array_values( array_unique( array_merge( array( self::TEMPLATE ), $registered ) ) );
		}

		/**
		 * Is this post type one the builder owns (as opposed to site content)?
		 *
		 * @param string $post_type Post type slug.
		 * @return bool
		 */
		public static function is_builder_type( $post_type ) {
			return in_array( (string) $post_type, self::slugs(), true );
		}
	}
}
