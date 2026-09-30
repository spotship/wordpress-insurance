/**
 * Spot Ship war insurance forms.
 *
 * Vanilla, no build step. The form ships INERT and this script enables it, so a
 * failure here leaves a visibly disabled form and an explanation rather than a
 * form that looks fine and silently is not.
 *
 * Two invariants in here exist because breaking them produced production bugs:
 *
 *   1. AN OPTION'S DOM ID IS DERIVED IN EXACTLY ONE PLACE (`optionId`). When the
 *      rendering path and the `aria-activedescendant` path each computed it,
 *      they drifted, and the form submitted one location while displaying
 *      another.
 *
 *   2. THE ATTEMPT HAS ONE OWNER. `forget()` means "this control changed, the
 *      pending attempt is invalid"; `startAttempt()` means "the backend
 *      confirmed this attempt" and is the ONLY place a submissionId is minted.
 *      Reusing one across submissions reported a false success on the second.
 */
( function () {
	'use strict';

	/** @type {Record<string, unknown>} */
	var i18n = {};

	function uuid() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}

		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
			var r = ( Math.random() * 16 ) | 0;
			var v = 'x' === c ? r : ( r & 0x3 ) | 0x8;
			return v.toString( 16 );
		} );
	}

	/**
	 * The DOM id of an option. THE ONLY DEFINITION.
	 *
	 * Both the markup and aria-activedescendant read this, so they cannot
	 * disagree about which option is current.
	 */
	function optionId( listId, index ) {
		return listId + '-opt-' + index;
	}

	function text( node, value ) {
		node.textContent = value;
	}

	function show( node ) {
		node.hidden = false;
	}

	function hide( node ) {
		node.hidden = true;
	}

	function fieldError( root, name, message ) {
		var field = root.querySelector( '[data-sswi-field="' + name + '"]' );

		if ( ! field ) {
			return false;
		}

		var slot = field.querySelector( '[data-sswi-error]' );
		var input = field.querySelector( 'input, select, textarea' );

		if ( ! slot ) {
			return false;
		}

		text( slot, message );
		show( slot );

		if ( input ) {
			input.setAttribute( 'aria-invalid', 'true' );
		}

		return true;
	}

	function clearErrors( root ) {
		root.querySelectorAll( '[data-sswi-error]' ).forEach( function ( slot ) {
			text( slot, '' );
			hide( slot );
		} );

		root.querySelectorAll( '[aria-invalid]' ).forEach( function ( input ) {
			input.removeAttribute( 'aria-invalid' );
		} );
	}

	/**
	 * One combobox.
	 */
	function combobox( root, wrap, state ) {
		var input = wrap.querySelector( 'input[role="combobox"]' );
		var list = wrap.querySelector( '[role="listbox"]' );
		var hidden = wrap.querySelector( '[data-sswi-value]' );
		var live = wrap.querySelector( '[role="status"]' );
		var kind = wrap.getAttribute( 'data-sswi-combo' );
		var listId = list.id;

		var rows = [];
		var active = -1;
		var timer = null;

		/**
		 * Write the chosen id. THE ONLY PLACE `hidden.value` is assigned, so the
		 * submitted value and the displayed label cannot disagree.
		 */
		function commit( row ) {
			hidden.value = row ? String( row.id ) : '';
			input.value = row ? String( row.name ) : input.value;

			if ( row ) {
				state.forget();
			}
		}

		function close() {
			hide( list );
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			active = -1;
		}

		function highlight( index ) {
			active = index;

			list.querySelectorAll( '[role="option"]' ).forEach( function ( node, i ) {
				node.setAttribute( 'aria-selected', i === index ? 'true' : 'false' );
			} );

			if ( index < 0 ) {
				input.removeAttribute( 'aria-activedescendant' );
				return;
			}

			// Same derivation the markup used. See optionId().
			input.setAttribute( 'aria-activedescendant', optionId( listId, index ) );
		}

		function render() {
			list.innerHTML = '';

			rows.forEach( function ( row, index ) {
				var li = document.createElement( 'li' );

				li.setAttribute( 'role', 'option' );
				li.id = optionId( listId, index );
				li.setAttribute( 'aria-selected', 'false' );
				li.textContent = row.name + ( row.locode ? ' (' + row.locode + ')' : '' );

				li.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
					commit( row );
					close();
				} );

				list.appendChild( li );
			} );

			if ( rows.length ) {
				show( list );
				input.setAttribute( 'aria-expanded', 'true' );
			} else {
				close();
			}

			if ( live ) {
				text( live, String( i18n.resultCount || '%d results available.' ).replace( '%d', String( rows.length ) ) );
			}
		}

		function search( term ) {
			state.lookup( kind, term ).then(
				function ( results ) {
					rows = results;
					render();
					highlight( -1 );
				},
				function () {
					rows = [];
					close();
				}
			);
		}

		input.addEventListener( 'input', function () {
			// The typed text no longer matches the committed id.
			hidden.value = '';
			state.forget();

			var term = input.value.trim();

			window.clearTimeout( timer );

			if ( term.length < ( state.config.minQueryLength || 2 ) ) {
				rows = [];
				close();
				return;
			}

			timer = window.setTimeout( function () {
				search( term );
			}, 200 );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( list.hidden ) {
				return;
			}

			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				highlight( Math.min( active + 1, rows.length - 1 ) );
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				highlight( Math.max( active - 1, 0 ) );
			} else if ( 'Enter' === event.key && active >= 0 ) {
				event.preventDefault();
				commit( rows[ active ] );
				close();
			} else if ( 'Escape' === event.key ) {
				close();
			}
		} );

		input.addEventListener( 'blur', function () {
			window.setTimeout( close, 150 );
		} );

		return {
			reset: function () {
				input.value = '';
				hidden.value = '';
				rows = [];
				close();
			},
		};
	}

	function init( root ) {
		var config;

		try {
			config = JSON.parse( root.getAttribute( 'data-sswi-config' ) || '{}' );
		} catch ( e ) {
			return;
		}

		i18n = config.i18n || {};

		var form = root.querySelector( '[data-sswi-form]' );
		var notice = root.querySelector( '[data-sswi-noscript]' );
		var result = root.querySelector( '[data-sswi-result]' );
		var summary = root.querySelector( '[data-sswi-summary]' );

		if ( ! form ) {
			return;
		}

		/**
		 * Lookups that are currently broken. A LIST, not a boolean: two failing
		 * comboboxes that recover one at a time must not re-enable submission
		 * while the other is still broken.
		 */
		var brokenLookups = [];
		var clearers = [];
		var submissionId = uuid();
		var pending = false;

		var state = {
			config: config,

			/** This control changed; any pending attempt is now invalid. */
			forget: function () {
				pending = false;
			},

			lookup: function ( kind, term ) {
				return window
					.fetch( config.root + '/' + kind + '-search?q=' + encodeURIComponent( term ), {
						headers: { Accept: 'application/json' },
						credentials: 'same-origin',
					} )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( body ) {
						if ( body && body.unavailable ) {
							reportLookup( kind, false );
							throw new Error( 'unavailable' );
						}

						reportLookup( kind, true );

						return ( body && body.results ) || [];
					} );
			},
		};

		function reportLookup( kind, ok ) {
			var at = brokenLookups.indexOf( kind );

			if ( ok && at >= 0 ) {
				brokenLookups.splice( at, 1 );
			} else if ( ! ok && at < 0 ) {
				brokenLookups.push( kind );
			}

			applyLookupState();
		}

		function applyLookupState() {
			var broken = brokenLookups.length > 0;
			var button = form.querySelector( '.sswi-submit' );

			if ( button ) {
				button.disabled = broken || pending;
			}

			if ( broken ) {
				text( result, String( i18n.lookupFailed || '' ) );
			}
		}

		/**
		 * The backend confirmed this attempt.
		 *
		 * The ONLY place a submissionId is minted after the first. Reusing one
		 * across submissions makes the backend treat the second as an idempotent
		 * replay of the first and answer 200, which the form would report as a
		 * fresh success for a request that was never recorded.
		 */
		function startAttempt() {
			submissionId = uuid();
			pending = false;
			form.reset();

			clearers.forEach( function ( reset ) {
				reset();
			} );
		}

		root.querySelectorAll( '[data-sswi-combo]' ).forEach( function ( wrap ) {
			clearers.push( combobox( root, wrap, state ).reset );
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( pending || brokenLookups.length ) {
				return;
			}

			clearErrors( root );
			hide( summary );

			pending = true;
			applyLookupState();
			text( result, String( i18n.sending || '' ) );

			var payload = {};

			new window.FormData( form ).forEach( function ( value, key ) {
				payload[ key ] = value;
			} );

			payload.dataConsent = form.querySelector( '#sswi-consent' ).checked;
			payload.submissionId = submissionId;

			var endpoint = 'cargo' === config.product ? '/cargo-quote-request' : '/quote-request';

			window
				.fetch( config.root + endpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify( payload ),
				} )
				.then( function ( response ) {
					return response.json().then( function ( body ) {
						return { status: response.status, body: body };
					} );
				} )
				.then( function ( answer ) {
					pending = false;

					if ( answer.body && true === answer.body.success ) {
						text( result, String( answer.body.message || '' ) );
						startAttempt();
						applyLookupState();
						return;
					}

					var landed = 0;

					if ( answer.body && answer.body.fields ) {
						Object.keys( answer.body.fields ).forEach( function ( name ) {
							var messages = answer.body.fields[ name ];

							if ( fieldError( root, name, messages[ 0 ] ) ) {
								landed += 1;
							}
						} );
					}

					if ( landed > 0 ) {
						text( summary, String( i18n.errorSummary || '' ) );
						show( summary );
						summary.focus();
						text( result, '' );
					} else {
						text( result, String( answer.body && answer.body.message ? answer.body.message : i18n.unavailable ) );
					}

					applyLookupState();
				} )
				.catch( function () {
					pending = false;
					text( result, String( i18n.unavailable || '' ) );
					applyLookupState();
				} );
		} );

		// The inert -> live transition. Nothing above this line enables anything.
		form.querySelectorAll( '[disabled]' ).forEach( function ( node ) {
			node.disabled = false;
		} );

		if ( notice ) {
			notice.remove();
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			document.querySelectorAll( '[data-sswi-root]' ).forEach( init );
		} );
	} else {
		document.querySelectorAll( '[data-sswi-root]' ).forEach( init );
	}
} )();
