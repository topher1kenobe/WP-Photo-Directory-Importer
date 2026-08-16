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

		// Selection is kept as an ordered list of IDs plus the photo objects
		// themselves, so it survives a filter change that drops those photos
		// out of the current results.
		selected: [],
		selectedPhotos: {},
		detailsOpen: false,
		edits: {},
		importDefaults: { size: 'full', addCredit: true },
		importJob: null,
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

			case 'TOGGLE_SELECT': {
				var isSelected = -1 !== state.selected.indexOf( action.photo.id );
				var photos     = Object.assign( {}, state.selectedPhotos );

				if ( isSelected ) {
					delete photos[ action.photo.id ];
				} else {
					photos[ action.photo.id ] = action.photo;
				}

				return Object.assign( {}, state, {
					selected: isSelected
						? state.selected.filter( function ( id ) {
								return id !== action.photo.id;
						  } )
						: state.selected.concat( [ action.photo.id ] ),
					selectedPhotos: photos,
				} );
			}

			case 'CLEAR_SELECTION':
				return Object.assign( {}, state, {
					selected: [],
					selectedPhotos: {},
					detailsOpen: false,
					edits: {},
				} );

			case 'TOGGLE_DETAILS':
				return Object.assign( {}, state, { detailsOpen: ! state.detailsOpen } );

			case 'SET_EDIT': {
				var edit = Object.assign( {}, state.edits[ action.id ] );
				edit[ action.field ] = action.value;
				var edits = Object.assign( {}, state.edits );
				edits[ action.id ] = edit;
				return Object.assign( {}, state, { edits: edits } );
			}

			case 'SET_DEFAULTS':
				return Object.assign( {}, state, {
					importDefaults: Object.assign( {}, state.importDefaults, action.defaults ),
				} );

			case 'JOB_START':
				return Object.assign( {}, state, {
					importJob: { total: action.total, completed: 0, currentFile: '', failed: 0 },
				} );

			case 'JOB_PROGRESS':
				return Object.assign( {}, state, {
					importJob: Object.assign( {}, state.importJob, action.progress ),
					importedMap: Object.assign( {}, state.importedMap, action.imported || {} ),
				} );

			case 'JOB_END':
				return Object.assign( {}, state, {
					importJob: null,
					notice: action.notice,
					// A finished run clears the selection, as the design calls for.
					// A cancelled one keeps whatever is still unimported.
					selected: action.keep || [],
					selectedPhotos: pick( state.selectedPhotos, action.keep || [] ),
					edits: pick( state.edits, action.keep || [] ),
					detailsOpen: action.keep && action.keep.length ? state.detailsOpen : false,
				} );

			case 'NOTICE':
				return Object.assign( {}, state, { notice: action.notice } );

			default:
				return state;
		}
	}

	function pick( source, keys ) {
		return keys.reduce( function ( carry, key ) {
			if ( 'undefined' !== typeof source[ key ] ) {
				carry[ key ] = source[ key ];
			}
			return carry;
		}, {} );
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
			{
				className: 'pdi-card' + ( props.selected ? ' is-selected' : '' ),
				onClick: function () {
					props.onToggle( photo );
				},
			},
			h(
				'div',
				{ className: 'pdi-card__thumb' },
				photo.thumbUrl
					? h( 'img', { src: photo.thumbUrl, alt: photo.alt || photo.title, loading: 'lazy' } )
					: null,
				// The selection control is a real checkbox rather than the card
				// itself, so keyboard and screen-reader users get one labelled,
				// stateful control per photo instead of a second tab stop that
				// duplicates it.
				h( 'input', {
					type: 'checkbox',
					className: 'pdi-card__check',
					checked: !! props.selected,
					'aria-label': format( strings.selectPhoto, [ photo.title ] ),
					onClick: function ( event ) {
						event.stopPropagation();
					},
					onChange: function () {
						props.onToggle( photo );
					},
				} ),
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
							{
								className: 'pdi-card__link',
								href: props.libraryUrl,
								onClick: function ( event ) {
									event.stopPropagation();
								},
							},
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

	// ---------------------------------------------------------- 1.7 the tray

	function DetailsPanel( props ) {
		return h(
			'div',
			{ className: 'pdi-tray__details' },
			props.photos.map( function ( photo ) {
				var edit = props.edits[ photo.id ] || {};

				function field( name, label, placeholder, fallback ) {
					return h( 'input', {
						type: 'text',
						className: 'pdi-tray__input',
						'aria-label': label + ': ' + photo.title,
						placeholder: placeholder || label,
						value: 'undefined' !== typeof edit[ name ] ? edit[ name ] : fallback || '',
						onChange: function ( event ) {
							props.onEdit( photo.id, name, event.target.value );
						},
					} );
				}

				return h(
					'div',
					{ key: photo.id, className: 'pdi-tray__row' },
					h( 'img', { className: 'pdi-tray__rowthumb', src: photo.thumbUrl, alt: '' } ),
					field( 'title', strings.fieldTitle, strings.fieldTitle, photo.title ),
					field( 'alt', strings.fieldAlt, strings.fieldAltPlaceholder, photo.alt ),
					field( 'caption', strings.fieldCaption, strings.fieldCaption, '' )
				);
			} )
		);
	}

	function ImportProgress( props ) {
		var job     = props.job;
		var percent = job.total ? Math.round( ( job.completed / job.total ) * 100 ) : 0;

		return h(
			'div',
			{ className: 'pdi-tray__progress' },
			h(
				'div',
				{ className: 'pdi-tray__progressrow' },
				h(
					'span',
					{ className: 'pdi-tray__progresstext', role: 'status' },
					format( strings.importProgress, [
						Math.min( job.completed + 1, job.total ),
						job.total,
						job.currentFile,
					] )
				),
				h(
					'button',
					{ type: 'button', className: 'pdi-tray__cancel', onClick: props.onCancel },
					strings.cancel
				)
			),
			h(
				'div',
				{ className: 'pdi-tray__track' },
				h( 'div', { className: 'pdi-tray__fill', style: { width: percent + '%' } } )
			)
		);
	}

	function SelectionTray( props ) {
		var photos  = props.photos;
		var running = !! props.job;

		return h(
			'div',
			{ className: 'pdi-tray', role: 'region', 'aria-label': strings.trayLabel },
			h(
				'div',
				{ className: 'pdi-tray__main' },
				h(
					'div',
					{ className: 'pdi-tray__selection' },
					h( 'span', { className: 'pdi-tray__count' }, format( strings.selectedCount, [ photos.length ] ) ),
					h(
						'div',
						{ className: 'pdi-tray__thumbs' },
						photos.map( function ( photo ) {
							return h(
								'span',
								{ key: photo.id, className: 'pdi-tray__thumb' },
								h( 'img', { src: photo.thumbUrl, alt: '' } ),
								h(
									'button',
									{
										type: 'button',
										className: 'pdi-tray__remove',
										'aria-label': format( strings.deselectPhoto, [ photo.title ] ),
										disabled: running,
										onClick: function () {
											props.onToggle( photo );
										},
									},
									'×'
								)
							);
						} )
					)
				),
				h(
					'div',
					{ className: 'pdi-tray__controls' },
					h(
						'label',
						{ className: 'pdi-tray__field' },
						h( 'span', { className: 'pdi-tray__label' }, strings.importSize ),
						h(
							'select',
							{
								className: 'pdi-select',
								value: props.defaults.size,
								disabled: running,
								onChange: function ( event ) {
									props.onDefaults( { size: event.target.value } );
								},
							},
							( settings.sizes || [] ).map( function ( size ) {
								return h( 'option', { key: size.value, value: size.value }, size.label );
							} )
						)
					),
					h(
						'label',
						{ className: 'pdi-tray__checkbox' },
						h( 'input', {
							type: 'checkbox',
							checked: props.defaults.addCredit,
							disabled: running,
							onChange: function ( event ) {
								props.onDefaults( { addCredit: event.target.checked } );
							},
						} ),
						strings.addCredit
					),
					h(
						'button',
						{
							type: 'button',
							className: 'pdi-tray__toggle',
							disabled: running,
							onClick: props.onToggleDetails,
						},
						props.detailsOpen ? strings.hideDetails : strings.editDetails
					),
					h(
						'button',
						{
							type: 'button',
							className: 'button button-primary pdi-tray__import',
							disabled: running,
							onClick: props.onImport,
						},
						1 === photos.length
							? strings.importOne
							: format( strings.importCount, [ photos.length ] )
					)
				)
			),
			props.detailsOpen && ! running
				? h( DetailsPanel, { photos: photos, edits: props.edits, onEdit: props.onEdit } )
				: null,
			running ? h( ImportProgress, { job: props.job, onCancel: props.onCancel } ) : null
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
		var cancelled = useRef( false );

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

		// Imports run one request at a time so a large selection can't open
		// twenty simultaneous downloads. Cancelling stops the queue before the
		// next photo starts; anything already imported stays imported.
		function runImport() {
			var photos = state.selected
				.map( function ( id ) {
					return state.selectedPhotos[ id ];
				} )
				.filter( Boolean );

			if ( ! photos.length ) {
				return;
			}

			var edits    = state.edits;
			var defaults = state.importDefaults;
			var imported = {};
			var failed   = [];
			var index    = 0;

			cancelled.current = false;
			dispatch( { type: 'JOB_START', total: photos.length } );

			function finish() {
				var succeeded = Object.keys( imported ).length;
				var pending   = cancelled.current
					? photos.slice( index ).map( function ( photo ) {
							return photo.id;
					  } )
					: [];
				var keep = failed
					.map( function ( photo ) {
						return photo.id;
					} )
					.concat( pending );

				var notice;
				if ( succeeded && ! failed.length ) {
					notice = {
						type: 'success',
						title:
							1 === succeeded
								? strings.importedOne
								: format( strings.importedCount, [ succeeded ] ),
						message: strings.importedBody,
						actionLabel: strings.viewInMediaLibrary,
						actionUrl: settings.libraryUrl,
					};
				} else if ( succeeded ) {
					notice = {
						type: 'error',
						title: format( strings.importedPartial, [ succeeded, failed.length ] ),
						message: strings.importedPartialBody,
					};
				} else {
					notice = { type: 'error', title: strings.importFailed, message: strings.error };
				}

				dispatch( {
					type: 'JOB_END',
					keep: keep.filter( function ( id, position ) {
						return keep.indexOf( id ) === position;
					} ),
					notice: notice,
				} );
			}

			function next() {
				if ( cancelled.current || index >= photos.length ) {
					finish();
					return;
				}

				var photo = photos[ index ];
				var edit  = edits[ photo.id ] || {};

				dispatch( { type: 'JOB_PROGRESS', progress: { completed: index, currentFile: photo.title } } );

				ajax( 'pdi_import', {
					photo_id: photo.id,
					size: defaults.size,
					add_credit: defaults.addCredit ? '1' : '0',
					title: 'undefined' !== typeof edit.title ? edit.title : photo.title,
					alt: 'undefined' !== typeof edit.alt ? edit.alt : photo.alt,
					caption: 'undefined' !== typeof edit.caption ? edit.caption : null,
				} )
					.then( function ( response ) {
						if ( response && response.success ) {
							imported[ photo.id ] = response.data.id;
						} else {
							failed.push( photo );
						}
					} )
					.catch( function () {
						failed.push( photo );
					} )
					.then( function () {
						index++;
						dispatch( {
							type: 'JOB_PROGRESS',
							progress: { completed: index },
							imported: imported,
						} );
						next();
					} );
			}

			next();
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
					),
					h(
						'span',
						{ className: 'pdi-results__hint' },
						state.selected.length
							? format( strings.hintSelected, [ state.selected.length ] )
							: strings.hintSelect
					),
					state.selected.length
						? h(
								'button',
								{
									type: 'button',
									className: 'pdi-results__clear',
									onClick: function () {
										dispatch( { type: 'CLEAR_SELECTION' } );
									},
								},
								strings.clearSelection
						  )
						: null
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
									selected: -1 !== state.selected.indexOf( photo.id ),
									onImport: importPhoto,
									onToggle: function ( selectedPhoto ) {
										dispatch( { type: 'TOGGLE_SELECT', photo: selectedPhoto } );
									},
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
			),
			state.selected.length
				? h( SelectionTray, {
						photos: state.selected.map( function ( id ) {
							return state.selectedPhotos[ id ];
						} ),
						defaults: state.importDefaults,
						detailsOpen: state.detailsOpen,
						edits: state.edits,
						job: state.importJob,
						onToggle: function ( photo ) {
							dispatch( { type: 'TOGGLE_SELECT', photo: photo } );
						},
						onDefaults: function ( defaults ) {
							dispatch( { type: 'SET_DEFAULTS', defaults: defaults } );
						},
						onToggleDetails: function () {
							dispatch( { type: 'TOGGLE_DETAILS' } );
						},
						onEdit: function ( id, field, value ) {
							dispatch( { type: 'SET_EDIT', id: id, field: field, value: value } );
						},
						onImport: runImport,
						onCancel: function () {
							cancelled.current = true;
						},
				  } )
				: null
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
