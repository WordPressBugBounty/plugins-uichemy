<?php
/**
 * The `uichemy-composer/cpt` ability - the site's content MODEL.
 *
 * The shape of the data: post types, taxonomies, field groups and the fields in
 * them. Its sibling `uichemy-composer/custom-fields` owns the VALUES. The split
 * is deliberate: creating a field is a schema change with consequences that
 * outlive the session, and filling one in is not, so they carry different
 * annotations and different refusals.
 *
 * UiChemy owns neither. Every write here delegates to ACF or JetEngine, so what
 * is created appears in that plugin's own admin screens and survives UiChemy
 * being removed - the alternative would make a design tool the owner of the
 * user's content model, which cannot be walked back once they have posts in it.
 *
 * Registered whether or not ACF or JetEngine is active. An ability that
 * disappears when a plugin is inactive teaches a model that the capability does
 * not exist here at all; one that is present and answers "install ACF or
 * JetEngine, or model this with plain posts and categories" tells it what to do.
 *
 * @link       https://posimyth.com/
 * @since      5.1.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_CPT_Ability' ) ) {

	/**
	 * Registers and routes uichemy-composer/cpt.
	 */
	final class Uich_CPT_Ability {

		/**
		 * Full ability name.
		 */
		const ABILITY_NAME = 'uichemy-composer/cpt';

		/**
		 * Ability category.
		 */
		const CATEGORY = 'uichemy-composer';

		// ============================================================
		// BOOTSTRAP
		// ============================================================

		/**
		 * Wire registration onto the Abilities API.
		 *
		 * @return void
		 */
		public static function init() {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			// Priority 10: UiChemy_Abilities registers the shared category at 5.
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ), 10 );
		}

		/**
		 * Register the ability.
		 *
		 * @return void
		 */
		public static function register_ability() {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( self::ABILITY_NAME ) ) {
				return;
			}

			wp_register_ability(
				self::ABILITY_NAME,
				array(
					'label'               => 'UiChemy Builder: Content Model',
					'description'         => 'The SHAPE of this site\'s data: post types, taxonomies, field groups and the fields in them, created through whichever plugin owns modelling here (ACF or JetEngine - never UiChemy\'s own storage). Start with "describe": it reports every type with its owning plugin, which field groups reach it, and what can and cannot be changed. Field VALUES are uichemy-composer/custom-fields; how any of it LOOKS is uichemy-composer/theme-builder plus uichemy-composer/dynamic.',
					'category'            => self::CATEGORY,
					'input_schema'        => array(
						'type'        => 'object',
						'description' => self::schema_description(),
						'properties'  => array(
							'action'            => array(
								'type'        => 'string',
								'enum'        => wp_list_pluck( self::actions(), 'name' ),
								'description' => 'Which operation to run.',
							),
							'action_parameters' => array(
								'type'        => 'object',
								'description' => 'Parameters for the chosen action. See meta.actions.',
							),
						),
						'required'    => array( 'action' ),
					),
					'execute_callback'    => array( __CLASS__, 'execute' ),
					'permission_callback' => array( __CLASS__, 'permission_check' ),
					'meta'                => array(
						'show_in_rest' => true,
						'mcp'          => array( 'public' => true ),
						'annotations'  => array(
							'title'       => 'UiChemy Builder: Content Model',
							'readonly'    => false,
							// Nothing here deletes or renames: every write is
							// additive, and the identity-changing operations are
							// refused with what to do instead.
							'destructive' => false,
							'idempotent'  => false,
						),
						'actions'      => self::actions(),
					),
				)
			);
		}

		/**
		 * The ability-level prose, paid for on every discovery call.
		 *
		 * Restricted to what cannot be worked out from an action name: the order,
		 * the delegation rule, and the boundary that decides which changes are
		 * refused.
		 *
		 * @return string
		 */
		private static function schema_description() {
			$contract = class_exists( 'UiChemy_Abilities' ) && method_exists( 'UiChemy_Abilities', 'contract' )
				? UiChemy_Abilities::contract() . ' '
				: '';

			return $contract
				. 'START WITH "describe". It reports every post type and taxonomy with the plugin that registered it, whether UiChemy can write to it at all, which field groups target it, and the additive/identity boundary below. '
				. 'ORDER: register-post-type -> register-taxonomy -> ensure-term -> register-field-group -> add-fields -> (write content) -> set-terms. A post type with no field group has no custom fields, a field group with no location rule shows nowhere, and a taxonomy with no terms renders as an empty archive and an empty filter - all three are silent, none of them errors. '
				. 'TERMS ARE NOT OPTIONAL. Registering a taxonomy does not populate it. Create the terms in the same session with "ensure-term" and put them on posts with "set-terms"; if the values are a fixed list nobody will browse by - a status, a tier, a size - model it as a select field instead and skip the taxonomy entirely. Deciding this AFTER the content is written is expensive: the alternative at that point is a parallel select field driving the front end off a shadow copy of a taxonomy that stays decorative. '
				. 'DELEGATION: UiChemy never registers a post type under its own storage; it calls ACF or JetEngine. With ONE of them active, "provider" is optional. With BOTH active every write REFUSES until you pass "provider" - ask the user which plugin they want their model in rather than choosing, because a model split across two plugins cannot be moved later without rewriting every template. With NEITHER active, the refusal names the two options: install one, or model the content with plain posts, categories and tags. '
				. 'THE BOUNDARY. Additive changes go through here: create a type, create a group, add a field, change a field\'s label / instructions / required / choices / default. Identity-changing ones are REFUSED and the refusal says what to do instead: renaming a field (every template binds by NAME, so a rename silently blanks every binding while the page still looks built), changing a field\'s type, deleting a field, changing or deleting a post type. '
				. 'ACF FREE vs PRO matters here. repeater, flexible_content, gallery and clone do not exist as field types on ACF free, and ACF does not refuse them - it stores their value as a scalar, which reads back intact and becomes one empty row the moment ACF Pro is installed. UiChemy refuses those types on free instead, naming what is missing. "describe" reports the edition under "providers". '
				. 'REGISTRATION TIMING: a JetEngine post type or taxonomy registers on the NEXT request, so anything that has to SEE it - a field group, a loop, a template condition - belongs in a separate call. ACF registers immediately. '
				. 'AFTERWARDS: fill values with uichemy-composer/custom-fields (action="update"), list what a type now has with the same ability (action="list"), and build the single/archive templates with uichemy-composer/theme-builder. A new post type has no template of its own until you make one.';
		}

		// ============================================================
		// SCHEMAS
		// ============================================================

		/**
		 * Per-action schemas, served through meta.actions.
		 *
		 * @return array<int,array>
		 */
		private static function actions() {
			$provider = array(
				'type'        => 'string',
				'enum'        => array( 'acf', 'jetengine' ),
				'description' => 'Which plugin performs the write. Optional while exactly one modelling plugin is active; REQUIRED when both are, and the refusal in that case is deliberate - ask the user rather than picking.',
			);

			$field_item = array(
				'type'        => 'object',
				'description' => 'One field. "name" is the meta key AND what every template binds, so choose it deliberately - it cannot be renamed later without blanking every binding.',
				'properties'  => array(
					'name'          => array(
						'type'        => 'string',
						'description' => 'snake_case meta key. A leading underscore is refused: it marks protected meta, which the value layer will not write, so the field would be created and then unwritable.',
					),
					'label'         => array(
						'type'        => 'string',
						'description' => 'What the editor shows. Defaults to the name, title-cased.',
					),
					'type'          => array(
						'type'        => 'string',
						'enum'        => class_exists( 'Uich_Model' ) ? Uich_Model::field_types() : array( 'text' ),
						// The edition caveat lives HERE and not only in the
						// ability's prose: the enum is what a caller validates
						// against, and an enum that lists a type the active
						// build refuses is a schema that lies. describe reports
						// the live answer under providers[].pro_only_types.
						'description' => self::field_type_description(),
					),
					'instructions'  => array(
						'type'        => 'string',
						'description' => 'Help text under the field in the editor.',
					),
					'required'      => array(
						'type'        => 'boolean',
						'description' => 'Enforced in the editor, and by custom-fields/update.',
					),
					'default'       => array(
						'type'        => 'string',
						'description' => 'Value used when nothing is entered.',
					),
					'choices'       => array(
						'type'        => 'array',
						'description' => 'REQUIRED for select / radio / checkbox. Either ["draft","live"], or [{"value":"draft","label":"Draft"}], or {"draft":"Draft"}. Without choices the field accepts anything and renders an empty label - and a caller writing a value has no way to know what is allowed.',
						'items'       => array( 'type' => array( 'string', 'object' ) ),
					),
					'multiple'      => array(
						'type'        => 'boolean',
						'description' => 'Allow several values (select, post_relation, taxonomy).',
					),
					'min'           => array(
						'type'        => array( 'number', 'string' ),
						'description' => 'Minimum for a number, or minimum rows for a repeater.',
					),
					'max'           => array(
						'type'        => array( 'number', 'string' ),
						'description' => 'Maximum for a number, or maximum rows for a repeater.',
					),
					'taxonomy'      => array(
						'type'        => 'string',
						'description' => 'REQUIRED for type="taxonomy": the taxonomy slug terms are chosen from.',
					),
					'post_type'     => array(
						'type'        => 'array',
						'description' => 'For type="post_relation": which post types may be chosen. Omit for any.',
						'items'       => array( 'type' => 'string' ),
					),
					'sub_fields'    => array(
						'type'        => 'array',
						'description' => 'REQUIRED for type="repeater" - the columns of each row, same shape as this object. A repeater with no sub-fields stores rows nothing can read. Repeaters cannot nest here.',
						'items'       => array( 'type' => 'object' ),
					),
					'return_format' => array(
						'type'        => 'string',
						'description' => 'Override the read format. Rarely needed: UiChemy already picks the one whose tokens resolve - attachment ID for media (a stored URL cannot be resized, and the .src() chain renders EMPTY on a URL-format image field) and ISO for dates (ACF\'s own d/m/Y is read as m/d/Y downstream, which turns 7 September into 9 July).',
					),
				),
				'required'    => array( 'name', 'type' ),
			);

			return array(
				array(
					'name'        => 'describe',
					'description' => 'The site\'s content model, and the only call that is safe to make blind. Every post type and taxonomy with its owning plugin, whether UiChemy can write to it, its supports, archive and term counts, the field groups that target it, the field types available here, and the additive/identity boundary. Reports the ACF edition, because four field types exist only in Pro and ACF does not refuse them on free. Reports each taxonomy\'s REAL TERMS and warns on any that is empty - an empty taxonomy renders as a blank archive and a blank filter without erroring, so it has to be seen before anything is modelled around it.',
					'parameters'  => array(
						'include_builtin' => array(
							'type'        => 'boolean',
							'description' => 'Include WordPress\'s own types (attachment, revisions, menu items) and Elementor\'s. Off by default: they crowd out the site\'s real model.',
						),
						'object_subtype'  => array(
							'type'        => 'string',
							'description' => 'Report one post type only.',
						),
					),
				),
				array(
					'name'        => 'register-post-type',
					'description' => 'Create a post type through the active modelling plugin. It is a real register_post_type() registration, editable afterwards in that plugin\'s screens. Refused if the slug exists - UiChemy does not reconfigure an existing type, because changing its slug or supports breaks stored references. Taxonomies named in "taxonomies" must already exist.',
					'parameters'  => array(
						'slug'           => array(
							'type'        => 'string',
							'description' => 'The post type key: lowercase, letters/digits/underscores, at most 20 characters. WordPress truncates a longer one silently, producing a type nothing can query by name. Reserved words (post, page, type, name, author, ...) are refused.',
						),
						'label'          => array(
							'type'        => 'string',
							'description' => 'Plural label, e.g. "Case Studies".',
						),
						'singular_label' => array(
							'type'        => 'string',
							'description' => 'Singular label, e.g. "Case Study".',
						),
						'description'    => array( 'type' => 'string' ),
						'public'         => array(
							'type'        => 'boolean',
							'description' => 'Default true. A non-public type has no front-end URL, so no template and no binding can show it.',
						),
						'hierarchical'   => array(
							'type'        => 'boolean',
							'description' => 'Page-like (parents and children) rather than post-like. Default false.',
						),
						'has_archive'    => array(
							'type'        => 'boolean',
							'description' => 'Default true. The archive is what a listing template attaches to; without it there is no /slug/ URL to build one for.',
						),
						'supports'       => array(
							'type'        => 'array',
							'description' => 'Editor features. Default ["title","editor","thumbnail","excerpt"]. "title" is added if you leave it out - a type with no title binds nothing to post.title and every listing prints a blank heading. Include "thumbnail" if any template will show an image.',
							'items'       => array( 'type' => 'string' ),
						),
						'taxonomies'     => array(
							'type'        => 'array',
							'description' => 'Existing taxonomy slugs to attach. A slug that does not exist is REFUSED rather than created, because a type pointing at a missing taxonomy has an admin box with nothing in it. On JetEngine this is reported as not attached - JetEngine attaches from the taxonomy\'s side.',
							'items'       => array( 'type' => 'string' ),
						),
						'menu_icon'      => array(
							'type'        => 'string',
							'description' => 'A dashicons class, e.g. "dashicons-video-alt3".',
						),
						'rewrite_slug'   => array(
							'type'        => 'string',
							'description' => 'The URL segment. Defaults to the slug with underscores turned into hyphens.',
						),
						'show_in_rest'   => array(
							'type'        => 'boolean',
							'description' => 'Default true. Turning it off hides the type from the block editor and the REST API.',
						),
						'provider'       => $provider,
					),
					'required'    => array( 'slug' ),
				),
				array(
					'name'        => 'register-taxonomy',
					'description' => 'Create a taxonomy and attach it to existing post types. Refused if the slug exists, or if a named post type does not - a taxonomy attached to nothing is registered, invisible in the admin, and unqueryable. IT IS CREATED EMPTY: follow immediately with action="ensure-term", or reconsider and use a select field. The response says so, because this is the last moment the choice is cheap.',
					'parameters'  => array(
						'slug'              => array(
							'type'        => 'string',
							'description' => 'Taxonomy key: lowercase, at most 20 characters, not a reserved word.',
						),
						'label'             => array( 'type' => 'string', 'description' => 'Plural label, e.g. "Industries".' ),
						'singular_label'    => array( 'type' => 'string', 'description' => 'Singular label, e.g. "Industry".' ),
						'object_types'      => array(
							'type'        => 'array',
							'description' => 'REQUIRED: the post type slugs this attaches to. They must already exist.',
							'items'       => array( 'type' => 'string' ),
						),
						'hierarchical'      => array(
							'type'        => 'boolean',
							'description' => 'Category-like (nestable, checkbox UI) rather than tag-like (flat, free text). Default false.',
						),
						'public'            => array( 'type' => 'boolean', 'description' => 'Default true.' ),
						'show_admin_column' => array( 'type' => 'boolean', 'description' => 'Show a column in the post list. Default true.' ),
						'rewrite_slug'      => array( 'type' => 'string' ),
						'show_in_rest'      => array( 'type' => 'boolean', 'description' => 'Default true.' ),
						'description'       => array( 'type' => 'string' ),
						'provider'          => $provider,
					),
					'required'    => array( 'slug', 'object_types' ),
				),
				array(
					'name'        => 'ensure-term',
					'description' => 'Create terms in any registered taxonomy, or return the ones already there. Idempotent, so it is safe to repeat across a retried run. Registering a taxonomy does NOT populate it, and an empty taxonomy makes every archive, filter and term-field render nothing - so this belongs in the same breath as register-taxonomy, not after the content is written.',
					'parameters'  => array(
						'taxonomy' => array(
							'type'        => 'string',
							'description' => 'Any registered taxonomy slug - "category" and "post_tag" included, not only ones created here. A JetEngine taxonomy created earlier in the SAME request does not exist yet; it registers on the next one.',
						),
						'terms'    => array(
							'type'        => 'array',
							'description' => 'The terms: ["Residential","Commercial"], or [{"name":"Residential","slug":"residential","description":"...","parent":"..."}] when the URL slug or the nesting matters. A parent that does not exist is refused rather than created - a typo would otherwise become a second top-level term.',
							'items'       => array( 'type' => array( 'string', 'object' ) ),
						),
					),
					'required'    => array( 'taxonomy', 'terms' ),
				),
				array(
					'name'        => 'set-terms',
					'description' => 'Put terms on a post. This is the step that makes an archive or a filtered loop return rows - a term that exists but is on no post shows nothing. An unknown term is REFUSED and nothing is assigned, including the terms that do exist: WordPress accepts an unknown slug by doing nothing at all, reports success, and leaves an archive that is empty forever.',
					'parameters'  => array(
						'post_id'        => array(
							'type'        => 'integer',
							'description' => 'The post to tag. Refused when the taxonomy is not registered for that post type - the write would land and no query would ever return it.',
						),
						'taxonomy'       => array( 'type' => 'string', 'description' => 'Which taxonomy the terms belong to.' ),
						'terms'          => array(
							'type'        => 'array',
							'description' => 'Term slugs, names or ids. An empty array with append=false CLEARS the taxonomy on this post, and the response says so.',
							'items'       => array( 'type' => array( 'string', 'integer' ) ),
						),
						'append'         => array(
							'type'        => 'boolean',
							'description' => 'Add to what is already there. Default false, which REPLACES the post\'s terms in this taxonomy.',
						),
						'create_missing' => array(
							'type'        => 'boolean',
							'description' => 'Create any term that does not exist yet instead of refusing. Default false, deliberately: with it on, a typo silently becomes a real term that looks like a category on the front end.',
						),
					),
					'required'    => array( 'post_id', 'taxonomy', 'terms' ),
				),
				array(
					'name'        => 'register-field-group',
					'description' => 'Create a field group targeting one object type, optionally with its fields in the same call. The location rule is what makes the fields reachable: a group with none shows nowhere and its fields can never be filled in, which is silent. Fields are validated as a set BEFORE anything is written, so one bad descriptor cannot leave half a group behind.',
					'parameters'  => array(
						'title'          => array(
							'type'        => 'string',
							'description' => 'The heading above the fields in the editor, e.g. "Case Study Details".',
						),
						'object_type'    => array(
							'type'        => 'string',
							'enum'        => array( 'post', 'term', 'user' ),
							'description' => 'WordPress\'s own object type. Default "post". Options pages are not created here - they need a menu slug and a capability, which are site-structure decisions; make one in the plugin\'s screens and its fields become readable with custom-fields object_type="options".',
						),
						'object_subtype' => array(
							'type'        => 'string',
							'description' => 'The post type or taxonomy slug. REQUIRED for object_type="post" and "term".',
						),
						'description'    => array( 'type' => 'string' ),
						'fields'         => array(
							'type'        => 'array',
							'description' => 'Fields to create in the group. Optional - an empty group is valid and add-fields fills it later.',
							'items'       => $field_item,
						),
						'provider'       => $provider,
					),
					'required'    => array( 'title' ),
				),
				array(
					'name'        => 'add-fields',
					'description' => 'Append fields to an existing group. Always safe: nothing existing breaks, no stored value moves. A name already in the group is refused with its current type - two fields cannot share a meta key, and overwriting the definition would orphan the values.',
					'parameters'  => array(
						'group_key' => array(
							'type'        => 'string',
							'description' => 'The group\'s key from action="describe" (an ACF group_key, or a JetEngine meta box id). Its title is accepted too, and an ambiguous title is reported rather than guessed.',
						),
						'fields'    => array(
							'type'        => 'array',
							'description' => 'The fields to append.',
							'items'       => $field_item,
						),
						'provider'  => $provider,
					),
					'required'    => array( 'group_key', 'fields' ),
				),
				array(
					'name'        => 'update-field',
					'description' => 'Change a field\'s PRESENTATION and VALIDATION: label, instructions, required, choices, default, placeholder. Nothing else. "name", "type" and "key" are refused, and the refusal says what to do instead - a rename keeps the key and loses the name, so every template binding blanks while the page still looks built. Removing a choice does not remove values already stored against it; the response says so and names the dropped values.',
					'parameters'  => array(
						'name'         => array(
							'type'        => 'string',
							'description' => 'The field\'s existing name. Unchanged by this call.',
						),
						'group_key'    => array(
							'type'        => 'string',
							'description' => 'Which group it is in. Optional - omit to search every group, and an ambiguous name is reported with the groups holding it.',
						),
						'label'        => array( 'type' => 'string' ),
						'instructions' => array( 'type' => 'string' ),
						'placeholder'  => array( 'type' => 'string' ),
						'required'     => array( 'type' => 'boolean' ),
						'default'      => array( 'type' => array( 'string', 'number', 'boolean' ) ),
						'choices'      => array(
							'type'        => 'array',
							'description' => 'Replaces the whole choice list for a select / radio / checkbox. A JetEngine field backed by a glossary is refused here - edit the glossary instead, which is the point of having one.',
							'items'       => array( 'type' => array( 'string', 'object' ) ),
						),
						'provider'     => $provider,
					),
					'required'    => array( 'name' ),
				),
			);
		}

		/**
		 * The type enum's own description, carrying the live edition constraint.
		 *
		 * @return string
		 */
		private static function field_type_description() {
			$base = 'UiChemy\'s provider-neutral type, mapped to the right ACF or JetEngine field for you. A type the active plugin does not have is refused with what it has instead - never substituted silently. '
				. 'NOT EVERY VALUE IN THIS ENUM IS AVAILABLE ON EVERY SITE: repeater, flexible_content, gallery and clone exist only in ACF PRO. On ACF free they are refused here - which is deliberate, because ACF itself does not refuse them, it stores the value down its scalar path and turns it into one empty row the day Pro is installed. ';

			if ( ! class_exists( 'Uich_Field_Registry' ) ) {
				return $base . 'Call action="describe" and read providers[] for this site\'s live edition before choosing.';
			}

			$status = Uich_Field_Registry::status();

			foreach ( ( isset( $status['providers'] ) ? $status['providers'] : array() ) as $row ) {
				if ( 'acf' !== $row['provider'] || empty( $row['active'] ) ) {
					continue;
				}

				return $base . sprintf(
					'ON THIS SITE: ACF %s is active, so %s. (Also reported by action="describe" under providers[].)',
					strtoupper( isset( $row['edition'] ) ? $row['edition'] : 'free' ),
					empty( $row['pro_types_available'] )
						? 'these are REFUSED right now: ' . implode( ', ', (array) $row['pro_only_types'] ) . ' - use a group of plain fields, or a select, instead'
						: 'every type in this enum is available'
				);
			}

			return $base . 'ACF is not active here; action="describe" reports which modelling plugin is, and what it supports.';
		}

		// ============================================================
		// PERMISSION
		// ============================================================

		/**
		 * Gate on the same terms as every other UiChemy ability, so the
		 * dashboard's MCP kill switch turns this off with the rest of the surface
		 * rather than leaving one ability live.
		 *
		 * @return bool
		 */
		public static function permission_check() {
			if ( class_exists( 'UiChemy_Abilities' ) && method_exists( 'UiChemy_Abilities', 'permission_check' ) ) {
				return (bool) UiChemy_Abilities::permission_check();
			}

			return current_user_can( 'manage_options' );
		}

		// ============================================================
		// ROUTER
		// ============================================================

		/**
		 * Route one call to its action.
		 *
		 * @param array $arguments { action, action_parameters?: array }.
		 * @return array|WP_Error
		 */
		public static function execute( $arguments ) {
			list( $action, $params ) = self::unwrap_action( $arguments );

			if ( ! class_exists( 'Uich_Model' ) ) {
				return new WP_Error( 'uich_model_unavailable', 'The UiChemy content-model layer is not loaded.' );
			}

			if ( 'describe' === $action ) {
				return Uich_Model::describe( $params );
			}

			if ( '' === $action ) {
				return new WP_Error(
					'uich_cpt_no_action',
					'Missing "action". Valid: ' . self::action_list() . '. Call action="describe" first - it reports what exists, which plugin owns it, and what can be changed.'
				);
			}

			// Terms are CONTENT, not schema. They belong to WordPress rather than
			// to ACF or JetEngine, they are created identically whichever of them
			// registered the taxonomy, and they are gated on WordPress's own
			// term capabilities - not on the model-writing gate below, which
			// exists for changes to the site's shape.
			if ( 'ensure_term' === $action || 'set_terms' === $action ) {
				if ( ! class_exists( 'Uich_Model_Terms' ) ) {
					return new WP_Error( 'uich_model_unavailable', 'The UiChemy term layer is not loaded.' );
				}

				return 'ensure_term' === $action
					? Uich_Model_Terms::ensure_term( $params )
					: Uich_Model_Terms::set_terms( $params );
			}

			// Every remaining action writes.
			$gate = self::write_gate();
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}

			$provider = Uich_Model::pick( isset( $params['provider'] ) ? $params['provider'] : '' );
			if ( is_wp_error( $provider ) ) {
				return $provider;
			}

			switch ( $action ) {
				case 'register_post_type':
					return $provider->register_post_type( $params );

				case 'register_taxonomy':
					return $provider->register_taxonomy( $params );

				case 'register_field_group':
					$fields = array();

					if ( ! empty( $params['fields'] ) ) {
						$fields = Uich_Model::normalise_fields( $params['fields'] );

						if ( is_wp_error( $fields ) ) {
							return $fields;
						}
					}

					return $provider->register_field_group( $params, $fields );

				case 'add_fields':
					$fields = Uich_Model::normalise_fields( isset( $params['fields'] ) ? $params['fields'] : null );

					if ( is_wp_error( $fields ) ) {
						return $fields;
					}

					return $provider->add_fields(
						isset( $params['group_key'] ) ? (string) $params['group_key'] : '',
						$fields
					);

				case 'update_field':
					$name = isset( $params['name'] ) ? (string) $params['name'] : '';

					if ( '' === $name ) {
						return new WP_Error( 'uich_model_no_field_name', '"name" is required - the existing field to change. It is not changed by this call; renaming is refused.' );
					}

					$changes = $params;
					unset( $changes['name'], $changes['group_key'], $changes['provider'] );

					return $provider->update_field(
						isset( $params['group_key'] ) ? (string) $params['group_key'] : '',
						$name,
						$changes
					);

				default:
					return new WP_Error(
						'uich_cpt_unknown_action',
						sprintf( 'Unknown action "%s". Valid: %s.', $action, self::action_list() )
					);
			}
		}

		/**
		 * Whether this build may change the content model.
		 *
		 * @return true|WP_Error
		 */
		private static function write_gate() {
			if ( uichemy_field_writes_allowed() ) {
				return true;
			}

			return new WP_Error(
				'uich_cpt_requires_pro',
				'Creating post types, taxonomies and fields needs UiChemy Pro. Reading the model is free: action="describe" reports every type, taxonomy and field group on this site, which is what stops a guessed binding. Upgrade at ' . uichemy_upgrade_url( 'cpt' ) . '. Note that ACF and JetEngine can still create these in their own admin screens - this gate is on UiChemy\'s automation of it, not on the capability itself.'
			);
		}

		/**
		 * Unwrap the { action, action_parameters } envelope, accepting a flat
		 * payload too. Mirrors UiChemy_MCP_V2_Router::unwrap_action() so every
		 * surface forgives the same mistakes.
		 *
		 * @param array $arguments Raw ability input.
		 * @return array{0:string,1:array}
		 */
		private static function unwrap_action( $arguments ) {
			$arguments = is_array( $arguments ) ? $arguments : array();
			$action    = isset( $arguments['action'] ) ? sanitize_key( str_replace( '-', '_', (string) $arguments['action'] ) ) : '';

			$params = array();

			if ( isset( $arguments['action_parameters'] ) && is_array( $arguments['action_parameters'] ) ) {
				$params = $arguments['action_parameters'];
			}

			$flat = $arguments;
			unset( $flat['action'], $flat['action_parameters'] );

			return array( $action, array_merge( $flat, $params ) );
		}

		/**
		 * Human-readable action list, for error messages.
		 *
		 * @return string
		 */
		private static function action_list() {
			return implode( ', ', wp_list_pluck( self::actions(), 'name' ) );
		}
	}
}
