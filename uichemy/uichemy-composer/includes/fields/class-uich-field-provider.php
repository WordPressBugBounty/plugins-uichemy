<?php
/**
 * The contract every field provider implements, plus the shared normalisation.
 *
 * A "provider" is the plugin that OWNS a field: ACF, JetEngine, or WordPress
 * itself for plain registered meta. The one rule this whole subsystem exists to
 * enforce is that a value is written through its owner and never around it -
 * `update_post_meta()` on an ACF field stores the value row and not the
 * reference row, which renders on the front end, shows blank in the editor, and
 * is wiped by the next human save. That failure passes a read-back check, which
 * is why the check cannot be round-trip equality (see assert_shape()).
 *
 * Providers return NORMALISED definitions - one shape, whatever the source - so
 * the ability, the writer and the value coercer are each written once. The
 * normalised shape is documented on definition_defaults().
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

if ( ! class_exists( 'Uich_Field_Provider' ) ) {

	/**
	 * Base class for ACF / JetEngine / native-meta field providers.
	 */
	abstract class Uich_Field_Provider {

		/**
		 * Provider slug, as it appears in every payload (`acf`, `jetengine`, `meta`).
		 *
		 * @return string
		 */
		abstract public function slug();

		/**
		 * Human label, for error messages a person may read.
		 *
		 * @return string
		 */
		abstract public function label();

		/**
		 * Whether the owning plugin is active on this site.
		 *
		 * @return bool
		 */
		abstract public function is_active();

		/**
		 * Field definitions this provider owns for one target.
		 *
		 * @param array $target Resolved target from Uich_Field_Guard::resolve_target().
		 * @return array<string,array> Field name => normalised definition.
		 */
		abstract public function definitions( $target );

		/**
		 * Read one field's stored value and its formatted counterpart.
		 *
		 * BOTH are always returned, and there is deliberately no "format"
		 * parameter: choosing wrongly is the Rule-4 corruption path. An agent
		 * that reads a formatted ACF date and writes it back turns 07/09/2026
		 * into today, and the corrupted value reads back looking like a date, so
		 * verification passes. Handing back both, always, means the writable one
		 * is never the one on screen by accident.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return array{value:mixed,formatted:mixed}
		 */
		abstract public function read( $def, $target );

		/**
		 * Write one field's value. The value has already been coerced.
		 *
		 * @param array $def    Normalised definition.
		 * @param mixed $value  Coerced storage value.
		 * @param array $target Resolved target.
		 * @return true|WP_Error
		 */
		abstract public function write( $def, $value, $target );

		/**
		 * Clear one field's value.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return true|WP_Error
		 */
		abstract public function clear( $def, $target );

		/**
		 * Assert that what is now in storage has the SHAPE the owning plugin
		 * expects - not that it equals what was sent.
		 *
		 * Round-trip equality passes on both known corruption paths: an ACF
		 * repeater written as one serialised array reads back intact through
		 * get_field() while ACF Pro sees a row-count integer, and an ACF value
		 * written with update_post_meta() reads back fine while the editor shows
		 * blank. So the assertion is structural.
		 *
		 * A provider with nothing structural to check returns an empty array.
		 *
		 * @param array $def    Normalised definition.
		 * @param array $target Resolved target.
		 * @return array<int,string> Problems found, empty when the shape is right.
		 */
		public function assert_shape( $def, $target ) {
			unset( $def, $target );
			return array();
		}

		// ============================================================
		// SHARED NORMALISATION
		// ============================================================

		/**
		 * The normalised definition shape, and what each key is for.
		 *
		 * @return array
		 */
		protected function definition_defaults() {
			return array(
				// Identity.
				'provider'        => $this->slug(),
				'name'            => '',
				// Provider-native key: an ACF field_key, a JetEngine field id, null
				// for plain meta. Carried so a caller can disambiguate two fields
				// with the same name in different groups - NEVER as the write
				// address: verified, writing an ACF repeater by key stores rows
				// keyed `field_r_sub1` where the reader looks for `k`, and the whole
				// repeater renders empty.
				'key'             => null,
				'label'           => '',
				'instructions'    => '',

				// Type, twice: as the owning plugin calls it, and as a shape a
				// caller can act on without knowing the plugin.
				'type'            => 'text',
				'value_shape'     => 'string',
				'shape'           => null,

				// Validation surface.
				'required'        => false,
				'choices'         => null,
				'constraints'     => array(),
				'default'         => null,

				// Structured types.
				'sub_fields'      => null,
				'layouts'         => null,
				'return_format'   => null,

				// Where it lives.
				'group'           => '',
				'group_key'       => '',
				'object_subtypes' => array(),

				// Writability, and why not when not.
				'writable'        => true,
				'reason'          => '',
				'write_hint'      => '',
				// How to PRINT it, which is a different question from how to
				// write it and has its own traps - a JetEngine date bound bare
				// renders a Unix timestamp on the page.
				'print_hint'      => '',
			);
		}

		/**
		 * Fill a partial definition out to the full normalised shape.
		 *
		 * @param array $def Partial definition.
		 * @return array
		 */
		protected function normalise( array $def ) {
			$out = array_merge( $this->definition_defaults(), $def );

			$out['provider'] = $this->slug();
			$out['name']     = (string) $out['name'];
			$out['label']    = '' !== (string) $out['label'] ? (string) $out['label'] : $out['name'];

			if ( '' === (string) $out['write_hint'] ) {
				$out['write_hint'] = Uich_Field_Value::write_hint( $out );
			}

			if ( '' === (string) $out['print_hint'] ) {
				$out['print_hint'] = Uich_Field_Value::print_hint( $out, $this->token_prefix() );
			}

			return $out;
		}

		/**
		 * The Twig provider prefix for the target currently being described.
		 *
		 * @var string
		 */
		protected $prefix = 'post';

		/**
		 * Remember which provider prefix a field on this target binds through.
		 *
		 * A term field is `term.meta()`, not `post.meta()`, and a token with the
		 * wrong prefix resolves to nothing - which renders empty rather than
		 * failing, so a hint carrying the wrong prefix is worse than no hint.
		 * Called at the top of definitions().
		 *
		 * @param array $target Resolved target.
		 * @return void
		 */
		protected function set_target_context( $target ) {
			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';

			$map = array(
				'post'    => 'post',
				'term'    => 'term',
				'user'    => 'user',
				// Deliberately not a real provider name. No token reaches an
				// options page yet, and print_hint() says so rather than
				// handing back one that renders empty.
				'options' => 'options',
			);

			$this->prefix = isset( $map[ $type ] ) ? $map[ $type ] : 'post';
		}

		/**
		 * The Twig provider a field on the current target is reached through.
		 *
		 * @return string
		 */
		protected function token_prefix() {
			return $this->prefix;
		}

		/**
		 * Purge the caches that make a correct write look like it failed.
		 *
		 * Without this the user reloads, sees the old value, and reports a bug
		 * against a write that actually landed. Elementor keeps generated CSS per
		 * post, and any persistent object cache holds the meta group.
		 *
		 * @param array $target Resolved target.
		 * @return void
		 */
		protected function purge_caches( $target ) {
			$id   = isset( $target['object_id'] ) ? (int) $target['object_id'] : 0;
			$type = isset( $target['object_type'] ) ? $target['object_type'] : 'post';

			if ( 'post' === $type && $id ) {
				clean_post_cache( $id );

				if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
					try {
						$css = new \Elementor\Core\Files\CSS\Post( $id );
						$css->delete();
					} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// A missing Elementor CSS file is not a write failure.
					}
				}
			}

			if ( 'term' === $type && $id ) {
				clean_term_cache( $id, isset( $target['object_subtype'] ) ? $target['object_subtype'] : '' );
			}

			if ( 'user' === $type && $id ) {
				clean_user_cache( $id );
			}

			/**
			 * Fires after the field layer has written to an object, so other
			 * plugins can purge their own caches.
			 *
			 * @since 5.1.0
			 *
			 * @param array  $target   Resolved target.
			 * @param string $provider Provider slug that performed the write.
			 */
			do_action( 'uichemy/fields/after_write', $target, $this->slug() );
		}
	}
}
