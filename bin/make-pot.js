#!/usr/bin/env node
/**
 * Generate languages/blt-activity-log.pot from the plugin's PHP and JS sources.
 *
 * A dependency-free stand-in for `wp i18n make-pot`, so the template can be
 * regenerated (and checked in CI) without WP-CLI or PHP installed. Handles
 * __, _e, esc_html__, esc_html_e, esc_attr__, esc_attr_e, _x, _ex,
 * esc_attr_x, esc_html_x, _n, _nx, _n_noop, _nx_noop with the blt-activity-log
 * text domain, and picks up "translators:" comments placed before a call.
 *
 * Usage: node bin/make-pot.js
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const DOMAIN = 'blt-activity-log';
const OUT = path.join( ROOT, 'languages', 'blt-activity-log.pot' );

const SKIP_DIRS = new Set( [ '.git', 'node_modules', 'vendor', 'build', 'tests', 'languages' ] );
const SKIP_PATHS = [ 'includes/lib/', 'includes/blt-family/', 'blt-family-smoke.php', 'bin/' ];

// function name -> argument roles (positions are 0-based).
const SIGNATURES = {
	__: { text: 0, domain: 1 },
	_e: { text: 0, domain: 1 },
	esc_html__: { text: 0, domain: 1 },
	esc_html_e: { text: 0, domain: 1 },
	esc_attr__: { text: 0, domain: 1 },
	esc_attr_e: { text: 0, domain: 1 },
	_x: { text: 0, context: 1, domain: 2 },
	_ex: { text: 0, context: 1, domain: 2 },
	esc_attr_x: { text: 0, context: 1, domain: 2 },
	esc_html_x: { text: 0, context: 1, domain: 2 },
	_n: { text: 0, plural: 1, domain: 3 },
	_nx: { text: 0, plural: 1, context: 3, domain: 4 },
	_n_noop: { text: 0, plural: 1, domain: 2 },
	_nx_noop: { text: 0, plural: 1, context: 2, domain: 3 },
};

function walk( dir, files ) {
	for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		if ( entry.isDirectory() ) {
			if ( SKIP_DIRS.has( entry.name ) ) {
				continue;
			}
			walk( path.join( dir, entry.name ), files );
			continue;
		}
		const full = path.join( dir, entry.name );
		const rel = path.relative( ROOT, full ).split( path.sep ).join( '/' );
		if ( SKIP_PATHS.some( ( p ) => rel.startsWith( p ) ) ) {
			continue;
		}
		if ( /\.(php|js)$/.test( entry.name ) ) {
			files.push( { full, rel } );
		}
	}
	return files;
}

/**
 * Parse the argument list starting right after the opening parenthesis.
 * Returns { args: [ { value, isString } ], end } or null when a string
 * argument is not a plain literal (concatenation, variables, ...).
 */
function parseArgs( src, start ) {
	const args = [];
	let i = start;
	let depth = 0;
	let current = { text: '', isString: false, literal: '' , parts: 0 };

	const push = () => {
		args.push( { value: current.literal, isString: current.isString && current.parts === 1 && current.text.trim() === '' } );
		current = { text: '', isString: false, literal: '', parts: 0 };
	};

	while ( i < src.length ) {
		const ch = src[ i ];

		if ( ch === "'" || ch === '"' ) {
			const quote = ch;
			let j = i + 1;
			let out = '';
			while ( j < src.length && src[ j ] !== quote ) {
				if ( src[ j ] === '\\' && j + 1 < src.length ) {
					const next = src[ j + 1 ];
					if ( quote === "'" ) {
						out += next === "'" || next === '\\' ? next : '\\' + next;
					} else {
						const map = { n: '\n', t: '\t', r: '\r', '"': '"', '\\': '\\', $: '$' };
						out += Object.prototype.hasOwnProperty.call( map, next ) ? map[ next ] : '\\' + next;
					}
					j += 2;
					continue;
				}
				out += src[ j ];
				j++;
			}
			current.literal += out;
			current.isString = true;
			current.parts++;
			i = j + 1;
			continue;
		}

		if ( ch === '(' || ch === '[' || ch === '{' ) {
			depth++;
			current.text += ch;
		} else if ( ch === ')' || ch === ']' || ch === '}' ) {
			if ( depth === 0 ) {
				push();
				return { args, end: i };
			}
			depth--;
			current.text += ch;
		} else if ( ch === ',' && depth === 0 ) {
			push();
		} else if ( ! /\s/.test( ch ) ) {
			current.text += ch;
		}
		i++;
	}
	return null;
}

function translatorsComment( src, callIndex ) {
	// Look back up to ~400 chars for the nearest /* translators: ... */ comment
	// that is not followed by another statement before the call.
	const before = src.slice( Math.max( 0, callIndex - 400 ), callIndex );
	// The comment body may not contain "*/", so an earlier comment can never
	// swallow the code between it and this call.
	const match = before.match( /\/\*\s*translators:\s*((?:(?!\*\/)[\s\S])*?)\*\/\s*(?:\/\/[^\n]*\s*)?$/i );
	if ( ! match ) {
		return '';
	}
	return match[ 1 ].replace( /\s+/g, ' ' ).trim();
}

function lineOf( src, index ) {
	let line = 1;
	for ( let i = 0; i < index; i++ ) {
		if ( src.charCodeAt( i ) === 10 ) {
			line++;
		}
	}
	return line;
}

const entries = new Map();

function addEntry( { text, plural, context, comment, ref } ) {
	const key = `${ context || '' }${ text }`;
	if ( ! entries.has( key ) ) {
		entries.set( key, { text, plural: plural || '', context: context || '', comments: new Set(), refs: new Set() } );
	}
	const entry = entries.get( key );
	if ( plural && ! entry.plural ) {
		entry.plural = plural;
	}
	if ( comment ) {
		entry.comments.add( comment );
	}
	entry.refs.add( ref );
}

const callRegex = /(?<![A-Za-z0-9_$.])(__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_ex|esc_attr_x|esc_html_x|_n|_nx|_n_noop|_nx_noop)\s*\(/g;

for ( const file of walk( ROOT, [] ) ) {
	const src = fs.readFileSync( file.full, 'utf8' );
	let match;
	callRegex.lastIndex = 0;
	while ( ( match = callRegex.exec( src ) ) !== null ) {
		const fn = match[ 1 ];
		const sig = SIGNATURES[ fn ];
		const parsed = parseArgs( src, match.index + match[ 0 ].length );
		if ( ! parsed ) {
			continue;
		}
		const { args } = parsed;
		const domainArg = args[ sig.domain ];
		if ( ! domainArg || ! domainArg.isString || domainArg.value !== DOMAIN ) {
			continue;
		}
		const textArg = args[ sig.text ];
		if ( ! textArg || ! textArg.isString || textArg.value === '' ) {
			continue;
		}
		const pluralArg = sig.plural !== undefined ? args[ sig.plural ] : null;
		const contextArg = sig.context !== undefined ? args[ sig.context ] : null;
		if ( ( pluralArg && ! pluralArg.isString ) || ( contextArg && ! contextArg.isString ) ) {
			continue;
		}

		addEntry( {
			text: textArg.value,
			plural: pluralArg ? pluralArg.value : '',
			context: contextArg ? contextArg.value : '',
			comment: translatorsComment( src, match.index ),
			ref: `${ file.rel }:${ lineOf( src, match.index ) }`,
		} );
	}
}

function po( str ) {
	const escaped = String( str )
		.replace( /\\/g, '\\\\' )
		.replace( /"/g, '\\"' )
		.replace( /\t/g, '\\t' );
	if ( ! escaped.includes( '\n' ) ) {
		return `"${ escaped }"`;
	}
	const lines = escaped.split( '\n' );
	const last = lines.pop();
	let out = '""\n';
	for ( const line of lines ) {
		out += `"${ line }\\n"\n`;
	}
	if ( last !== '' ) {
		out += `"${ last }"`;
	} else {
		out = out.trimEnd();
	}
	return out;
}

const pluginFile = fs.readFileSync( path.join( ROOT, 'blt-activity-log.php' ), 'utf8' );
const version = ( pluginFile.match( /^\s*\*\s*Version:\s*([^\s]+)/m ) || [ , '0.0.0' ] )[ 1 ];
const now = new Date().toISOString().replace( /\.\d{3}Z$/, '+0000' ).replace( 'T', ' ' ).replace( /:\d{2}\+0000$/, '+0000' );

let out = `# Copyright (C) ${ new Date().getFullYear() } S-FX.com
# This file is distributed under the GPL-2.0-or-later.
msgid ""
msgstr ""
"Project-Id-Version: BLT Activity Log ${ version }\\n"
"Report-Msgid-Bugs-To: https://github.com/S-FX-com/BLT-Activity-Log/issues\\n"
"POT-Creation-Date: ${ now }\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"
"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"
"Language-Team: LANGUAGE <LL@li.org>\\n"
"X-Generator: bin/make-pot.js\\n"
"X-Domain: ${ DOMAIN }\\n"

`;

const sorted = [ ...entries.values() ].sort( ( a, b ) => {
	const ra = [ ...a.refs ][ 0 ];
	const rb = [ ...b.refs ][ 0 ];
	return ra.localeCompare( rb ) || a.text.localeCompare( b.text );
} );

for ( const entry of sorted ) {
	for ( const comment of entry.comments ) {
		out += `#. translators: ${ comment }\n`;
	}
	for ( const ref of entry.refs ) {
		out += `#: ${ ref }\n`;
	}
	if ( entry.context ) {
		out += `msgctxt ${ po( entry.context ) }\n`;
	}
	out += `msgid ${ po( entry.text ) }\n`;
	if ( entry.plural ) {
		out += `msgid_plural ${ po( entry.plural ) }\n`;
		out += 'msgstr[0] ""\nmsgstr[1] ""\n\n';
	} else {
		out += 'msgstr ""\n\n';
	}
}

fs.mkdirSync( path.dirname( OUT ), { recursive: true } );
fs.writeFileSync( OUT, out.trimEnd() + '\n' );
console.log( `Wrote ${ entries.size } strings to ${ path.relative( ROOT, OUT ) }` );
