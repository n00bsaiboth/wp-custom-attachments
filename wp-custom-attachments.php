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
 * Check if user has required permissions.
 */
function wca_user_has_permission() {
    return is_user_logged_in() && wca_user_has_role('community');
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
 * Validate uploaded file.
 *
 */
function wca_validate_file($file) {
    // Max file size: 5 MB
    $max_file_size = 5 * 1024 * 1024;
    if ($file['size'] > $max_file_size) {
        return new WP_Error('file_too_large', 'File is too large. Maximum size is 5MB.');
    }

    // Validate MIME type using finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if ($mime_type !== 'application/pdf') {
        return new WP_Error('invalid_file_type', 'Invalid file type. Only PDF files are allowed.');
    }

    return true;
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
    $dirs['url'] = content_url( '/private' ); 
    
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
 * Create .htaccess file to protect the private directory.
 */
function wca_create_htaccess() {
    $htaccess_file = WP_CONTENT_DIR . '/private/.htaccess';
    if (!file_exists($htaccess_file)) {
        $rules = "Require all denied";
        file_put_contents($htaccess_file, $rules);
    }
}
register_activation_hook(__FILE__, 'wca_create_htaccess');

/**
 * Enqueue plugin's necessary styles and scripts.
 */
function wca_enqueue_assets() {
    wp_enqueue_style( 'wca-styles', plugin_dir_url( __FILE__ ) . 'assets/css/style.css' );
    wp_enqueue_script( 'wca-scripts', plugin_dir_url( __FILE__ ) . 'assets/js/script.js');
}
add_action( 'wp_enqueue_scripts', 'wca_enqueue_assets' );

function wca_admin_assets($hook) {
    if ($hook === 'settings_page_wca-settings') {
        wp_enqueue_style('wca-admin-styles', plugin_dir_url(__FILE__) . 'assets/css/admin.css');
    }
}
add_action('admin_enqueue_scripts', 'wca_admin_assets');

/**
 * Shortcode to retrieve and display files from the custom database table.
 */
function wca_display_files_list() {
        // Check if user is logged in AND has the community role
    if ( wca_user_has_permission() ) {
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
        $output = '<ul class="wca-file-list">';

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
    if ( ! wca_user_has_permission() ) {
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
    $base_path = WP_CONTENT_DIR . '/private/';
    $absolute_path = realpath($base_path . basename($file->file_path));

    // Ensure the file is within the allowed directory
    if (!$absolute_path || strpos($absolute_path, $base_path) !== 0) {
        wp_die('Invalid file path.', '403 Forbidden', ['response' => 403]);
    }

    // Check if file exists
    if ( ! file_exists( $absolute_path ) || ! is_file( $absolute_path ) ) {
        wp_die( 'File missing from storage.', '404 Not Found', [ 'response' => 404 ] );
    }

    // Serve the file securely
    header( 'Content-Description: File Transfer' );
    header( 'Content-Type: application/octet-stream' );
    header( 'Content-Disposition: inline; filename="' . basename( $file->original_name ) . '"' );
    header( 'Content-Length: ' . filesize( $absolute_path ) );
    header( 'Cache-Control: no-cache, no-store, must-revalidate' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    readfile( $absolute_path );
    exit;
}
add_action( 'init', 'wca_secure_download' );


/**
 * Frontend file upload form for community users.
 */
function wca_upload_form_shortcode() {
    if ( ! wca_user_has_permission() ) {
        return '';
    }

    // Generate nonce for security
    $nonce = wp_create_nonce( 'wca_file_upload' );

    ob_start();
    ?>
    <div id="wca-upload">
        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" enctype="multipart/form-data" class="wca-upload-form">
            <input type="hidden" name="action" value="wca_handle_upload">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
            <input type="hidden" name="post_id" value="<?php echo get_the_ID(); ?>">
            <div>
            <label for="wca_file" class="form-label">Select a file to upload:</label>
            <input type="file" name="wca_file" class="form-control" id="wca_file" required>
            </div>
            

            <button type="submit">Upload File</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'wca_upload_form', 'wca_upload_form_shortcode' );

/**
 * Handle frontend file uploads.
 */
function wca_handle_upload() {
    if ( ! wca_user_has_permission() ) {
        wp_die( 'You do not have permission to upload files.' );
    }

    check_admin_referer( 'wca_file_upload' );

    if ( empty( $_FILES['wca_file']['name'] ) ) {
        wp_die( 'No file selected.' );
    }

    // Validate the uploaded file, pdf/5MB limit
    $file = $_FILES['wca_file'];
    
    $validation_result = wca_validate_file($file);
    if (is_wp_error($validation_result)) {
        wp_die($validation_result->get_error_message());
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

    // Random filename
    $extension = pathinfo( $original_name, PATHINFO_EXTENSION );
    $extension = $extension ? '.' . strtolower( $extension ) : '';
    $random_name = sha1( uniqid( bin2hex( random_bytes(8) ), true ) ) . $extension;

    $destination = trailingslashit( $upload_dir ) . $random_name;

    // Relative path for database
    $relative_path = 'private/' . $random_name;

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

/**
 * Add settings for the plugin
 */

function wca_register_settings() {

}
add_action( 'admin_init', 'wca_register_settings' );

/**
 * Add settings page to the admin menu
 */

function wca_add_settings_page() {
    add_options_page(
        'WP Custom Attachments Settings',
        'WP Custom Attachments',
        'read',
        'wca-settings',
        'wca_render_settings_page'
    );
}
add_action( 'admin_menu', 'wca_add_settings_page' );

/**
 * Render the settings page
 */

function wca_render_settings_page() {
    // Restrict access: only admins or community members
    if ( ! current_user_can( 'manage_options' ) && ! wca_user_has_role( 'community' ) ) {
        wp_die( 'You do not have permission to view this page.', '403 Forbidden', [ 'response' => 403 ] );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_attachments';
    $current_user_id = get_current_user_id();

    // Fetch files — admins see all, users see only their own
    if ( current_user_can( 'manage_options' ) ) {
        $files = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY uploaded_at DESC" );
    } else {
        $files = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE user_id = %d ORDER BY uploaded_at DESC",
            $current_user_id
        ) );
    }
    ?>
    <div class="wrap">
        <h1>WP Custom Attachments Settings</h1>

        <?php if ( isset( $_GET['deleted'] ) ) : ?>
            <div class="updated notice"><p>File deleted successfully.</p></div>
        <?php endif; ?>

        <?php if ( empty( $files ) ) : ?>
            <p>No files uploaded yet.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Original Name</th>
                        <th>User</th>
                        <th>Post</th>
                        <th>Uploaded At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $files as $file ) : 
                        $user_info = get_userdata( $file->user_id );
                        $username  = $user_info ? $user_info->display_name : 'Unknown User';

                        // Get the post title
                        $post_title = $file->post_id ? get_the_title( $file->post_id ) : '(no post)';
                        if ( empty( $post_title ) ) {
                            $post_title = '(no title)';
                        }

                        // Make title clickable (to view post)
                        $post_link = get_permalink( $file->post_id );
                        if ( $post_link ) {
                            $post_title = sprintf(
                                '<a href="%s" target="_blank">%s</a>',
                                esc_url( $post_link ),
                                esc_html( $post_title )
                            );
                        } else {
                            $post_title = esc_html( $post_title );
                        }

                        // Delete action link
                        $delete_url = wp_nonce_url(
                            admin_url( 'admin-post.php?action=wca_handle_file_delete&file_id=' . intval( $file->id ) ),
                            'wca_handle_file_delete'
                        );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $file->id ); ?></td>
                        <td><?php echo esc_html( $file->original_name ); ?></td>
                        <td><?php echo esc_html( $username ); ?></td>
                        <td><?php echo $post_title; ?></td>
                        <td><?php echo esc_html( $file->uploaded_at ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $delete_url ); ?>" 
                               class="button button-small" 
                               onclick="return confirm('Are you sure you want to delete this file?');">
                               Delete
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Handle file deletion from the admin area
 */ 
function wca_handle_file_delete() {
    if ( ! is_user_logged_in() ) {
        wp_die( 'You must be logged in to delete files.' );
    }

    check_admin_referer( 'wca_handle_file_delete' );

    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_attachments';
    $current_user_id = get_current_user_id();

    $file_id = intval( $_GET['file_id'] ?? 0 );
    if ( ! $file_id ) {
        wp_die( 'Invalid request.' );
    }

    // Fetch the file record
    $file = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $file_id )
    );

    if ( ! $file ) {
        wp_die( 'File not found.' );
    }

    // Permission check:
    // Admins can delete anything, community users only their own files
    if ( ! current_user_can( 'manage_options' ) && (int) $file->user_id !== (int) $current_user_id ) {
        wp_die( 'You do not have permission to delete this file.', '403 Forbidden', [ 'response' => 403 ] );
    }

    // Delete the file from disk
    $absolute_path = WP_CONTENT_DIR . '/' . $file->file_path;
    if ( file_exists( $absolute_path ) ) {
        unlink( $absolute_path );
    }

    // Delete from database
    $wpdb->delete( $table_name, [ 'id' => $file_id ], [ '%d' ] );

    wp_redirect( admin_url( 'admin.php?page=wca-settings&deleted=1' ) );
    exit;
}
add_action( 'admin_post_wca_handle_file_delete', 'wca_handle_file_delete' );

/**
 * Cleanup on plugin uninstall: remove custom database table.
 */
function wca_cleanup() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'custom_attachments';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
}
register_uninstall_hook(__FILE__, 'wca_cleanup');
