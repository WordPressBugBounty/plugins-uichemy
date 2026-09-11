<?php
/**
 * The content-model layer: who owns post types, taxonomies and field groups.
 *
 * UiChemy does not register a post type under its own storage, and never will.
 * That turns a design tool into a data-modelling plugin, and it cannot be walked
 * back once a user has content in it - uninstalling UiChemy would orphan their
 * posts. So every model write DELEGATES to whichever plugin the site already
 * uses, and this class is the delegation decision:
 *
 *   ACF active         => acf_update_internal_post_type() / acf_update_field_group()
 *   JetEngine active   => jet_engine()->cpt->data / taxonomies->data / meta_boxes->data
 *   Both active        => ASK the user which; never pick
 *   Neither active     => REFUSE with a specific instruction
 *
 * The neither-active branch is the one that matters most. With no PHP runner on
 * the other end, an empty field map reads as "this site has no data" and the
 * agent hardcodes the page. It has to read as "this site has no field-modelling
 * plugin; install ACF or JetEngine, or I can build this with plain posts and
 * categories instead".
 *
 * @link       https://posimyth.com/
 * @since      5.1.0
 *
 * @package    UiChemy
 * @subpackage UiChemy/includes/model
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Model' ) ) {

	/**
	 * Provider selection, the shared field vocabulary, and site description.
	 */
	final class Uich_Model {

		/**
		 * Provider instances.
		 *
		 * @var array<string,Uich_Model_Provider>|null
		 */
		private static $providers = null;

		/**
		 * The provider-neutral field types a caller may ask for.
		 *
		 * One vocabulary, mapped per provider, so the same request builds the
		 * right thing on either plugin - and so a type neither plugin has is
		 * refused with a list rather than silently created as a text field.
		 *
		 * @return array<int,string>
		 */
		public static function field_types() {
			return array(
				'text',
				'textarea',
				'wysiwyg',
				'number',
				'email',
				'url',
				'boolean',
				'select',
				'radio',
				'checkbox',
				'image',
				'file',
				'gallery',
				'date',
				'datetime',
				'time',
				'color',
				'link',
				'post_relation',
				'taxonomy',
				'repeater',
			);
		}

		/**
		 * Every model provider, in preference order.
		 *
		 * @return array<string,Uich_Model_Provider>
		 */
		public static function providers() {
			if ( null !== self::$providers ) {
				return self::$providers;
			}

			$providers = array();

			foreach ( array( 'Uich_Model_ACF', 'Uich_Model_Jet' ) as $class ) {
				if ( class_exists( $class ) ) {
					$provider                       = new $class();
					$providers[ $provider->slug() ] = $provider;
				}
			}

			/**
			 * Filter the content-model providers.
			 *
			 * @since 5.1.0
			 *
			 * @param array<string,Uich_Model_Provider> $providers Provider instances.
			 */
			self::$providers = (array) apply_filters( 'uichemy/model/providers', $providers );

			return self::$providers;
		}

		/**
		 * Which model providers are actually installed.
		 *
		 * @return array<string,Uich_Model_Provider>
		 */
		public static function active() {
			$out = array();

			foreach ( self::providers() as $slug => $provider ) {
				if ( $provider->is_active() ) {
					$out[ $slug ] = $provider;
				}
			}

			return $out;
		}

		/**
		 * Choose the provider a model write goes through.
		 *
		 * With two installed this REFUSES rather than picking. Choosing for the
		 * user puts half their content model in one plugin's admin screens and
		 * half in the other's, which is the sort of mess that is discovered
		 * months later and cannot be migrated without rewriting every template.
		 *
		 * @param string $prefer Provider slug the caller pinned, or ''.
		 * @return Uich_Model_Provider|WP_Error
		 */
		public static function pick( $prefer = '' ) {
			$active = self::active();
			$prefer = sanitize_key( (string) $prefer );

			if ( '' !== $prefer ) {
				if ( isset( $active[ $prefer ] ) ) {
					return $active[ $prefer ];
				}

				$known = self::providers();

				if ( isset( $known[ $prefer ] ) ) {
					return new WP_Error(
						'uich_model_provider_inactive',
						sprintf( '%s is not active on this site. Active: %s.', $known[ $prefer ]->label(), implode( ', ', array_keys( $active ) ) ?: 'none' )
					);
				}

				return new WP_Error(
					'uich_model_unknown_provider',
					sprintf( 'Unknown provider "%s". UiChemy delegates content modelling to ACF or JetEngine: pass provider="acf" or provider="jetengine".', $prefer )
				);
			}

			if ( ! $active ) {
				return new WP_Error(
					'uich_model_no_provider',
					'This site has no field-modelling plugin, so UiChemy cannot create a post type, taxonomy or custom field here. UiChemy deliberately does not register content types under its own storage - that would make uninstalling it orphan the user\'s content. Tell the user: install Advanced Custom Fields or JetEngine and call this again, OR model the content with what WordPress already has (posts plus categories and tags), which UiChemy can bind and lay out today.'
				);
			}

			if ( count( $active ) > 1 ) {
				$names = array();
				foreach ( $active as $slug => $provider ) {
					$names[] = $provider->label() . ' (provider="' . $slug . '")';
				}

				return new WP_Error(
					'uich_model_ambiguous_provider',
					sprintf(
						'Both %s are active, so UiChemy will not choose which one owns this. ASK THE USER which plugin they want their content model in, then pass "provider". Do not pick for them: a model split across two plugins ends up half in each plugin\'s admin screens, and moving it later means rewriting every template that binds it.',
						implode( ' and ', $names )
					)
				);
			}

			return reset( $active );
		}

		// ============================================================
		// DESCRIBE
		// ============================================================

		/**
		 * The site's content model: post types, taxonomies, field groups, owners.
		 *
		 * The owner column is the point. "Which plugin registered `movie`"
		 * decides whether UiChemy can change it at all, and a post type
		 * registered in a theme's functions.php is not editable through any API
		 * here - saying so is more useful than attempting it.
		 *
		 * @param array $args { include_builtin?: bool, object_subtype?: string }.
		 * @return array
		 */
		public static function describe( $args = array() ) {
			$args    = is_array( $args ) ? $args : array();
			$builtin = ! empty( $args['include_builtin'] );
			$only    = isset( $args['object_subtype'] ) ? sanitize_key( (string) $args['object_subtype'] ) : '';

			$owners = self::ownership_index();

			$post_types = array();

			foreach ( get_post_types( array(), 'objects' ) as $pt ) {
				if ( '' !== $only && $pt->name !== $only ) {
					continue;
				}

				if ( ! $builtin && self::is_infrastructure_type( $pt->name, (bool) $pt->_builtin ) ) {
					continue;
				}

				$post_types[] = array(
					'slug'          => $pt->name,
					'label'         => $pt->labels->name,
					'singular'      => $pt->labels->singular_name,
					'owner'         => isset( $owners['post_types'][ $pt->name ] ) ? $owners['post_types'][ $pt->name ] : ( $pt->_builtin ? 'wordpress' : 'code' ),
					'editable_here' => isset( $owners['post_types'][ $pt->name ] ),
					'public'        => (bool) $pt->public,
					'hierarchical'  => (bool) $pt->hierarchical,
					'has_archive'   => (bool) $pt->has_archive,
					'show_in_rest'  => (bool) $pt->show_in_rest,
					'supports'      => array_keys( array_filter( (array) get_all_post_type_supports( $pt->name ) ) ),
					'taxonomies'    => array_values( get_object_taxonomies( $pt->name ) ),
					'field_groups'  => self::groups_targeting( 'post', $pt->name ),
					'post_count'    => (int) wp_count_posts( $pt->name )->publish,
				);
			}

			$taxonomies = array();

			foreach ( get_taxonomies( array(), 'objects' ) as $tax ) {
				if ( ! $builtin && self::is_infrastructure_type( $tax->name, (bool) $tax->_builtin ) ) {
					continue;
				}

				$term_count = (int) wp_count_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false ) );

				$row = array(
					'slug'          => $tax->name,
					'label'         => $tax->labels->name,
					'singular'      => $tax->labels->singular_name,
					'owner'         => isset( $owners['taxonomies'][ $tax->name ] ) ? $owners['taxonomies'][ $tax->name ] : ( $tax->_builtin ? 'wordpress' : 'code' ),
					'editable_here' => isset( $owners['taxonomies'][ $tax->name ] ),
					'object_types'  => array_values( (array) $tax->object_type ),
					'hierarchical'  => (bool) $tax->hierarchical,
					'public'        => (bool) $tax->public,
					'show_in_rest'  => (bool) $tax->show_in_rest,
					'term_count'    => $term_count,
					'terms'         => self::term_sample( $tax->name ),
					'field_groups'  => self::groups_targeting( 'term', $tax->name ),
				);

				// Reported alongside owner / editable_here because it answers the
				// same class of question and is asked at the same moment: can I
				// build on this. A taxonomy with no terms is not a usable
				// taxonomy, and nothing downstream errors when it is empty - the
				// archive is just blank. Three independent builds each modelled
				// around a taxonomy and only discovered it was unpopulated after
				// the content was written.
				if ( 0 === $term_count ) {
					$row['warning'] = sprintf(
						'EMPTY - no terms. Every archive, filter, term field and term loop for "%s" renders nothing, silently. Create the terms with action="ensure-term" and put them on posts with action="set-terms" BEFORE building anything that reads this taxonomy. If the values are a fixed list nobody browses by, a select field is the simpler model.',
						$tax->name
					);
				}

				$taxonomies[] = $row;
			}

			$out = array(
				'providers'   => Uich_Field_Registry::status(),
				'post_types'  => $post_types,
				'taxonomies'  => $taxonomies,
				'field_types' => self::field_types(),
				'boundary'    => self::boundary(),
			);

			$active = self::active();

			if ( ! $active ) {
				$out['message'] = 'No field-modelling plugin is active. Post types and taxonomies can be read here but not created. Install ACF or JetEngine, or model the content with plain posts, categories and tags - do not report this as "the site has no content model".';
			} elseif ( count( $active ) > 1 ) {
				$out['message'] = 'ACF and JetEngine are both active. Every register-* / add-fields call needs "provider" - UiChemy will not choose which plugin owns the model. Ask the user.';
			} else {
				$provider       = reset( $active );
				$out['message'] = sprintf( 'Model writes go through %s. "provider" is optional while it is the only one active.', $provider->label() );
			}

			return $out;
		}

		/**
		 * Whether a post type or taxonomy is another plugin's plumbing rather
		 * than part of this site's content model.
		 *
		 * ACF stores its own field groups, fields, post types and taxonomies as
		 * post types; so do Elementor, JetEngine and UiChemy's own theme builder.
		 * Listing them alongside `post` and `movie` is noise that crowds out the
		 * real model, and none of them is ever the answer to "where should this
		 * content live" - so they are excluded unless explicitly asked for.
		 *
		 * @param string $name     Post type or taxonomy slug.
		 * @param bool   $is_core  Whether WordPress registered it.
		 * @return bool
		 */
		private static function is_infrastructure_type( $name, $is_core ) {
			if ( in_array( $name, array( 'post', 'page', 'category', 'post_tag' ), true ) ) {
				return false;
			}

			if ( $is_core ) {
				return true;
			}

			foreach ( array( 'acf-', 'acf_', 'elementor_', 'e_', 'e-', 'jet-engine', 'jet_engine', 'uichemy_', 'nxt_', 'wp_' ) as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					return true;
				}
			}

			return in_array(
				$name,
				array(
					'elementor_library',
					'elementor_font',
					'elementor_icons',
					'elementor_snippet',
					'elementor_library_type',
					'elementor_library_category',
					'elementor_font_type',
					'nav_menu_item',
					'shop_order',
					'shop_coupon',
					'shop_order_refund',
					'product_visibility',
					'scheduled-action',
				),
				true
			);
		}

		/**
		 * A few real term slugs, so a caller never has to guess one.
		 *
		 * Capped: a taxonomy with two thousand terms would otherwise dominate the
		 * describe payload, and the count above already says how many there are.
		 *
		 * @param string $taxonomy Taxonomy slug.
		 * @return array<int,array{slug:string,name:string,count:int}>
		 */
		private static function term_sample( $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 20,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);

			if ( is_wp_error( $terms ) || ! $terms ) {
				return array();
			}

			$out = array();

			foreach ( $terms as $term ) {
				$out[] = array(
					'slug'  => $term->slug,
					'name'  => $term->name,
					'count' => (int) $term->count,
				);
			}

			return $out;
		}

		/**
		 * Which post types and taxonomies were registered through a provider
		 * whose API UiChemy can write back to.
		 *
		 * @return array{post_types:array<string,string>,taxonomies:array<string,string>}
		 */
		public static function ownership_index() {
			$index = array(
				'post_types' => array(),
				'taxonomies' => array(),
			);

			foreach ( self::active() as $slug => $provider ) {
				foreach ( $provider->owned_post_types() as $name ) {
					$index['post_types'][ $name ] = $slug;
				}
				foreach ( $provider->owned_taxonomies() as $name ) {
					$index['taxonomies'][ $name ] = $slug;
				}
			}

			return $index;
		}

		/**
		 * Field groups whose location reaches one object type + subtype.
		 *
		 * This is the answer to "can a binding on this post type resolve at
		 * all" - a post type with no group targeting it has no custom fields,
		 * however many exist elsewhere on the site.
		 *
		 * @param string $object_type    post | term | user | options.
		 * @param string $object_subtype Post type or taxonomy slug.
		 * @return array<int,array>
		 */
		public static function groups_targeting( $object_type, $object_subtype ) {
			$out = array();

			foreach ( Uich_Field_Registry::active() as $slug => $provider ) {
				if ( ! method_exists( $provider, 'groups' ) ) {
					continue;
				}

				foreach ( (array) $provider->groups() as $group ) {
					if ( 'acf' === $slug ) {
						$scope = isset( $group['scope'] ) ? $group['scope'] : array();

						if ( ! in_array( $object_type, isset( $scope['object_types'] ) ? $scope['object_types'] : array(), true ) ) {
							continue;
						}

						// Only post and term groups carry a subtype list worth
						// filtering on. A user group has no subtype at all, and
						// "option" is the generic options target - filtering
						// either against a named list would drop every match.
						$bucket = 'post' === $object_type ? 'post_types' : ( 'term' === $object_type ? 'taxonomies' : '' );
						$list   = '' !== $bucket && isset( $scope[ $bucket ] ) ? $scope[ $bucket ] : array();

						if ( $list && '' !== $object_subtype && ! in_array( $object_subtype, $list, true ) ) {
							continue;
						}

						$out[] = array(
							'provider'    => 'acf',
							'key'         => $group['key'],
							'title'       => $group['title'],
							'field_count' => $group['field_count'],
						);
						continue;
					}

					if ( ( isset( $group['object_type'] ) ? $group['object_type'] : '' ) !== $object_type ) {
						continue;
					}

					// A meta box can be scoped to several post types, so the match
					// is against the whole list where one is reported.
					$subtypes = ! empty( $group['object_subtypes'] ) && is_array( $group['object_subtypes'] )
						? $group['object_subtypes']
						: array( isset( $group['object_subtype'] ) ? $group['object_subtype'] : '' );

					// An empty list means "every subtype of this object type" -
					// JetEngine's own scope-less default.
					$scoped = array_values( array_filter( $subtypes, 'strlen' ) );

					if ( $scoped && '' !== $object_subtype && ! in_array( $object_subtype, $scoped, true ) ) {
						continue;
					}

					$row = array(
						'provider'    => $slug,
						'key'         => $group['key'],
						'title'       => $group['title'],
						'field_count' => $group['field_count'],
					);

					if ( isset( $group['stored_on'] ) ) {
						$row['stored_on'] = $group['stored_on'];
					}

					$out[] = $row;
				}
			}

			return $out;
		}

		/**
		 * The additive / identity-changing boundary, stated so a caller can plan
		 * around it instead of discovering it as a refusal.
		 *
		 * @return array
		 */
		public static function boundary() {
			return array(
				'allowed'   => array(
					'Add a field to an existing group - nothing existing breaks.',
					'Change a field\'s label, instructions, required flag, choices or default - presentation and validation only.',
					'Create a new post type, taxonomy or field group.',
				),
				'refused'   => array(
					'rename a field'      => 'Every template binds by NAME, not key. The key survives a rename and the name does not, so every binding silently blanks and the page still looks built. Add a new field, copy the values across with custom-fields/update, then retire the old one by hand.',
					'change a field type' => 'The storage shape changes and the existing data may become unreadable. Add a new field of the new type and migrate the values.',
					'delete a field'      => 'The stored values are orphaned invisibly - they stay in the database, unreachable. Clear the values with custom-fields/delete, then remove the definition in the plugin\'s own admin screen where the consequences are shown.',
					'change a post type slug' => 'It breaks every permalink and every stored reference to the type.',
					'delete a post type'  => 'All its content is orphaned. Do this in the plugin\'s own admin screen.',
				),
				'principle' => 'Additive changes go through UiChemy. Identity-changing ones go through the owning plugin\'s admin screen, where the user sees what they are about to break.',
			);
		}

		/**
		 * Drop cached provider instances.
		 *
		 * @return void
		 */
		public static function flush() {
			self::$providers = null;
			Uich_Field_Registry::flush();
		}

		// ============================================================
		// SHARED VALIDATION
		// ============================================================

		/**
		 * Validate and normalise a provider-neutral field descriptor list.
		 *
		 * Runs before any provider is touched, so a bad descriptor cannot leave
		 * half a group created.
		 *
		 * @param mixed $fields Incoming descriptors.
		 * @return array<int,array>|WP_Error
		 */
		public static function normalise_fields( $fields ) {
			if ( ! is_array( $fields ) || ! $fields ) {
				return new WP_Error(
					'uich_model_no_fields',
					'"fields" is required: an array of { name, label, type }. Valid types: ' . implode( ', ', self::field_types() ) . '.'
				);
			}

			$out   = array();
			$seen  = array();
			$types = self::field_types();

			foreach ( array_values( $fields ) as $i => $field ) {
				if ( ! is_array( $field ) ) {
					return new WP_Error( 'uich_model_bad_field', sprintf( 'fields[%d] is not an object.', $i ) );
				}

				$name = isset( $field['name'] ) ? (string) $field['name'] : '';
				$name = strtolower( trim( str_replace( array( ' ', '-' ), '_', $name ) ) );
				$name = preg_replace( '/[^a-z0-9_]/', '', $name );

				if ( '' === $name ) {
					return new WP_Error(
						'uich_model_field_no_name',
						sprintf( 'fields[%d] has no usable "name". The name is the meta key AND what every template binds - pick it deliberately, in snake_case, because it cannot be renamed later without blanking every binding.', $i )
					);
				}

				if ( 0 === strpos( $name, '_' ) ) {
					return new WP_Error(
						'uich_model_field_underscore',
						sprintf( 'fields[%d] is named "%s". A leading underscore marks protected meta in WordPress, which the field layer refuses to write - the field would be created and then unwritable.', $i, $name )
					);
				}

				if ( isset( $seen[ $name ] ) ) {
					return new WP_Error( 'uich_model_duplicate_field', sprintf( 'Two fields named "%s" in one call.', $name ) );
				}

				$seen[ $name ] = true;

				$type = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text';

				if ( ! in_array( $type, $types, true ) ) {
					return new WP_Error(
						'uich_model_bad_field_type',
						sprintf( 'fields[%d] ("%s") has type "%s", which UiChemy does not know. Valid: %s. These are UiChemy\'s provider-neutral names - they are mapped to the right ACF or JetEngine type for you.', $i, $name, $type, implode( ', ', $types ) )
					);
				}

				$row = array(
					'name'          => $name,
					'label'         => isset( $field['label'] ) && '' !== $field['label'] ? (string) $field['label'] : ucwords( str_replace( '_', ' ', $name ) ),
					'type'          => $type,
					'instructions'  => isset( $field['instructions'] ) ? (string) $field['instructions'] : '',
					'required'      => ! empty( $field['required'] ),
					'default'       => isset( $field['default'] ) ? $field['default'] : '',
					'choices'       => self::normalise_choices( isset( $field['choices'] ) ? $field['choices'] : ( isset( $field['options'] ) ? $field['options'] : null ) ),
					'multiple'      => ! empty( $field['multiple'] ),
					'min'           => isset( $field['min'] ) ? $field['min'] : '',
					'max'           => isset( $field['max'] ) ? $field['max'] : '',
					'taxonomy'      => isset( $field['taxonomy'] ) ? sanitize_key( (string) $field['taxonomy'] ) : '',
					'post_type'     => isset( $field['post_type'] ) ? array_map( 'sanitize_key', (array) $field['post_type'] ) : array(),
					'return_format' => isset( $field['return_format'] ) ? sanitize_key( (string) $field['return_format'] ) : '',
					'sub_fields'    => array(),
				);

				if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) && ! $row['choices'] ) {
					return new WP_Error(
						'uich_model_no_choices',
						sprintf( 'Field "%s" is a %s and needs "choices". Without them the field accepts anything and renders an empty label - and a caller writing a value cannot know what is allowed.', $name, $type )
					);
				}

				if ( 'taxonomy' === $type && '' === $row['taxonomy'] ) {
					return new WP_Error(
						'uich_model_no_taxonomy',
						sprintf( 'Field "%s" is a taxonomy field and needs "taxonomy" - the slug it selects terms from.', $name )
					);
				}

				if ( 'repeater' === $type ) {
					$subs = isset( $field['sub_fields'] ) ? $field['sub_fields'] : ( isset( $field['fields'] ) ? $field['fields'] : null );

					if ( ! $subs ) {
						return new WP_Error(
							'uich_model_no_sub_fields',
							sprintf( 'Repeater "%s" needs "sub_fields". A repeater with no sub-fields stores rows nothing can read.', $name )
						);
					}

					$nested = self::normalise_fields( $subs );
					if ( is_wp_error( $nested ) ) {
						return $nested;
					}

					foreach ( $nested as $sub ) {
						if ( 'repeater' === $sub['type'] ) {
							return new WP_Error(
								'uich_model_nested_repeater',
								sprintf( 'Repeater "%s" contains repeater "%s". UiChemy does not create nested repeaters - build the inner one in the plugin\'s own screen if it is really needed.', $name, $sub['name'] )
							);
						}
					}

					$row['sub_fields'] = $nested;
				}

				$out[] = $row;
			}

			return $out;
		}

		/**
		 * Normalise a choice list into value => label.
		 *
		 * Accepts a flat list (value doubles as label), a value=>label object, or
		 * a list of { value, label } - all three are shapes a caller will send.
		 *
		 * @param mixed $choices Incoming choices.
		 * @return array<string,string>|null
		 */
		public static function normalise_choices( $choices ) {
			if ( ! is_array( $choices ) || ! $choices ) {
				return null;
			}

			$out = array();

			foreach ( $choices as $key => $value ) {
				if ( is_array( $value ) ) {
					$v = isset( $value['value'] ) ? (string) $value['value'] : ( isset( $value['key'] ) ? (string) $value['key'] : '' );
					$l = isset( $value['label'] ) ? (string) $value['label'] : $v;

					if ( '' !== $v ) {
						$out[ $v ] = '' !== $l ? $l : $v;
					}
					continue;
				}

				if ( is_int( $key ) ) {
					$out[ (string) $value ] = (string) $value;
					continue;
				}

				$out[ (string) $key ] = (string) $value;
			}

			return $out ? $out : null;
		}

		/**
		 * Validate a post type or taxonomy slug against WordPress's own limits.
		 *
		 * @param string $slug  Proposed slug.
		 * @param string $what  'post type' | 'taxonomy'.
		 * @return string|WP_Error The sanitised slug.
		 */
		public static function validate_slug( $slug, $what = 'post type' ) {
			$slug = strtolower( trim( (string) $slug ) );
			$slug = str_replace( array( ' ', '-' ), '_', $slug );
			$slug = preg_replace( '/[^a-z0-9_]/', '', $slug );

			if ( '' === $slug ) {
				return new WP_Error( 'uich_model_no_slug', sprintf( 'A %s needs a "slug": lowercase, letters, digits and underscores.', $what ) );
			}

			if ( strlen( $slug ) > 20 ) {
				return new WP_Error(
					'uich_model_slug_too_long',
					sprintf( '"%s" is %d characters. WordPress caps a %s slug at 20 and truncates silently, which produces a type nothing can query by name.', $slug, strlen( $slug ), $what )
				);
			}

			$reserved = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'action', 'author', 'order', 'theme', 'type', 'fields', 'terms', 'status', 'title', 'content', 'name', 'date', 'link', 'tag', 'category', 'post_type', 'taxonomy' );

			if ( in_array( $slug, $reserved, true ) ) {
				return new WP_Error(
					'uich_model_slug_reserved',
					sprintf( '"%s" is reserved by WordPress and would collide with a core query variable. Pick another slug - prefixing it ("%s_item") is the usual fix.', $slug, $slug )
				);
			}

			return $slug;
		}
	}
}
