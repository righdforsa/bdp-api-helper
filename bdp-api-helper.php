<?php
/**
 * Plugin Name: BDP API Helper
 * Description: Dynamically exposes BDP (Business Directory Plugin) fields for REST API usage and validates meta field updates.
 * Version: 1.1.1
 * Author: Christopher Peters
 * License: MIT
 * Text Domain: bdp-api-helper
 *
 * GitHub Plugin URI: https://github.com/righdforsa/bdp-api-helper
 */

const REGION_KEYS = array('country', 'state', 'city');

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Activation Hook: Cache BDP form fields so we can retrieve values from memory
register_activation_hook( __FILE__, 'bdp_api_helper_plugin_activate' );
function bdp_api_helper_plugin_activate() {
	bdp_api_helper_refresh_registered_fields();
}

// Helper function: Refresh the cached fields list
function bdp_api_helper_refresh_registered_fields() {
    global $wpdb;

    $fields = $wpdb->get_results( "SELECT id, shortname, association, field_type, validators FROM {$wpdb->prefix}wpbdp_form_fields WHERE association = 'meta' OR association = 'region'" );

    if ( ! empty( $fields ) ) {
        $mapped_fields = [];
        foreach ( $fields as $field ) {
            $mapped_fields[] = [
                'id'        => (int) $field->id,
                'shortname' => sanitize_key( $field->shortname ),
                'association' => sanitize_key( $field->association ),
                'field_type' => sanitize_key( $field->field_type ),
                'validators' => sanitize_key( $field->validators ),
            ];
        }

        update_option( 'bdp_api_helper_registered_fields', $mapped_fields, false );
    } else {
        delete_option( 'bdp_api_helper_registered_fields' );
    }
}

// Use BDP hooks to update registered fields cache when changes are made
add_action( 'wpbdp_save_form_field', function( $field_id ) {
    bdp_api_helper_refresh_registered_fields();
} );

add_action( 'wpbdp_delete_form_field', function( $field_id ) {
    bdp_api_helper_refresh_registered_fields();
} );

// Runtime Hook: Dynamically register BDP meta fields at init, so they are available to the current REST request
add_action( 'init', 'bdp_api_helper_register_meta_fields' );
function bdp_api_helper_register_meta_fields() {
    if ( ! post_type_exists( 'wpbdp_listing' ) ) {
        return; // Safety check: BDP must be active
    }

    $fields = get_option( 'bdp_api_helper_registered_fields', [] );

    foreach ( $fields as $field ) {
        $meta_key = "_wpbdp[fields][{$field['id']}]";

        register_post_meta( 'wpbdp_listing', $meta_key, [
            'show_in_rest' => true,
            'single'       => true,
            'type'         => 'string',
            'auth_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        ]);
    }
}

// REST API Endpoint: List available BDP fields
add_action( 'rest_api_init', function() {
    register_rest_route( 'bdp-api-helper/v1', '/fields', [
        'methods'  => 'GET',
        'callback' => 'bdp_api_helper_list_fields',
        'permission_callback' => '__return_true',
    ]);

});

// Endpoint "list fields" callback function
function bdp_api_helper_list_fields( WP_REST_Request $request ) {
    global $wpdb;
    bdp_api_helper_refresh_registered_fields();
    $results = get_option('bdp_api_helper_registered_fields', []);

    if ( empty( $results ) ) {
        return new WP_Error( 'no_fields', 'No BDP fields found.', [ 'status' => 404 ] );
    }

    return rest_ensure_response( $results );
}

// Validation: only known meta fields allowed in REST calls
function bdp_api_helper_validate_meta_fields( $prepared_post, WP_REST_Request $request ) {
    if ( empty( $request['meta'] ) || ! is_array( $request['meta'] ) ) {
        return $prepared_post;
    }

    $allowed_fields = get_option( 'bdp_api_helper_registered_fields', [] );
    $allowed_meta_keys = array_map( function( $field ) {
        return "_wpbdp[fields][{$field['id']}]";
    }, $allowed_fields );

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

    return $prepared_post;
}

// debug logging for successful listing update
add_action( 'rest_after_insert_wpbdp_listing', function( $post, $request, $creating ) {
    error_log( '[BDP API HELPER] PATCH DATA: ' . print_r( $request->get_params(), true ) );
    error_log( '[BDP API HELPER] SAVING POST ID: ' . $post->ID );
}, 10, 3 );

// Register the BDP API helper listing routes
add_action( 'rest_api_init', function() {
    register_rest_route( 'bdp-api-helper/v1', '/update-listing', array(
        'methods'  => 'PATCH',
        'callback' => 'bdp_api_helper_update_listing',
        'permission_callback' => 'bdp_api_helper_permission_callback',
        'args'     => array( bdp_api_helper_get_dynamic_args( 'update' ) ),
    ) );

    register_rest_route( 'bdp-api-helper/v1', '/create-listing', array(
        'methods'  => 'POST',
        'callback' => 'bdp_api_helper_create_listing',
        'permission_callback' => 'bdp_api_helper_permission_callback',
        'args'     => array( bdp_api_helper_get_dynamic_args( 'create' ) ),
    ) );

    // Add meta validation for updates
    add_filter( 'rest_pre_insert_wpbdp_listing', 'bdp_api_helper_validate_meta_fields', 10, 2 );
} );

// Callback function to handle creating the listing
function bdp_api_helper_create_listing( $request ) {
    error_log("BDP API Helper: create: " . json_encode($request->get_params()));

    $post_data = array(
        'post_type'   => 'wpbdp_listing',
        'post_status' => $request->get_param('status') ?: 'draft',
        'post_title'  => $request->get_param('title'),
    );

    if ( empty( $post_data['post_title'] ) ) {
        return new WP_Error( 'missing_title', 'Title is required to create a listing.', array( 'status' => 400 ) );
    }

    // Create post
    $post_id = wp_insert_post( $post_data );

    if ( ! $post_id || is_wp_error( $post_id ) ) {
        error_log( "BDP API Helper: Failed to insert listing with title: " . $post_data['post_title'] );
        return new WP_Error( 'insert_failed', 'Failed to create BDP listing.', array( 'status' => 500 ) );
    }

    // Save all valid BDP fields as post meta
    foreach ( $request->get_params() as $key => $value ) {
        if ( in_array( $key, array(
                                'title',
                                'status',
                                'id',
                                'country',
                                'state',
                                'city'
                             ), true ) ) {
            continue;
        }

        if( in_array( $key, REGION_KEYS, true)) {
            $found = find_region_term_id($region_lookup, $value);
            if ($found === null) {
            error_log( "BDP API Helper: Unknown region value during creation: {$key}" );
            return new WP_Error( 'invalid_region', "{$key} region {$value} not found.", array( 'status' => 400 ) );

            }
        }
        // confirm each parameter is a known listing field
        $field_id = bdp_api_helper_get_field_id_by_shortname( $key );
        if ( ! $field_id ) {
            error_log( "BDP API Helper: Unknown field during creation: {$key}" );
            return new WP_Error( 'invalid_field', "Field '{$key}' not found.", array( 'status' => 400 ) );
        }

        // hack for data correction for URL type fields, which can't
        // seem to come in from a POST as request params with array
        // index notation, due to tripping the security module, so
        // they are being sent as string encoded arrays

        // get a list of known fields that have a type "url"
        $url_type_fields = array(function() {
            $form_fields = get_option( 'bdp_api_helper_registered_fields', array() );
            $selected_fields = array();
            foreach ( $form_fields as $field ) {
                if ( empty( $field['shortname'] ) ) {
                    continue;
                }

                if ($field['field_type'] == 'url') {
                    $selected_fields.push($field['shortname']);
                }
            }
            error_log("debug bpd-api-helper: selected_fields" . json_encode($selected_fields, true));
            return $selected_fields;
        });

        // if the current field is in the url_type array, make sure to
        // unpack the string into an array so it will insert correctly
        error_log("debug: bdp-api-helper: testing url_type_fields " . json_encode($url_type_fields) . " for key {$key}");
        if(in_array($key, $url_type_fields)) {
            if ( is_string( $value ) ) {
                $decoded = json_decode( $value, true );
                if ( is_array( $decoded ) ) {
                    $value = $decoded;
                }
            }
        }

        // prepare the field and insert
        $meta_key = "_wpbdp[fields][{$field_id}]";
        $update_result = update_post_meta( $post_id, $meta_key, $value );
        if( $update_result === false ) {
            error_log( "BDP API Helper: Failed to insert meta field {$field_key} for post ID {$post_id}" );
            return new WP_Error( 'update_failed', "Failed to insert meta field {$field_key}.", array( 'status' => 500 ) );
        }
    }

    error_log("BDP API Helper: Created listing ID {$post_id}");

    return rest_ensure_response( array(
        'success'  => true,
        'post'  => get_post($post_id),
        'edit_url' => admin_url( "post.php?post={$post_id}&action=edit" ),
    ) );
}

// Callback function to handle the listing update
function bdp_api_helper_update_listing( $request ) {
    error_log("debug: BDP-api-helper: update: " . json_encode($request->get_params()));

    $post_id = $request->get_param( 'id' );
    if ( ! isset($post_id) || ! get_post($post_id) ) {
        return new WP_Error( 'invalid_post', 'Listing not found.', array( 'status' => 404 ) );
    }

    $skip_keys = array( 'id', 'title', 'status' );
    $updates = array();

    // Update the postmeta
    foreach ( $request->get_params() as $key => $value ) {
        if(in_array( $key, $skip_keys, true )) {
            continue;
        }
        if(in_array( $key, REGION_KEYS, true )) {
            // confirm the region key exists
            if (find_region_term_id($region_lookup, $value) === null) {
                error_log( "BDP API Helper: Unknown region value during creation: {$key}" );
                return new WP_Error( 'invalid_region', "{$key} region {$value} not found.", array( 'status' => 400 ) );
            }

        }
        $field_id = bdp_api_helper_get_field_id_by_shortname($key);
        if(! $field_id) {
            error_log("BDP API Helper: Unknown field received in PATCH: {$key}");
            return new WP_Error( 'invalid_post', 'Field not found.', array( 'status' => 400 ) );
        }

        $field_key = "_wpbdp[fields][{$field_id}]";
        $current_value = get_post_meta($post_id, $field_key, true);
        if( $value != $current_value ) {
            $update_result = update_post_meta( $post_id, $field_key, $value );
            if( $update_result === false ) {
                error_log( "BDP API Helper: Failed to update meta field {$field_key} for post ID {$post_id}" );
                return new WP_Error( 'update_failed', "Failed to update meta field {$field_key}.", array( 'status' => 500 ) );
            }

            if( $update_result > 0) {
                array_push(
                    $updates,
                    array(
                        'field_updated' => $field_key,
                        'new_value' => $value
                    )
                );
            }
        }
        else {
            error_log("bdp_api_helper_update_listing: skipping update for $field_key because values match: "
                . print_r($value, true));
        }
    }

    return rest_ensure_response( array(
        'success' => true,
        'post_id' => $post_id,
        'updates' => $updates,
    ) );
}

// Get dynamic list of possible BDP field args, to populate API argument enforcement parameter
function bdp_api_helper_get_dynamic_args( $operation ) {
    $args = array(
        // static args present for every BDP listing
        'id' => array(
            'required' => $operation == 'update' ? true : false,
            'type'     => 'integer',
            'validate_callback' => 'is_numeric',
        ),
        'title' => array(
            'required' => $operation == 'create' ? false : true,
            'type'     => 'string',
        ),
        'status' => array(
            'required' => false,
            'type'     => 'string',
        ),

        // BDP region fields
        'country' => array(
            'required' => false,
            'type' => 'string',
        ),
        'state' => array(
            'required' => false,
            'type' => 'string',
        ),
        'city' => array(
            'required' => false,
            'type' => 'string',
        ),

    );

    $form_fields = get_option( 'bdp_api_helper_registered_fields', array() );
    foreach ( $form_fields as $field ) {
        if ( empty( $field['shortname'] ) ) {
            continue;
        }

        $param_type = 'string';
        $param_items = null;
        switch ($field['field_type']) {
            case 'url':
                $param_type = 'array';
                $param_items = array('items' => array('type' => 'string'));
            default:
                break;
        }

        $param_args = array(
            'required' => false,
            'type'     => $param_type,
            $param_items,
        );
        $args[ $field['shortname'] ] = $param_args;
    }

    return $args;
}

function bdp_api_helper_permission_callback( $request ) {
    // Check if the user is logged in
    if ( is_user_logged_in() ) {
        return true;
    }

    return new WP_Error( 'rest_forbidden', __( 'Authentication required to access this endpoint.' ), array( 'status' => 401 ) );
}

function bdp_api_helper_get_field_id_by_shortname( $shortname ) {
    $fields = get_option( 'bdp_api_helper_registered_fields', array() );
    foreach ( $fields as $field ) {
        if ( $field['shortname'] === $shortname ) {
            return $field['id'];
        }
    }
    error_log("bdp_api_helper_get_field_id_by_shortname: no match found for shortname '{$shortname}'");
    return null;
}

// cache regions in a global so we can look them up without multiple DB hits
function preload_regions_lookup_data() {
    // get the bdp region terms
    $terms = get_terms(array(
        'taxonomy' => 'wpbdp_region',
        'hide_empty' => false,
        'fields' => 'all', // or 'id=>name' if you want to be fancy
    ));

    if ( is_wp_error($terms) ) {
        error_log("bdp-api-helper: Failed to load regions taxonomy. Error: " . print_r($terms->get_error_messages(), true));
        return array(); // Return empty array so downstream logic doesn't break
    }

    // populate the terms lookup array
    $lookup = array();
    foreach ( $terms as $term ) {
        error_log("bdp_api_helper: preload_regions_lookup_data term: " . print_r($term, true));
        $lookup[ strtolower( $term->name ) ] = $term->term_id;
    }

    error_log("bdp-api-helper: preloaded " . count($lookup) . " region terms");
    return $lookup;
}
$region_lookup = preload_regions_lookup_data();

// lookup region by name to confirm it exists
function find_region_term_id($region_lookup, $incoming_region_name) {
    return $region_lookup[ strtolower( trim($incoming_region_name) ) ] ?? null;
}

