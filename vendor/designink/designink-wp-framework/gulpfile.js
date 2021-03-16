'use strict';

// Gulp-specific requires
const gulp = require( 'gulp' );
const util = require( 'gulp-util' );
const prompt = require( 'gulp-prompt' );
const jeditor = require( 'gulp-json-editor' );
const replace = require( 'gulp-replace' );

// NPM requires
const sprintf = require( 'sprintf-js' ).sprintf;
const compareVersions = require( 'compare-versions' );

// Process requires
const fs = require( 'fs' );

function upgrade_version( cb ) {
	const packageJSON = fs.readFileSync( 'package.json' );
	const packageInfo = JSON.parse( packageJSON );
	const version = packageInfo.version;

	util.log( util.colors.yellow( sprintf( 'Current package version: %s', version ) ) );

	// Start with version prompt
	gulp.src( 'gulpfile.js' )
		.pipe( prompt.prompt(
			{
				type: 'input',
				name: 'version',
				message: util.colors.cyan( 'Enter the version to bump to (e.g. "1.0.0"):' )
			},
			function( res ) {
				// Limit subversions to 3 numbers with 3 digits each
				const numberFormat = /^\d{1,3}\.\d{1,3}\.\d{1,3}$/g;

				// Test version format
				if ( numberFormat.test( res.version ) ) {

					let newerVersion = false;

					if ( packageInfo.version ) {
						newerVersion = compareVersions(res.version, packageInfo.version) === 1;
					} else {
						newerVersion = true;
					}

					// Check if version is newer
					if ( newerVersion ) {
						util.log( util.colors.green( res.version ) );

						/**
						 * Find/replace all version instances
						 */

						// Replace package.json version
						gulp.src( 'package.json' )
							.pipe( jeditor( {
								"version": res.version
							} ) )
							.pipe( gulp.dest( './' ) );

						// Replace composer.json version
						gulp.src( 'composer.json' )
							.pipe( jeditor( {
								"version": res.version
							} ) )
							.pipe( gulp.dest( './' ) );

						// Replace Framework PHP class version
						gulp.src( 'includes/classes/class-framework.php' )
							.pipe( replace( /const VERSION = '[\d\.]+';/, sprintf( 'const VERSION = \'%s\';', res.version ) ) )
							.pipe( gulp.dest( './includes/classes' ) );

						// Replace PHP file versions
						gulp.src( '**/*.php' )
							.pipe( replace( /DesignInk\\WordPress\\Framework(\\v[0-9_]+)?/g, sprintf( 'DesignInk\\WordPress\\Framework\\v%s', res.version.replace( /\./g, '_' ) ) ) )
							.pipe( gulp.dest( './' ) );

						cb();

					} else {
						const message = 'Provided version is not newer than the old version.';
						util.log( util.colors.red( message ) );
						cb( new RangeError( message ) );
					}

				} else {
					const message = 'Provided version is not in the correct format.';
					util.log( util.colors.red( message ) );
					cb( new TypeError( message ) );
				}

			}
		) );
}

exports.upgrade_version = upgrade_version;
