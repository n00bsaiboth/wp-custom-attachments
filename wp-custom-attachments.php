<?php
/**
 * Plugin Name: WP Custom Attachments
 * Plugin URI: https://github.com/n00bsaiboth/wp-custom-attachments
 * Description: Simple plugin to add an attachments outside document root, so it's not accessible via browser or search engines. Inspired by ACF.
 * Version: 1.0
 * Author: Jussi Jokinen
 * Author URI: https://openinnovations.io
 * License: MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Check if a user has a specific role.
 */
function wca_user_has_role( $role, $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }

    $user = get_userdata( $user_id );
    if ( ! $user || empty( $user->roles ) ) {
        return false;
    }

    return in_array( $role, (array) $user->roles, true );
}

/**
 * Change the upload directory for custom attachments.
 */
function wca_upload_directory( $dirs ) {
    // Define the custom upload directory inside wp-content/private
    $custom_dir = WP_CONTENT_DIR . '/private';
    
    // Ensure the directory exists, if not create it
    if ( ! is_dir( $custom_dir ) ) {
        mkdir( $custom_dir, 0755, true );
    }
    
    // Update the upload path and URL
    $dirs['path'] = $custom_dir;
    $dirs['url'] = content_url( '/private' ); // Public URL to access files via the server.
    
    return $dirs;
}
add_filter( 'upload_dir', 'wca_upload_directory' );

/**
 * Create custom database table on plugin activation.
 */
function wca_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_attachments';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned DEFAULT NULL,
        user_id bigint(20) unsigned DEFAULT NULL,
        file_path varchar(255) NOT NULL UNIQUE,
        original_name varchar(255) NOT NULL,
        uploaded_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY post_id (post_id),
        KEY user_id (user_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
register_activation_hook( __FILE__, 'wca_create_table' );

/**
 * Shortcode to retrieve and display files from the custom database table.
 */
function wca_display_files_list() {
        // Check if user is logged in AND has the community role
    if ( is_user_logged_in() && wca_user_has_role( 'community' ) ) {
        global $wpdb, $post;

        // Debugging: Log and display the current post ID

        // error_log( 'Current post ID: ' . ( isset($post->ID) ? $post->ID : 'no post' ) );
        // echo 'Current post ID: ' . ( isset($post->ID) ? $post->ID : 'no post' );

        // Make sure we're inside a post context
        if ( ! isset( $post->ID ) ) {
            return '<p>Attachments cannot be displayed outside a post context.</p>';
        }

        // Define the custom table name
        $table_name = $wpdb->prefix . 'custom_attachments';

        // Get the current post ID
        $post_id = get_the_ID();

        // If the current post is a revision, get the parent post
        if ( $parent_id = wp_is_post_revision( $post_id ) ) {
            $post_id = $parent_id;
        }

        // Fetch files related to this post
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT file_path, original_name, user_id, uploaded_at 
                FROM $table_name 
                WHERE post_id = %d 
                ORDER BY uploaded_at DESC",
                $post_id
            )
        );

        if ( empty( $results ) ) {
            return '<p>No attachment files for this post.</p>';
        }

        // Start the unordered list
        $output = '<ul>';

        foreach ( $results as $file ) {
            // Get username for display
            $user_info = get_userdata( $file->user_id );
            $username  = $user_info ? $user_info->display_name : 'Unknown User';

            // Build file URL
            $file_url = add_query_arg( 'wca_download', basename( $file->file_path ), home_url( '/' ) );

            // Format uploaded date
            $uploaded_date = date_i18n( get_option( 'date_format' ), strtotime( $file->uploaded_at ) );

            $output .= sprintf(
                '<li><a href="%s" target="_blank">%s</a> (%s – %s)</li>',
                esc_url( $file_url ),
                esc_html( $file->original_name ),
                esc_html( $username ),
                esc_html( $uploaded_date )
            );
        }

        $output .= '</ul>';

        return $output;
    }
}
add_shortcode( 'wca_file_list', 'wca_display_files_list' );

/**
 * Handle secure file downloads.
 */
function wca_secure_download() {
    // Run only if the correct query variable is set
    if ( ! isset( $_GET['wca_download'] ) ) {
        return;
    }

    // Require login + "community" role
    if ( ! is_user_logged_in() || ! wca_user_has_role( 'community' ) ) {
        wp_die( 'Access denied. You do not have permission to download this file.', '403 Forbidden', [ 'response' => 403 ] );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_attachments';

    // Sanitize filename and lookup in DB
    $requested = sanitize_file_name( $_GET['wca_download'] );
    $file = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE file_path LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like( $requested )
        )
    );

    if ( ! $file ) {
        wp_die( 'File not found.', '404 Not Found', [ 'response' => 404 ] );
    }

    // Build absolute path
    $absolute_path = WP_CONTENT_DIR . '/' . ltrim( $file->file_path, '/' );

    if ( ! file_exists( $absolute_path ) || ! is_file( $absolute_path ) ) {
        wp_die( 'File missing from storage.', '404 Not Found', [ 'response' => 404 ] );
    }

    // Serve the file securely
    header( 'Content-Description: File Transfer' );
    header( 'Content-Type: application/octet-stream' );
    header( 'Content-Disposition: inline; filename="' . basename( $file->original_name ) . '"' );
    header( 'Content-Length: ' . filesize( $absolute_path ) );

    readfile( $absolute_path );
    exit;
}
add_action( 'init', 'wca_secure_download' );


/**
 * Frontend file upload form for community users.
 */
function wca_upload_form_shortcode() {
    if ( ! is_user_logged_in() || ! wca_user_has_role( 'community' ) ) {
        return ''; // Hide for non-community users
    }

    // Generate nonce for security
    $nonce = wp_create_nonce( 'wca_file_upload' );

    ob_start();
    ?>
    <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" enctype="multipart/form-data" class="wca-upload-form">
        <input type="hidden" name="action" value="wca_handle_upload">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
        <input type="hidden" name="post_id" value="<?php echo get_the_ID(); ?>">

        <label for="wca_file">Select a file to upload:</label><br>
        <input type="file" name="wca_file" id="wca_file" required><br><br>

        <button type="submit">Upload File</button>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode( 'wca_upload_form', 'wca_upload_form_shortcode' );

/**
 * Handle frontend file uploads.
 */
function wca_handle_upload() {
    if ( ! is_user_logged_in() || ! wca_user_has_role( 'community' ) ) {
        wp_die( 'You do not have permission to upload files.' );
    }

    check_admin_referer( 'wca_file_upload' );

    if ( empty( $_FILES['wca_file']['name'] ) ) {
        wp_die( 'No file selected.' );
    }

    $post_id = intval( $_POST['post_id'] ?? 0 );
    $user_id = get_current_user_id();

    // Upload directory
    $upload_dir = WP_CONTENT_DIR . '/private';
    if ( ! file_exists( $upload_dir ) ) {
        wp_mkdir_p( $upload_dir );
    }

    // Original filename
    $original_name = sanitize_file_name( $_FILES['wca_file']['name'] );

    // 🔒 Scramble filename
    $extension = pathinfo( $original_name, PATHINFO_EXTENSION );
    $extension = $extension ? '.' . strtolower( $extension ) : '';
    $scrambled_name = sha1( uniqid( bin2hex( random_bytes(8) ), true ) ) . $extension;

    $destination = trailingslashit( $upload_dir ) . $scrambled_name;

    // Relative path for database
    $relative_path = 'private/' . $scrambled_name;

    if ( move_uploaded_file( $_FILES['wca_file']['tmp_name'], $destination ) ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'custom_attachments';

        $wpdb->insert(
            $table_name,
            [
                'file_path'     => $relative_path,
                'original_name' => $original_name,
                'uploaded_at'   => current_time( 'mysql' ),
                'post_id'       => $post_id,
                'user_id'       => $user_id,
            ],
            [ '%s', '%s', '%s', '%d', '%d' ]
        );

        wp_redirect( get_permalink( $post_id ) . '?upload=success' );
        exit;
    } else {
        wp_die( 'Error moving uploaded file.' );
    }
}

add_action( 'admin_post_wca_handle_upload', 'wca_handle_upload' );
add_action( 'admin_post_nopriv_wca_handle_upload', 'wca_handle_upload' );


