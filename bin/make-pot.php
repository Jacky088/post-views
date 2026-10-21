<?php
/**
 * Build languages/post-views.pot from the plugin sources.
 *
 * There is no Composer/wp-cli toolchain in this repository, so this script is
 * the whole extraction pipeline: it walks the PHP files, finds the gettext
 * calls, and writes a POT with the same layout wp i18n make-pot produces.
 *
 * Usage: php bin/make-pot.php
 *
 * @package Post-Views
 */

// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment, WordPress.WP.I18n.NonSingularStringLiteralText

$root = dirname( __DIR__ );

$skip_dirs = array( '.git', 'node_modules', 'vendor', 'languages', 'bin', 'tests' );

$entries = array();

/**
 * Read a quoted PHP string literal starting at $i.
 *
 * @param string $source Source text.
 * @param int    $i      Offset, advanced past the closing quote.
 * @return string|null
 */
function pv_pot_read_string( $source, &$i ) {
	$length = strlen( $source );

	while ( $i < $length && ctype_space( $source[ $i ] ) ) {
		$i++;
	}

	if ( $i >= $length || ( "'" !== $source[ $i ] && '"' !== $source[ $i ] ) ) {
		return null;
	}

	$quote = $source[ $i ];
	$i++;
	$out = '';

	while ( $i < $length ) {
		$char = $source[ $i ];

		if ( '\\' === $char && $i + 1 < $length ) {
			$next = $source[ $i + 1 ];
			$map  = array(
				'n'  => "\n",
				't'  => "\t",
				'r'  => "\r",
				'\\' => '\\',
				"'"  => "'",
				'"'  => '"',
			);
			$out .= isset( $map[ $next ] ) ? $map[ $next ] : $next;
			$i   += 2;
			continue;
		}

		if ( $char === $quote ) {
			$i++;
			return $out;
		}

		$out .= $char;
		$i++;
	}

	return null;
}

/**
 * Skip to the next top-level comma (or the closing parenthesis).
 *
 * @param string $source Source text.
 * @param int    $i      Offset, left on the comma/parenthesis.
 * @return void
 */
function pv_pot_skip_arg( $source, &$i ) {
	$length = strlen( $source );
	$depth  = 0;
	$quote  = '';

	while ( $i < $length ) {
		$char = $source[ $i ];

		if ( '' !== $quote ) {
			if ( '\\' === $char ) {
				$i += 2;
				continue;
			}
			if ( $char === $quote ) {
				$quote = '';
			}
			$i++;
			continue;
		}

		if ( "'" === $char || '"' === $char ) {
			$quote = $char;
			$i++;
			continue;
		}

		if ( '(' === $char || '[' === $char || '{' === $char ) {
			$depth++;
			$i++;
			continue;
		}

		if ( ')' === $char || ']' === $char || '}' === $char ) {
			if ( 0 === $depth ) {
				return;
			}
			$depth--;
			$i++;
			continue;
		}

		if ( ',' === $char && 0 === $depth ) {
			return;
		}

		$i++;
	}
}

/**
 * Collect one string argument, advancing to the next argument.
 *
 * @param string $source Source text.
 * @param int    $i      Offset.
 * @return string|null
 */
function pv_pot_next_string( $source, &$i ) {
	$value = pv_pot_read_string( $source, $i );
	pv_pot_skip_arg( $source, $i );

	if ( ',' === substr( $source, $i, 1 ) ) {
		$i++;
	}

	return $value;
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator(
		$root,
		FilesystemIterator::SKIP_DOTS
	)
);

foreach ( $iterator as $file ) {
	$path = $file->getPathname();

	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	$relative = str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) );
	$segments = explode( '/', $relative );
	if ( array_intersect( $segments, $skip_dirs ) ) {
		continue;
	}

	$source = file_get_contents( $path );

	if ( preg_match_all( '/(?<![\w>])(__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_n|_x|_nx)\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches[1] as $index => $match ) {
			$function = $match[0];
			$offset   = $matches[0][ $index ][1];
			$cursor   = $offset + strlen( $matches[0][ $index ][0] );

			$msgid     = '';
			$plural    = '';
			$context   = '';

			if ( '_x' === $function || '_nx' === $function ) {
				$context = (string) pv_pot_next_string( $source, $cursor );
			}

			$msgid = (string) pv_pot_next_string( $source, $cursor );

			if ( '_n' === $function || '_nx' === $function ) {
				$plural = (string) pv_pot_next_string( $source, $cursor );
			}

			if ( '' === $msgid ) {
				continue;
			}

			$key = $context . "\x04" . $msgid;

			if ( ! isset( $entries[ $key ] ) ) {
				$entries[ $key ] = array(
					'context' => $context,
					'msgid'   => $msgid,
					'plural'  => $plural,
					'refs'    => array(),
				);
			}

			$line = substr_count( substr( $source, 0, $offset ), "\n" ) + 1;
			$ref  = $relative . ':' . $line;

			if ( ! in_array( $ref, $entries[ $key ]['refs'], true ) ) {
				$entries[ $key ]['refs'][] = $ref;
			}
		}
	}
}

/**
 * Escape a string for a PO file.
 *
 * @param string $value Raw value.
 * @return string
 */
function pv_pot_escape( $value ) {
	return str_replace(
		array( '\\', '"', "\n", "\t" ),
		array( '\\\\', '\\"', '\\n', '\\t' ),
		$value
	);
}

usort(
	$entries,
	function ( $a, $b ) {
		return strcmp( $a['msgid'], $b['msgid'] );
	}
);

$output  = "# Copyright (C) 2026 Post Views\n";
$output .= "# This file is distributed under the GPLv2 or later.\n";
$output .= "msgid \"\"\n";
$output .= "msgstr \"\"\n";
$output .= "\"Project-Id-Version: Post Views 2.1.1\\n\"\n";
$output .= "\"Report-Msgid-Bugs-To: https://github.com/Jacky088/post-views\\n\"\n";
$output .= "\"POT-Creation-Date: " . gmdate( 'Y-m-d H:i+0000' ) . "\\n\"\n";
$output .= "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n";
$output .= "\"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n\"\n";
$output .= "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n";
$output .= "\"Language: \\n\"\n";
$output .= "\"MIME-Version: 1.0\\n\"\n";
$output .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$output .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$output .= "\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n";
$output .= "\"X-Generator: bin/make-pot.php\\n\"\n\n";

foreach ( $entries as $entry ) {
	$output .= '#: ' . implode( ' ', $entry['refs'] ) . "\n";

	if ( '' !== $entry['context'] ) {
		$output .= 'msgctxt "' . pv_pot_escape( $entry['context'] ) . "\"\n";
	}

	$output .= 'msgid "' . pv_pot_escape( $entry['msgid'] ) . "\"\n";

	if ( '' !== $entry['plural'] ) {
		$output .= 'msgid_plural "' . pv_pot_escape( $entry['plural'] ) . "\"\n";
		$output .= "msgstr[0] \"\"\n";
		$output .= "msgstr[1] \"\"\n";
	} else {
		$output .= "msgstr \"\"\n";
	}

	$output .= "\n";
}

$target_dir = $root . '/languages';

if ( ! is_dir( $target_dir ) ) {
	mkdir( $target_dir, 0777, true );
}

file_put_contents( $target_dir . '/post-views.pot', $output );

echo 'Wrote ' . count( $entries ) . " strings to languages/post-views.pot\n";
