/**
 * Merges per-source-file JSON translation files produced by `wp i18n make-json`
 * into a single file named by the registered script handle.
 *
 * WordPress's load_script_textdomain() first looks for:
 *   languages/{domain}-{locale}-{handle}.json
 *
 * `wp i18n make-json` emits files keyed by md5(source-file-path), not the
 * bundled handle, so the default output is never found at runtime. This script
 * reads the webpack map (languages/payjp-for-wc-webpack-map.json) to determine
 * which source files belong to which handle, merges their JSON translation data,
 * and writes the correctly named output file.
 *
 * Run after `wp i18n make-json`:
 *   node scripts/merge-block-translations.js
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const LANGUAGES_DIR = path.join( __dirname, '..', 'languages' );
const MAP_FILE = path.join( LANGUAGES_DIR, 'payjp-for-wc-webpack-map.json' );
const DOMAIN = 'payjp-for-wc';

if ( ! fs.existsSync( MAP_FILE ) ) {
	console.error( 'Map file not found:', MAP_FILE );
	process.exit( 1 );
}

/** @type {Record<string, string[]>} bundlePath → [sourcePaths] */
const map = JSON.parse( fs.readFileSync( MAP_FILE, 'utf8' ) );

// Derive the registered WP script handle from the bundle path.
// build/blocks/checkout.js → payjp-blocks-checkout
function bundlePathToHandle( bundlePath ) {
	return (
		'payjp-' +
		bundlePath
			.replace( /^build\//, '' )
			.replace( /\.js$/, '' )
			.replace( /\//g, '-' )
	);
}

// Load all JSON files in languages/ that were emitted by `wp i18n make-json`
// (identified by having a "source" field pointing to a src/ file).
function loadSourceKeyedJsonFiles() {
	return fs
		.readdirSync( LANGUAGES_DIR )
		.filter( ( f ) => f.endsWith( '.json' ) && f.startsWith( DOMAIN ) )
		.map( ( f ) => {
			try {
				const data = JSON.parse(
					fs.readFileSync( path.join( LANGUAGES_DIR, f ), 'utf8' )
				);
				// Only files emitted by make-json have a "source" field.
				if ( data.source && data.locale_data ) {
					return { file: f, data };
				}
			} catch {
				// ignore malformed files
			}
			return null;
		} )
		.filter( Boolean );
}

const allJsonFiles = loadSourceKeyedJsonFiles();

for ( const [ bundlePath, sourcePaths ] of Object.entries( map ) ) {
	const handle = bundlePathToHandle( bundlePath );

	// Find JSON files whose "source" field matches one of the mapped source paths.
	const relevant = allJsonFiles.filter( ( { data } ) =>
		sourcePaths.some( ( src ) => data.source === src )
	);

	if ( ! relevant.length ) {
		console.warn( `No translation files found for ${ bundlePath }` );
		continue;
	}

	// Group by locale (read from locale_data.messages[""].lang).
	/** @type {Map<string, Array<{file: string, data: object}>>} */
	const byLocale = new Map();
	for ( const entry of relevant ) {
		const lang =
			entry.data.locale_data?.messages?.[ '' ]?.lang ?? 'unknown';
		if ( ! byLocale.has( lang ) ) {
			byLocale.set( lang, [] );
		}
		byLocale.get( lang ).push( entry );
	}

	for ( const [ locale, entries ] of byLocale ) {
		const header = entries[ 0 ].data.locale_data.messages[ '' ];

		// Merge all locale_data.messages objects.
		const merged = {};
		for ( const { data } of entries ) {
			Object.assign( merged, data.locale_data.messages );
		}
		merged[ '' ] = header; // Restore header after merge.

		const stringCount = Object.keys( merged ).filter( ( k ) => k !== '' ).length;

		const output = {
			'translation-revision-date':
				entries[ 0 ].data[ 'translation-revision-date' ],
			generator: entries[ 0 ].data.generator,
			source: bundlePath,
			domain: 'messages',
			locale_data: { messages: merged },
		};

		const outFile = path.join(
			LANGUAGES_DIR,
			`${ DOMAIN }-${ locale }-${ handle }.json`
		);
		fs.writeFileSync( outFile, JSON.stringify( output, null, 2 ) );
		console.log(
			`Created: ${ path.basename( outFile ) } (${ stringCount } strings)`
		);

		// Remove the per-source-file JSON files that WordPress will never find.
		for ( const { file } of entries ) {
			fs.unlinkSync( path.join( LANGUAGES_DIR, file ) );
			console.log( `Removed:  ${ file }` );
		}
	}
}
