<?php
/**
 * Plugin Name: R2 CDN URL Rewriter (MU)
 * Description: Rewrites local static asset URLs to your R2 CDN subdomain.
 * Author:      Gaurav Tiwari
 * Version:     1.1.0
 * License:     MIT
 *
 * Usage:
 *   1. Drop this file into wp-content/mu-plugins/
 *   2. Define the CDN base URL in wp-config.php:
 *      define( 'R2_CDN_BASE', 'https://r2.example.com' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CDN base URL — set in wp-config.php via:
 *   define( 'R2_CDN_BASE', 'https://r2.yourdomain.com' );
 */
function gt_r2_cdn_base() {
	return defined( 'R2_CDN_BASE' ) ? rtrim( R2_CDN_BASE, '/' ) : '';
}

/**
 * Check if the current request is for the web app manifest (should not be rewritten).
 */
function gt_r2_cdn_is_manifest_request() {
	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return false;
	}

	$request_uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
	$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

	if ( ! is_string( $path ) || '' === $path ) {
		return false;
	}

	return 'manifest.webmanifest' === strtolower( wp_basename( $path ) );
}

/**
 * Determine whether a given path should be rewritten to the CDN.
 */
function gt_r2_cdn_is_allowed_path( $path ) {
	$path = (string) $path;

	if ( '' === $path ) {
		return false;
	}

	// Never rewrite the web app manifest.
	if ( 'manifest.webmanifest' === strtolower( wp_basename( $path ) ) ) {
		return false;
	}

	// Never rewrite page cache files.
	if ( 0 === strpos( ltrim( strtolower( $path ), '/' ), 'wp-content/cache/' ) ) {
		return false;
	}

	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( '' === $ext ) {
		return false;
	}

	// Never rewrite dynamic files.
	if ( in_array( $ext, array( 'php', 'html', 'htm' ), true ) ) {
		return false;
	}

	$allowed = array(
		// Images
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff',
		// Stylesheets and scripts
		'css', 'js', 'mjs', 'map',
		// Fonts
		'woff', 'woff2', 'ttf', 'otf', 'eot',
		// Media
		'mp4', 'webm', 'ogg', 'ogv', 'mp3', 'wav',
		// Documents
		'pdf', 'txt', 'csv',
	);

	return in_array( $ext, $allowed, true );
}

/**
 * Rewrite a single URL to the CDN.
 */
function gt_r2_cdn_rewrite_url( $url ) {
	$cdn_base = gt_r2_cdn_base();

	if ( '' === $cdn_base ) {
		return $url;
	}

	if ( gt_r2_cdn_is_manifest_request() ) {
		return $url;
	}

	if ( ! is_string( $url ) || '' === $url ) {
		return $url;
	}

	// Already rewritten.
	if ( 0 === strpos( $url, $cdn_base ) ) {
		return $url;
	}

	$parts = wp_parse_url( $url );
	if ( false === $parts || empty( $parts['path'] ) ) {
		return $url;
	}

	if ( ! gt_r2_cdn_is_allowed_path( $parts['path'] ) ) {
		return $url;
	}

	// Only rewrite URLs that belong to this site.
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	$url_host  = isset( $parts['host'] ) ? $parts['host'] : '';

	if ( '' !== $url_host && $site_host && strtolower( $url_host ) !== strtolower( $site_host ) ) {
		return $url;
	}

	$rebuilt = $cdn_base . $parts['path'];

	if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
		$rebuilt .= '?' . $parts['query'];
	}

	if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
		$rebuilt .= '#' . $parts['fragment'];
	}

	return $rebuilt;
}

/**
 * Rewrite all matching URLs inside a block of HTML content.
 */
function gt_r2_cdn_rewrite_content_urls( $content ) {
	if ( gt_r2_cdn_is_manifest_request() ) {
		return $content;
	}

	if ( ! is_string( $content ) || '' === $content ) {
		return $content;
	}

	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! $site_host ) {
		return $content;
	}

	$pattern = '#https?://' . preg_quote( $site_host, '#' ) . '[^\s"\'\)<>]+#i';

	return preg_replace_callback(
		$pattern,
		static function ( $matches ) {
			return gt_r2_cdn_rewrite_url( $matches[0] );
		},
		$content
	);
}

/**
 * Rewrite srcset sources array.
 */
function gt_r2_cdn_rewrite_srcset( $sources ) {
	if ( gt_r2_cdn_is_manifest_request() ) {
		return $sources;
	}

	if ( ! is_array( $sources ) ) {
		return $sources;
	}

	foreach ( $sources as $key => $source ) {
		if ( isset( $source['url'] ) ) {
			$sources[ $key ]['url'] = gt_r2_cdn_rewrite_url( $source['url'] );
		}
	}

	return $sources;
}

/**
 * Rewrite preload/resource-hint arrays.
 */
function gt_r2_cdn_rewrite_resource_array( $urls ) {
	if ( gt_r2_cdn_is_manifest_request() ) {
		return $urls;
	}

	if ( ! is_array( $urls ) ) {
		return $urls;
	}

	foreach ( $urls as $key => $entry ) {
		if ( is_array( $entry ) && isset( $entry['href'] ) ) {
			$urls[ $key ]['href'] = gt_r2_cdn_rewrite_url( $entry['href'] );
			continue;
		}

		if ( is_string( $entry ) ) {
			$urls[ $key ] = gt_r2_cdn_rewrite_url( $entry );
		}
	}

	return $urls;
}

/**
 * Final pass: rewrite any remaining URLs in the full HTML output buffer.
 */
function gt_r2_cdn_rewrite_final_html( $html ) {
	if ( gt_r2_cdn_is_manifest_request() ) {
		return $html;
	}

	if ( ! is_string( $html ) || '' === $html ) {
		return $html;
	}

	return gt_r2_cdn_rewrite_content_urls( $html );
}

/**
 * Start output buffering on front-end requests.
 */
function gt_r2_cdn_start_output_buffer() {
	if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
		return;
	}

	ob_start( 'gt_r2_cdn_rewrite_final_html' );
}

// ── Hook into WordPress ──────────────────────────────────────────

// Individual URL filters (fast path for enqueued assets).
add_filter( 'wp_get_attachment_url', 'gt_r2_cdn_rewrite_url', 99 );
add_filter( 'script_loader_src', 'gt_r2_cdn_rewrite_url', 99 );
add_filter( 'style_loader_src', 'gt_r2_cdn_rewrite_url', 99 );
add_filter( 'content_url', 'gt_r2_cdn_rewrite_url', 99 );
add_filter( 'plugins_url', 'gt_r2_cdn_rewrite_url', 99 );
add_filter( 'theme_file_uri', 'gt_r2_cdn_rewrite_url', 99 );
add_filter( 'wp_calculate_image_srcset', 'gt_r2_cdn_rewrite_srcset', 99 );
add_filter( 'wp_preload_resources', 'gt_r2_cdn_rewrite_resource_array', 99 );
add_filter( 'wp_resource_hints', 'gt_r2_cdn_rewrite_resource_array', 99 );

// HTML content filters (catch inline URLs in rendered blocks/widgets).
add_filter( 'script_loader_tag', 'gt_r2_cdn_rewrite_content_urls', 99 );
add_filter( 'style_loader_tag', 'gt_r2_cdn_rewrite_content_urls', 99 );
add_filter( 'the_content', 'gt_r2_cdn_rewrite_content_urls', 99 );
add_filter( 'widget_text', 'gt_r2_cdn_rewrite_content_urls', 99 );
add_filter( 'widget_text_content', 'gt_r2_cdn_rewrite_content_urls', 99 );
add_filter( 'render_block', 'gt_r2_cdn_rewrite_content_urls', 99 );

// Output buffer: catch anything the filters above miss.
add_action( 'template_redirect', 'gt_r2_cdn_start_output_buffer', 0 );
