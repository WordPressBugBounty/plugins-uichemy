<?php
/**
 * Code Mode dispatcher: one UiChemy call in a script -> one ability execution.
 *
 *   "page.grep" + { pattern }  ->  uichemy-composer/page { action: grep, action_parameters: { pattern } }
 *
 * It takes v2's own execute-ability path, not a copy of it:
 * UiChemy_MCP_Server_V2::resolve_served_ability(), then ::check_call()
 * (permission, and whether this builder mode offers the action), then
 * execute(). In front of that sits the one thing v3 adds, a gate that sorts
 * every call into one of four kinds:
 *
 *   read     the ability or the action is annotated readonly, or the action's
 *            first word is a read verb (list, get, grep, find, audit ...);
 *            always runs.
 *   write    anything else, revert and restore included; runs unless
 *            UICHEMY_CODE_MODE_WRITES is false, within the run's call budget,
 *            previewed instead of made on a dry run.
 *   delete   actions whose first word is "delete". Permanent, so on top of
 *            the write rules: only when the run has allow_delete, at most
 *            MAX_DELETES per run. A confirmation code (confirm_token, or
 *            Module's confirm) is never accepted, by any write, in the run
 *            that received it, so a two-step call always has a
 *            person-visible step in between.
 *   refused  writes and deletes while they are switched off.
 *
 * Before the first write to a post (a call with a post_id) the post is
 * snapshotted for undo (UiChemy_Code_Mode_Undo).
 *
 * The kind comes from what the abilities already declare (their readonly
 * annotation and their action names), so there is no second list of
 * abilities to keep in step.
 *
 * @link       https://posimyth.com/
 * @since      5.6.0
 *
 * @package    UiChemy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'UiChemy_Code_Mode_Dispatcher' ) ) {

	/**
	 * Runs the ability calls a script makes.
	 */
	class UiChemy_Code_Mode_Dispatcher {

		/**
		 * First words of an action name that only read: list-redirects,
		 * get-code, grep, audit-page-seo, preview-page-schema ...
		 */
		const READ_VERBS = array( 'list', 'get', 'grep', 'find', 'search', 'describe', 'audit', 'preview', 'read', 'status', 'schema', 'entities', 'architecture' );

		/** First words of an action name that delete: delete, delete-section, delete-redirect ... */
		const DELETE_VERBS = array( 'delete' );

		/** Deletes one run may make. */
		const MAX_DELETES = 5;

		/** @var bool Answer writes with what they would do instead of running them. */
		private $dry_run;

		/** @var bool Did the caller let this run delete? */
		private $allow_delete;

		/** @var UiChemy_Code_Mode_Undo|null */
		private $undo;

		/** @var int */
		private $writes = 0;

		/** @var int */
		private $deletes = 0;

		/** Keys a confirmation code comes back under, and is sent back in. */
		const CONFIRM_KEYS = array( 'confirm_token', 'confirm' );

		/** @var array<string,true> Confirmation codes handed out during this run. */
		private $issued_tokens = array();

		/**
		 * @param bool                        $dry_run      Preview writes instead of running them.
		 * @param bool                        $allow_delete Let delete actions run.
		 * @param UiChemy_Code_Mode_Undo|null $undo         Where to snapshot posts before writes.
		 */
		public function __construct( $dry_run = false, $allow_delete = false, $undo = null ) {
			$this->dry_run      = (bool) $dry_run;
			$this->allow_delete = (bool) $allow_delete;
			$this->undo         = $undo;
		}

		/**
		 * Can scripts change the site? Yes, unless wp-config.php sets
		 * UICHEMY_CODE_MODE_WRITES to false. A refusal never points the model at
		 * another way round it: the switch is the site owner's.
		 *
		 * @return bool
		 */
		public static function writes_enabled() {
			return ! defined( 'UICHEMY_CODE_MODE_WRITES' ) || (bool) UICHEMY_CODE_MODE_WRITES;
		}

		/**
		 * Can scripts delete (when a run asks with allow_delete)? Yes, unless
		 * writes are off or wp-config.php sets UICHEMY_CODE_MODE_DELETES to false.
		 *
		 * @return bool
		 */
		public static function deletes_enabled() {
			return self::writes_enabled() && ( ! defined( 'UICHEMY_CODE_MODE_DELETES' ) || (bool) UICHEMY_CODE_MODE_DELETES );
		}

		/**
		 * Does the ability declare itself read-only (meta.annotations.readonly)?
		 *
		 * @param \WP_Ability $ability Ability.
		 * @return bool
		 */
		public static function ability_is_readonly( $ability ) {
			$meta = $ability->get_meta();
			return ! empty( $meta['annotations']['readonly'] );
		}

		/**
		 * Does one action declare itself read-only (meta.actions[].readonly)? For
		 * an action that changes nothing on the site but is not named with a
		 * read verb, e.g. uichemy-team's report-bug, which only keeps a draft.
		 *
		 * @param \WP_Ability $ability Ability.
		 * @param string      $action  Action name.
		 * @return bool
		 */
		public static function action_is_readonly( $ability, $action ) {
			$meta = $ability->get_meta();
			foreach ( (array) ( $meta['actions'] ?? array() ) as $entry ) {
				if ( is_array( $entry ) && ( $entry['name'] ?? null ) === $action ) {
					return ! empty( $entry['readonly'] );
				}
			}
			return false;
		}

		/**
		 * What kind of call is this?
		 *
		 * @param string $action           Action name, '' for an ability without actions.
		 * @param bool   $ability_readonly The ability's own readonly annotation.
		 * @return string 'read', 'write', 'delete' or 'refused'.
		 */
		public static function kind( $action, $ability_readonly ) {
			$verb = (string) strtok( (string) $action, '-' );

			if ( in_array( $verb, self::DELETE_VERBS, true ) ) {
				return self::deletes_enabled() ? 'delete' : 'refused';
			}
			if ( $ability_readonly || in_array( $verb, self::READ_VERBS, true ) ) {
				return 'read';
			}

			return self::writes_enabled() ? 'write' : 'refused';
		}

		/**
		 * Why a refused call is refused, in one sentence.
		 *
		 * @param string $id Call id, e.g. "page.grep".
		 * @return string
		 */
		public static function refusal( $id ) {
			list( , $action ) = self::split( $id );

			if ( in_array( (string) strtok( $action, '-' ), self::DELETE_VERBS, true ) && self::writes_enabled() ) {
				return sprintf( '%s deletes, and the site owner has switched deletes off for scripts (UICHEMY_CODE_MODE_DELETES is false). Tell the user; do not look for another way to delete.', $id );
			}
			return sprintf( '%s changes the site, and the site owner has made scripts read-only (UICHEMY_CODE_MODE_WRITES is false). Tell the user; do not look for another way to change the site.', $id );
		}

		/**
		 * Run one call.
		 *
		 * @param string $id   Call id, e.g. "page.grep".
		 * @param array  $args Named arguments the script passed (nulls already dropped).
		 * @return array{ok:bool, value?:mixed, message?:string, refused?:bool, write?:bool}
		 */
		public function dispatch( $id, $args ) {
			$result = $this->dispatch_raw( (string) $id, is_array( $args ) ? $args : array() );
			if ( ! class_exists( 'UiChemy_Code_Mode_Text' ) ) {
				return $result;
			}

			// The abilities answer in v2's words ("call action=\"get-code\""): say them
			// in v3's. Content (code, values, titles) is left as it is.
			$map          = UiChemy_Code_Mode_Text::map( UiChemy_Code_Mode_Catalog::modules() );
			$owner        = '';
			list( $slug ) = self::split( (string) $id );
			foreach ( $map as $class => $info ) {
				if ( $info['slug'] === $slug ) {
					$owner = $class;
				}
			}
			if ( isset( $result['message'] ) && is_string( $result['message'] ) ) {
				$result['message'] = UiChemy_Code_Mode_Text::to_v3( $result['message'], $owner, $map, true );
			}
			if ( ! empty( $result['ok'] ) && array_key_exists( 'value', $result ) ) {
				if ( 'read-skill' === $slug && is_array( $result['value'] ) && isset( $result['value']['text'] ) && is_string( $result['value']['text'] ) ) {
					// A skill is the build process, written for v2: all of it in v3 names.
					$result['value']['text'] = "> Code Mode: this skill names abilities the v2 way. An ability slug plus an action is a method here: uichemy-composer/page action \"patch-code\" is Page::patchCode(), with the action's parameters as named arguments. Run every call from an `execute` script.\n\n"
						. UiChemy_Code_Mode_Text::to_v3( $result['value']['text'], '', $map, false );
				} else {
					$result['value'] = UiChemy_Code_Mode_Text::result( $result['value'], $owner, $map );
				}
			}
			return $result;
		}

		/**
		 * Run one call, as the ability answers it (v2 wording).
		 *
		 * @param string $id   Call id.
		 * @param array  $args Named arguments.
		 * @return array
		 */
		private function dispatch_raw( $id, array $args ) {
			list( $slug, $action ) = self::split( $id );

			$ability = UiChemy_MCP_Server_V2::resolve_served_ability( UiChemy_MCP_Server_V2::NAMESPACE_SLUG . '/' . $slug );
			if ( is_wp_error( $ability ) ) {
				return self::failure( $ability );
			}

			$kind = self::kind( $action, self::ability_is_readonly( $ability ) || self::action_is_readonly( $ability, $action ) || self::reads_only( $ability, $action, $args ) );
			if ( 'refused' === $kind ) {
				return self::refuse( self::refusal( $id ) );
			}

			$write = 'write' === $kind || 'delete' === $kind;
			if ( 'delete' === $kind ) {
				// A dry run deletes nothing, so it may preview a delete without
				// allow_delete: that preview is what the user is shown before saying yes.
				if ( ! $this->allow_delete && ! $this->dry_run ) {
					return self::refuse( sprintf( '%s deletes, and deletes are permanent. It runs only when execute is called with allow_delete: true. Tell the user exactly what will be deleted (run with dry_run: true) and ask first.', $id ) );
				}
				if ( $this->deletes >= self::MAX_DELETES ) {
					return self::refuse( sprintf( '%s is refused: a script may delete at most %d times per run.', $id, self::MAX_DELETES ) );
				}
			}
			if ( $write ) {
				// A confirmation code a call handed out in this run (Module::delete
				// names it "confirm", most abilities "confirm_token") is never accepted
				// in the same run, for any write: the person sees it in between.
				foreach ( self::CONFIRM_KEYS as $key ) {
					if ( isset( $args[ $key ] ) && is_string( $args[ $key ] ) && isset( $this->issued_tokens[ trim( $args[ $key ] ) ] ) ) {
						return self::refuse( sprintf( '%s is refused: its %s came from this same run. Show the user what the first call said it will do, then confirm in a new execute call.', $id, $key ) );
					}
				}
				// Writes count toward the run's one call budget (UiChemy_Code_Mode::MAX_CALLS),
				// not a budget of their own; deletes keep theirs.
				++$this->writes;
				if ( 'delete' === $kind ) {
					++$this->deletes;
				}
			}

			$input = '' === $action
				? $args
				: array(
					'action'            => $action,
					'action_parameters' => $args,
				);

			$refused = UiChemy_MCP_Server_V2::check_call( $ability, $input );
			if ( $refused ) {
				return array_merge( self::failure( $refused ), array( 'write' => $write ) );
			}

			// A dry run has been through every check above, and the ability's own
			// input validation below, so a preview fails where the real run would.
			if ( $write && $this->dry_run ) {
				$invalid = self::invalid_input( $ability, $input );
				if ( null !== $invalid ) {
					return array_merge( self::failure( $invalid ), array( 'write' => true ) );
				}
				$preview = array(
					'dry_run'    => true,
					'would_call' => $id,
					'with'       => $args,
				);
				$checked = false;
				// The target must exist, as the real call would require.
				foreach ( array( 'post_id', 'product_id' ) as $key ) {
					if ( isset( $args[ $key ] ) && is_int( $args[ $key ] ) && $args[ $key ] > 0 && ! get_post( $args[ $key ] ) ) {
						return array_merge( self::refuse( sprintf( '%s: there is no post with %s %d.', $id, $key, $args[ $key ] ) ), array( 'refused' => false, 'write' => true ) );
					}
				}
				// A two-step delete's first call (no confirm code yet) only reports what
				// would go and hands out the code: run it, so the preview shows the user
				// what they are agreeing to. Every such first step was checked to return
				// before it deletes anything.
				if ( 'delete' === $kind && '' !== self::confirm_key( $ability, $action ) && ! self::has_confirm( $args ) ) {
					$result = $ability->execute( $input );
					if ( is_wp_error( $result ) ) {
						return array_merge( self::failure( $result ), array( 'write' => true ) );
					}
					$this->remember_tokens( $result );
					$preview['preview'] = $result;
					$checked            = true;
				} elseif ( '' !== $action && self::has_own_dry_run( $ability, $action ) ) {
					// An action with a dry_run of its own previews for real (what would
					// change, match counts) and still writes nothing.
					$input['action_parameters']['dry_run'] = true;
					$result                                 = $ability->execute( $input );
					if ( is_wp_error( $result ) ) {
						// The real run would fail here too.
						return array_merge( self::failure( $result ), array( 'write' => true ) );
					}
					$preview['preview'] = $result;
					$checked            = true;
				}
				if ( ! $checked ) {
					// Honest about what a preview proves when the ability did not run.
					$preview['checked'] = 'Arguments and types only. The real call can still refuse, e.g. a post or file that does not exist.';
				}
				return array(
					'ok'    => true,
					'write' => true,
					'value' => $preview,
				);
			}

			if ( $write && null !== $this->undo ) {
				if ( isset( $args['post_id'] ) && is_int( $args['post_id'] ) && $args['post_id'] > 0 ) {
					$this->undo->capture( $args['post_id'] );
				} else {
					$this->undo->not_covered( $id );
				}
			}

			$result = $ability->execute( $input );
			if ( is_wp_error( $result ) ) {
				return array_merge( self::failure( $result ), array( 'write' => $write ) );
			}
			$this->remember_tokens( $result );

			$answer = array(
				'ok'    => true,
				'write' => $write,
				'value' => $result,
			);
			// A batch write that answered without an error but says part of it failed
			// ({ ok: false } or { failed: 2 }) is listed under writes as not ok.
			if ( $write && is_array( $result ) && ( ( array_key_exists( 'ok', $result ) && false === $result['ok'] ) || ( array_key_exists( 'success', $result ) && false === $result['success'] ) || ( isset( $result['failed'] ) && is_int( $result['failed'] ) && $result['failed'] > 0 ) ) ) {
				$answer['reported_failure'] = true;
			}
			return $answer;
		}

		/**
		 * The confirmation parameter a two-step action takes, or ''.
		 *
		 * @param \WP_Ability $ability Ability.
		 * @param string      $action  Action.
		 * @return string
		 */
		private static function confirm_key( $ability, $action ) {
			$params = self::action_params( $ability, $action );
			foreach ( self::CONFIRM_KEYS as $key ) {
				if ( isset( $params[ $key ] ) ) {
					return $key;
				}
			}
			return '';
		}

		/**
		 * @param array $args Call arguments.
		 * @return bool Was a confirmation code passed?
		 */
		private static function has_confirm( array $args ) {
			foreach ( self::CONFIRM_KEYS as $key ) {
				if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && '' !== trim( (string) $args[ $key ] ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * A call that only reads, although its action can write: an action that
		 * declares readonly_unless (parameter names) called with none of them, e.g.
		 * Module::safeMode() without enabled, Forms::configure() with only its target.
		 *
		 * @param \WP_Ability $ability Ability.
		 * @param string      $action  Action.
		 * @param array       $args    Arguments.
		 * @return bool
		 */
		private static function reads_only( $ability, $action, array $args ) {
			foreach ( (array) ( $ability->get_meta()['actions'] ?? array() ) as $entry ) {
				if ( is_array( $entry ) && ( $entry['name'] ?? null ) === $action && ! empty( $entry['readonly_unless'] ) && is_array( $entry['readonly_unless'] ) ) {
					$given = array_keys(
						array_filter(
							$args,
							static function ( $v ) {
								return null !== $v;
							}
						)
					);
					return ! array_intersect( $entry['readonly_unless'], $given );
				}
			}
			return false;
		}

		/**
		 * An action's parameter schemas, by name.
		 *
		 * @param \WP_Ability $ability Ability.
		 * @param string      $action  Action.
		 * @return array<string,array>
		 */
		private static function action_params( $ability, $action ) {
			foreach ( (array) ( $ability->get_meta()['actions'] ?? array() ) as $entry ) {
				if ( ! is_array( $entry ) || ( $entry['name'] ?? null ) !== $action ) {
					continue;
				}
				$params = $entry['action_parameters_schema']['properties'] ?? ( $entry['parameters'] ?? array() );
				return is_object( $params ) ? get_object_vars( $params ) : (array) $params;
			}
			return array();
		}

		/**
		 * The Abilities API's own input check, without running the ability.
		 *
		 * @param \WP_Ability $ability Ability.
		 * @param array       $input   Input as execute() would get it.
		 * @return WP_Error|null Why the real run would refuse it, or null.
		 */
		private static function invalid_input( $ability, array $input ) {
			if ( ! method_exists( $ability, 'validate_input' ) ) {
				return null;
			}
			$normal = method_exists( $ability, 'normalize_input' ) ? $ability->normalize_input( $input ) : $input;
			$valid  = $ability->validate_input( $normal );
			return is_wp_error( $valid ) ? $valid : null;
		}

		/**
		 * Does this action take a boolean dry_run of its own?
		 *
		 * @param \WP_Ability $ability Ability.
		 * @param string      $action  Action name.
		 * @return bool
		 */
		private static function has_own_dry_run( $ability, $action ) {
			$meta = $ability->get_meta();
			foreach ( (array) ( $meta['actions'] ?? array() ) as $entry ) {
				if ( ! is_array( $entry ) || ( $entry['name'] ?? null ) !== $action ) {
					continue;
				}
				$params = $entry['action_parameters_schema']['properties'] ?? ( $entry['parameters'] ?? array() );
				$params = is_object( $params ) ? get_object_vars( $params ) : (array) $params;
				$type   = $params['dry_run']['type'] ?? null;
				return 'boolean' === $type || ( is_array( $type ) && in_array( 'boolean', $type, true ) );
			}
			return false;
		}

		/**
		 * Note every confirm_token a result hands out, so this run cannot use it.
		 *
		 * @param mixed $value Ability result.
		 * @param int   $depth How deep to look.
		 */
		public function remember_tokens( $value, $depth = 0 ) {
			if ( $depth > 3 || ( ! is_array( $value ) && ! is_object( $value ) ) ) {
				return;
			}
			foreach ( (array) $value as $key => $item ) {
				if ( in_array( $key, self::CONFIRM_KEYS, true ) && is_string( $item ) && '' !== trim( $item ) ) {
					$this->issued_tokens[ trim( $item ) ] = true;
				} elseif ( is_array( $item ) || is_object( $item ) ) {
					$this->remember_tokens( $item, $depth + 1 );
				}
			}
		}

		/**
		 * "seo.audit-page-seo" -> [ "seo", "audit-page-seo" ]; "read-skill" -> [ "read-skill", "" ].
		 *
		 * @param string $id Call id, e.g. "page.grep".
		 * @return array{0:string, 1:string}
		 */
		private static function split( $id ) {
			$parts = explode( '.', (string) $id, 2 );
			return array( $parts[0], $parts[1] ?? '' );
		}

		/**
		 * @param string $message Why.
		 * @return array{ok:false, refused:true, message:string}
		 */
		private static function refuse( $message ) {
			return array(
				'ok'      => false,
				'refused' => true,
				'message' => $message,
			);
		}

		/**
		 * @param WP_Error $error Error.
		 * @return array{ok:false, message:string}
		 */
		private static function failure( $error ) {
			return array(
				'ok'      => false,
				'message' => $error->get_error_message(),
			);
		}
	}
}
