/**
 * Live show/hide for Content Fields conditional logic.
 *
 * The "Show this field if …" panel previously only affected the SERVER: required validation was
 * skipped for a conditional field, and uich_field_value() returned null when the rule was unmet.
 * The metabox itself always rendered the field, so the UI promised a behaviour it never performed.
 *
 * This evaluator mirrors Uich_Fields_Field_Groups_Location::match_field_conditional() and
 * ::rule_values_equal() exactly. That fidelity is the whole point: if JS and PHP disagreed, the
 * form would show a field the server then discards, or hide one the server still requires. Any
 * change to the PHP matcher must be mirrored here.
 *
 * Rule shape: groups of rules. Groups are OR'd, rules within a group are AND'd.
 *   [ [ { field, operator, value }, … ], … ]
 * A bare rule or a flat list of rules is also accepted, matching the PHP normalisation.
 *
 * @package Uichemy
 * @since 5.2.6
 */
( function ( $ ) {
	'use strict';

	var ROW = '.uich-gf-field-row';

	/**
	 * PHP's empty() semantics, which are NOT JavaScript's.
	 * Critically, the string "0" IS empty in PHP but truthy in JS — a Number/Text field containing
	 * "0" must satisfy an `empty` rule the same way the server sees it.
	 *
	 * @param {*} v Value.
	 * @return {boolean}
	 */
	function phpEmpty( v ) {
		if ( v === null || typeof v === 'undefined' ) {
			return true;
		}
		if ( typeof v === 'boolean' ) {
			return ! v;
		}
		if ( typeof v === 'number' ) {
			return v === 0;
		}
		if ( typeof v === 'string' ) {
			return v === '' || v === '0';
		}
		if ( Array.isArray( v ) ) {
			return v.length === 0;
		}
		return false;
	}

	/** Mirrors rule_values_equal(): scalars compare as strings, everything else strictly. */
	function valuesEqual( actual, value ) {
		var aScalar = actual === null || [ 'string', 'number', 'boolean' ].indexOf( typeof actual ) !== -1;
		var bScalar = value === null || [ 'string', 'number', 'boolean' ].indexOf( typeof value ) !== -1;
		if ( aScalar && bScalar ) {
			return String( actual === null ? '' : actual ) === String( value === null ? '' : value );
		}
		return actual === value;
	}

	/**
	 * PHP's is_numeric(), which is NOT JavaScript's Number() coercion.
	 *
	 * The difference matters here: Number('') is 0 and Number(' ') is 0, so a JS-native check would
	 * treat an empty field as the number zero and make "Greater than -1" true for an untouched
	 * field. PHP accepts leading whitespace, a sign, a decimal point and an exponent — and nothing
	 * else. Mirrored exactly so the browser hides precisely what the server discards.
	 *
	 * @param {*} v Value.
	 * @return {boolean}
	 */
	function phpIsNumeric( v ) {
		if ( typeof v === 'number' ) {
			return isFinite( v );
		}
		if ( typeof v !== 'string' ) {
			return false;
		}
		return /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/.test( v );
	}

	/**
	 * Mirrors rule_contains(): a multi-value field is searched entry by entry.
	 *
	 * The old test required both sides to be strings, so a Select with "Select Multiple" — which
	 * stores an array — could never match. An empty needle is false, because indexOf( '' ) is 0 and
	 * would make an unfinished rule match everything.
	 */
	function contains( actual, want ) {
		if ( Array.isArray( want ) || want === null || typeof want === 'undefined' ) {
			return false;
		}
		var needle = String( want );
		if ( needle === '' ) {
			return false;
		}
		if ( Array.isArray( actual ) ) {
			for ( var i = 0; i < actual.length; i++ ) {
				var entry = actual[ i ];
				if ( ( typeof entry === 'string' || typeof entry === 'number' || typeof entry === 'boolean' ) &&
					String( entry ).indexOf( needle ) !== -1 ) {
					return true;
				}
			}
			return false;
		}
		if ( actual === null || typeof actual === 'undefined' || typeof actual === 'object' ) {
			return false;
		}
		return String( actual ).indexOf( needle ) !== -1;
	}

	/** Mirrors rule_numeric_compare(): a real number comparison, or false — never alphabetical. */
	function numericCompare( actual, want, op ) {
		if ( Array.isArray( actual ) || Array.isArray( want ) ) {
			return false;
		}
		if ( ! phpIsNumeric( actual ) || ! phpIsNumeric( want ) ) {
			return false;
		}
		var a = parseFloat( actual );
		var b = parseFloat( want );
		return op === '>' ? a > b : a < b;
	}

	/** Mirrors rule_all_set(): a value is present, and every entry of a multi-value field is too. */
	function allSet( actual ) {
		if ( Array.isArray( actual ) ) {
			if ( actual.length === 0 ) {
				return false;
			}
			for ( var i = 0; i < actual.length; i++ ) {
				var entry = actual[ i ];
				if ( Array.isArray( entry ) ? entry.length === 0 : ( entry === '' || entry === null || typeof entry === 'undefined' ) ) {
					return false;
				}
			}
			return true;
		}
		return actual !== '' && actual !== null && typeof actual !== 'undefined';
	}

	/** Mirrors rule_in_range(): inclusive, each bound optional, both bounds missing is never a match. */
	function inRange( actual, want ) {
		if ( Array.isArray( actual ) || ! phpIsNumeric( actual ) ) {
			return false;
		}
		var parts = String( typeof want === 'undefined' || want === null ? '' : want ).split( ',' );
		var min = parts.length > 0 ? String( parts[ 0 ] ).trim() : '';
		var max = parts.length > 1 ? String( parts[ 1 ] ).trim() : '';
		if ( min === '' && max === '' ) {
			return false;
		}
		var a = parseFloat( actual );
		if ( min !== '' && phpIsNumeric( min ) && a < parseFloat( min ) ) {
			return false;
		}
		if ( max !== '' && phpIsNumeric( max ) && a > parseFloat( max ) ) {
			return false;
		}
		return true;
	}

	/** Normalise to groups-of-rules, mirroring the PHP branch on isset($cl['field']) / $cl[0]['field']. */
	function toGroups( cl ) {
		if ( ! cl || typeof cl !== 'object' ) {
			return [];
		}
		if ( ! Array.isArray( cl ) ) {
			return cl.field ? [ [ cl ] ] : [];
		}
		if ( cl.length && cl[ 0 ] && ! Array.isArray( cl[ 0 ] ) && cl[ 0 ].field ) {
			return [ cl ];
		}
		return cl;
	}

	function evaluate( cl, values ) {
		var groups = toGroups( cl );
		if ( ! groups.length ) {
			return true;
		}
		for ( var g = 0; g < groups.length; g++ ) {
			var group = groups[ g ];
			if ( ! Array.isArray( group ) ) {
				continue;
			}
			var matches = true;
			for ( var r = 0; r < group.length; r++ ) {
				var rule = group[ r ];
				if ( ! rule || typeof rule !== 'object' ) {
					continue;
				}
				var name = rule.field || '';
				var op = typeof rule.operator === 'string' && rule.operator !== '' ? rule.operator : '==';
				var want = typeof rule.value === 'undefined' ? '' : rule.value;
				var actual = Object.prototype.hasOwnProperty.call( values, name ) ? values[ name ] : '';
				var ok;

				switch ( op ) {
					case '!=':
						ok = ! valuesEqual( actual, want );
						break;
					case 'contains':
						ok = contains( actual, want );
						break;
					case 'empty':
						ok = phpEmpty( actual );
						break;
					case '!empty':
						ok = ! phpEmpty( actual );
						break;
					case '>':
						ok = numericCompare( actual, want, '>' );
						break;
					case '<':
						ok = numericCompare( actual, want, '<' );
						break;
					case 'all_set':
						ok = allSet( actual );
						break;
					case 'range':
						ok = inRange( actual, want );
						break;
					default:
						// '==' and any unknown operator, matching the PHP default branch.
						ok = valuesEqual( actual, want );
						break;
				}

				if ( ! ok ) {
					matches = false;
					break;
				}
			}
			if ( matches ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Current value of one field row, as the server would see it in $_POST.
	 * Multi-value inputs collapse to an array; a single checkbox to '1'/'' so it compares against
	 * the stored value the same way the PHP side does.
	 *
	 * @param {jQuery} $row Field row.
	 * @return {*}
	 */
	function readValue( $row ) {
		// Only named inputs reach $_POST. The design-system controls add unnamed helper inputs
		// (Radix mirrors each checkbox / switch / radio / select into one) that must not count.
		var $inputs = $row.find( 'input[name], select[name], textarea[name]' ).not( '[type="hidden"][disabled]' );
		if ( ! $inputs.length ) {
			return '';
		}

		// The "toggle all" box is a UI control with no name and no value of its own. It was counted
		// as a value input, so a checkbox field rendered with it had one extra checkable element —
		// which both skewed the single-checkbox test below and pushed jQuery's default 'on' into
		// the collected values when it was ticked.
		var $checkable = $inputs.filter( '[type="checkbox"], [type="radio"]' ).not( '.uich-gf-toggle-all' );
		if ( $checkable.length ) {
			var checked = $checkable.filter( ':checked' );
			if ( $checkable.filter( '[type="radio"]' ).length === $checkable.length ) {
				return checked.length ? String( checked.val() ) : '';
			}

			// A single checkbox is a true_false only when its name is NOT an array — a `checkbox`
			// field posts as name="…[]" and stores an array even when it has exactly one choice.
			var firstName = String( $checkable.first().attr( 'name' ) || '' );
			var isArrayName = firstName.slice( -2 ) === '[]';

			if ( $checkable.length === 1 && ! isArrayName ) {
				// '0', not ''. The server stores '0' for an unchecked true_false — that is the whole
				// job of the hidden companion input the renderer emits beside the box — so reporting
				// '' here made the browser and PHP disagree about the same field. With an empty rule
				// value the browser matched ( '' === '' ) while the server did not, so the dependent
				// field appeared while the box was UNCHECKED and vanished when it was ticked: the
				// inversion, and not something either evaluator could be blamed for alone.
				//
				// This keeps `empty` working: PHP's empty('0') is true, and phpEmpty() above treats
				// '0' as empty for exactly this reason.
				return checked.length ? '1' : '0';
			}

			var many = [];
			checked.each( function () { many.push( String( $( this ).val() ) ); } );
			return many;
		}

		// A wysiwyg field is a TinyMCE iframe over a textarea; read the live editor when it exists.
		var $ta = $inputs.filter( 'textarea' ).first();
		if ( $ta.length && window.tinymce ) {
			var ed = window.tinymce.get( $ta.attr( 'id' ) );
			if ( ed && ! ed.isHidden() ) {
				return ed.getContent();
			}
		}

		var $multi = $inputs.filter( 'select[multiple]' ).first();
		if ( $multi.length ) {
			return $multi.val() || [];
		}

		var v = $inputs.first().val();
		return typeof v === 'undefined' || v === null ? '' : v;
	}

	/** Every field value in one group, keyed by field name — the $values map PHP receives. */
	function collectValues( $scope ) {
		var values = {};
		$scope.find( ROW + '[data-uich-field]' ).each( function () {
			var $row = $( this );
			// Repeater sub-rows carry their own scope; skip rows nested in a deeper group.
			values[ $row.attr( 'data-uich-field' ) ] = readValue( $row );
		} );
		return values;
	}

	function apply( $scope ) {
		var values = collectValues( $scope );
		$scope.find( ROW + '[data-uich-cond]' ).each( function () {
			var $row = $( this );
			var cl;
			try {
				cl = JSON.parse( $row.attr( 'data-uich-cond' ) || 'null' );
			} catch ( e ) {
				return; // Malformed rule: leave the field visible rather than hiding it wrongly.
			}
			var visible = evaluate( cl, values );
			$row.toggleClass( 'uich-gf-cond-hidden', ! visible );
			// aria-hidden alone would leave the control tabbable; the class sets display:none, and
			// disabling is deliberately NOT done — a disabled input is omitted from $_POST, which
			// would wipe a stored value the moment an unrelated field changed.
			$row.attr( 'aria-hidden', visible ? null : 'true' );
		} );
	}

	function scopes() {
		var $s = $( '.uich-gf-metabox' );
		return $s.length ? $s : $( 'form' ).filter( function () {
			return $( this ).find( ROW + '[data-uich-cond]' ).length > 0;
		} );
	}

	function init() {
		var $all = scopes();
		if ( ! $all.length ) {
			return;
		}
		$all.each( function () {
			var $scope = $( this );
			apply( $scope );
			$scope.on( 'change input', 'input, select, textarea', function () {
				apply( $scope );
			} );
		} );
		// TinyMCE changes never surface as a jQuery input event on the textarea.
		if ( window.tinymce && window.tinymce.on ) {
			window.tinymce.on( 'AddEditor', function ( e ) {
				e.editor.on( 'change keyup SetContent', function () {
					scopes().each( function () { apply( $( this ) ); } );
				} );
			} );
		}
		// Repeater rows are added after load; re-evaluate when the DOM under a scope changes.
		if ( window.MutationObserver ) {
			$all.each( function () {
				var el = this;
				var pending = null;
				new window.MutationObserver( function () {
					window.clearTimeout( pending );
					pending = window.setTimeout( function () { apply( $( el ) ); }, 50 );
				} ).observe( el, { childList: true, subtree: true } );
			} );
		}
	}

	$( init );
} )( window.jQuery );
