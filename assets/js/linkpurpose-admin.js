/**
 * Conditional-visibility toggles for the Link Purpose settings form.
 *
 * Each `<tr data-linkpurpose-when='[{"input":"name","value":"v"}, …]'>`
 * is shown only while EVERY listed input currently has the listed value
 * (checkbox: '1' when checked, '' otherwise). The server never relies on
 * this; hidden rows still post and are sanitized normally.
 */
( function () {
	'use strict';

	var ATTRIBUTE = 'data-linkpurpose-when';

	function currentValue( inputs ) {
		var first = inputs[ 0 ];
		if ( 'radio' === first.type ) {
			for ( var i = 0; i < inputs.length; i++ ) {
				if ( inputs[ i ].checked ) {
					return inputs[ i ].value;
				}
			}
			return '';
		}
		if ( 'checkbox' === first.type ) {
			return first.checked ? first.value : '';
		}
		return first.value;
	}

	function inputsNamed( name ) {
		return document.querySelectorAll( '[name="' + name.replace( /"/g, '\\"' ) + '"]' );
	}

	var rules = [];
	document.querySelectorAll( '[' + ATTRIBUTE + ']' ).forEach( function ( target ) {
		var conditions;
		try {
			conditions = JSON.parse( target.getAttribute( ATTRIBUTE ) );
		} catch ( _err ) {
			return;
		}
		if ( ! Array.isArray( conditions ) || ! conditions.length ) {
			return;
		}
		var resolved = [];
		conditions.forEach( function ( condition ) {
			var inputs = inputsNamed( String( condition.input ) );
			if ( inputs.length ) {
				resolved.push( { inputs: inputs, value: String( condition.value ) } );
			}
		} );
		if ( resolved.length ) {
			rules.push( { target: target, conditions: resolved } );
		}
	} );
	if ( ! rules.length ) {
		return;
	}

	function sync() {
		rules.forEach( function ( rule ) {
			var visible = rule.conditions.every( function ( condition ) {
				return currentValue( condition.inputs ) === condition.value;
			} );
			rule.target.hidden = ! visible;
		} );
	}

	rules.forEach( function ( rule ) {
		rule.conditions.forEach( function ( condition ) {
			condition.inputs.forEach( function ( input ) {
				input.addEventListener( 'change', sync );
			} );
		} );
	} );
	sync();
} )();
