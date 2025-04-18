<?php
/**
 * Plugin Name: BDP API Helper
 * Description: Dynamically exposes BDP (Business Directory Plugin) fields for REST API usage and validates meta field updates.
 * Version: 1.0.0
 * Author: Christopher Peters
 * License: GPL2+
 * Text Domain: bdp-api-helper
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register custom REST API routes for BDP API Helper
 */
add_action( 'rest_api_init', function() {

    // Register the /fields endpoint
    register_rest_route( 'bdp-api-helper/v1', '/fields', [
        'methods'  => 'GET',
        'callback' => 'bdp_api_helper_list_fields',
        'permission_callback' => '__return_true',
    ]);

    // Add meta validation on listing update
    add_filter( 'rest_pre_insert_wpbdp_listing', 'bdp_api_helper_validate_meta_fields', 10, 2 );
});

/**
 * Callback for listing BDP form fields.
 */
function bdp_api_helper_list_fields( WP_REST_Request $request ) {
    global $wpdb;

    $results = $wpdb->get_results( "
        SELECT id, shortname, label, association, field_type
        FROM {$wpdb->prefix}wpbdp_form_fields
        WHERE association = 'meta'
    " );

    if ( empty( $results ) ) {
        return new WP_Error( 'no_fields', 'No BDP fields found.', [ 'status' => 404 ] );
    }

    $fields = [];

    foreach ( $results as $row ) {
        $fields[] = [
            'id'          => (int) $row->id,
            'shortname'   => $row->shortname,
            'label'       => $row->label,
            'association' => $row->association,
            'field_type'  => $row->field_type,
        ];
    }

    return rest_ensure_response( $fields );
}

/**
 * Validate incoming meta fields during listing updates.
 */
function bdp_api_helper_validate_meta_fields( $prepared_post, WP_REST_Request $request ) {
    if ( empty( $request['meta'] ) || ! is_array( $request['meta'] ) ) {
        return $prepared_post;
    }

    global $wpdb;

    // Load allowed BDP meta field IDs
    $field_ids = $wpdb->get_col( "
        SELECT id FROM {$wpdb->prefix}wpbdp_form_fields WHERE association = 'meta'
    " );

    $allowed_meta_keys = array_map( function( $id ) {
        return "_wpbdp[fields][{$id}]";
    }, $field_ids );

    $submitted_meta = $request['meta'];
    $invalid_fields = [];

    foreach ( array_keys( $submitted_meta ) as $key ) {
        if ( ! in_array( $key, $allowed_meta_keys, true ) ) {
            $invalid_fields[] = $key;
        }
    }

    if ( ! empty( $invalid_fields ) ) {
        return new WP_Error( 'invalid_meta_fields', 'Invalid field(s) detected in meta payload.', [
            'status' => 400,
            'invalid_fields' => $invalid_fields
        ] );
    }

    // All fields are valid, let it proceed
    return $prepared_post;
}
