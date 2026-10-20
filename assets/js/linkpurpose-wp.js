/**
 * WordPress initializer for Link Purpose Icons.
 *
 * Port of the Drupal module's linkpurpose-drupal.js. Reads the settings
 * object PHP inlines as `window.linkpurposeSettings` (see
 * LinkPurpose\Frontend\Assets), builds the library options object with
 * the same fallbacks the Drupal initializer applies, offers a
 * `window.linkPurposeOptions` hook for themes, then starts the library.
 *
 * Classic script (no module syntax) so it can be loaded as a plain
 * dependency of the vendored library, which defines the global
 * `LinkPurpose` class.
 */
( function () {
	'use strict';

	var invalidClass = /([^A-Za-z0-9\-_])+/g;
	var invalidClasses = /([^A-Za-z0-9,\-_])+/g;

	/**
	 * Single class name: strip anything that is not a class character;
	 * fall back when nothing survives.
	 */
	function sanitizeClass( string, defaultString ) {
		var cleanClass = string ? String( string ).replace( invalidClass, '' ) : false;
		return cleanClass || defaultString;
	}

	/**
	 * Class list: accept comma- or space-separated input, return an array;
	 * fall back when nothing survives.
	 */
	function classArray( source, defaults ) {
		if ( ! source ) {
			return defaults;
		}
		source = String( source ).replace( /(,*\s+)/g, ',' );
		source = source.replace( invalidClasses, '' );
		source = source.split( ',' ).filter( Boolean );
		return source.length > 0 ? source : defaults;
	}

	var queryCheck = document.createDocumentFragment();

	/**
	 * Return the selector when the browser can parse it, otherwise warn and
	 * return the fallback. Only a browser can decide validity, which is why
	 * PHP leaves this to the client.
	 */
	function validateSelector( string, fallback, label ) {
		if ( string && String( string ).trim() ) {
			try {
				queryCheck.querySelector( string );
			} catch ( _err ) {
				console.warn( 'Invalid selector in Link Purpose Icons for "' + label + '".' );
				return fallback;
			}
			return string;
		}
		return fallback;
	}

	/**
	 * Build a purpose entry. Keys that have no WordPress setting
	 * (iconHTML, redundantStrings, priority) fall through to the library's
	 * deep-merge defaults.
	 */
	function purpose( s, prefix, defaults ) {
		return {
			message: s[ prefix + 'Message' ] ? s[ prefix + 'Message' ] : defaults.message,
			linkClass: sanitizeClass( s[ prefix + 'Class' ], defaults.linkClass ),
			iconWrapperClass: sanitizeClass( s[ prefix + 'IconWrapperClass' ], defaults.iconWrapperClass ),
			iconType: s[ prefix + 'IconType' ] ? s[ prefix + 'IconType' ] : 'html',
			iconPosition: s[ prefix + 'IconPosition' ] ? s[ prefix + 'IconPosition' ] : 'beforeend',
			iconClasses: classArray( s[ prefix + 'IconClasses' ], defaults.iconClasses ),
		};
	}

	/**
	 * Settings payload → LinkPurpose options object.
	 */
	function buildOptions( s ) {
		var ignores = validateSelector( s.ignore, '', 'Ignore elements' );
		// Admin-bar links are always ignored (the Drupal module's equivalent
		// is the toolbar + CKEditor rule).
		var alwaysIgnore = '#wpadminbar a';

		var external = purpose( s, 'purposeExternal', {
			message: 'Link is external',
			linkClass: 'link-purpose-external',
			iconWrapperClass: 'link-purpose-external-icon',
			iconClasses: [ 'fa-solid', 'fa-up-right-from-square' ],
		} );
		external.selector = s.purposeExternal ? '[href^="https://"], [href^="http://"], [href^="//"]' : false;
		external.additionalSelector = validateSelector( s.purposeExternalSelector, false, 'External link selector' );
		external.newWindow = !! s.purposeExternalNewWindow;

		var documentPurpose = purpose( s, 'purposeDocument', {
			message: 'Link downloads document',
			linkClass: 'link-purpose-document',
			iconWrapperClass: 'link-purpose-document-icon',
			iconClasses: [ 'fa-regular', 'fa-file-lines' ],
		} );
		documentPurpose.selector = s.purposeDocument
			? validateSelector(
				s.purposeDocumentSelector,
				"[href^='/document/'], [href$='.pdf'], [href*='.pdf?'], [href$='.doc'], [href$='.docx'], [href*='.doc?'], [href*='.docx?'], [href$='.ppt'], [href$='.pptx'], [href*='.ppt?'], [href*='.pptx?'], [href^='https://docs.google']",
				'Document selector'
			)
			: false;

		var download = purpose( s, 'purposeDownload', {
			message: 'Link downloads file',
			linkClass: 'link-purpose-download',
			iconWrapperClass: 'link-purpose-download-icon',
			iconClasses: [ 'fa-solid', 'fa-download' ],
		} );
		download.selector = s.purposeDownload
			? validateSelector( s.purposeDownloadSelector, '[download]', 'Download selector' )
			: false;

		var app = purpose( s, 'purposeApp', {
			message: 'Link opens app',
			linkClass: 'link-purpose-app',
			iconWrapperClass: 'link-purpose-app-icon',
			iconClasses: [ 'fa-solid', 'fa-window-restore' ],
		} );
		app.selector = s.purposeApp
			? validateSelector(
				s.purposeAppSelector,
				':is([href*="://"]):not([href^="/"], [href^="https:"], [href^="http:"], [href^="file:"])',
				'App selector'
			)
			: false;

		var mail = purpose( s, 'purposeMail', {
			message: 'Link sends email',
			linkClass: 'link-purpose-mailto',
			iconWrapperClass: 'link-purpose-mail-icon',
			iconClasses: [ 'fa-regular', 'fa-envelope' ],
		} );
		mail.selector = s.purposeMail ? '[href^="mailto:"]' : false;

		var tel = purpose( s, 'purposeTel', {
			message: 'Link opens phone app',
			linkClass: 'link-purpose-tel',
			iconWrapperClass: 'link-purpose-tel-icon',
			iconClasses: [ 'fa-solid', 'fa-square-phone' ],
		} );
		tel.selector = s.purposeTel ? '[href^="tel:"]' : false;

		var newWindow = purpose( s, 'purposeNewWindow', {
			message: 'Link opens in new window',
			linkClass: 'link-purpose-window',
			iconWrapperClass: 'link-purpose-window-icon',
			iconClasses: [ 'fa-regular', 'fa-window-restore' ],
		} );
		newWindow.selector = s.purposeNewWindow ? '[target="_blank"]' : false;

		return {
			domain: s.domain,
			roots: validateSelector( s.roots, 'body', 'Check roots' ),
			shadowComponents: validateSelector( s.shadowComponents, '', 'Shadow components' ),
			shadowCSS: s.shadowCSS ? s.shadowCSS : false,
			ignore: ignores ? ignores + ', ' + alwaysIgnore : alwaysIgnore,
			hideIcon: validateSelector( s.hideIcon, false, 'Hide icon on element' ),
			noRunIfPresent: validateSelector(
				s.noRunIfPresent,
				'.elementor-editor-active, .wp-customizer-unloading',
				'No run if present'
			),
			noRunIfAbsent: validateSelector( s.noRunIfAbsent, '', 'No run if absent' ),
			baseSelector: validateSelector( s.baseSelector, 'a[href]', 'Base selector' ),
			baseLinkClass: sanitizeClass( s.baseLinkClass, 'link-purpose' ),
			baseIconWrapperClass: sanitizeClass( s.baseIconWrapperClass, 'link-purpose-icon' ),
			noBreakClass: sanitizeClass( s.noBreakClass, 'link-purpose-nobreak' ),
			noReferrer: !! s.purposeExternalNoReferrer,
			noIconOnImages: !! s.noIconOnImages,
			suppressNoBreak: validateSelector( s.suppressNoBreak, false, 'Suppress no-break' ),
			insertIconOutsideHiddenSpan: validateSelector(
				s.insertIconOutsideHiddenSpan,
				'.screen-reader-text, .sr-only, .visually-hidden',
				'Insert icon outside hidden span'
			),
			purposes: {
				external: external,
				document: documentPurpose,
				download: download,
				app: app,
				mail: mail,
				newWindow: newWindow,
				tel: tel,
			},
		};
	}

	// Exposed for themes (e.g. a custom initializer under "Attach library
	// then stop") and for the unit tests.
	window.linkPurposeWP = {
		buildOptions: buildOptions,
		sanitizeClass: sanitizeClass,
		classArray: classArray,
		validateSelector: validateSelector,
	};

	var settings = window.linkpurposeSettings;
	if ( ! settings || typeof LinkPurpose === 'undefined' ) {
		return;
	}
	// `selector(:is())` support drops browsers too old for the library.
	if ( ! ( window.CSS && CSS.supports && CSS.supports( 'selector(:is(body))' ) ) ) {
		return;
	}
	// Guard against double initialization (e.g. a theme that also inlines
	// this script, or a plugin re-running the footer scripts).
	if ( window.linkPurpose ) {
		return;
	}

	var options = buildOptions( settings );
	if ( typeof window.linkPurposeOptions === 'function' ) {
		options = window.linkPurposeOptions( options );
	}
	window.linkPurpose = new LinkPurpose( options );
} )();
