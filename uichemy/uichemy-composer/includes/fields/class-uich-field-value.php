<?php
/**
 * Type coercion and validation for field values, and the per-type write hints.
 *
 * UiChemy has to do this conversion itself. Verified on ACF 6.8.9: ACF performs
 * NO date conversion of its own - it stores whatever it is handed. Hand it
 * "2026-09-07" for a `d/m/Y` date field and it stores that string, the admin
 * shows an empty date, and the front end prints the raw string. Nothing errors.
 *
 * The same is true in the other direction for booleans, media and taxonomy
 * fields, and it is true DIFFERENTLY for ACF and JetEngine - a true_false is
 * "1"/"0" in ACF and 'true'/'' in JetEngine, a checkbox is a list in ACF and a
 * value=>true map in JetEngine. So coercion is keyed on the provider as well as
 * the type, and the two paths deliberately do not share code.
 *
 * @link       https://posimyth.com/
 * @since      5.1.0
 *
 * @package    UiChemy
 * @subpackage UiChemy/includes/fields
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Uich_Field_Value' ) ) {

	/**
	 * Coerces an incoming value into the shape its owning plugin stores.
	 */
	final class Uich_Field_Value {

		/**
		 * Map a provider-native field type onto a shape a caller can act on.
		 *
		 * @param string $type     Provider-native type.
		 * @param string $provider Provider slug.
		 * @return string One of: string, html, number, bool, date, datetime, time, url, email, image, file, array, object, rows, choice, choices, relation, unknown.
		 */
		public static function shape_for( $type, $provider = 'acf' ) {
			$type = (string) $type;

			$common = array(
				'text'             => 'string',
				'textarea'         => 'string',
				'password'         => 'string',
				'number'           => 'number',
				'range'            => 'number',
				'email'            => 'email',
				'url'              => 'url',
				'wysiwyg'          => 'html',
				'oembed'           => 'url',
				'true_false'       => 'bool',
				'checkbox'         => 'choices',
				'select'           => 'choice',
				'radio'            => 'choice',
				'button_group'     => 'choice',
				'image'            => 'image',
				'media'            => 'image',
				'gallery'          => 'array',
				'file'             => 'file',
				'date_picker'      => 'date',
				'date'             => 'date',
				'date_time_picker' => 'datetime',
				'datetime'         => 'datetime',
				'datetime-local'   => 'datetime',
				'time_picker'      => 'time',
				'time'             => 'time',
				'colour_picker'    => 'string',
				'color_picker'     => 'string',
				'link'             => 'object',
				'google_map'       => 'object',
				'post_object'      => 'relation',
				'page_link'        => 'relation',
				'relationship'     => 'relation',
				'taxonomy'         => 'relation',
				'user'             => 'relation',
				'repeater'         => 'rows',
				'flexible_content' => 'rows',
				'group'            => 'object',
				'clone'            => 'object',
				'message'          => 'unknown',
				'tab'              => 'unknown',
				'accordion'        => 'unknown',
				'switcher'         => 'bool',
				'iconpicker'       => 'string',
				'wysiwyg_editor'   => 'html',
			);

			if ( isset( $common[ $type ] ) ) {
				return $common[ $type ];
			}

			unset( $provider );

			return 'unknown';
		}

		/**
		 * The structural hint for types whose value is an object, so a caller
		 * knows which member to print instead of printing the array.
		 *
		 * Verified consequence of not having this: `{{ post.meta('cta') }}` on an
		 * ACF link field prints nothing at all, and the payload said "text".
		 *
		 * @param string $type Provider-native type.
		 * @return array|null
		 */
		public static function structure_for( $type ) {
			$map = array(
				'link'       => array( 'url', 'title', 'target' ),
				'google_map' => array( 'address', 'lat', 'lng' ),
				'image'      => array( 'ID', 'url', 'alt', 'title', 'sizes' ),
				'file'       => array( 'ID', 'url', 'filename', 'mime_type' ),
			);

			return isset( $map[ (string) $type ] ) ? $map[ (string) $type ] : null;
		}

		/**
		 * One line naming the specific mistake this field type invites.
		 *
		 * Generated rather than hand-written per field, so a new field type gets
		 * a hint without anyone remembering to add one.
		 *
		 * @param array $def Normalised definition.
		 * @return string
		 */
		public static function write_hint( $def ) {
			$provider = isset( $def['provider'] ) ? $def['provider'] : 'meta';
			$type     = isset( $def['type'] ) ? $def['type'] : 'text';
			$shape    = isset( $def['value_shape'] ) ? $def['value_shape'] : 'string';

			if ( ! empty( $def['reason'] ) ) {
				return (string) $def['reason'];
			}

			$route = 'acf' === $provider
				? 'Written through ACF update_field() by NAME, which stores both the value row and the reference row ACF needs.'
				: ( 'jetengine' === $provider
					? 'Written with update_post_meta(): raw meta IS JetEngine\'s storage.'
					: 'Written with update_post_meta().' );

			switch ( $shape ) {
				case 'bool':
					return ( 'jetengine' === $provider
						? 'Pass true or false. Stored as JetEngine\'s \'true\' / \'\' pair, not 1/0. '
						: 'Pass true or false. Stored as ACF\'s "1" / "0". ' ) . $route;

				case 'date':
					return 'Pass an ISO date ("2026-09-07"). UiChemy converts it to the storage format ' . ( 'acf' === $provider ? '(Ymd)' : '(timestamp)' ) . ' - ACF does no conversion of its own, so an unconverted string stores as-is and the admin shows an empty date. ' . $route;

				case 'datetime':
					return 'Pass an ISO datetime ("2026-09-07 14:30:00"). Converted to the storage format before writing. ' . $route;

				case 'time':
					return 'Pass "HH:MM" or "HH:MM:SS". ' . $route;

				case 'image':
				case 'file':
					return 'Pass an attachment ID, or a URL that is already in this site\'s media library. A remote URL is REFUSED - upload it with uichemy-composer/media first, then pass the id. ' . $route;

				case 'choice':
					return 'Pass one of the values in "choices" - the value, not the label. A value outside the list is refused rather than stored. ' . $route;

				case 'choices':
					return 'Pass an array of values from "choices". ' . $route;

				case 'relation':
					return 'taxonomy' === $type
						? 'Pass term IDs (or slugs, which are resolved). ' . $route
						: 'Pass object IDs. ' . $route;

				case 'rows':
					return 'flexible_content' === $type
						? 'NOT SUPPORTED for writes: flexible content is polymorphic - each row\'s shape depends on its layout - and the render engine has no per-layout partial facility. Read it, do not write it.'
						: 'Pass a complete array of rows, each an object keyed by SUB-FIELD NAME (see "sub_fields"). A write REPLACES every row, so read first and send the full set - sending one row deletes the others. ' . $route;

				case 'object':
					$members = self::structure_for( $type );
					return 'Pass an object' . ( $members ? ' with keys: ' . implode( ', ', $members ) . '.' : '.' ) . ' ' . $route;

				case 'number':
					return 'Pass a number. ' . $route;

				case 'html':
					return 'HTML is allowed. It is stored unescaped, so it is only as safe as its source - print it through |kses_post, never |raw, when it can contain user input. ' . $route;

				default:
					return 'Pass a string. ' . $route;
			}
		}

		/**
		 * The token that actually PRINTS this field, and the trap if you omit it.
		 *
		 * Separate from write_hint() because they answer opposite questions, and
		 * because the printing traps are the ones that reach a visitor. Verified:
		 * a JetEngine date field stores a Unix timestamp, so a bare
		 * {{ post.meta('launch') }} renders "1773446400" on the page - the value
		 * is correct, the binding "works", and the visitor sees a number.
		 *
		 * @param array  $def      Normalised definition.
		 * @param string $provider_token Provider prefix for the example token.
		 * @return string
		 */
		public static function print_hint( $def, $provider_token = 'post' ) {
			$name  = isset( $def['name'] ) ? $def['name'] : 'field';
			$shape = isset( $def['value_shape'] ) ? $def['value_shape'] : 'string';
			$type  = isset( $def['type'] ) ? $def['type'] : '';

			if ( 'options' === $provider_token ) {
				return 'NO TOKEN reaches an options page on this build. The value is readable and writable here, but nothing prints it in a section yet - pass it into the markup yourself, or store it on a post instead if it has to render.';
			}

			$token = sprintf( "{{ %s.meta('%s') }}", $provider_token, $name );

			switch ( $shape ) {
				case 'date':
				case 'datetime':
				case 'time':
					$is_timestamp = ! empty( $def['constraints']['is_timestamp'] )
						|| 'jetengine' === ( isset( $def['provider'] ) ? $def['provider'] : '' );

					return $is_timestamp
						? sprintf(
							"Stored as a Unix timestamp, so %s prints a raw NUMBER on the page. Always print it through the date filter: {{ %s.meta('%s')|date('j F Y') }}.",
							$token,
							$provider_token,
							$name
						)
						: sprintf(
							"%s prints the stored date as-is. {{ %s.meta('%s')|date('j F Y') }} formats it.",
							$token,
							$provider_token,
							$name
						);

				case 'bool':
					return sprintf(
						"A boolean is for a CONDITION, not for printing - %s renders \"1\" or nothing. Use {%% if %s.meta('%s') %%}...{%% endif %%}.",
						$token,
						$provider_token,
						$name
					);

				case 'choice':
				case 'choices':
					return sprintf(
						"%s prints the stored VALUE, not the label. Map it yourself from \"choices\" if the label is what should show.",
						$token
					);

				case 'image':
					return sprintf(
						"An image field holds an attachment id, so %s prints a number. Chain a size: {{ %s.meta('%s').src('large') }} for the URL, .alt for the alt text.",
						$token,
						$provider_token,
						$name
					);

				case 'file':
					return sprintf( "Holds an attachment id. Use {{ %s.meta('%s').url }}.", $provider_token, $name );

				case 'rows':
					$first = '';

					if ( ! empty( $def['sub_fields'][0]['name'] ) ) {
						$first = $def['sub_fields'][0]['name'];
					}

					return sprintf(
						"Repeating rows, so %s prints nothing usable. Loop it: {%% for row in %s.meta('%s') %%}{{ row.%s }}{%% endfor %%}.",
						$token,
						$provider_token,
						$name,
						$first ? $first : 'sub_field_name'
					);

				case 'relation':
					return sprintf(
						"Holds object IDs, so %s prints ids. Loop them and resolve each id, or bind the related object through a loop.",
						$token
					);

				case 'array':
					return sprintf( "An array - %s prints nothing usable. Loop it with {%% for item in ... %%}.", $token );

				case 'object':
					$members = self::structure_for( $type );

					return sprintf(
						"An object, so %s prints nothing usable. Print a member%s.",
						$token,
						$members ? sprintf( ": {{ %s.meta('%s').%s }}", $provider_token, $name, $members[0] ) : ''
					);

				case 'html':
					return sprintf( "%s emits stored HTML. Print it through |kses_post when it can contain user input, never |raw.", $token );

				case 'unknown':
					return 'A layout element. It holds no value and prints nothing.';
			}

			return $token . ' prints it directly.';
		}

		/**
		 * Coerce and validate a value for one field.
		 *
		 * @param array $def    Normalised definition.
		 * @param mixed $value  Incoming value.
		 * @param array $target Resolved target.
		 * @return array{value:mixed,notes:array<int,string>}|WP_Error
		 */
		public static function coerce( $def, $value, $target = array() ) {
			$provider = isset( $def['provider'] ) ? $def['provider'] : 'meta';
			$shape    = isset( $def['value_shape'] ) ? $def['value_shape'] : 'string';
			$type     = isset( $def['type'] ) ? $def['type'] : 'text';
			$name     = isset( $def['name'] ) ? $def['name'] : '';
			$notes    = array();

			if ( ! empty( $def['required'] ) && ( null === $value || '' === $value || array() === $value ) ) {
				return new WP_Error(
					'uich_field_required',
					sprintf( 'Field "%s" is required and an empty value was passed. Use action="delete" if clearing it is really what you want.', $name )
				);
			}

			// A null clears; every provider treats that path through clear().
			if ( null === $value ) {
				return array(
					'value' => null,
					'notes' => $notes,
				);
			}

			switch ( $shape ) {
				case 'bool':
					$bool = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					if ( null === $bool ) {
						return new WP_Error( 'uich_field_bad_bool', sprintf( 'Field "%s" expects a boolean; "%s" is not one.', $name, self::describe( $value ) ) );
					}
					// The two plugins store booleans differently and each reads
					// only its own pair back as true.
					$out = 'jetengine' === $provider ? ( $bool ? 'true' : '' ) : ( $bool ? 1 : 0 );
					return array(
						'value' => $out,
						'notes' => $notes,
					);

				case 'number':
					if ( '' === $value ) {
						return array(
							'value' => '',
							'notes' => $notes,
						);
					}
					if ( ! is_numeric( $value ) ) {
						return new WP_Error( 'uich_field_bad_number', sprintf( 'Field "%s" expects a number; got "%s".', $name, self::describe( $value ) ) );
					}
					$num = ( (float) $value === floor( (float) $value ) ) ? (int) $value : (float) $value;

					$min = isset( $def['constraints']['min'] ) ? $def['constraints']['min'] : null;
					$max = isset( $def['constraints']['max'] ) ? $def['constraints']['max'] : null;
					if ( null !== $min && '' !== $min && $num < (float) $min ) {
						return new WP_Error( 'uich_field_out_of_range', sprintf( 'Field "%s" has a minimum of %s.', $name, $min ) );
					}
					if ( null !== $max && '' !== $max && $num > (float) $max ) {
						return new WP_Error( 'uich_field_out_of_range', sprintf( 'Field "%s" has a maximum of %s.', $name, $max ) );
					}

					return array(
						'value' => $num,
						'notes' => $notes,
					);

				case 'date':
				case 'datetime':
				case 'time':
					return self::coerce_temporal( $def, $value, $shape, $provider );

				case 'image':
				case 'file':
					return self::coerce_attachment( $def, $value );

				case 'choice':
				case 'choices':
					return self::coerce_choice( $def, $value, $shape, $provider );

				case 'relation':
					return self::coerce_relation( $def, $value, $target );

				case 'rows':
					return self::coerce_rows( $def, $value, $target );

				case 'object':
					if ( ! is_array( $value ) ) {
						return new WP_Error(
							'uich_field_bad_object',
							sprintf( 'Field "%s" (%s) expects an object%s; got "%s".', $name, $type, ( self::structure_for( $type ) ? ' with keys ' . implode( ', ', self::structure_for( $type ) ) : '' ), self::describe( $value ) )
						);
					}
					return array(
						'value' => $value,
						'notes' => $notes,
					);

				case 'array':
					if ( ! is_array( $value ) ) {
						return new WP_Error( 'uich_field_bad_array', sprintf( 'Field "%s" expects an array; got "%s".', $name, self::describe( $value ) ) );
					}
					return array(
						'value' => array_values( $value ),
						'notes' => $notes,
					);

				case 'unknown':
					return new WP_Error(
						'uich_field_not_writable',
						sprintf( 'Field "%s" has type "%s", which holds no value (it is a layout element).', $name, $type )
					);
			}

			// string / html / url / email.
			if ( is_array( $value ) ) {
				return new WP_Error( 'uich_field_bad_string', sprintf( 'Field "%s" expects a string; an array was passed.', $name ) );
			}

			$str = (string) $value;

			if ( 'email' === $shape && '' !== $str && ! is_email( $str ) ) {
				return new WP_Error( 'uich_field_bad_email', sprintf( '"%s" is not a valid email address for field "%s".', $str, $name ) );
			}

			if ( 'url' === $shape && '' !== $str ) {
				$clean = esc_url_raw( $str );
				if ( '' === $clean ) {
					return new WP_Error( 'uich_field_bad_url', sprintf( '"%s" is not a usable URL for field "%s".', $str, $name ) );
				}
				$str = $clean;
			}

			if ( 'html' === $shape ) {
				// Stored as given: an imported design's markup must survive, and
				// the output-safety decision belongs at print time (|kses_post),
				// not here - sanitising on the way in would silently rewrite the
				// user's own content.
				$notes[] = sprintf( 'Field "%s" stores HTML unescaped. Print it with |kses_post if it can contain user input.', $name );
			}

			return array(
				'value' => $str,
				'notes' => $notes,
			);
		}

		/**
		 * Dates, datetimes and times => the owning plugin's storage format.
		 *
		 * @param array  $def      Normalised definition.
		 * @param mixed  $value    Incoming value.
		 * @param string $shape    date | datetime | time.
		 * @param string $provider Provider slug.
		 * @return array|WP_Error
		 */
		private static function coerce_temporal( $def, $value, $shape, $provider ) {
			$name = isset( $def['name'] ) ? $def['name'] : '';

			if ( '' === $value ) {
				return array(
					'value' => '',
					'notes' => array(),
				);
			}

			// A bare integer is already a timestamp, which is what JetEngine
			// stores for its date fields.
			if ( is_numeric( $value ) && (int) $value > 100000 ) {
				$ts = (int) $value;
			} else {
				$ts = strtotime( (string) $value );
			}

			if ( false === $ts || null === $ts ) {
				return new WP_Error(
					'uich_field_bad_date',
					sprintf( '"%s" is not a date UiChemy can parse for field "%s". Pass ISO 8601 - "2026-09-07" or "2026-09-07 14:30:00". Ambiguous forms like "07/09/2026" are refused rather than guessed, because guessing produces a plausible wrong date that passes every read-back.', self::describe( $value ), $name )
				);
			}

			if ( 'jetengine' === $provider ) {
				// JetEngine date/datetime fields are timestamps when
				// is_timestamp is on, which is the default its own UI writes.
				if ( ! empty( $def['constraints']['is_timestamp'] ) ) {
					return array(
						'value' => $ts,
						'notes' => array(),
					);
				}

				$fmt = 'time' === $shape ? 'H:i' : ( 'datetime' === $shape ? 'Y-m-d H:i' : 'Y-m-d' );

				return array(
					'value' => gmdate( $fmt, $ts ),
					'notes' => array(),
				);
			}

			// ACF's storage formats are fixed and are NOT the display format -
			// `return_format` governs reading only.
			$fmt = 'Ymd';
			if ( 'datetime' === $shape ) {
				$fmt = 'Y-m-d H:i:s';
			} elseif ( 'time' === $shape ) {
				$fmt = 'H:i:s';
			}

			return array(
				'value' => gmdate( $fmt, $ts ),
				'notes' => array(
					sprintf( 'Stored as "%s" (ACF storage format). Reading it back returns the field\'s return_format, which is a different string - never write the read value back.', gmdate( $fmt, $ts ) ),
				),
			);
		}

		/**
		 * Media values => an attachment ID.
		 *
		 * A remote URL is refused rather than sideloaded. This code path is
		 * reachable with a caller-supplied URL, so fetching it would make the
		 * field layer a request-forgery primitive; uichemy-composer/media is the
		 * door that is designed to take a remote URL.
		 *
		 * @param array $def   Normalised definition.
		 * @param mixed $value Incoming value.
		 * @return array|WP_Error
		 */
		private static function coerce_attachment( $def, $value ) {
			$name = isset( $def['name'] ) ? $def['name'] : '';

			if ( '' === $value ) {
				return array(
					'value' => '',
					'notes' => array(),
				);
			}

			if ( is_array( $value ) ) {
				$value = isset( $value['ID'] ) ? $value['ID'] : ( isset( $value['id'] ) ? $value['id'] : ( isset( $value['url'] ) ? $value['url'] : '' ) );
			}

			if ( is_numeric( $value ) ) {
				$id = (int) $value;
				if ( 'attachment' !== get_post_type( $id ) ) {
					return new WP_Error( 'uich_field_bad_attachment', sprintf( '%d is not an attachment, so it cannot be the value of media field "%s".', $id, $name ) );
				}
				return array(
					'value' => self::media_storage_value( $def, $id ),
					'notes' => array(),
				);
			}

			$url  = (string) $value;
			$home = home_url();

			if ( 0 !== strpos( $url, $home ) && 0 !== strpos( $url, '/' ) ) {
				return new WP_Error(
					'uich_field_remote_media',
					sprintf( 'Field "%s" was given the remote URL "%s". The field layer never downloads from a URL - upload it with uichemy-composer/media (action="request-upload"), then pass the attachment id it returns.', $name, $url )
				);
			}

			$id = attachment_url_to_postid( $url );

			if ( ! $id ) {
				return new WP_Error(
					'uich_field_unknown_media',
					sprintf( '"%s" is not in this site\'s media library, so field "%s" cannot point at it. Find it with uichemy-composer/media (action="find") or upload it first.', $url, $name )
				);
			}

			return array(
				'value' => self::media_storage_value( $def, $id ),
				'notes' => array( sprintf( 'Resolved "%s" to attachment %d.', $url, $id ) ),
			);
		}

		/**
		 * What a media field actually stores, which differs per provider and per
		 * field configuration.
		 *
		 * @param array $def Normalised definition.
		 * @param int   $id  Attachment id.
		 * @return int|string
		 */
		private static function media_storage_value( $def, $id ) {
			$format = isset( $def['return_format'] ) ? (string) $def['return_format'] : '';

			// JetEngine media fields store either the id or the URL, chosen per
			// field (`value_format`). Storing the wrong one renders nothing.
			if ( 'jetengine' === ( isset( $def['provider'] ) ? $def['provider'] : '' ) && 'url' === $format ) {
				return wp_get_attachment_url( $id );
			}

			// ACF always STORES the id whatever its return_format is; the format
			// governs reading only.
			return (int) $id;
		}

		/**
		 * Select / radio / checkbox => a value inside the declared choice list.
		 *
		 * The list is the whole point: a select's allowed values are otherwise
		 * unguessable, and a value outside it stores fine and then renders as an
		 * empty label everywhere the choices are used for display.
		 *
		 * @param array  $def      Normalised definition.
		 * @param mixed  $value    Incoming value.
		 * @param string $shape    choice | choices.
		 * @param string $provider Provider slug.
		 * @return array|WP_Error
		 */
		private static function coerce_choice( $def, $value, $shape, $provider ) {
			$name    = isset( $def['name'] ) ? $def['name'] : '';
			$choices = isset( $def['choices'] ) && is_array( $def['choices'] ) ? $def['choices'] : array();
			$valid   = array_map( 'strval', array_keys( $choices ) );

			$values = is_array( $value ) ? array_values( $value ) : array( $value );
			$values = array_map( 'strval', $values );

			if ( 'choice' === $shape && count( $values ) > 1 ) {
				return new WP_Error( 'uich_field_single_choice', sprintf( 'Field "%s" accepts one value, not %d.', $name, count( $values ) ) );
			}

			if ( $valid ) {
				$bad = array_values( array_diff( array_filter( $values, 'strlen' ), $valid ) );
				if ( $bad ) {
					return new WP_Error(
						'uich_field_bad_choice',
						sprintf(
							'Field "%s" does not allow %s. Valid values: %s. (Pass the value, not the label.)',
							$name,
							'"' . implode( '", "', $bad ) . '"',
							implode( ', ', $valid )
						)
					);
				}
			}

			if ( 'choice' === $shape ) {
				return array(
					'value' => isset( $values[0] ) ? $values[0] : '',
					'notes' => array(),
				);
			}

			// JetEngine stores a multi-checkbox as a value => true map; ACF
			// stores a plain list. Each reads only its own shape.
			if ( 'jetengine' === $provider ) {
				$map = array();
				foreach ( $values as $v ) {
					if ( '' !== $v ) {
						$map[ $v ] = true;
					}
				}
				return array(
					'value' => $map,
					'notes' => array( sprintf( 'Stored as JetEngine\'s { value: true } map for field "%s", not a list.', $name ) ),
				);
			}

			return array(
				'value' => array_values( array_filter( $values, 'strlen' ) ),
				'notes' => array(),
			);
		}

		/**
		 * Relationship / post_object / taxonomy / user => IDs.
		 *
		 * @param array $def    Normalised definition.
		 * @param mixed $value  Incoming value.
		 * @param array $target Resolved target.
		 * @return array|WP_Error
		 */
		private static function coerce_relation( $def, $value, $target ) {
			unset( $target );

			$name  = isset( $def['name'] ) ? $def['name'] : '';
			$type  = isset( $def['type'] ) ? $def['type'] : '';
			$taxes = isset( $def['constraints']['taxonomy'] ) ? (array) $def['constraints']['taxonomy'] : array();
			$multi = ! empty( $def['constraints']['multiple'] );
			$notes = array();

			$values = is_array( $value ) ? array_values( $value ) : array( $value );
			$ids    = array();

			foreach ( $values as $item ) {
				if ( '' === $item || null === $item ) {
					continue;
				}

				if ( is_numeric( $item ) ) {
					$ids[] = (int) $item;
					continue;
				}

				if ( 'taxonomy' === $type ) {
					$resolved = false;
					foreach ( $taxes ? $taxes : get_taxonomies( array(), 'names' ) as $tax ) {
						$term = get_term_by( 'slug', (string) $item, $tax );
						if ( $term && ! is_wp_error( $term ) ) {
							$ids[]    = (int) $term->term_id;
							$notes[]  = sprintf( 'Resolved term slug "%s" to id %d in taxonomy "%s".', $item, $term->term_id, $tax );
							$resolved = true;
							break;
						}
					}
					if ( ! $resolved ) {
						return new WP_Error(
							'uich_field_unknown_term',
							sprintf( 'No term with slug "%s" exists%s, so field "%s" cannot reference it. A non-existent term stores silently and renders as nothing.', $item, $taxes ? ' in ' . implode( '/', $taxes ) : '', $name )
						);
					}
					continue;
				}

				return new WP_Error(
					'uich_field_bad_relation',
					sprintf( 'Field "%s" expects object IDs; "%s" is neither an id nor a resolvable slug.', $name, self::describe( $item ) )
				);
			}

			if ( ! $multi && count( $ids ) > 1 && in_array( $type, array( 'post_object', 'user', 'page_link' ), true ) ) {
				$notes[] = sprintf( 'Field "%s" is configured single-value; only the first id was kept.', $name );
				$ids     = array( $ids[0] );
			}

			$out = ( $multi || in_array( $type, array( 'relationship', 'taxonomy' ), true ) )
				? $ids
				: ( isset( $ids[0] ) ? $ids[0] : '' );

			return array(
				'value' => $out,
				'notes' => $notes,
			);
		}

		/**
		 * Repeater rows => a list of maps keyed by sub-field NAME.
		 *
		 * A repeater write replaces every row. That is not a UiChemy choice - it
		 * is how both plugins store one - so the note is emitted every time
		 * rather than only when it looks wrong.
		 *
		 * @param array $def    Normalised definition.
		 * @param mixed $value  Incoming value.
		 * @param array $target Resolved target.
		 * @return array|WP_Error
		 */
		private static function coerce_rows( $def, $value, $target ) {
			$name = isset( $def['name'] ) ? $def['name'] : '';
			$type = isset( $def['type'] ) ? $def['type'] : '';

			if ( 'flexible_content' === $type ) {
				return new WP_Error(
					'uich_field_unsupported',
					sprintf( 'Field "%s" is ACF Flexible Content, which UiChemy does not write. Each row\'s shape depends on its layout name, so a correct write needs per-layout partials the render engine does not have. It is on the declared-unsupported list - read it, and model the data another way.', $name )
				);
			}

			if ( ! is_array( $value ) ) {
				return new WP_Error( 'uich_field_bad_rows', sprintf( 'Field "%s" is a repeater and expects an array of rows; got "%s".', $name, self::describe( $value ) ) );
			}

			$subs  = isset( $def['sub_fields'] ) && is_array( $def['sub_fields'] ) ? $def['sub_fields'] : array();
			$known = array();
			foreach ( $subs as $sub ) {
				if ( isset( $sub['name'] ) ) {
					$known[ (string) $sub['name'] ] = $sub;
				}
			}

			$rows  = array();
			$notes = array(
				sprintf( 'A repeater write REPLACES every row of "%s". %d row(s) sent.', $name, count( $value ) ),
			);

			foreach ( array_values( $value ) as $i => $row ) {
				if ( ! is_array( $row ) ) {
					return new WP_Error( 'uich_field_bad_row', sprintf( 'Row %d of "%s" is not an object.', $i, $name ) );
				}

				$out = array();

				foreach ( $row as $key => $val ) {
					$key = (string) $key;

					if ( $known && ! isset( $known[ $key ] ) ) {
						return new WP_Error(
							'uich_field_unknown_subfield',
							sprintf( 'Row %d of "%s" has no sub-field "%s". Known sub-fields: %s.', $i, $name, $key, implode( ', ', array_keys( $known ) ) )
						);
					}

					if ( isset( $known[ $key ] ) ) {
						$coerced = self::coerce( $known[ $key ], $val, $target );
						if ( is_wp_error( $coerced ) ) {
							return new WP_Error(
								$coerced->get_error_code(),
								sprintf( 'Row %d of "%s": %s', $i, $name, $coerced->get_error_message() )
							);
						}
						$out[ $key ] = $coerced['value'];
						$notes       = array_merge( $notes, $coerced['notes'] );
						continue;
					}

					$out[ $key ] = $val;
				}

				$rows[] = $out;
			}

			return array(
				'value' => $rows,
				'notes' => $notes,
			);
		}

		/**
		 * A short, safe description of an arbitrary value, for error text.
		 *
		 * @param mixed $value Value.
		 * @return string
		 */
		public static function describe( $value ) {
			if ( is_array( $value ) ) {
				return 'array(' . count( $value ) . ')';
			}
			if ( is_bool( $value ) ) {
				return $value ? 'true' : 'false';
			}
			if ( null === $value ) {
				return 'null';
			}
			if ( is_object( $value ) ) {
				return get_class( $value );
			}

			$str = (string) $value;

			return strlen( $str ) > 60 ? substr( $str, 0, 57 ) . '...' : $str;
		}
	}
}
