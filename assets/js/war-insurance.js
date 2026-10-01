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
		 * Write the chosen id, or clear it with `commit( null )`.
		 *
		 * THE ONLY PLACE `hidden.value` IS ASSIGNED. Not a style rule: when the
		 * clear lived in the input handler and the set lived here, the submitted
		 * value and the displayed label were owned by two different pieces of code
		 * and drifted — the form submitted one location while showing another.
		 *
		 * Both directions invalidate any pending attempt, because both mean the
		 * control no longer holds what the visitor last confirmed.
		 */
		function commit( row ) {
			hidden.value = row ? String( row.id ) : '';

			if ( row ) {
				input.value = String( row.name );
			}

			state.forget();
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
			// The typed text no longer matches the committed id. Cleared THROUGH
			// commit(), so that function remains the only writer.
			commit( null );

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
				commit( null );
				input.value = '';
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

		/**
		 * Which submission the in-flight handlers belong to.
		 *
		 * Bumped whenever an attempt is abandoned, so the answer to a payload the
		 * visitor has already moved away from cannot land as though it described
		 * the form as it now stands.
		 */
		var attempt = 0;

		var state = {
			config: config,

			/**
			 * This control changed; any pending attempt is now invalid.
			 *
			 * MINTING A NEW submissionId HERE IS THE WHOLE POINT. Clearing
			 * `pending` on its own re-opened submission while the first POST was
			 * still in flight, and the second one then carried the SAME
			 * submissionId — which is exactly the replay startAttempt() exists to
			 * prevent: the backend answers 200 for the record it already holds,
			 * and the form reports success for an edited payload it never
			 * received.
			 *
			 * The in-flight request is deliberately NOT aborted. It may already
			 * have been received and stored, so correctness cannot rest on
			 * cancelling it; `attempt` is what makes its answer inert.
			 */
			forget: function () {
				if ( ! pending ) {
					return;
				}

				attempt += 1;
				mintSubmissionId();
				pending = false;

				text( result, '' );
				applyLookupState();
			},

			lookup: function ( kind, term ) {
				return window
					.fetch( config.root + '/' + kind + '-search?q=' + encodeURIComponent( term ), {
						headers: { Accept: 'application/json' },
						credentials: 'same-origin',
					} )
					.then( function ( response ) {
						/*
						 * THE STATUS MATTERS HERE, not just the body. An upstream
						 * miss comes back 200 with `unavailable: true` and is
						 * handled below -- but OUR OWN proxy refusing (its per-IP
						 * limiter returning 429, a binding failure) is a real
						 * error status carrying no `unavailable` key. Reading
						 * only the body turns those into a successful lookup of
						 * zero rows, which leaves submit enabled and tells the
						 * visitor "No matches found" for a vessel that exists.
						 */
						return response.json().then(
							function ( body ) {
								return { ok: response.ok, body: body };
							},
							function () {
								return { ok: false, body: null };
							}
						);
					} )
					.then( function ( answer ) {
						if ( ! answer.ok || ( answer.body && answer.body.unavailable ) ) {
							reportLookup( kind, false );
							throw new Error( 'unavailable' );
						}

						reportLookup( kind, true );

						return ( answer.body && answer.body.results ) || [];
					} );
			},
		};

		/**
		 * Seconds to wait, from the throttled response.
		 *
		 * The body is authoritative because the proxy always sets it; the header
		 * is the fallback for a refusal generated above our own handler. The
		 * floor of 1 keeps "wait 0 seconds" off the screen.
		 */
		function retryAfter( answer ) {
			var fromBody = answer.body && answer.body.retry;
			var seconds = parseInt( fromBody || answer.retryHeader || '', 10 );

			return isNaN( seconds ) || seconds < 1 ? 60 : seconds;
		}

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
		 * Retire the current submissionId and mint a fresh one.
		 *
		 * THE ONLY WRITER, and a contract test counts the assignment sites. An id
		 * is retired for exactly two reasons — the backend confirmed the attempt
		 * (startAttempt), or the visitor changed a control while it was still in
		 * flight (forget) — and both must produce a NEW one. Reusing one makes the
		 * backend treat the next submit as an idempotent replay of the first and
		 * answer 200, which the form would report as a fresh success for a request
		 * that was never recorded.
		 */
		function mintSubmissionId() {
			submissionId = uuid();
		}

		/**
		 * The backend confirmed this attempt.
		 */
		function startAttempt() {
			mintSubmissionId();
			pending = false;
			form.reset();

			clearers.forEach( function ( reset ) {
				reset();
			} );
		}

		/**
		 * The ordered destination multi-select.
		 *
		 * Chips carry their own labelled remove button and every add/remove is
		 * announced, because a silent list change is invisible to a screen reader.
		 * ORDER IS MEANINGFUL — the backend stores position — so removal splices
		 * rather than rebuilding from a set.
		 */
		function destinations( wrap ) {
			var input = wrap.querySelector( 'input[role="combobox"]' );
			var list = wrap.querySelector( '[role="listbox"]' );
			var chips = wrap.querySelector( '[data-sswi-chips]' );
			var live = wrap.querySelector( '[data-sswi-chip-live]' );
			var listId = list.id;

			var chosen = [];
			var rows = [];
			var active = -1;
			var timer = null;

			function announce( message ) {
				if ( live ) {
					text( live, message );
				}
			}

			function paint() {
				chips.innerHTML = '';

				chosen.forEach( function ( row, index ) {
					var li = document.createElement( 'li' );
					var label = document.createElement( 'span' );
					var button = document.createElement( 'button' );

					label.textContent = row.name;

					button.type = 'button';
					button.textContent = '\u00D7';
					button.setAttribute(
						'aria-label',
						String( i18n.removeChip || 'Remove %s' ).replace( '%s', row.name )
					);

					button.addEventListener( 'click', function () {
						chosen.splice( index, 1 );
						state.forget();
						paint();
						announce(
							String( i18n.removeChip || 'Remove %s' ).replace( '%s', row.name )
						);
						input.focus();
					} );

					li.appendChild( label );
					li.appendChild( button );
					chips.appendChild( li );
				} );
			}

			function close() {
				hide( list );
				input.setAttribute( 'aria-expanded', 'false' );
				input.removeAttribute( 'aria-activedescendant' );
				active = -1;
			}

			function add( row ) {
				var already = chosen.some( function ( item ) {
					return item.id === row.id;
				} );

				if ( already || chosen.length >= ( config.maxDestinations || 50 ) ) {
					return;
				}

				chosen.push( row );
				state.forget();
				paint();
				announce( row.name );
				input.value = '';
				close();
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

				// Same single derivation as every other listbox. See optionId().
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
						add( row );
					} );

					list.appendChild( li );
				} );

				if ( rows.length ) {
					show( list );
					input.setAttribute( 'aria-expanded', 'true' );
				} else {
					close();
				}
			}

			input.addEventListener( 'input', function () {
				var term = input.value.trim();

				window.clearTimeout( timer );

				if ( term.length < ( config.minQueryLength || 2 ) ) {
					rows = [];
					close();
					return;
				}

				timer = window.setTimeout( function () {
					state.lookup( 'location', term ).then(
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
					add( rows[ active ] );
				} else if ( 'Escape' === event.key ) {
					close();
				}
			} );

			return {
				ids: function () {
					return chosen.map( function ( row ) {
						return row.id;
					} );
				},
				reset: function () {
					chosen = [];
					rows = [];
					input.value = '';
					paint();
					close();
				},
			};
		}

		/**
		 * Populate the cargo risk-zone select from the served rate table.
		 *
		 * The zone list is the backend's, not ours — hardcoding it here would be a
		 * second copy of a commercially-owned list, which is exactly the drift the
		 * rates endpoint exists to end.
		 */
		function populateRiskZones() {
			var select = root.querySelector( '[data-sswi-risk-zone]' );

			if ( ! select ) {
				return;
			}

			window
				.fetch( config.root + '/rates', {
					headers: { Accept: 'application/json' },
					credentials: 'same-origin',
				} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( body ) {
					/*
					 * `rates.cargo` IS AN OBJECT, NOT A ROW LIST.
					 *
					 * The endpoint returns
					 *   cargo: { columns, zones, disclaimers, footnotes }
					 * with each row keyed `name`. This read `rates.cargo` as the
					 * rows and `row.zone` as the label, so against the real
					 * backend `.forEach` threw — objects have no forEach — the
					 * promise rejected into the catch below, and the select stayed
					 * empty.
					 *
					 * The select is REQUIRED, so an empty one is not a degraded
					 * cargo form, it is a cargo form nobody can submit. The catch
					 * is what made it silent: a genuine outage and a shape error
					 * were indistinguishable.
					 */
					var cargo = ( body && body.rates && body.rates.cargo ) || {};
					var zones = Array.isArray( cargo.zones ) ? cargo.zones : [];

					zones.forEach( function ( row ) {
						if ( ! row || ! row.name ) {
							return;
						}

						var option = document.createElement( 'option' );
						option.value = row.name;
						option.textContent = row.name;
						select.appendChild( option );
					} );
				} )
				.catch( function () {
					/* The select stays at its placeholder; submission fails validation
					   upstream rather than sending a zone we invented. */
				} );
		}

		var destinationsField = null;

		root.querySelectorAll( '[data-sswi-destinations]' ).forEach( function ( wrap ) {
			destinationsField = destinations( wrap );
			clearers.push( destinationsField.reset );
		} );

		populateRiskZones();

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
			attempt += 1;

			// Which attempt the handlers below belong to. forget() bumps this
			// when a control changes mid-flight, which is how their answer is
			// discarded rather than applied to a form that has moved on.
			var mine = attempt;

			applyLookupState();
			text( result, String( i18n.sending || '' ) );

			var payload = {};

			new window.FormData( form ).forEach( function ( value, key ) {
				payload[ key ] = value;
			} );

			payload.dataConsent = form.querySelector( '[data-sswi-consent]' ).checked;

			if ( destinationsField ) {
				// Ordered: the backend stores position, so this is not a set.
				payload.destinations = destinationsField.ids();
			}

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
					var retryHeader = response.headers && response.headers.get( 'Retry-After' );

					return response.json().then(
						function ( body ) {
							return { status: response.status, body: body, retryHeader: retryHeader };
						},
						function () {
							return { status: response.status, body: null, retryHeader: retryHeader };
						}
					);
				} )
				.then( function ( answer ) {
					// Abandoned while in flight: a control changed, a new
					// submissionId has already been minted, and this answer
					// describes a payload the form no longer holds.
					if ( mine !== attempt ) {
						return;
					}

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
					} else if ( 429 === answer.status ) {
						/*
						 * The proxy computed a wait and sent it, in the body as
						 * `retry` and in the Retry-After header. Falling through
						 * to the generic "temporarily unavailable" throws that
						 * away and leaves the visitor with no idea whether to
						 * wait seconds or give up.
						 */
						text(
							result,
							String( i18n.throttled || 'Too many requests. Please wait %d seconds and try again.' )
								.replace( '%d', String( retryAfter( answer ) ) )
						);
					} else {
						text( result, String( answer.body && answer.body.message ? answer.body.message : i18n.unavailable ) );
					}

					applyLookupState();
				} )
				.catch( function () {
					if ( mine !== attempt ) {
						return;
					}

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
