<?php
/**
 * AJAX endpoints for Wix Blog Importer.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MWI_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_mwi_test_feed',    [ $this, 'test_feed' ] );
        add_action( 'wp_ajax_mwi_import_batch', [ $this, 'import_batch' ] );
    }

    // ------------------------------------------------------------------ //

    public function test_feed() {
        $this->verify_nonce();

        $feed_url = esc_url_raw( $_POST['feed_url'] ?? '' );
        if ( empty( $feed_url ) ) {
            wp_send_json_error( [ 'error' => 'No feed URL provided.' ] );
        }

        $importer = new MWI_Importer();
        $result   = $importer->test_feed( $feed_url );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    // ------------------------------------------------------------------ //

    public function import_batch() {
        $this->verify_nonce();

        // Remove time limit — image downloading can take a long time per batch
        @set_time_limit( 0 );

        $feed_url = esc_url_raw( $_POST['feed_url'] ?? '' );
        if ( empty( $feed_url ) ) {
            wp_send_json_error( [ 'error' => 'No feed URL provided.' ] );
        }

        $raw_indices = isset( $_POST['selected_indices'] ) ? (array) $_POST['selected_indices'] : [];

        $options = [
            'post_status'      => sanitize_text_field( $_POST['post_status']   ?? 'draft' ),
            'import_images'    => filter_var( $_POST['import_images'] ?? true, FILTER_VALIDATE_BOOLEAN ),
            'set_thumbnail'    => filter_var( $_POST['set_thumbnail'] ?? true, FILTER_VALIDATE_BOOLEAN ),
            'import_cats'      => filter_var( $_POST['import_cats']   ?? true, FILTER_VALIDATE_BOOLEAN ),
            'import_tags'      => filter_var( $_POST['import_tags']   ?? true, FILTER_VALIDATE_BOOLEAN ),
            'skip_existing'    => filter_var( $_POST['skip_existing'] ?? true, FILTER_VALIDATE_BOOLEAN ),
            'offset'           => (int) ( $_POST['offset'] ?? 0 ),
            'batch_size'       => 5,
            'selected_indices' => array_map( 'intval', $raw_indices ),
        ];

        $importer = new MWI_Importer();
        $result   = $importer->import( $feed_url, $options );

        if ( isset( $result['success'] ) && $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    // ------------------------------------------------------------------ //

    private function verify_nonce() {
        if ( ! check_ajax_referer( 'mwi_import_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'error' => 'Security check failed. Please refresh the page.' ] );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'error' => 'Insufficient permissions.' ] );
        }
    }
}
