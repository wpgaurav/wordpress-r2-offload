<?php
/**
 * Plugin Name: R2 Purge on Attachment Update (MU)
 * Description: Deletes R2-cached images (+ AVIF/WebP variants) when attachments are updated, replaced, or optimized.
 * Author:      Gaurav Tiwari
 * Version:     1.0.0
 * License:     MIT
 *
 * Usage:
 *   1. Drop this file into wp-content/mu-plugins/
 *   2. Define these constants in wp-config.php:
 *      define( 'R2_CDN_HOST', 'r2.example.com' );
 *      define( 'R2_PURGE_SECRET', 'your-purge-secret-here' );
 *
 * WP-CLI usage:
 *   wp r2-purge 1234       # Purge a single attachment
 *   wp r2-purge all        # Purge all image attachments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge R2 keys via the Worker's PURGE endpoint.
 *
 * @param string[] $keys R2 object keys to delete.
 */
function r2_purge_keys( $keys ) {
	if ( empty( $keys ) ) {
		return;
	}

	$cdn_host = defined( 'R2_CDN_HOST' ) ? R2_CDN_HOST : '';
	$secret   = defined( 'R2_PURGE_SECRET' ) ? R2_PURGE_SECRET : '';

	if ( '' === $cdn_host || '' === $secret ) {
		return;
	}

	$url = 'https://' . $cdn_host . '/_purge';

	wp_remote_request( $url, array(
		'method'  => 'PURGE',
		'timeout' => 10,
		'headers' => array(
			'Authorization' => 'Bearer ' . $secret,
			'Content-Type'  => 'application/json',
		),
		'body'    => wp_json_encode( array( 'keys' => array_values( $keys ) ) ),
	) );
}

/**
 * Build the list of R2 keys for an attachment: original + all sizes + AVIF/WebP variants.
 *
 * @param int        $attachment_id
 * @param array|null $metadata      wp_get_attachment_metadata() result.
 * @return string[]
 */
function r2_get_attachment_keys( $attachment_id, $metadata = null ) {
	if ( null === $metadata ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );
	}

	if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
		return array();
	}

	$upload_dir = wp_get_upload_dir();
	$base_dir   = ltrim( str_replace( ABSPATH, '', $upload_dir['basedir'] ), '/' );
	$dir        = dirname( $metadata['file'] );

	$files = array();

	// Original file.
	$files[] = $base_dir . '/' . $metadata['file'];

	// All registered sizes.
	if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
		foreach ( $metadata['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $base_dir . '/' . $dir . '/' . $size['file'];
			}
		}
	}

	// Add AVIF and WebP variants for each file.
	$keys         = array();
	$variant_exts = array( 'avif', 'webp' );
	$image_exts   = array( 'jpg', 'jpeg', 'png' );

	foreach ( $files as $file ) {
		$keys[] = $file;

		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, $image_exts, true ) ) {
			$without_ext = preg_replace( '/\.[^.]+$/', '', $file );
			foreach ( $variant_exts as $variant ) {
				$keys[] = $without_ext . '.' . $variant;
			}
		}
	}

	return array_unique( $keys );
}

/**
 * Fires after attachment metadata is updated (upload, re-upload, optimization, regenerate).
 */
function r2_purge_on_metadata_update( $metadata, $attachment_id ) {
	$keys = r2_get_attachment_keys( $attachment_id, $metadata );
	r2_purge_keys( $keys );
	return $metadata;
}
add_filter( 'wp_update_attachment_metadata', 'r2_purge_on_metadata_update', 99, 2 );

/**
 * Fires when an attachment is deleted.
 */
function r2_purge_on_delete( $attachment_id ) {
	$keys = r2_get_attachment_keys( $attachment_id );
	r2_purge_keys( $keys );
}
add_action( 'delete_attachment', 'r2_purge_on_delete' );

/**
 * WP-CLI command: wp r2-purge <attachment_id|all>
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'r2-purge', function ( $args ) {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Usage: wp r2-purge <attachment_id|all>' );
		}

		if ( 'all' === $args[0] ) {
			$ids = get_posts( array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );

			WP_CLI::log( sprintf( 'Purging %d image attachments...', count( $ids ) ) );

			$total_keys = 0;
			foreach ( $ids as $id ) {
				$keys = r2_get_attachment_keys( $id );
				r2_purge_keys( $keys );
				$total_keys += count( $keys );
			}

			WP_CLI::success( sprintf( 'Purged %d R2 keys across %d attachments.', $total_keys, count( $ids ) ) );
			return;
		}

		$id   = (int) $args[0];
		$keys = r2_get_attachment_keys( $id );

		if ( empty( $keys ) ) {
			WP_CLI::warning( 'No keys found for attachment #' . $id );
			return;
		}

		r2_purge_keys( $keys );
		WP_CLI::success( sprintf( 'Purged %d R2 keys for attachment #%d:', count( $keys ), $id ) );
		foreach ( $keys as $k ) {
			WP_CLI::log( '  - ' . $k );
		}
	} );
}
