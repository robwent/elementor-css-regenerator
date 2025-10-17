<?php
/**
 * Plugin Name: Elementor CSS Regenerator
 * Plugin URI: https://robertwent.com
 * Description: Automatically regenerates and serves missing Elementor CSS files on-demand when they're requested from cached pages. Prevents broken styling after Elementor updates clear CSS cache.
 * Version: 1.0.0
 * Author: Robert Went
 * Author URI: https://robertwent.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class
 */
class Elementor_CSS_Regenerator {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'intercept_css_404' ), 1 );
	}

	/**
	 * Intercept 404 requests for Elementor CSS files
	 */
	public function intercept_css_404() {
		// Only proceed if this is a 404
		if ( ! is_404() ) {
			return;
		}

		// Check if Elementor is active
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		// Get the requested URI
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';

		// Check if this is an Elementor CSS file request
		if ( ! $this->is_elementor_css_request( $request_uri ) ) {
			return;
		}

		// Parse the CSS file details
		$css_details = $this->parse_css_filename( $request_uri );

		if ( ! $css_details ) {
			return;
		}

		// Debug logging
		$this->debug_log( sprintf( 'Processing CSS request: %s-%d.css', $css_details['type'], $css_details['id'] ) );

		// Try to regenerate and serve the CSS file
		$this->regenerate_and_serve_css( $css_details );
	}

	/**
	 * Check if the request is for an Elementor CSS file
	 *
	 * @param string $uri The request URI
	 * @return bool
	 */
	private function is_elementor_css_request( $uri ) {
		// Fast string check to eliminate 99.9% of 404s instantly
		if ( strpos( $uri, '/elementor/css/' ) === false ) {
			return false;
		}

		// Extract filename and remove query string
		$filename = basename( strtok( $uri, '?' ) );

		// Minimal regex on just the filename (faster than full path regex)
		return preg_match( '/^(post|loop)-(\d+)\.css$/', $filename );
	}

	/**
	 * Parse the CSS filename to extract ID and type
	 *
	 * @param string $uri The request URI
	 * @return array|false Array with 'type' and 'id', or false on failure
	 */
	private function parse_css_filename( $uri ) {
		// Extract filename and remove query string
		$filename = basename( strtok( $uri, '?' ) );

		// Match patterns like: post-123.css, loop-456.css
		if ( preg_match( '/^(post|loop)-(\d+)\.css$/', $filename, $matches ) ) {
			return array(
				'type' => $matches[1],
				'id'   => (int) $matches[2],
			);
		}

		return false;
	}

	/**
	 * Regenerate and serve the CSS file
	 *
	 * @param array $css_details Array with 'type' and 'id'
	 */
	private function regenerate_and_serve_css( $css_details ) {
		$type = $css_details['type'];
		$id   = $css_details['id'];

		// Prevent race conditions - check if another request is already regenerating this file
		$lock_key = "elementor_css_regen_{$type}_{$id}";

		if ( get_transient( $lock_key ) ) {
			$this->debug_log( sprintf( 'Lock detected for %s-%d, waiting...', $type, $id ) );
			// Another request is already handling this, wait a moment and try to serve the file
			sleep( 1 );
			$this->serve_css_file( $type, $id );
			return;
		}

		// Set a lock for 30 seconds
		set_transient( $lock_key, true, 30 );

		// Validate the post exists
		$post = get_post( $id );
		if ( ! $post ) {
			$this->debug_log( sprintf( 'Post %d does not exist', $id ) );
			delete_transient( $lock_key );
			return;
		}

		// Check if the post is built with Elementor
		if ( ! $this->is_built_with_elementor( $id ) ) {
			$this->debug_log( sprintf( 'Post %d is not built with Elementor', $id ) );
			delete_transient( $lock_key );
			return;
		}

		// Regenerate the CSS file
		$this->debug_log( sprintf( 'Regenerating CSS for %s-%d...', $type, $id ) );
		$success = $this->regenerate_css_file( $type, $id );

		// Release the lock
		delete_transient( $lock_key );

		if ( $success ) {
			$this->debug_log( sprintf( 'Successfully regenerated %s-%d, serving file', $type, $id ) );
			// Serve the newly created file
			$this->serve_css_file( $type, $id );
		} else {
			$this->debug_log( sprintf( 'Failed to regenerate %s-%d', $type, $id ) );
		}
	}

	/**
	 * Check if a post is built with Elementor
	 *
	 * @param int $post_id Post ID
	 * @return bool
	 */
	private function is_built_with_elementor( $post_id ) {
		$document = \Elementor\Plugin::$instance->documents->get( $post_id );
		return $document && $document->is_built_with_elementor();
	}

	/**
	 * Regenerate the CSS file using Elementor's API
	 *
	 * @param string $type CSS file type (post or loop)
	 * @param int $id Post/Template ID
	 * @return bool Success status
	 */
	private function regenerate_css_file( $type, $id ) {
		try {
			// Get the document
			$document = \Elementor\Plugin::$instance->documents->get_doc_for_frontend( $id );

			if ( ! $document ) {
				return false;
			}

			// Both post and loop files use the Post CSS class
			// Loop templates are just posts with template-type metadata
			$css_file = \Elementor\Core\Files\CSS\Post::create( $id );

			if ( ! $css_file ) {
				return false;
			}

			// Force update the CSS file
			$css_file->update();

			return true;

		} catch ( Exception $e ) {
			// Log error if WP_DEBUG is enabled
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf(
					'Elementor CSS Regenerator: Failed to regenerate %s-%d.css - %s',
					$type,
					$id,
					$e->getMessage()
				) );
			}
			return false;
		}
	}

	/**
	 * Serve the CSS file with proper headers
	 *
	 * @param string $type CSS file type (post or loop)
	 * @param int $id Post/Template ID
	 */
	private function serve_css_file( $type, $id ) {
		// Build the file path
		$upload_dir = wp_upload_dir();
		$css_file_path = sprintf(
			'%s/elementor/css/%s-%d.css',
			$upload_dir['basedir'],
			$type,
			$id
		);

		// Check if file exists
		if ( ! file_exists( $css_file_path ) ) {
			$this->debug_log( sprintf( 'File does not exist after regeneration: %s', $css_file_path ) );
			return;
		}

		// CRITICAL: Override the 404 status with 200
		status_header( 200 );

		// Set proper headers
		header( 'Content-Type: text/css; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=31536000' );
		header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 31536000 ) . ' GMT' );
		header( 'Content-Length: ' . filesize( $css_file_path ) );

		// Output the file
		readfile( $css_file_path );

		// Exit to prevent WordPress from loading
		exit;
	}

	/**
	 * Debug logging helper
	 *
	 * @param string $message Message to log
	 */
	private function debug_log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'Elementor CSS Regenerator: ' . $message );
		}
	}
}

// Initialize the plugin
new Elementor_CSS_Regenerator();
