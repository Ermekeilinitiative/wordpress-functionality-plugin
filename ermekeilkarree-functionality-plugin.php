<?php
/*
   Plugin Name: Ermekeilkarree Functionality Plugin
   Description: Plugin for site ermekeilkarree.de offering functionality that is independent from themes. Activate this plugin for the whole multisite network to make at an integral part of the network.
   Version: 0.4.0
   License: MIT
   Author: Daniel Appelt
   Author URI: https://github.com/danielappelt
   See also: http://wpcandy.com/teaches/how-to-create-a-functionality-plugin/
 */

/*
   Register a global menu across the network.

   See http://wpmututorials.com/plugins/add-a-global-menu-to-your-network/
 */
register_nav_menu( 'global', 'Global Navigation Menu' );

function global_nav_menu_init() {
    if (!is_main_site()) {
        // Remove the menu from menu screens on sub sites to avoid confusions.
        unregister_nav_menu('global');
    }
}
// Remove the global menu from admin and customizing areas in sub sites.
add_action('admin_init', 'global_nav_menu_init');
add_action('start_previewing_theme', 'global_nav_menu_init');

/*
   Inject the 'global' navigation menu defined for the network's main site also
   as 'global' menu for other sites on the network.
 */
function global_menu_filter($output, $args) {
    // This is the current network's information; 'site' is old terminology.
    global $current_site;

    $sorted_menu_items = null;
    if(!is_main_site() && $args->theme_location == "global") {
        // Retrieve the main site's global menu.
        switch_to_blog($current_site->blog_id);
        $sorted_menu_items = wp_nav_menu($args);
        restore_current_blog();
    }

    return $sorted_menu_items;
}
// See http://codex.wordpress.org/Function_Reference/add_filter for priority and accepted_args.
add_filter('pre_wp_nav_menu', 'global_menu_filter', 10, 2);


function long_description_callback() {
    $option = get_option('blog_long_description');

    echo "<textarea id='blog_long_description' name='blog_long_description' ".
            "rows='5' cols='80'>{$option}</textarea>";
}

function cover_image_callback() {
    $option = get_option('blog_cover_image');

    if($option) {
        $img = wp_get_attachment_image($option);
        echo "<div>{$img}</div>";
    }
    echo "<input id='blog_cover_image' name='blog_cover_image' size='40' type='text' value='{$option}' />";

    if (!function_exists('media_buttons')) {
        include(ABSPATH . 'wp-admin/includes/media.php');
    }

    echo '<span id="blog-cover-image-media-buttons" class="wp-media-buttons">';
    /**
     * Fires after the default media button(s) are displayed.
     *
     * @since 2.5.0
     *
     * @param string $editor_id Unique editor identifier, e.g. 'content'.
     */
    do_action('media_buttons', 'blog_cover_image');
    echo "</span>\n";
}

function cover_image_filter($html, $id, $attachment) {
    if($_POST && array_key_exists('post_id', $_POST) && $_POST['post_id'] == 0) {
        // The post_id will be zero if we are not editing a real post. We assume,
        // this means that we are in our settings field for the cover image.
        // TODO: see
        //   - https://github.com/WordPress/WordPress/blob/master/wp-admin/custom-background.php
        //   - https://github.com/WordPress/WordPress/blob/master/wp-admin/js/custom-background.js
        // on how to use the media library in the "right" way.
        return $id;
    } else {
        return $html;
    }
}
add_filter('media_send_to_editor', 'cover_image_filter', 10, 3);

function init_blog_settings() {
    // Add fields with name and function to use for our extra settings
    add_settings_field(
            'blog_long_description',
            'Längere Beschreibung',
            'long_description_callback',
            'general' );

    add_settings_field(
            'blog_cover_image',
            'Cover Bild',
            'cover_image_callback',
            'general' );

    // Register our setting so that $_POST handling is done for us and our
    // callback function just has to echo the form HTML
    register_setting('general', 'blog_long_description');
    register_setting('general', 'blog_cover_image');
}
add_action('admin_init', 'init_blog_settings');

// TODO: maybe use a transient to save the result
// See also https://github.com/wp-plugins/network-summary/blob/master/includes/class-network-summary.php
function get_posts_for_sites(array $sites, $limit) {
    $result = array();

    if ( empty( $sites ) ) {
        return $result;
    }

    function sort_by_post_date( $a, $b ) {
        return strtotime( $b->post_date_gmt ) - strtotime( $a->post_date_gmt );
    }

    # By default get_posts() will retrieve the 5 latest posts. In order to get
    # correct results, we need to retrieve $limit posts from every blog.
    $post_params = array('numberposts' => $limit);

    foreach ( $sites as $site ) {
        $site = (object)$site;
        switch_to_blog( $site->blog_id );

        foreach ( get_posts($post_params) as $post ) {
            $post->site_id = $site->blog_id;
            array_push( $result, $post );
        }
        restore_current_blog();

        usort( $result, 'sort_by_post_date' );
        $result = array_slice( $result, 0, $limit );
    }

    return $result;
}

// Use template multisite_front_page.php if available to display the network's front page!
function front_page_filter($template) {
    if(is_main_site() && is_front_page()) {
        $main_template = locate_template( array( 'multisite_front_page.php' ) );
        if ( '' != $main_template ) {
            return $main_template;
        }
    }

    return $template;
}
// See http://codex.wordpress.org/Plugin_API/Filter_Reference/template_include
add_filter('template_include', 'front_page_filter');

# Add two log actions which allow us to temporarely block spammers via fail2ban
# See http://www.scottbrownconsulting.com/2014/09/countering-wordpress-xml-rpc-attacks-with-fail2ban/
function fail2ban_login_failed_hook($username) {
//    openlog('wordpress('.$_SERVER['HTTP_HOST'].')', LOG_NDELAY|LOG_PID, LOG_AUTHPRIV);
    openlog('wordpress', LOG_NDELAY|LOG_PID, LOG_AUTHPRIV);
    syslog(LOG_NOTICE,"Authentication failure for ".$username." from ".$_SERVER['REMOTE_ADDR']);
}
add_action('wp_login_failed', 'fail2ban_login_failed_hook');

function fail2ban_pingback_error_hook($ixr_error) {
    if ( $ixr_error->code === 48 ) return $ixr_error; // don't punish duplication

//    openlog('wordpress('.$_SERVER['HTTP_HOST'].')', LOG_NDELAY|LOG_PID, LOG_AUTHPRIV);
    openlog('wordpress', LOG_NDELAY|LOG_PID, LOG_AUTHPRIV);
    syslog(LOG_NOTICE,"Pingback error ".$ixr_error->code." generated from ".$_SERVER['REMOTE_ADDR']);
    return $ixr_error;
}
add_filter('xmlrpc_pingback_error', 'fail2ban_pingback_error_hook', 1);
?>
