/* global PDI_Browser */
/**
 * Browse and import UI for Media > Photo Directory.
 *
 * Written against wp-element (the copy of React WordPress already ships)
 * rather than a bundled build, so the plugin keeps its no-build-step setup.
 * That rules out JSX, hence the `h()` calls throughout.
 *
 * Chrome is deliberately plain markup with core wp-admin classes instead of
 * @wordpress/components: the design targets wp-admin's own control metrics
 * (30px selects, 3px radii, #8c8f94 borders), which the Gutenberg component
 * library does not match.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element ) {
		return;
	}

	var h          = wp.element.createElement;
	var Fragment   = wp.element.Fragment;
	var useState   = wp.element.useState;
	var useEffect  = wp.element.useEffect;
	var useReducer = wp.element.useReducer;
	var useRef     = wp.element.useRef;
	var useMemo    = wp.element.useMemo;

	var settings = window.PDI_Browser || {};
	var strings  = settings.strings || {};

	var TAX_CATEGORY    = 'photo-categories';
	var TAX_ORIENTATION = 'photo-orientations';
	var TAX_COLOR       = 'photo-colors';

	// ---------------------------------------------------------------- utils

	/**
	 * Minimal printf for the localized strings, supporting %s and %1$s.
	 *
	 * @param {string} template Localized string.
	 * @param {Array}  values   Replacements, in order.
	 * @return {string} Interpolated string.
	 */
	function format( template, values ) {
		var auto = 0;
		return String( template || '' ).replace( /%(?:(\d+)\$)?s/g, function ( match, position ) {
			var index = position ? parseInt( position, 10 ) - 1 : auto++;
			return 'undefined' !== typeof values[ index ] ? values[ index ] : '';
		} );
	}

	function formatNumber( value ) {
		return Number( value || 0 ).toLocaleString();
	}

	function ajax( action, data ) {
		var params = Object.assign( { action: action, nonce: settings.nonce }, data || {} );
		var body   = Object.keys( params )
			.filter( function ( key ) {
				return null !== params[ key ] && 'undefined' !== typeof params[ key ];
			} )
			.map( function ( key ) {
				return encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] );
			} )
			.join( '&' );

		return fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	// ------------------------------------------------------------ reducer

	var EMPTY_FILTERS = { category: 0, orientation: 0, color: 0, sort: 'relevance' };

	var initialState = {
		query: '',
		filters: Object.assign( {}, EMPTY_FILTERS ),
		page: 1,
		// Bumped to re-run the fetch when nothing else about the query changed,
		// which is what "Try again" needs after a failed request.
		reloadToken: 0,
		status: 'loading',
		results: [],
		total: 0,
		hasMore: false,
		importedMap: {},
		importing: {},
		error: '',
		notice: null,
	};

	function reducer( state, action ) {
		switch ( action.type ) {
			case 'SET_QUERY':
				if ( action.query === state.query ) {
					return state;
				}
				return Object.assign( {}, state, { query: action.query, page: 1, status: 'loading' } );

			case 'SET_FILTER':
				return Object.assign( {}, state, {
					filters: Object.assign( {}, state.filters, action.filters ),
					page: 1,
					status: 'loading',
				} );

			case 'CLEAR_FILTERS':
				return Object.assign( {}, state, {
					filters: Object.assign( {}, EMPTY_FILTERS, { sort: state.filters.sort } ),
					page: 1,
					status: 'loading',
				} );

			case 'LOAD_MORE':
				return Object.assign( {}, state, { page: state.page + 1, status: 'loadingMore' } );

			case 'RETRY':
				return Object.assign( {}, state, {
					page: 1,
					reloadToken: state.reloadToken + 1,
					status: 'loading',
				} );

			case 'RESULTS':
				return Object.assign( {}, state, {
					status: action.data.photos.length || action.append ? 'ready' : 'empty',
					results: action.append ? state.results.concat( action.data.photos ) : action.data.photos,
					total: action.data.total,
					hasMore: action.data.page < action.data.totalPages,
					importedMap: Object.assign( {}, action.append ? state.importedMap : {}, action.data.importedMap ),
					error: '',
				} );

			case 'ERROR':
				return Object.assign( {}, state, { status: 'error', error: action.message } );

			case 'IMPORT_START':
				return Object.assign( {}, state, {
					importing: Object.assign( {}, state.importing, mapOf( action.ids, true ) ),
				} );

			case 'IMPORT_END':
				return Object.assign( {}, state, {
					importing: omit( state.importing, action.ids ),
					importedMap: Object.assign( {}, state.importedMap, action.imported || {} ),
					notice: 'undefined' === typeof action.notice ? state.notice : action.notice,
				} );

			case 'NOTICE':
				return Object.assign( {}, state, { notice: action.notice } );

			default:
				return state;
		}
	}

	function mapOf( keys, value ) {
		return keys.reduce( function ( carry, key ) {
			carry[ key ] = value;
			return carry;
		}, {} );
	}

	function omit( source, keys ) {
		var out = Object.assign( {}, source );
		keys.forEach( function ( key ) {
			delete out[ key ];
		} );
		return out;
	}

	// ----------------------------------------------------------- components

	function PageHeader() {
		return h(
			'div',
			{ className: 'pdi-header' },
			h(
				'div',
				{ className: 'pdi-header__text' },
				h( 'h1', { className: 'pdi-header__title' }, strings.title ),
				h( 'p', { className: 'pdi-header__description' }, strings.description )
			),
			settings.settingsUrl
				? h( 'a', { className: 'pdi-header__settings', href: settings.settingsUrl }, strings.importSettings )
				: null
		);
	}

	function SearchRow( props ) {
		return h(
			'div',
			{ className: 'pdi-search' },
			h(
				'div',
				{ className: 'pdi-search__field' },
				h( 'span', { className: 'dashicons dashicons-search pdi-search__icon', 'aria-hidden': 'true' } ),
				h( 'input', {
					type: 'search',
					className: 'pdi-search__input',
					value: props.value,
					placeholder: strings.searchPlaceholder,
					'aria-label': strings.searchLabel,
					onChange: function ( event ) {
						props.onChange( event.target.value );
					},
					onKeyDown: function ( event ) {
						if ( 'Enter' === event.key ) {
							event.preventDefault();
							props.onSubmit();
						}
					},
				} )
			),
			h(
				'button',
				{ type: 'button', className: 'button button-primary pdi-search__submit', onClick: props.onSubmit },
				strings.search
			)
		);
	}

	function TermSelect( props ) {
		return h(
			'select',
			{
				className: 'pdi-select',
				value: props.value,
				'aria-label': props.label,
				onChange: function ( event ) {
					props.onChange( parseInt( event.target.value, 10 ) || 0 );
				},
			},
			[ h( 'option', { key: 'any', value: 0 }, props.anyLabel ) ].concat(
				props.terms.map( function ( term ) {
					return h( 'option', { key: term.id, value: term.id }, term.name );
				} )
			)
		);
	}

	function ColorFilter( props ) {
		if ( ! props.terms.length ) {
			return null;
		}

		return h(
			'div',
			{ className: 'pdi-colors', role: 'group', 'aria-label': strings.colorLabel },
			h( 'span', { className: 'pdi-colors__label' }, strings.colorLabel ),
			props.terms.map( function ( term ) {
				var selected = props.value === term.id;
				return h( 'button', {
					key: term.id,
					type: 'button',
					className: 'pdi-swatch' + ( selected ? ' is-selected' : '' ),
					style: { backgroundColor: term.hex || '#f6f7f7' },
					title: term.name,
					'aria-label': format( strings.colorSwatch, [ term.name ] ),
					'aria-pressed': selected,
					onClick: function () {
						props.onChange( selected ? 0 : term.id );
					},
				} );
			} )
		);
	}

	function FilterRow( props ) {
		var filters = props.filters;

		return h(
			'div',
			{ className: 'pdi-filters' },
			h( TermSelect, {
				terms: props.terms[ TAX_CATEGORY ] || [],
				value: filters.category,
				label: strings.allCategories,
				anyLabel: strings.allCategories,
				onChange: function ( value ) {
					props.onChange( { category: value } );
				},
			} ),
			h( TermSelect, {
				terms: props.terms[ TAX_ORIENTATION ] || [],
				value: filters.orientation,
				label: strings.anyOrientation,
				anyLabel: strings.anyOrientation,
				onChange: function ( value ) {
					props.onChange( { orientation: value } );
				},
			} ),
			h( ColorFilter, {
				terms: props.terms[ TAX_COLOR ] || [],
				value: filters.color,
				onChange: function ( value ) {
					props.onChange( { color: value } );
				},
			} ),
			h(
				'select',
				{
					className: 'pdi-select',
					value: filters.sort,
					'aria-label': strings.sortLabel,
					onChange: function ( event ) {
						props.onChange( { sort: event.target.value } );
					},
				},
				h( 'option', { value: 'relevance' }, strings.sortRelevance ),
				h( 'option', { value: 'date' }, strings.sortNewest )
			),
			h( 'span', { className: 'pdi-filters__count' }, props.count )
		);
	}

	function FilterChips( props ) {
		if ( ! props.chips.length ) {
			return null;
		}

		return h(
			'div',
			{ className: 'pdi-chips' },
			h( 'span', { className: 'pdi-chips__label' }, strings.filtersLabel ),
			props.chips.map( function ( chip ) {
				return h(
					'button',
					{
						key: chip.key,
						type: 'button',
						className: 'pdi-chip',
						'aria-label': format( strings.removeFilter, [ chip.label ] ),
						onClick: function () {
							props.onRemove( chip.key );
						},
					},
					chip.label,
					h( 'span', { className: 'pdi-chip__remove', 'aria-hidden': 'true' }, '×' )
				);
			} ),
			h( 'button', { type: 'button', className: 'pdi-chips__clear', onClick: props.onClear }, strings.clearAll )
		);
	}

	function Notice( props ) {
		var notice = props.notice;
		return h(
			'div',
			{ className: 'notice pdi-notice pdi-notice--' + notice.type, role: 'status' },
			h(
				'div',
				{ className: 'pdi-notice__text' },
				h( 'strong', null, notice.title ),
				notice.message ? h( 'span', { className: 'pdi-notice__detail' }, notice.message ) : null
			),
			notice.actionLabel
				? h(
						notice.actionUrl ? 'a' : 'button',
						{
							className: notice.actionUrl ? 'pdi-notice__link' : 'button pdi-notice__button',
							href: notice.actionUrl || null,
							type: notice.actionUrl ? null : 'button',
							onClick: notice.onAction || null,
						},
						notice.actionLabel
				  )
				: null
		);
	}

	function Skeletons() {
		var cards = [];
		for ( var i = 0; i < 10; i++ ) {
			cards.push(
				h(
					'div',
					{ key: i, className: 'pdi-card pdi-card--skeleton', 'aria-hidden': 'true' },
					h( 'div', { className: 'pdi-skeleton__thumb' } ),
					h(
						'div',
						{ className: 'pdi-skeleton__body' },
						h( 'span', { className: 'pdi-skeleton__bar pdi-skeleton__bar--wide' } ),
						h( 'span', { className: 'pdi-skeleton__bar pdi-skeleton__bar--narrow' } )
					)
				)
			);
		}
		return h( 'div', { className: 'pdi-grid', 'aria-busy': 'true' }, cards );
	}

	function EmptyState( props ) {
		return h(
			'div',
			{ className: 'pdi-empty' },
			h(
				'h2',
				{ className: 'pdi-empty__title' },
				props.query ? format( strings.emptyTitle, [ props.query ] ) : strings.emptyTitleFiltered
			),
			h( 'p', { className: 'pdi-empty__body' }, strings.emptyBody ),
			props.suggestions.length
				? h(
						'div',
						{ className: 'pdi-empty__suggestions' },
						props.suggestions.map( function ( suggestion ) {
							return h(
								'button',
								{
									key: suggestion,
									type: 'button',
									className: 'pdi-suggestion',
									onClick: function () {
										props.onSuggest( suggestion );
									},
								},
								suggestion
							);
						} )
				  )
				: null
		);
	}

	function PhotoCard( props ) {
		var photo        = props.photo;
		var attachmentId = props.attachmentId;
		var meta         = [ photo.author, photo.width && photo.height ? photo.width + ' × ' + photo.height : '' ]
			.filter( Boolean )
			.join( ' · ' );

		return h(
			'div',
			{ className: 'pdi-card' },
			h(
				'div',
				{ className: 'pdi-card__thumb' },
				photo.thumbUrl
					? h( 'img', { src: photo.thumbUrl, alt: photo.alt || photo.title, loading: 'lazy' } )
					: null,
				attachmentId ? h( 'span', { className: 'pdi-card__badge' }, strings.inLibrary ) : null
			),
			h(
				'div',
				{ className: 'pdi-card__body' },
				h( 'span', { className: 'pdi-card__title', title: photo.title }, photo.title ),
				meta ? h( 'span', { className: 'pdi-card__meta' }, meta ) : null,
				attachmentId
					? h(
							'a',
							{ className: 'pdi-card__link', href: props.libraryUrl },
							strings.viewInLibrary
					  )
					: h(
							'button',
							{
								type: 'button',
								className: 'pdi-card__import',
								disabled: props.importing,
								onClick: function ( event ) {
									event.stopPropagation();
									props.onImport( photo );
								},
							},
							props.importing ? strings.importing : strings.import
					  )
			)
		);
	}

	// -------------------------------------------------------------- the app

	function PhotoBrowser() {
		var reduced  = useReducer( reducer, initialState );
		var state    = reduced[ 0 ];
		var dispatch = reduced[ 1 ];

		var inputState = useState( '' );
		var input      = inputState[ 0 ];
		var setInput   = inputState[ 1 ];

		var termsState = useState( {} );
		var terms      = termsState[ 0 ];
		var setTerms   = termsState[ 1 ];

		// Guards against a slow earlier request landing after a newer one and
		// overwriting fresher results, which debounced typing makes likely.
		var requestId = useRef( 0 );

		useEffect( function () {
			ajax( 'pdi_terms' ).then( function ( response ) {
				if ( response && response.success ) {
					setTerms( response.data );
				}
			} );
		}, [] );

		// Debounced as-you-type search. Committing the query resets paging.
		useEffect(
			function () {
				var timer = window.setTimeout( function () {
					dispatch( { type: 'SET_QUERY', query: input.trim() } );
				}, 400 );

				return function () {
					window.clearTimeout( timer );
				};
			},
			[ input ]
		);

		useEffect(
			function () {
				var append  = state.page > 1;
				var current = ++requestId.current;

				ajax( 'pdi_search', {
					search: state.query,
					page: state.page,
					category: state.filters.category,
					orientation: state.filters.orientation,
					color: state.filters.color,
					sort: state.filters.sort,
				} )
					.then( function ( response ) {
						if ( current !== requestId.current ) {
							return;
						}
						if ( ! response || ! response.success ) {
							dispatch( {
								type: 'ERROR',
								message: ( response && response.data && response.data.message ) || strings.error,
							} );
							return;
						}
						dispatch( { type: 'RESULTS', data: response.data, append: append } );
					} )
					.catch( function () {
						if ( current === requestId.current ) {
							dispatch( { type: 'ERROR', message: strings.error } );
						}
					} );
			},
			[ state.query, state.filters, state.page, state.reloadToken ]
		);

		function importPhoto( photo ) {
			dispatch( { type: 'IMPORT_START', ids: [ photo.id ] } );

			ajax( 'pdi_import', { photo_id: photo.id, size: 'full' } )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						dispatch( {
							type: 'IMPORT_END',
							ids: [ photo.id ],
							notice: {
								type: 'error',
								title: strings.importFailed,
								message: ( response && response.data && response.data.message ) || strings.error,
							},
						} );
						return;
					}

					var imported = {};
					imported[ photo.id ] = response.data.id;

					dispatch( {
						type: 'IMPORT_END',
						ids: [ photo.id ],
						imported: imported,
						notice: {
							type: 'success',
							title: response.data.alreadyInLibrary
								? strings.alreadyImported
								: format( strings.importedCount, [ 1 ] ),
							message: response.data.alreadyInLibrary ? strings.alreadyImportedBody : '',
							actionLabel: strings.viewInLibrary,
							actionUrl: response.data.libraryUrl,
						},
					} );
				} )
				.catch( function () {
					dispatch( {
						type: 'IMPORT_END',
						ids: [ photo.id ],
						notice: { type: 'error', title: strings.importFailed, message: strings.error },
					} );
				} );
		}

		function submitSearch() {
			dispatch( { type: 'SET_QUERY', query: input.trim() } );
		}

		var chips = useMemo(
			function () {
				var active = [];
				[
					{ key: 'category', taxonomy: TAX_CATEGORY },
					{ key: 'orientation', taxonomy: TAX_ORIENTATION },
					{ key: 'color', taxonomy: TAX_COLOR },
				].forEach( function ( entry ) {
					var id = state.filters[ entry.key ];
					if ( ! id ) {
						return;
					}
					var list = terms[ entry.taxonomy ] || [];
					var term = list.filter( function ( candidate ) {
						return candidate.id === id;
					} )[ 0 ];
					if ( term ) {
						active.push( { key: entry.key, label: term.name } );
					}
				} );
				return active;
			},
			[ state.filters, terms ]
		);

		var suggestions = useMemo(
			function () {
				var words = state.query
					.split( /\s+/ )
					.filter( function ( word ) {
						return word.length > 3;
					} )
					.sort( function ( a, b ) {
						return b.length - a.length;
					} )
					.slice( 0, 2 );

				var categories = ( terms[ TAX_CATEGORY ] || [] ).slice( 0, 3 ).map( function ( term ) {
					return term.name;
				} );

				return words
					.concat( categories )
					.filter( function ( word, index, list ) {
						return word.toLowerCase() !== state.query.toLowerCase() && list.indexOf( word ) === index;
					} )
					.slice( 0, 3 );
			},
			[ state.query, terms ]
		);

		var isLoading = 'loading' === state.status;

		// Only meaningful once a response has landed: showing "0 photos" while
		// the first request is still in flight reads as an empty directory.
		var hasCount = 'ready' === state.status || 'loadingMore' === state.status;
		var count    = ! hasCount
			? ''
			: state.query
			? format( strings.photoCountFor, [ formatNumber( state.total ), state.query ] )
			: format( strings.photoCount, [ formatNumber( state.total ) ] );

		return h(
			Fragment,
			null,
			h( PageHeader ),
			h( SearchRow, { value: input, onChange: setInput, onSubmit: submitSearch } ),
			h( FilterRow, {
				filters: state.filters,
				terms: terms,
				count: count,
				onChange: function ( filters ) {
					dispatch( { type: 'SET_FILTER', filters: filters } );
				},
			} ),
			h( FilterChips, {
				chips: chips,
				onRemove: function ( key ) {
					var patch  = {};
					patch[ key ] = 0;
					dispatch( { type: 'SET_FILTER', filters: patch } );
				},
				onClear: function () {
					dispatch( { type: 'CLEAR_FILTERS' } );
				},
			} ),
			state.notice
				? h( Notice, {
						notice: Object.assign( {}, state.notice, {
							onAction: state.notice.onAction
								? state.notice.onAction
								: function () {
										dispatch( { type: 'NOTICE', notice: null } );
								  },
						} ),
				  } )
				: null,
			'error' === state.status
				? h( Notice, {
						notice: {
							type: 'error',
							title: strings.errorTitle,
							message: state.error,
							actionLabel: strings.tryAgain,
							onAction: function () {
								dispatch( { type: 'RETRY' } );
							},
						},
				  } )
				: null,
			h(
				'div',
				{ className: 'pdi-results' },
				h(
					'div',
					{ className: 'pdi-results__heading' },
					h(
						'h2',
						null,
						state.query ? format( strings.resultsFor, [ state.query ] ) : strings.recentlyAdded
					)
				),
				isLoading ? h( Skeletons ) : null,
				! isLoading && 'empty' === state.status
					? h( EmptyState, {
							query: state.query,
							suggestions: suggestions,
							onSuggest: function ( suggestion ) {
								setInput( suggestion );
								dispatch( { type: 'SET_QUERY', query: suggestion } );
							},
					  } )
					: null,
				! isLoading && state.results.length
					? h(
							'div',
							{ className: 'pdi-grid' },
							state.results.map( function ( photo ) {
								var attachmentId = state.importedMap[ photo.id ];
								return h( PhotoCard, {
									key: photo.id,
									photo: photo,
									attachmentId: attachmentId,
									libraryUrl: attachmentId ? settings.libraryUrl + '?item=' + attachmentId : '',
									importing: !! state.importing[ photo.id ],
									onImport: importPhoto,
								} );
							} )
					  )
					: null,
				state.hasMore && ! isLoading
					? h(
							'div',
							{ className: 'pdi-pagination' },
							h(
								'button',
								{
									type: 'button',
									className: 'pdi-load-more',
									disabled: 'loadingMore' === state.status,
									onClick: function () {
										dispatch( { type: 'LOAD_MORE' } );
									},
								},
								'loadingMore' === state.status ? strings.loading : strings.loadMore
							),
							h(
								'span',
								{ className: 'pdi-pagination__count' },
								format( strings.showingCount, [
									formatNumber( state.results.length ),
									formatNumber( state.total ),
								] )
							)
					  )
					: null
			)
		);
	}

	// ---------------------------------------------------------------- mount

	document.addEventListener( 'DOMContentLoaded', function () {
		var node = document.getElementById( 'pdi-browser' );
		if ( ! node ) {
			return;
		}

		var app = h( PhotoBrowser );

		if ( wp.element.createRoot ) {
			wp.element.createRoot( node ).render( app );
		} else {
			wp.element.render( app, node );
		}
	} );
} )( window.wp );
