<?php
/**
 * The `uichemy-composer/custom-fields` ability - field VALUES.
 *
 * Its sibling `uichemy-composer/cpt` owns the SHAPE. This one fills it in, reads
 * it back, clears it, and changes JetEngine relation membership.
 *
 * Two design decisions are load-bearing and both come from failures that pass
 * every check a caller can make on its own:
 *
 *   - "get" always returns BOTH `value` and `formatted`, and takes no format
 *     parameter. Read output is formatted and storage is not, so writing back
 *     what was read corrupts the value - a rendered ACF date written back turns
 *     7 September into today, and the corrupted value reads back looking like a
 *     date, so verification passes.
 *   - "update" refuses an unknown field name instead of storing it as loose
 *     meta. Loose meta is a value the site never reads back, reported as a
 *     success, and the page it was meant for stays blank.
 *
 * The *-relations actions are omitted from meta.actions entirely when no
 * relation-capable provider is active, and answer with a specific reason if
 * called anyway. A tag that always renders nothing is worse than one that is
 * absent, because the absence is the answer.
 *
 * @link       https://posimyth.com/
 * @since      5.1.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Custom_Fields_Ability' ) ) {

	/**
	 * Registers and routes uichemy-composer/custom-fields.
	 */
	final class Uich_Custom_Fields_Ability {

		/**
		 * Full ability name.
		 */
		const ABILITY_NAME = 'uichemy-composer/custom-fields';

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
					'label'               => 'UiChemy Builder: Custom Fields',
					'description'         => 'Custom-field VALUES on posts, terms, users and options pages - ACF, JetEngine and registered meta, each written through the plugin that owns it. "list" is the field map for an object type and the call that has to happen before any binding is written; "get" reads values; "update" writes them (batched, with a dry run and a revert token); "delete" clears them. The field DEFINITIONS are uichemy-composer/cpt.',
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
							'title'       => 'UiChemy Builder: Custom Fields',
							'readonly'    => false,
							// "delete" and a "replace" on relations both remove
							// data. Both are recoverable through a revert token,
							// but the annotation reflects what the ability can
							// do, not what the safest action does.
							'destructive' => true,
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
		 * @return string
		 */
		private static function schema_description() {
			$contract = class_exists( 'UiChemy_Abilities' ) && method_exists( 'UiChemy_Abilities', 'contract' )
				? UiChemy_Abilities::contract() . ' '
				: '';

			return $contract
				. 'DISCOVER BEFORE YOU BIND, AND BEFORE YOU WRITE. Call action="list" for the object type first. An unknown field name renders EMPTY rather than failing, so a guessed name produces a page that looks built and is blank, with nothing in the output to say so - and on the write side an unknown name is refused here rather than stored, precisely so that failure is loud. '
				. 'ADDRESSING is WordPress\'s own triple: object_type ("post" | "term" | "user" | "options") + object_subtype (the post type or taxonomy slug) + object_id. object_type defaults to "post". Resolve real ids with uichemy-composer/describe-site (action="entities"). '
				. 'NEVER WRITE BACK WHAT YOU READ. "get" returns both "value" (what is stored) and "formatted" (what renders). Send "value" back. A formatted ACF date written back turns into today\'s date, and it reads back looking like a date afterwards, so nothing you can check will tell you. '
				. 'A REPEATER WRITE REPLACES EVERY ROW. To append, "get" the field, add your row to the array you were given, and send the whole set. Sending one row deletes the rest, silently. '
				. 'VALIDATE FIRST on anything non-trivial: dry_run=true resolves, coerces and validates every field and writes nothing, reporting exactly what would land in storage. Then write for real. '
				. 'WHAT IS REFUSED, and why the refusal is the useful part: an unknown field name (with the list of real ones); protected meta and any key starting with "_"; _elementor_data and UiChemy\'s own template meta (a write there erases a page, silently); WooCommerce derived state like _price and _stock, which is computed and mirrored into its own lookup table - use uichemy-composer/store; the featured image, which is protected meta and has no UiChemy write path on a plain post (the WordPress editor, or store/set-images on a product); and ACF Pro field types on an ACF free install, because ACF stores those down a scalar path that reads back intact and becomes one empty row the moment Pro is installed. '
				. 'VERIFICATION IS STRUCTURAL, not an echo. After every write UiChemy asserts the shape the owning plugin expects - ACF\'s reference row, a repeater\'s row count - and returns the RE-READ value. A response carrying "problems" means the values are in storage but the shape is wrong: do not report that as a success. '
				. 'EVERY write and delete returns a revert_token; action="revert" restores exactly the fields that call changed, for 24 hours. '
				. 'RELATIONS ARE NOT META. JetEngine keeps them in its own tables, so a field write aimed at one goes nowhere and returns success. Use get-relations / update-relations - present only when a relation-capable provider is active. ACF relationship fields ARE meta and work through get/update. '
				. 'THE BOUNDARY: this writes values into fields that already exist. Create the fields with uichemy-composer/cpt. Print them with uichemy-composer/dynamic and lay them out with uichemy-composer/page or theme-builder.';
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
			$object_type = array(
				'type'        => 'string',
				'enum'        => array( 'post', 'term', 'user', 'options' ),
				'description' => 'WordPress\'s own object type. Defaults to "post". "options" reaches an ACF options page and needs no object_id.',
			);

			$object_subtype = array(
				'type'        => 'string',
				'description' => 'The post type or taxonomy slug. Optional with an object_id (it is derived and then CHECKED - a mismatch is refused rather than guessed, because the wrong one writes real values onto the wrong object), required without one.',
			);

			$object_id = array(
				'type'        => array( 'integer', 'string' ),
				'description' => 'The object to read or write. Resolve real ids with uichemy-composer/describe-site (action="entities").',
			);

			$provider = array(
				'type'        => 'string',
				'enum'        => array( 'acf', 'jetengine', 'meta' ),
				'description' => 'Pin which plugin owns the field. Only needed when ACF and JetEngine both define the same name - the response flags that as "also_claimed_by". Pinning the wrong one is refused, not silently routed.',
			);

			$names = array(
				'type'        => 'array',
				'description' => 'Field names. Omit to cover every field attached to the object.',
				'items'       => array( 'type' => 'string' ),
			);

			$actions = array(
				array(
					'name'        => 'list',
					'description' => 'The field DEFINITIONS for an object type, or for one object. This is the call that has to happen before any binding or write. Each field carries its provider, its native type, a value_shape you can act on without knowing the plugin, required, choices (JetEngine glossaries resolved to real values, because a glossary-backed select carries an empty choice list of its own), return_format, sub_fields for repeaters, layouts for flexible content, whether it is writable and why not, and a write_hint naming the mistake that type invites. Also reports which plugins are active and their edition - the ACF free/Pro distinction changes which field types exist.',
					'parameters'  => array(
						'object_type'    => $object_type,
						'object_subtype' => array(
							'type'        => 'string',
							'description' => 'The post type or taxonomy slug. This is the normal discovery call: ask what a "movie" has before any movie exists.',
						),
						'object_id'      => array(
							'type'        => array( 'integer', 'string' ),
							'description' => 'Optional. With an id the answer is scoped to the groups that actually reach that object.',
						),
						'provider'       => $provider,
						'names'          => $names,
					),
				),
				array(
					'name'        => 'get',
					'description' => 'Read field VALUES on one object. Returns BOTH "value" (stored, and what to send back on a write) and "formatted" (rendered). There is no format parameter on purpose: choosing wrongly is how a read corrupts the next write, and the corruption passes every read-back afterwards. Fields whose name looks like a credential are reported by name with the value withheld. Unknown names come back in "unknown_fields" rather than failing the whole call.',
					'parameters'  => array(
						'object_type'    => $object_type,
						'object_subtype' => $object_subtype,
						'object_id'      => $object_id,
						'names'          => $names,
						'provider'       => $provider,
					),
					'required'    => array( 'object_id' ),
				),
				array(
					'name'        => 'update',
					'description' => 'Write field values. Each name is routed to its owning plugin and written through that plugin\'s own API - ACF through update_field() BY NAME (which writes the reference row ACF needs; update_post_meta() does not, and the value then renders on the front end, shows blank in the editor, and is erased by the next human save), JetEngine through raw meta, which is its storage. Values are coerced by declared type: an ISO date to the plugin\'s storage format, a boolean to "1"/"0" or \'true\'/\'\' depending on the plugin, a media URL already in the library to its attachment id, a term slug to its id. Every field is validated BEFORE anything is written, so one bad value cannot half-update the object. Afterwards the storage SHAPE is asserted and the re-read value returned, together with "renders_as" - what the token for that field actually resolves to right now - and "print_hint", the trap specific to printing that type. TWO DIFFERENT CHECKS: "stored" is the owning plugin\'s storage and "renders_as" is the render engine, they are separate systems, and they have disagreed - a number field storing "22" once printed an unrelated image URL. NEITHER is a check that the PAGE is right; the template, its conditions and the layout are not exercised here. Look at the rendered page before reporting a build done.',
					'parameters'  => array(
						'object_type'    => $object_type,
						'object_subtype' => $object_subtype,
						'object_id'      => $object_id,
						'object_ids'     => array(
							'type'        => 'array',
							'description' => 'Write the same fields to several objects in one call - seeding twenty populated posts is one call, not twenty. Up to 100. A per-object failure does not abort the rest; each object reports its own result.',
							'items'       => array( 'type' => 'integer' ),
						),
						'fields'         => array(
							'type'        => 'object',
							'description' => 'REQUIRED: { field_name: value }. A name not attached to the object is REFUSED with the list of real ones - never stored as loose meta. A repeater value is a complete array of rows keyed by SUB-FIELD NAME, and it REPLACES every existing row. null clears a field (or use action="delete", which snapshots first).',
						),
						'provider'       => $provider,
						'dry_run'        => array(
							'type'        => 'boolean',
							'description' => 'Resolve, coerce and validate everything and write NOTHING, reporting the exact values a real write would store. Use it whenever the payload is non-trivial - it is the only way to see a date conversion or a choice validation before it lands.',
						),
					),
					'required'    => array( 'fields' ),
				),
				array(
					'name'        => 'delete',
					'description' => 'Clear field values on one object. Separate from "update" because it destroys data and because a null buried in a fields payload is too easy to send by accident. The prior values are snapshotted and a revert_token returned. The field DEFINITIONS are untouched - deleting a definition is refused (see uichemy-composer/cpt), because it orphans every stored value invisibly.',
					'parameters'  => array(
						'object_type'    => $object_type,
						'object_subtype' => $object_subtype,
						'object_id'      => $object_id,
						'names'          => array(
							'type'        => 'array',
							'description' => 'REQUIRED: the field names to clear. Clearing every field on an object is not offered, because it is never what was meant.',
							'items'       => array( 'type' => 'string' ),
						),
						'provider'       => $provider,
					),
					'required'    => array( 'object_id', 'names' ),
				),
				array(
					'name'        => 'revert',
					'description' => 'Restore the values a previous "update" or "delete" replaced, using the revert_token that call returned. It restores exactly those fields on those objects and nothing else. Tokens last 24 hours and are spent on use - reverting a revert is not possible, so take a fresh read before the next write.',
					'parameters'  => array(
						'revert_token' => array(
							'type'        => 'string',
							'description' => 'The token from the update or delete you want to undo.',
						),
					),
					'required'    => array( 'revert_token' ),
				),
			);

			// Conditionally exposed: with no relation-capable provider these
			// would be two actions that can only ever answer "not here".
			if ( class_exists( 'Uich_Field_Relations' ) && Uich_Field_Relations::supported() ) {
				$actions[] = array(
					'name'        => 'get-relations',
					'description' => 'Relation membership. Relations are NOT meta - JetEngine keeps them in its own database tables, so a field write aimed at one goes nowhere and returns success. With no object_id this lists every relation on the site with its two sides; with one it adds that object\'s current membership and which side it sits on.',
					'parameters'  => array(
						'object_type'    => $object_type,
						'object_subtype' => $object_subtype,
						'object_id'      => array(
							'type'        => array( 'integer', 'string' ),
							'description' => 'Optional. Omit to list the relations without resolving membership.',
						),
						'relation_id'    => array(
							'type'        => 'integer',
							'description' => 'Report one relation only.',
						),
					),
				);

				$actions[] = array(
					'name'        => 'update-relations',
					'description' => 'Attach or detach related objects. UiChemy does not CREATE relations - a relation defines the content model and its two sides have to be chosen deliberately, which is a JetEngine > Relations decision. An object that is neither side of the named relation is refused: attaching it would write a row the relation\'s own queries never return.',
					'parameters'  => array(
						'relation_id'    => array(
							'type'        => 'integer',
							'description' => 'REQUIRED. From action="get-relations".',
						),
						'object_type'    => $object_type,
						'object_subtype' => $object_subtype,
						'object_id'      => $object_id,
						'add'            => array(
							'type'        => 'array',
							'description' => 'Object ids to attach. Already-attached ids are a no-op, so this is safe to repeat.',
							'items'       => array( 'type' => 'integer' ),
						),
						'remove'         => array(
							'type'        => 'array',
							'description' => 'Object ids to detach.',
							'items'       => array( 'type' => 'integer' ),
						),
						'replace'        => array(
							'type'        => 'array',
							'description' => 'Set the membership to exactly these ids, detaching everything else. The destructive one, so it is named rather than implied - and unlike a field write it has no revert token.',
							'items'       => array( 'type' => 'integer' ),
						),
					),
					'required'    => array( 'relation_id', 'object_id' ),
				);
			}

			return $actions;
		}

		// ============================================================
		// PERMISSION
		// ============================================================

		/**
		 * Gate on the same terms as every other UiChemy ability.
		 *
		 * The per-object capability check is separate and lives in
		 * Uich_Field_Guard::can(): this answers "may this user use UiChemy's MCP
		 * surface", which is not the same question as "may they edit post 6923".
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

			if ( ! class_exists( 'Uich_Field_Service' ) ) {
				return new WP_Error( 'uich_fields_unavailable', 'The UiChemy field layer is not loaded.' );
			}

			// ONE name per operation, and no aliases. An alias would be dead
			// code here: the Abilities API validates `action` against this
			// ability's own input-schema enum BEFORE execute() runs, so anything
			// outside the enum is rejected upstream and never reaches this
			// switch. Adding "set" as a synonym for "update" would therefore
			// either do nothing, or - if added to the enum too - put two
			// identical choices in front of a model at the point where it picks.
			//
			// "update" rather than "set" is deliberate on both counts. It mirrors
			// update_post_meta(), completing WordPress's own get/update/delete
			// triple; and in this codebase "set" already means overwrite
			// wholesale (design-system "set" replaces the document where "patch"
			// edits it; platform "set-site-settings" overwrites an existing value
			// where "update-site-settings" only fills in blanks). This action
			// MERGES - only the named fields change - so "set" would put the
			// destructive verb on the careful operation and invite reading
			// { a: 1 } as "and clear everything else".
			switch ( $action ) {
				case 'list':
					return Uich_Field_Service::list_fields( $params );

				case 'get':
					return Uich_Field_Service::get( $params );

				case 'update':
					return Uich_Field_Service::update( $params );

				case 'delete':
					return Uich_Field_Service::delete( $params );

				case 'revert':
					return Uich_Field_Service::revert( $params );

				case 'get_relations':
					return Uich_Field_Relations::get( $params );

				case 'update_relations':
					// Pro-gated inside Uich_Field_Relations::update() itself -
					// relation membership IS JetEngine field data, so a Free
					// build that could attach a related post still could not
					// render the relation. Every write in this switch gates
					// itself; do not rely on this dispatcher to do it.
					return Uich_Field_Relations::update( $params );

				case '':
					return new WP_Error(
						'uich_fields_no_action',
						'Missing "action". Valid: ' . self::action_list() . '. Call action="list" first - an unknown field name is refused on a write and renders empty in a template, so the field map is what makes either safe.'
					);

				default:
					// Naming why an absent action is absent, rather than lumping
					// it in with a typo.
					if ( in_array( $action, array( 'get_relations', 'update_relations' ), true ) ) {
						return Uich_Field_Relations::unsupported();
					}

					return new WP_Error(
						'uich_fields_unknown_action',
						sprintf( 'Unknown action "%s". Valid: %s.', $action, self::action_list() )
					);
			}
		}

		/**
		 * Unwrap the { action, action_parameters } envelope, accepting a flat
		 * payload too.
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
