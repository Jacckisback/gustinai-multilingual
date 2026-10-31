/**
 * GustinAI Multilingual admin: AI translation buttons, queue progress,
 * bulk translation, model list and connection test. No dependencies.
 */
( function () {
	'use strict';

	var cfg = window.gaimlAdmin || {};
	var t = cfg.i18n || {};
	var running = false;
	var stopped = false;

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', 'gaiml_' + action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			var value = data[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( v ) {
					body.append( key + '[]', v );
				} );
			} else if ( value !== undefined && value !== null ) {
				body.append( key, value );
			}
		} );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json().catch( function () {
					return { success: false, data: { message: t.error + ' ' + response.status } };
				} );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || t.error );
				}
				return json.data;
			} );
	}

	function format( template, n ) {
		return String( template || '' ).replace( '%d', n );
	}

	function setText( el, text ) {
		if ( el ) {
			el.textContent = text;
		}
	}

	/**
	 * Processes the queue until nothing is left (jobs of one object first).
	 */
	function run( scope, onUpdate, onDone ) {
		if ( running ) {
			return;
		}
		running = true;
		stopped = false;
		var errors = 0;
		var step = function () {
			if ( stopped ) {
				running = false;
				onDone( null, true );
				return;
			}
			post( 'process', scope )
				.then( function ( data ) {
					errors = 0;
					onUpdate( data );
					var left = data.stats.pending + data.stats.running;
					var mine = scope && scope.id ? Object.keys( data.object || {} ).filter( function ( lang ) {
						return 'failed' !== data.object[ lang ].status;
					} ).length : left;
					if ( mine > 0 && ( data.results.length > 0 || left > 0 ) ) {
						window.setTimeout( step, data.results.length ? 300 : 4000 );
					} else {
						running = false;
						onDone( data, false );
					}
				} )
				.catch( function ( error ) {
					errors++;
					if ( errors < 4 ) {
						window.setTimeout( step, 3000 * errors );
					} else {
						running = false;
						onDone( null, false, error );
					}
				} );
		};
		step();
	}

	/* Translate buttons (editor box, lists, terms). */
	function boxOf( el ) {
		return el.closest( '[data-gaiml-object]' );
	}

	function progressOf( box ) {
		var scope = box.closest( '.gaiml-box' ) || box.closest( 'td' ) || box.parentNode;
		return ( scope && scope.querySelector( '.gaiml-progress' ) ) || box.querySelector( '.gaiml-progress' );
	}

	function startObject( box ) {
		var progress = progressOf( box );
		var scope = { type: box.getAttribute( 'data-gaiml-object' ), id: box.getAttribute( 'data-gaiml-id' ) };
		setText( progress, t.working );
		run(
			scope,
			function ( data ) {
				var langs = Object.keys( data.object || {} );
				var failed = langs.filter( function ( lang ) {
					return 'failed' === data.object[ lang ].status;
				} );
				data.results.forEach( function ( row ) {
					if ( 'pending' === row.status && row.message ) {
						setText( progress, t.working + ' ' + row.language + ' — ' + row.message );
					}
				} );
				if ( failed.length ) {
					setText( progress, t.failed + ': ' + data.object[ failed[ 0 ] ].message );
				}
			},
			function ( data, wasStopped, error ) {
				if ( error ) {
					setText( progress, t.error + ': ' + error.message );
					return;
				}
				setText( progress, t.done );
				window.setTimeout( function () {
					window.location.reload();
				}, 600 );
			}
		);
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.gaiml-translate' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		if ( '1' === button.getAttribute( 'data-confirm' ) && ! window.confirm( t.confirmEdit ) ) {
			return;
		}
		var box = boxOf( button );
		if ( ! box ) {
			return;
		}
		var progress = progressOf( box );
		button.disabled = true;
		setText( progress, t.queued );
		post( 'translate', {
			type: box.getAttribute( 'data-gaiml-object' ),
			id: box.getAttribute( 'data-gaiml-id' ),
			langs: ( button.getAttribute( 'data-langs' ) || '' ).split( ',' ),
			force: button.getAttribute( 'data-force' ) || '0',
		} )
			.then( function () {
				startObject( box );
			} )
			.catch( function ( error ) {
				button.disabled = false;
				setText( progress, t.error + ': ' + error.message );
			} );
	} );

	/* Resume pending jobs when a screen opens with work in progress. */
	document.querySelectorAll( '.gaiml-progress[data-autorun]' ).forEach( function ( progress ) {
		var box = progress.closest( '.gaiml-box' );
		if ( box && box.matches( '[data-gaiml-object]' ) ) {
			startObject( box );
			return;
		}
		setText( progress, t.working );
		run(
			null,
			function ( data ) {
				setText( progress, format( t.remaining, data.stats.pending + data.stats.running ) );
			},
			function () {
				setText( progress, t.finished );
			}
		);
	} );

	/* Settings: only the rows of the selected provider are shown. */
	var providerRadios = document.querySelectorAll( 'input[name="provider"]' );
	var showProvider = function () {
		var checked = document.querySelector( 'input[name="provider"]:checked' );
		document.querySelectorAll( 'tr.gaiml-provider' ).forEach( function ( row ) {
			row.hidden = !! checked && row.getAttribute( 'data-provider' ) !== checked.value;
		} );
	};
	providerRadios.forEach( function ( radio ) {
		radio.addEventListener( 'change', showProvider );
	} );
	showProvider();

	/* Settings: model selector, model list, connection test. */
	document.querySelectorAll( '.gaiml-model-select' ).forEach( function ( select ) {
		var custom = select.parentNode.querySelector( '.gaiml-model-custom' );
		var toggle = function () {
			if ( custom ) {
				custom.hidden = '__custom' !== select.value;
			}
		};
		select.addEventListener( 'change', toggle );
		toggle();
	} );

	document.querySelectorAll( '.gaiml-load-models' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var provider = button.getAttribute( 'data-provider' );
			var row = button.closest( 'tr' );
			var keyInput = document.querySelector( 'input[name="key_' + provider + '"]' );
			var select = row.querySelector( '.gaiml-model-select' );
			button.disabled = true;
			button.textContent = t.loading;
			post( 'models', { provider: provider, key: keyInput ? keyInput.value : '' } )
				.then( function ( data ) {
					var current = select.value;
					var group = document.createElement( 'optgroup' );
					group.label = button.getAttribute( 'data-label' ) || provider;
					data.models.forEach( function ( model ) {
						if ( select.querySelector( 'option[value="' + CSS.escape( model.id ) + '"]' ) ) {
							return;
						}
						var option = document.createElement( 'option' );
						option.value = model.id;
						option.textContent = model.label + ' (' + model.id + ')';
						group.appendChild( option );
					} );
					if ( group.children.length ) {
						select.insertBefore( group, select.querySelector( 'option[value="__custom"]' ) );
					}
					select.value = current;
					button.textContent = '✓';
				} )
				.catch( function ( error ) {
					button.disabled = false;
					button.textContent = t.error + ': ' + error.message;
				} );
		} );
	} );

	var test = document.querySelector( '.gaiml-test' );
	if ( test ) {
		test.addEventListener( 'click', function () {
			var result = document.querySelector( '.gaiml-test-result' );
			test.disabled = true;
			setText( result, t.loading );
			post( 'test', {} )
				.then( function ( data ) {
					setText( result, '✓ ' + data.model + ' (' + data.seconds + ' s) : « ' + data.translation + ' »' );
				} )
				.catch( function ( error ) {
					setText( result, '✗ ' + error.message );
				} )
				.finally( function () {
					test.disabled = false;
				} );
		} );
	}

	/* Bulk translation. */
	var bulk = document.querySelector( '.gaiml-bulk-form' );
	if ( bulk ) {
		var values = function ( name ) {
			return Array.prototype.map.call( bulk.querySelectorAll( 'input[name="' + name + '[]"]:checked' ), function ( input ) {
				return input.value;
			} );
		};
		var params = function () {
			return {
				langs: values( 'langs' ),
				types: values( 'types' ),
				outdated: bulk.querySelector( 'input[name="outdated"]' ).checked ? '1' : '',
				strings: bulk.querySelector( 'input[name="strings"]' ).checked ? '1' : '',
			};
		};
		var estimate = bulk.querySelector( '.gaiml-estimate' );
		var start = bulk.querySelector( '.gaiml-bulk-start' );
		var stop = bulk.querySelector( '.gaiml-bulk-stop' );
		var bar = bulk.querySelector( '.gaiml-bar' );
		var fill = bulk.querySelector( '.gaiml-bar__fill' );
		var progress = bulk.querySelector( '.gaiml-progress' );
		var log = bulk.querySelector( '.gaiml-log' );
		var estimateResult = bulk.querySelector( '.gaiml-estimate-result' );

		estimate.addEventListener( 'click', function () {
			setText( estimateResult, t.loading );
			post( 'estimate', params() )
				.then( function ( data ) {
					setText(
						estimateResult,
						data.contents + ' contents, ' + data.jobs + ' translations, ~' + Math.round( data.chars / 1000 ) + 'k characters' + ( null !== data.cost ? ' ≈ $' + data.cost + ' (' + data.model + ')' : '' )
					);
				} )
				.catch( function ( error ) {
					setText( estimateResult, t.error + ': ' + error.message );
				} );
		} );

		var watch = function () {
			var total = 0;
			bar.hidden = false;
			stop.hidden = false;
			start.disabled = true;
			run(
				null,
				function ( data ) {
					var left = data.stats.pending + data.stats.running;
					total = Math.max( total, left + data.results.length );
					fill.style.width = ( total ? Math.round( ( 100 * ( total - left ) ) / total ) : 100 ) + '%';
					setText( progress, format( t.remaining, left ) );
					data.results.forEach( function ( row ) {
						if ( 'pending' === row.status ) {
							return;
						}
						var li = document.createElement( 'li' );
						li.textContent = row.language + ' — #' + row.id + ' — ' + ( 'done' === row.status ? t.done : t.failed ) + ( row.message ? ' (' + row.message + ')' : '' );
						log.insertBefore( li, log.firstChild );
					} );
				},
				function ( data, wasStopped, error ) {
					start.disabled = false;
					stop.hidden = true;
					if ( error ) {
						setText( progress, t.error + ': ' + error.message );
					} else if ( wasStopped ) {
						setText( progress, t.stopped );
					} else {
						fill.style.width = '100%';
						setText( progress, t.finished );
					}
				}
			);
		};

		start.addEventListener( 'click', function () {
			start.disabled = true;
			setText( progress, t.queued );
			post( 'bulk', params() )
				.then( watch )
				.catch( function ( error ) {
					start.disabled = false;
					setText( progress, t.error + ': ' + error.message );
				} );
		} );
		stop.addEventListener( 'click', function () {
			stopped = true;
		} );
	}

	document.querySelectorAll( '.gaiml-queue' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			button.disabled = true;
			post( 'queue', { do: button.getAttribute( 'data-do' ) } ).then( function () {
				window.location.reload();
			} );
		} );
	} );

	var strings = document.querySelector( '.gaiml-strings-translate' );
	if ( strings ) {
		strings.addEventListener( 'click', function () {
			var progress = strings.parentNode.querySelector( '.gaiml-progress' );
			strings.disabled = true;
			setText( progress, t.working );
			post( 'strings', { langs: strings.getAttribute( 'data-langs' ).split( ',' ) } )
				.then( function () {
					run(
						null,
						function ( data ) {
							setText( progress, format( t.remaining, data.stats.pending + data.stats.running ) );
						},
						function () {
							window.location.reload();
						}
					);
				} )
				.catch( function ( error ) {
					strings.disabled = false;
					setText( progress, t.error + ': ' + error.message );
				} );
		} );
	}
} )();
