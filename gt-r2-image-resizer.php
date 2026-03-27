<?php
/**
 * Plugin Name: R2 On-Demand Image Resizer (MU)
 * Description: Generates resized image variants on-demand when the R2 Worker requests them.
 * Author:      Gaurav Tiwari
 * Version:     1.0.0
 * License:     MIT
 *
 * Usage:
 *   1. Drop this file into wp-content/mu-plugins/
 *   2. Define the shared secret in wp-config.php:
 *      define( 'GT_R2_RESIZE_SECRET', 'your-shared-secret-here' );
 *   3. Define the R2 CDN base URL:
 *      define( 'R2_CDN_BASE', 'https://r2.example.com' );
 *
 * The Worker sends resize requests to:
 *   https://example.com/?gt_r2_resize=1&path=wp-content/uploads/2024/01/photo.jpg&w=800&h=600
 *
 * The shared secret is sent in the X-Resize-Secret header to prevent abuse.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maximum width/height we'll generate. Must match the Worker's MAX_DIMENSION.
 */
if ( ! defined( 'GT_R2_MAX_DIMENSION' ) ) {
	define( 'GT_R2_MAX_DIMENSION', 2560 );
}

/**
 * Allowed output MIME types keyed by extension.
 */
function gt_r2_allowed_mime_types() {
	return array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
		'bmp'  => 'image/bmp',
		'tiff' => 'image/tiff',
		'tif'  => 'image/tiff',
	);
}

/**
 * Output quality by format.
 */
function gt_r2_output_quality( $ext ) {
	$qualities = array(
		'webp' => 82,
		'avif' => 72,
		'jpg'  => 82,
		'jpeg' => 82,
		'png'  => 9, // PNG compression level (0-9).
		'gif'  => 90,
		'bmp'  => 90,
		'tiff' => 90,
		'tif'  => 90,
	);

	return isset( $qualities[ $ext ] ) ? $qualities[ $ext ] : 82;
}

/**
 * Intercept resize requests early in the WordPress lifecycle.
 */
add_action( 'init', function () {
	if ( empty( $_GET['gt_r2_resize'] ) ) {
		return;
	}

	gt_r2_direct_resize_handler();
	exit;
} );

/**
 * Direct resize handler — outputs binary image, no REST wrapper.
 */
function gt_r2_direct_resize_handler() {
	// Verify shared secret.
	$expected = defined( 'GT_R2_RESIZE_SECRET' ) ? GT_R2_RESIZE_SECRET : '';
	$received = isset( $_SERVER['HTTP_X_RESIZE_SECRET'] ) ? $_SERVER['HTTP_X_RESIZE_SECRET'] : '';

	if ( '' === $expected || ! hash_equals( $expected, $received ) ) {
		status_header( 403 );
		echo 'Forbidden';
		return;
	}

	$path   = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
	$width  = isset( $_GET['w'] ) ? absint( $_GET['w'] ) : 0;
	$height = isset( $_GET['h'] ) ? absint( $_GET['h'] ) : 0;

	$path = ltrim( $path, '/' );

	if ( empty( $path ) || $width < 1 || $height < 1 || $width > GT_R2_MAX_DIMENSION || $height > GT_R2_MAX_DIMENSION ) {
		status_header( 400 );
		echo 'Invalid parameters';
		return;
	}

	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	$allowed_mimes = gt_r2_allowed_mime_types();

	if ( ! isset( $allowed_mimes[ $ext ] ) ) {
		status_header( 400 );
		echo 'Unsupported image type';
		return;
	}

	$output_mime = $allowed_mimes[ $ext ];

	// Try local file first, then download from R2.
	$local_file = gt_r2_resolve_local_path( $path );
	$is_temp    = false;

	if ( ! $local_file ) {
		$local_file = gt_r2_download_from_r2( $path );
		$is_temp    = true;
	}

	if ( ! $local_file || ! file_exists( $local_file ) ) {
		status_header( 404 );
		echo 'Original not found';
		return;
	}

	// Use WordPress image editor (GD or Imagick) to resize.
	$editor = wp_get_image_editor( $local_file );
	if ( is_wp_error( $editor ) ) {
		if ( $is_temp ) {
			@unlink( $local_file );
		}
		status_header( 500 );
		echo 'Editor error: ' . $editor->get_error_message();
		return;
	}

	$resized = $editor->resize( $width, $height, true );
	if ( is_wp_error( $resized ) ) {
		if ( $is_temp ) {
			@unlink( $local_file );
		}
		status_header( 500 );
		echo 'Resize error: ' . $resized->get_error_message();
		return;
	}

	$editor->set_quality( gt_r2_output_quality( $ext ) );

	$tmp_dir  = get_temp_dir();
	$tmp_name = wp_unique_filename( $tmp_dir, wp_basename( $path ) );
	$tmp_path = trailingslashit( $tmp_dir ) . $tmp_name;

	$saved = $editor->save( $tmp_path, $output_mime );
	if ( $is_temp ) {
		@unlink( $local_file );
	}

	if ( is_wp_error( $saved ) ) {
		status_header( 500 );
		echo 'Save error: ' . $saved->get_error_message();
		return;
	}

	$saved_path = $saved['path'];

	// Stream the resized image back to the Worker.
	status_header( 200 );
	header( 'Content-Type: ' . $output_mime );
	header( 'Content-Length: ' . filesize( $saved_path ) );
	header( 'Cache-Control: no-store' );
	header( 'X-Resized-By: gt-r2-image-resizer' );

	readfile( $saved_path );
	@unlink( $saved_path );
}

/**
 * Try to resolve an R2 key to a local file path.
 *
 * R2 keys map like: wp-content/uploads/2024/01/photo.jpg → ABSPATH/wp-content/uploads/2024/01/photo.jpg
 */
function gt_r2_resolve_local_path( $key ) {
	$key = ltrim( $key, '/' );

	// Direct ABSPATH mapping.
	$absolute = ABSPATH . $key;
	if ( file_exists( $absolute ) ) {
		return $absolute;
	}

	// Try with uploads basedir.
	if ( 0 === strpos( $key, 'wp-content/uploads/' ) ) {
		$upload_dir = wp_upload_dir();
		$relative   = substr( $key, strlen( 'wp-content/uploads/' ) );
		$full_path  = trailingslashit( $upload_dir['basedir'] ) . $relative;

		if ( file_exists( $full_path ) ) {
			return $full_path;
		}
	}

	return false;
}

/**
 * Download an image from R2 to a temp file (fallback when the local file doesn't exist).
 */
function gt_r2_download_from_r2( $key ) {
	$cdn_base = defined( 'R2_CDN_BASE' ) ? rtrim( R2_CDN_BASE, '/' ) : '';

	if ( '' === $cdn_base ) {
		return false;
	}

	$r2_url = $cdn_base . '/' . ltrim( $key, '/' );

	$response = wp_remote_get( $r2_url, array(
		'timeout'   => 30,
		'sslverify' => true,
	) );

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return false;
	}

	$body = wp_remote_retrieve_body( $response );
	if ( empty( $body ) ) {
		return false;
	}

	$tmp_dir  = get_temp_dir();
	$tmp_name = wp_unique_filename( $tmp_dir, wp_basename( $key ) );
	$tmp_path = trailingslashit( $tmp_dir ) . $tmp_name;

	if ( false === file_put_contents( $tmp_path, $body ) ) {
		return false;
	}

	return $tmp_path;
}
