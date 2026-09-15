<?php
/**
 * The contract every page-builder driver implements.
 *
 * UiChemy Composer already supports Elementor, Gutenberg and Bricks in the
 * editor, and all three store the SAME neutral settings map (raw_html /
 * raw_css / raw_js / _title / page_custom_code_* / slot_*) rendered by the one
 * shared UiChemy_Composer_Renderer. Only the envelope around that map differs:
 *
 *   Elementor  node  { elType:'widget', widgetType:'uichemy-composer', settings }
 *                    inside the _elementor_data JSON blob
 *   Gutenberg  block uichemy/composer, attrs.uid + attrs.settings, in post_content
 *   Bricks     flat  { id, name:'uichemy-composer', parent, children,
 *                      settings:{ uichemy_settings: <JSON string> } }
 *                    in the _bricks_page_<area>_2 post meta
 *
 * So the abstraction is drawn at STORAGE, not at operation. A driver only has
 * to load and save an ordered list of sections; move, duplicate, delete,
 * insert, patch and grep are then ordinary array work implemented ONCE in
 * UiChemy_Section_Ops. Exposing move_section()/duplicate_section()/... on the
 * driver instead would buy nine implementations today and twelve for the
 * fourth builder.
 *
 * @link       https://posimyth.com/
 * @since      5.1.1
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'UiChemy_Builder_Driver' ) ) {

	/**
	 * One page builder's storage adapter.
	 */
	interface UiChemy_Builder_Driver {

		/**
		 * Builder slug: elementor | gutenberg | bricks.
		 *
		 * Must match the slugs used by uichemy_composer_enabled() and
		 * Uich_ND_Settings::BUILDERS so the three lists cannot drift.
		 *
		 * @return string
		 */
		public function slug();

		/**
		 * Human label for messages and MCP responses.
		 *
		 * @return string
		 */
		public function label();

		/**
		 * Whether this builder can be driven on this site right now.
		 *
		 * Replaces the scattered class_exists( '\Elementor\Plugin' ) hard gates
		 * in UiChemy_Composer_Manager. Note Bricks is a THEME, so its check is
		 * on the stylesheet, not on a plugin being active.
		 *
		 * @return bool
		 */
		public function is_available();

		/**
		 * Whether this builder owns an existing post's content.
		 *
		 * Replaces the detection inlined at class-uichemy-composer-enqueue.php
		 * and class-uichemy-template-cpt.php. Used to refuse a write whose
		 * requested builder disagrees with the post — writing Elementor data
		 * into a Bricks page returns success and renders nothing.
		 *
		 * @param int $post_id Post id.
		 * @return bool
		 */
		public function owns_post( $post_id );

		/**
		 * Whether the post carries no builder content at all, so it may be
		 * adopted by the session builder rather than treated as a conflict.
		 *
		 * @param int $post_id Post id.
		 * @return bool
		 */
		public function is_unclaimed( $post_id );

		/**
		 * Create a post this builder owns, stamping whatever edit-mode meta the
		 * builder needs. Returns the new post id.
		 *
		 * @param array $attrs { post_title, post_type, post_status }.
		 * @return int|WP_Error
		 */
		public function create_post( array $attrs );

		/**
		 * Read every Composer section on a post, in document order.
		 *
		 * @param int    $post_id Post id.
		 * @param string $area    content | header | footer. Builders without
		 *                        separate areas ignore anything but 'content'.
		 * @return array<int,array>|WP_Error List of UiChemy_Section shapes.
		 */
		public function load_sections( $post_id, $area = 'content' );

		/**
		 * Persist an ordered section list, reconciling against storage by uid:
		 * a known uid keeps its native node and is updated and reordered, an
		 * empty uid mints a new node, and a uid missing from the list is
		 * deleted. This is what lets UiChemy_Section_Ops stay ignorant of the
		 * fact that Elementor nests in containers, Gutenberg in innerBlocks and
		 * Bricks is flat with parent/children ids.
		 *
		 * Each driver owns its own wp_slash() rule here, and getting that wrong
		 * blanks an entire page rather than one section.
		 *
		 * @param int    $post_id  Post id.
		 * @param array  $sections Ordered list of UiChemy_Section shapes.
		 * @param string $area     content | header | footer.
		 * @return true|WP_Error
		 */
		public function save_sections( $post_id, array $sections, $area = 'content' );

		/**
		 * Build one native node from the neutral settings map, without saving.
		 *
		 * @param array $settings Neutral Composer settings map.
		 * @param array $opts     Driver hints (e.g. container id).
		 * @return array A UiChemy_Section shape with a freshly minted uid.
		 */
		public function new_section( array $settings, array $opts = array() );

		/**
		 * Summarise the post's native tree for get-structure. Genuinely
		 * per-builder: Elementor recurses elements[], Gutenberg recurses
		 * innerBlocks, Bricks joins a flat array by parent/children ids.
		 *
		 * @param int $post_id Post id.
		 * @return array|WP_Error
		 */
		public function describe_structure( $post_id );

		/**
		 * Editor deep links for a post ( ?action=elementor | ?bricks=run | the
		 * block editor ), merged into MCP responses.
		 *
		 * @param int $post_id Post id.
		 * @return array<string,string>
		 */
		public function edit_links( $post_id );

		/**
		 * WP_Query args selecting posts that could hold this builder's Composer
		 * sections. Backs a cross-builder grep: the current
		 * meta_query{_elementor_data EXISTS} returns nothing on a Bricks site,
		 * so grep reads as empty rather than as unsupported.
		 *
		 * @param array $args Base query args to merge into.
		 * @return array
		 */
		public function grep_query_args( array $args );

		/**
		 * Capability probe. Known caps: theme_builder_native, page_custom_code,
		 * dynamic_tags, atomic_globals, header_footer_areas.
		 *
		 * An ability must SAY when a capability is missing rather than silently
		 * dropping the input — dynamic tags are Elementor-only, and quietly
		 * discarding {{ tokens }} yields a page that looks built and is blank.
		 *
		 * @param string $cap Capability slug.
		 * @return bool
		 */
		public function supports( $cap );

		/**
		 * Readiness for check_config / describe-site: whether a build can start,
		 * and what to tell the user when it cannot.
		 *
		 * @return array { ready: bool, checks: array, notes: string[] }
		 */
		public function readiness();

		/**
		 * The value handed to UiChemy_Template_Store::create()'s `editor` arg.
		 *
		 * @return string
		 */
		public function template_editor_slug();

		/**
		 * Post-write cache invalidation: Elementor clears its files manager and
		 * the element cache, Bricks regenerates per-page CSS, Gutenberg no-ops.
		 *
		 * @param int $post_id Post id.
		 * @return void
		 */
		public function after_write( $post_id );
	}
}
