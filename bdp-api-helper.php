<?php
/**
 * Plugin Name: BDP API Helper
 * Description: Dynamically exposes BDP (Business Directory Plugin) fields for REST API usage and validates meta field updates.
 * Version: 1.1.35
 * Author: Christopher Peters
 * License: MIT
 * Text Domain: bdp-api-helper
 *
 * GitHub Plugin URI: https://github.com/righdforsa/bdp-api-helper
 */

const REGION_KEYS = array('country', 'state', 'city');
const CATEGORY_TAXONOMY = 'wpbdp_category';
const TAG_TAXONOMY = 'wpbdp_tag';

// Field type constants
const SYSTEM_FIELDS = array('id', 'title', 'status', 'featured_image');
const TAXONOMY_FIELDS = array('wpbdp_categories', 'wpbdp_tags');
const SKIP_FIELDS = array('id', 'title', 'status', 'featured_image', 'country', 'state', 'city', 'wpbdp_categories', 'wpbdp_tags');

$region_lookup = null;
$category_lookup = null;
$tag_lookup = null;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Activation Hook: Cache BDP form fields so we can retrieve values from memory
register_activation_hook( __FILE__, 'bdp_api_helper_plugin_activate' );
function bdp_api_helper_plugin_activate() {
	bdp_api_helper_refresh_registered_fields();
}

// Use BDP hooks to update registered fields cache when changes are made
add_action( 'wpbdp_save_form_field', function( $field_id ) {
    bdp_api_helper_refresh_registered_fields();
} );

add_action( 'wpbdp_delete_form_field', function( $field_id ) {
    bdp_api_helper_refresh_registered_fields();
} );

// debug logging for successful listing update
add_action( 'rest_after_insert_wpbdp_listing', function( $post, $request, $creating ) {
    error_log( '[BDP API HELPER] PATCH DATA: ' . print_r( $request->get_params(), true ) );
    error_log( '[BDP API HELPER] SAVING POST ID: ' . $post->ID );
}, 10, 3 );

// Runtime Hook: Dynamically register BDP meta fields at init, so they are available to the current REST request
add_action( 'rest_api_init', 'bdp_api_helper_register_meta_fields' );
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

// Runtime Hook: preload the BDP regions and categories data
add_action('rest_api_init', function() {
    global $region_lookup, $category_lookup, $tag_lookup;
    $region_lookup = preload_regions_lookup_data();
    $category_lookup = preload_categories_lookup_data();
    $tag_lookup = preload_tags_lookup_data();
});


// REST API endpoint: Register the BDP API helper listing routes
add_action( 'rest_api_init', function() {
    register_rest_route( 'bdp-api-helper/v1', '/fields', [
        'methods'  => 'GET',
        'callback' => 'bdp_api_helper_list_fields',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route( 'bdp-api-helper/v1', '/regions', [
        'methods'  => 'GET',
        'callback' => 'bdp_api_helper_list_regions',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route( 'bdp-api-helper/v1', '/categories', [
        'methods'  => 'GET',
        'callback' => 'bdp_api_helper_list_categories',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route( 'bdp-api-helper/v1', '/tags', [
        'methods'  => 'GET',
        'callback' => 'bdp_api_helper_list_tags',
        'permission_callback' => '__return_true',
    ]);

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
// Helper function: Refresh the cached BDP form fields list
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

// Endpoint "list regions" callback function
function bdp_api_helper_list_regions( WP_REST_Request $request ) {
    global $region_lookup;
    return rest_ensure_response( $region_lookup );
}

// Endpoint "list categories" callback function
function bdp_api_helper_list_categories( WP_REST_Request $request ) {
    global $category_lookup;
    return rest_ensure_response( $category_lookup );
}

// Endpoint "list tags" callback function
function bdp_api_helper_list_tags( WP_REST_Request $request ) {
    global $tag_lookup;
    return rest_ensure_response( $tag_lookup );
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

function bdp_api_helper_sanitize_meta_fields($params) {
    foreach ( $params as $key => $value ) {
        // Skip system fields, taxonomy fields, region fields, and parameters with empty values
        if ( in_array( $key, SKIP_FIELDS, true ) || empty($value) ) {
            continue;
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
            error_log("debug bpd-api-helper: URL type selected_fields" . json_encode($selected_fields, true));
            return $selected_fields;
        });

        // if the current field is in the url_type array, make sure to
        // unpack the string into an array so it will insert correctly
        error_log("debug: bdp-api-helper: testing url_type_fields " . json_encode($url_type_fields) . " for key {$key}");
        if(in_array($key, $url_type_fields)) {
            if ( is_string( $value ) ) {
                $decoded = json_decode( $value, true );
                if ( is_array( $decoded ) ) {
                    $params[$key] = $decoded;
                }
            }
        }
    }

    return $params;
}

function bdp_api_helper_validate_region_fields($params) {
    // Validate region hierarchy
    $parent_empty = false;
    
    foreach (REGION_KEYS as $region_key) {
        // skip empty values, mark the parent as empty for the next value check
        $value = $params[$region_key] ?? '';
        if (empty($value)) {
            $parent_empty = true;
            unset($params[$region_key]);
            continue;
        }
        
        if($parent_empty) {
            return new WP_Error( 'invalid_region_hierarchy', 'Parent region is required for any region value.', array( 'status' => 400 ) );
        }

        // confirm the region key exists
        $found = bdp_api_helper_find_region_term_id($value);
        if ($found === null) {
            error_log( "BDP API Helper: Unknown region value during creation: {$region_key} {$value}" );
            return new WP_Error( 'invalid_region', "{$region_key} region {$value} not found.", array( 'status' => 400 ) );
        }
    }
    
    return $params;
}

function bdp_api_helper_validate_category_fields($params) {
    if (!isset($params['wpbdp_categories'])) {
        return $params;
    }

    // Handle string-encoded array for categories
    $categories = $params['wpbdp_categories'];
    if (is_string($categories)) {
        $decoded = json_decode($categories, true);
        if (is_array($decoded)) {
            $categories = $decoded;
        } else {
            // If it's not a valid JSON array, treat it as a single category
            $categories = array($categories);
        }
    } else if (!is_array($categories)) {
        return new WP_Error('invalid_categories', 'Categories must be an array or JSON string.', array('status' => 400));
    }

    $valid_categories = array();
    foreach ($categories as $category) {
        if (empty($category)) {
            continue; // Skip empty categories
        }
        $term_id = bdp_api_helper_find_category_term_id($category);
        if ($term_id === null) {
            error_log("BDP API Helper: Unknown category value: {$category}");
            return new WP_Error('invalid_category', "Category '{$category}' not found.", array('status' => 400));
        }
        $valid_categories[] = $term_id;
    }

    $params['wpbdp_categories'] = $valid_categories;
    return $params;
}

function bdp_api_helper_validate_tag_fields($params) {
    if (!isset($params['wpbdp_tags'])) {
        return $params;
    }

    // Handle both array and JSON string input
    $tags = $params['wpbdp_tags'];
    if (is_string($tags)) {
        $tags = json_decode($tags, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'invalid_tags',
                'Tags string must be a valid JSON array string',
                array('status' => 400)
            );
        }
    }

    if (!is_array($tags)) {
        return new WP_Error(
            'invalid_tags',
            'Tags array must be a valid array',
            array('status' => 400)
        );
    }

    $valid_tags = array();
    foreach ($tags as $tag) {
        $term_id = bdp_api_helper_find_tag_term_id($tag);
        if ($term_id === null) {
            return new WP_Error(
                'invalid_tag',
                "Tag '{$tag}' does not exist",
                array('status' => 400)
            );
        }
        $valid_tags[] = $term_id;
    }

    $params['wpbdp_tags'] = $valid_tags;
    return $params;
}

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

    // Check for existing listings with the same title
    $existing_posts = get_posts(array(
        'post_type' => 'wpbdp_listing',
        'post_status' => array('publish', 'draft', 'pending'),
        'title' => $post_data['post_title'],
        'posts_per_page' => 1,
    ));

    if (!empty($existing_posts)) {
        error_log("BDP API Helper: Found existing listing with title: " . $post_data['post_title']);
        return new WP_Error(
            'duplicate_title',
            'A listing with this title already exists.',
            array(
                'status' => 409,
                'existing_post_id' => $existing_posts[0]->ID,
                'existing_post_status' => $existing_posts[0]->post_status
            )
        );
    }

    // validate and clean the params
    $params = $request->get_params();
    
    // Sanitize meta fields first
    $params = bdp_api_helper_sanitize_meta_fields($params);
    if (is_wp_error($params)) {
        return $params;
    }
    
    // Then validate region fields
    $params = bdp_api_helper_validate_region_fields($params);
    if (is_wp_error($params)) {
        return $params;
    }

    // Then validate category fields
    $params = bdp_api_helper_validate_category_fields($params);
    if (is_wp_error($params)) {
        return $params;
    }

    // Validate tags
    $params = bdp_api_helper_validate_tag_fields($params);
    if (is_wp_error($params)) {
        return $params;
    }

    $clean_params = $params;

    // Create post
    $post_id = wp_insert_post( $post_data );

    if ( ! $post_id || is_wp_error( $post_id ) ) {
        error_log( "BDP API Helper: Failed to insert listing with title: " . $post_data['post_title'] );
        return new WP_Error( 'insert_failed', 'Failed to create BDP listing.', array( 'status' => 500 ) );
    }

    $skip_keys = SKIP_FIELDS;
    $region_updates = array();
    $updates = array();

    // Handle featured image if provided
    if (isset($clean_params['featured_image'])) {
        $image_id = intval($clean_params['featured_image']);
        // Verify the image exists
        if (!get_post($image_id)) {
            error_log("BDP API Helper: Featured image ID {$image_id} not found");
            return new WP_Error('invalid_image', "Featured image ID {$image_id} not found.", array('status' => 400));
        }
        
        $update_result = set_post_thumbnail($post_id, $image_id);
        if ($update_result === false) {
            error_log("BDP API Helper: Failed to set featured image for post ID {$post_id}");
            return new WP_Error('update_failed', "Failed to set featured image.", array('status' => 500));
        }
        
        array_push(
            $updates,
            array(
                'field_updated' => 'featured_image',
                'new_value' => $image_id
            )
        );
    }

    // Save all valid BDP fields as post meta
    foreach ( $clean_params as $key => $value ) {
        // Skip system fields, and empty values
        if ( in_array( $key, $skip_keys, true ) || empty($value) ) {
            continue;
        }

        // Collect region updates, which we've already validated
        if(in_array( $key, REGION_KEYS, true )) {
            $region_id = bdp_api_helper_find_region_term_id($value);
            $region_updates[$key] = $region_id;
            continue;
        }

        // prepare the field and insert
        $field_id = bdp_api_helper_get_field_id_by_shortname( $key );
        $meta_key = "_wpbdp[fields][{$field_id}]";
        $update_result = update_post_meta( $post_id, $meta_key, $value );
        if( $update_result === false ) {
            error_log( "BDP API Helper: Failed to insert meta field {$field_key} for post ID {$post_id}" );
            return new WP_Error( 'update_failed', "Failed to insert meta field {$field_key}.", array( 'status' => 500 ) );
        }
    }

    // Update regions
    if (!empty($region_updates)) {
        $update_result = wp_set_object_terms($post_id, $region_updates, 'wpbdm-region');
        if( $update_result === false ) {
            error_log( "BDP API Helper: Failed to update regions for post ID {$post_id}" );
            return new WP_Error( 'update_failed', "Failed to update regions.", array( 'status' => 500 ) );
        }
    }

    // Update categories
    if (!empty($clean_params['wpbdp_categories'])) {
        $update_result = wp_set_object_terms($post_id, $clean_params['wpbdp_categories'], CATEGORY_TAXONOMY);
        if( $update_result === false ) {
            error_log( "BDP API Helper: Failed to update BDP categories for post ID {$post_id}" );
            return new WP_Error( 'update_failed', "Failed to update BDP categories.", array( 'status' => 500 ) );
        }
    }

    // Update tags
    if (!empty($clean_params['wpbdp_tags'])) {
        error_log("bdp-api-helper: Updating tags for post {$post_id} with values: " . json_encode($clean_params['wpbdp_tags']));
        $update_result = wp_set_object_terms($post_id, $clean_params['wpbdp_tags'], TAG_TAXONOMY);
        if (is_wp_error($update_result)) {
            error_log("bdp-api-helper: Failed to set tags for post {$post_id}. Error: " . json_encode($update_result->get_error_messages()));
            return new WP_Error(
                'tag_update_failed',
                'Failed to update tags',
                array('status' => 500)
            );
        }
        
        error_log("bdp-api-helper: Successfully updated tags for post {$post_id}");
        // Add tag updates to the response
        array_push(
            $updates,
            array(
                'field_updated' => 'wpbdp_tags',
                'new_value' => $clean_params['wpbdp_tags']
            )
        );
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

    // validate and clean the params
    $params = $request->get_params();
    $params = bdp_api_helper_sanitize_meta_fields($params);
    if (is_wp_error($params)) {
        return $params;
    }
    $params = bdp_api_helper_validate_region_fields($params);
    if (is_wp_error($params)) {
        return $params;
    }
    $params = bdp_api_helper_validate_category_fields($params);
    if (is_wp_error($params)) {
        return $params;
    }
    $params = bdp_api_helper_validate_tag_fields($params);
    if (is_wp_error($params)) {
        return $params;
    }
    $clean_params = $params;
    
    $skip_keys = SKIP_FIELDS;
    $updates = array();
    $region_updates = array();

    // Handle featured image update if provided
    if (isset($clean_params['featured_image'])) {
        $image_id = intval($clean_params['featured_image']);
        // Verify the image exists
        if (!get_post($image_id)) {
            error_log("BDP API Helper: Featured image ID {$image_id} not found");
            return new WP_Error('invalid_image', "Featured image ID {$image_id} not found.", array('status' => 400));
        }
        
        $current_image_id = get_post_thumbnail_id($post_id);
        if ($current_image_id != $image_id) {
            $update_result = set_post_thumbnail($post_id, $image_id);
            if ($update_result === false) {
                error_log("BDP API Helper: Failed to update featured image for post ID {$post_id}");
                return new WP_Error('update_failed', "Failed to update featured image.", array('status' => 500));
            }
            
            array_push(
                $updates,
                array(
                    'field_updated' => 'featured_image',
                    'new_value' => $image_id
                )
            );
        }
    }

    // Update the postmeta
    foreach ( $clean_params as $key => $value ) {
        // Skip system fields and empty values
        if(in_array( $key, $skip_keys, true ) || empty($value)) {
            continue;
        }

        // Collect region updates, which we've already validated
        if(in_array( $key, REGION_KEYS, true )) {
            $region_id = bdp_api_helper_find_region_term_id($value);
            $region_updates[$key] = $region_id;
            continue;
        }

        // Handle meta fields, which we've already validated
        $field_id = bdp_api_helper_get_field_id_by_shortname($key);
        if($field_id === null) {
            error_log("BDP API Helper: Unknown field id for previously validated field: {$key} {$value}");
            return new WP_Error( 'invalid_field', "Field '{$key}' not found.", array( 'status' => 400 ) );
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
            error_log("debug: bdp_api_helper_update_listing: skipping update for $field_key because values match: "
                . print_r($value, true));
        }
    }

    // Update regions if we have any
    if (!empty($region_updates)) {
        $update_result = wp_set_object_terms($post_id, array_values($region_updates), 'wpbdm-region');
        if( $update_result === false ) {
            error_log( "BDP API Helper: Failed to update regions for post ID {$post_id}" );
            return new WP_Error( 'update_failed', "Failed to update regions.", array( 'status' => 500 ) );
        }
        
        // Add region updates to the response
        foreach ($region_updates as $key => $term_id) {
            array_push(
                $updates,
                array(
                    'field_updated' => $key,
                    'new_value' => $term_id
                )
            );
        }
    }

    // Update categories if we have any
    if (!empty($clean_params['wpbdp_categories'])) {
        $update_result = wp_set_object_terms($post_id, $clean_params['wpbdp_categories'], CATEGORY_TAXONOMY);
        if( $update_result === false ) {
            error_log( "BDP API Helper: Failed to update categories for post ID {$post_id}" );
            return new WP_Error( 'update_failed', "Failed to update categories.", array( 'status' => 500 ) );
        }
        
        // Add category updates to the response
        array_push(
            $updates,
            array(
                'field_updated' => 'wpbdp_categories',
                'new_value' => $clean_params['wpbdp_categories']
            )
        );
    }

    // Update tags if provided
    if (!empty($clean_params['wpbdp_tags'])) {
        error_log("bdp-api-helper: Updating tags for post {$post_id} with values: " . json_encode($clean_params['wpbdp_tags']));
        $update_result = wp_set_object_terms($post_id, $clean_params['wpbdp_tags'], TAG_TAXONOMY);
        if (is_wp_error($update_result)) {
            error_log("bdp-api-helper: Failed to set tags for post {$post_id}. Error: " . json_encode($update_result->get_error_messages()));
            return new WP_Error(
                'tag_update_failed',
                'Failed to update tags',
                array('status' => 500)
            );
        }
        
        error_log("bdp-api-helper: Successfully updated tags for post {$post_id}");
        // Add tag updates to the response
        array_push(
            $updates,
            array(
                'field_updated' => 'wpbdp_tags',
                'new_value' => $clean_params['wpbdp_tags']
            )
        );
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
        'featured_image' => array(
            'required' => false,
            'type'     => 'integer',
            'validate_callback' => 'is_numeric',
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

        // BDP category fields
        'wpbdp_categories' => array(
            'required' => false,
            'type' => 'array',
            'items' => array(
                'type' => 'string'
            ),
        ),

        // BDP tag fields
        'wpbdp_tags' => array(
            'required' => false,
            'type' => 'array',
            'items' => array(
                'type' => 'string'
            ),
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
        'taxonomy' => 'wpbdm-region',
        'hide_empty' => false,
        'fields' => 'all', // or 'id=>name' if you want to be fancy
    ));

    if ( is_wp_error($terms) ) {
        error_log("bdp-api-helper: Failed to load regions taxonomy. Error: " . json_encode($terms->get_error_messages()));
        return array(); // Return empty array so downstream logic doesn't break
    }

    // populate the terms lookup array
    $lookup = array();
    foreach ( $terms as $term ) {
        $enabled = get_term_meta( $term->term_id, 'enabled', true );
        // skip disabled region terms
        if( $enabled === '0' ) {
            continue;
        }

        $lookup[ strtolower( $term->name ) ] = $term->term_id;
    }

    error_log("bdp-api-helper: preloaded " . count($lookup) . " region terms");
    return $lookup;
}

// lookup region by name to confirm it exists
function bdp_api_helper_find_region_term_id($incoming_region_name) {
    global $region_lookup;

    $region_name = strtolower(trim($incoming_region_name));

    // Normalize common region USA aliases
    // cast to lower case to defend against capitalization
    $alias_map = array(
        'united states' => 'usa',
        'united states of america' => 'usa',
        'u.s.' => 'usa',
	    'u.s.a.' => 'usa',

        // USA states
        'al' => 'alabama',
        'ak' => 'alaska',
        'az' => 'arizona',
        'ar' => 'arkansas',
        'ca' => 'california',
        'co' => 'colorado',
        'ct' => 'connecticut',
        'de' => 'delaware',
        'fl' => 'florida',
        'ga' => 'georgia',
        'hi' => 'hawaii',
        'ia' => 'iowa',
        'id' => 'idaho',
        'il' => 'illinois',
        'in' => 'indiana',
        'ks' => 'kansas',
        'ky' => 'kentucky',
        'la' => 'louisiana',
        'ma' => 'massachusetts',
        'md' => 'maryland',
        'me' => 'maine',
        'mi' => 'michigan',
        'mn' => 'minnesota',
        'mo' => 'missouri',
        'ms' => 'mississippi',
        'mt' => 'montana',
        'nc' => 'north carolina',
        'nd' => 'north dakota',
        'ne' => 'nebraska',
        'nh' => 'new hampshire',
        'nj' => 'new jersey',
        'nm' => 'new mexico',
        'nv' => 'nevada',
        'ny' => 'new york',
        'oh' => 'ohio',
        'ok' => 'oklahoma',
        'or' => 'oregon',
        'pa' => 'pennsylvania',
        'ri' => 'rhode island',
        'sc' => 'south carolina',
        'sd' => 'south dakota',
        'tn' => 'tennessee',
        'tx' => 'texas',
        'ut' => 'utah',
        'va' => 'virginia',
        'wa' => 'washington',
        'wi' => 'wisconsin',
        'wv' => 'west virginia',
        'wy' => 'wyoming',

        // Canadian provinces
        'ab' => 'alberta',
        'bc' => 'british columbia',
        'mb' => 'manitoba',
        'nb' => 'new brunswick',
        'nl' => 'newfoundland and labrador',
        'ns' => 'nova scotia',
        'nt' => 'northwest territories',
        'nu' => 'nunavut',
        'on' => 'ontario',
        'pe' => 'prince edward island',
        'qc' => 'quebec',
        'sk' => 'saskatchewan',
        'yk' => 'yukon',
    );

    if ( isset($alias_map[$region_name]) ) {
        error_log("bdp-api-helper: bdp_api_helper_find_region_term_id: remapping {$region_name} to " . $alias_map[$region_name]);
        $region_name = $alias_map[$region_name];
    }

    return $region_lookup[$region_name] ?? null;
}

// cache categories in a global so we can look them up without multiple DB hits
function preload_categories_lookup_data() {
    // get the bdp category terms
    $terms = get_terms(array(
        'taxonomy' => CATEGORY_TAXONOMY,
        'hide_empty' => false,
        'fields' => 'all',
    ));

    if ( is_wp_error($terms) ) {
        error_log("bdp-api-helper: Failed to load categories taxonomy. Error: " . json_encode($terms->get_error_messages()));
        return array(); // Return empty array so downstream logic doesn't break
    }

    // populate the terms lookup array
    $lookup = array();
    foreach ( $terms as $term ) {
        $enabled = get_term_meta( $term->term_id, 'enabled', true );
        // skip disabled category terms
        if( $enabled === '0' ) {
            continue;
        }

        $lookup[ strtolower( $term->name ) ] = $term->term_id;
    }

    error_log("bdp-api-helper: preloaded " . count($lookup) . " bdp category terms");
    return $lookup;
}

// lookup category by name to confirm it exists
function bdp_api_helper_find_category_term_id($incoming_category_name) {
    global $category_lookup;
    return $category_lookup[strtolower(trim($incoming_category_name))] ?? null;
}

// cache tags in a global so we can look them up without multiple DB hits
function preload_tags_lookup_data() {
    // get the bdp tag terms
    $terms = get_terms(array(
        'taxonomy' => TAG_TAXONOMY,
        'hide_empty' => false,
        'fields' => 'all',
    ));

    if ( is_wp_error($terms) ) {
        error_log("bdp-api-helper: Failed to load tags taxonomy. Error: " . json_encode($terms->get_error_messages()));
        return array(); // Return empty array so downstream logic doesn't break
    }

    // populate the terms lookup array
    $lookup = array();
    foreach ( $terms as $term ) {
        $enabled = get_term_meta( $term->term_id, 'enabled', true );
        // skip disabled tag terms
        if( $enabled === '0' ) {
            continue;
        }

        $lookup[ strtolower( $term->name ) ] = $term->term_id;
    }

    error_log("bdp-api-helper: preloaded " . count($lookup) . " bdp tag terms");
    return $lookup;
}

// lookup tag by name to confirm it exists
function bdp_api_helper_find_tag_term_id($incoming_tag_name) {
    global $tag_lookup;
    return $tag_lookup[strtolower(trim($incoming_tag_name))] ?? null;
}

