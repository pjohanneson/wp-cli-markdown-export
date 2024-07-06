<?php

require 'vendor/autoload.php';
use League\HTMLToMarkdown\HtmlConverter;

$q = new WP_Query([
	'posts_per_page' => 1000,
	'post_type' => [ 'evans_movie', 'page', 'post' ],
]);

$posts = $q->get_posts();

foreach ( $posts as $p ) {
	$process_function = 'process_' . $p->post_type;
	echo "Processing {$p->post_title} ($p->post_type}, ID {$p->ID}..."; 
	$process_function( $p );
	echo "done.\n";
}


function sort_by_post_type( $a, $b ) {
	return strcmp( $a->post_type, $b->post_type );
}

function process_post( $p ) {
	return;
}
function process_page( $p ) {
	return;
}

function process_evans_movie( $p ) {
	// Gets the show year.
	$showtime = get_post_meta( $p->ID, '_evans_showtime' );
	// If that's empty, try the old style.
	if ( empty( $showtime ) ) {
		$showtime = array();
		for ( $i = 1; $i <=3; $i++ ) {
			$showtime[] = get_post_meta( $p->ID, '_evans_showtime' . $i, true );
		}
	}
	// If it's *still* empty, use the publication date.
	if ( empty( $showtime ) ) {
		$showtime = [ strtotime( $p->post_date_gmt ) ];
	}
	$showtime = fix_showtime( $showtime );
	if ( ! is_numeric( $showtime[0] ) && ! is_string( $showtime[0]) ) {
		die( 'Whoops!' . print_r( $showtime, 1 ) );
	}
	$year = date( 'Y', $showtime[0] );
	echo ' Year: ' . $year . '...';

	$dir         = "./movie/{$year}";
	$permissions = 0775;
	$recursive   = true;
	if ( ! file_exists( $dir ) ) {
		mkdir( $dir, $permissions, $recursive );
	}
	$md = fopen( "{$dir}/{$p->post_name}.md", "w" );

	// Front matter.
	$frontmatter = [
		'title'        => $p->post_title,
		'permalink'    => strip_host( get_permalink( $p->ID ) ),
		'showtime'     => format_showtimes( $showtime ),
		'excerpt'      => get_the_excerpt( $p->ID ),
		'tags'         => array( 'movie', 'movie-' . $year ),
		'rating'       => evans_rating( $p->ID ),
		'featured_img' => featured_image( $p->ID ),
		// 'original_featured_img' => featured_image( $p->ID, 'original' ),
		'layout'       => 'movie',
		'links'        => get_movie_links( $p->ID ),
		// In case we missed something.
		'all_meta'     => dedup_meta( get_post_meta( $p->ID ) ),
	];
	$yaml = yaml_emit( $frontmatter );
	// Converts ending '...' to '---'.
	$yaml = str_replace( "\n...\n", "\n---\n", $yaml );
	fwrite( $md, $yaml );
	// Line break.
	fwrite( $md, "\n" );
	fwrite( $md, degutenberg( $p->post_content ) );
	fclose( $md );

}

function fix_showtime( $data ) {
	if ( ! is_array( $data ) ) {
		$data = [ $data ];
	}
	if ( is_array( $data[0] ) ) {
		$data = $data[0];
	}
	sort( $data );
	// Clear out duplicates.
	$data = array_unique( $data );
	// Remove empty items.
	$data = array_filter( $data );
	// Reindex the array.
	$data = array_values( $data );
	return $data;
}

function format_showtimes( $data ) {
	foreach ( $data as $key => $date ) {
		$data[ $key ] = date( 'Y-m-d g:i:s a', $date );
	}
	return $data;
}

function strip_host( $url ) {
	return preg_replace( '|https?://[^/]+/|', '/', $url );
}

function evans_rating( $id ) {
	$_rating = get_post_meta( $id, '_evans_rating', true );
	$rating  = [];
	$keys    = ['rating', 'detail' ];
	foreach( $keys as $key ) {
		$rating[ $key ] = (empty( $_rating[ '_evans_' . $key ] ) ) ? false : $_rating[ '_evans_' . $key ];
	}
	return $rating;
}

function dedup_meta( $data ) {
	foreach ( $data as $key => $item ) {
		$data[ $key ] = array_unique( $item );
	}
	return $data;
}

/**
 * Removes the Gutenberg cruft from our HTML.
 *
 * @param  string $html The HTML to clean.
 * @return string       The cleaned-up HTML.
 */
function degutenberg( $html ) {
	// Removes the <wp:*> tags.
	$html = preg_replace( '|<!-- /?wp:[^>]+>|', '', $html );
	// Cleans up the Gutenberg embed code (we'll be using 11ty embeds).
	$html = preg_replace( '|<figure[^>]+><div[^>]+embed[^>]+>|', '', $html );
	$html = str_replace( '</div></figure>', '', $html );
	// Convert to Markdown.
	$converter = new HtmlConverter(array('header_style'=>'atx'));
	return $converter->convert( $html );
}

function featured_image( $id, $type = 'local' ) {
	if ( has_post_thumbnail( $id ) ) {
		$original_url = get_the_post_thumbnail_url( $id );
		if ( empty( $original_url ) ) {
			return false;
		}
		if ( 'original' === $type ) {
			return $original_url;
		}
		$parsed_url   = explode( '/', $original_url );
		$local_url    = '/images/feature/' . end( $parsed_url );
		return $local_url;
	}
	return false;
}

function get_movie_links( $id ) {
	$links = get_post_meta( $id, '_evans_url' );
	if ( empty( $links ) ) {
		return false;
	}
	if ( is_array( $links ) ) {
		$links = array_unique( $links );
	}
	print_r( $links );
	$the_links = [];
	foreach ( $links as $link ) {
		if ( ! is_array( $link ) ) {
			$the_links[] = [ 'url' => $link, 'text' => 'Official Site' ];
		} else {
			$the_links[] = [ 'url' => $link['_evans_url'], 'text' => $link['_evans_url_name'] ];
		}
	}
	print_r( $the_links );
	return $the_links;

}