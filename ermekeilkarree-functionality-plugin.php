<?php
/*
   Plugin Name: Ermekeilkarree Functionality Plugin
   Description: Plugin for site ermekeilkarree.de offering functionality that is independent from themes. Activate this plugin for the whole multisite network to make it an integral part of the network.
   Version: 0.5.0
   License: MIT
   Author: Daniel Appelt
   Author URI: https://github.com/danielappelt
   See also: http://wpcandy.com/teaches/how-to-create-a-functionality-plugin/
 */

/*
   Register global menu locations for navigation and footer that appear across the network.

   See http://wpmututorials.com/plugins/add-a-global-menu-to-your-network/
 */
register_nav_menu( 'global', 'Global Navigation Menu' );
register_nav_menu( 'footer', 'Global Footer Menu' );

function global_menu_init() {
    if (!is_main_site()) {
        // Remove the menu from menu screens on sub sites to avoid confusions.
        unregister_nav_menu('global');
        unregister_nav_menu('footer');
        unregister_nav_menu('mobile-nav');
    }
}
// Remove global menus from admin and customizing areas in sub sites.
add_action('admin_init', 'global_menu_init');
add_action('start_previewing_theme', 'global_menu_init');

/*
   Inject menus defined for the network's main site above into other sites
   on the network.
 */
function global_menu_filter($output, $args) {
    // This is the current network's information; 'site' is old terminology.
    global $current_site;

    $sorted_menu_items = null;
    if(!is_main_site()) {
        if($args->theme_location == "global"
        || $args->theme_location == "footer"
        || $args->theme_location == "mobile-nav") {
            // Retrieve the main site's global menu.
            switch_to_blog($current_site->blog_id);
            $sorted_menu_items = wp_nav_menu($args);
            restore_current_blog();
        }
    }

    return $sorted_menu_items;
}
// See http://codex.wordpress.org/Function_Reference/add_filter for priority and accepted_args.
add_filter('pre_wp_nav_menu', 'global_menu_filter', 10, 2);

/*
    Register another menu which might be used on each blog to add sub site navigation.
*/
register_nav_menu( 'site', 'Site Menu' );

/*
    Create a custom post type to handle custom alerts to be placed on the front page.
    TODO: Maybe it's easier to just use a plugin for this. For example:
    https://wordpress.org/plugins/custom-post-type-ui/
    https://wordpress.org/plugins/pods/
    https://github.com/wpmetabox/mb-custom-post-type
*/
function create_custom_post_alert() {
    $labels = array(
            'name'               => _x( 'Alerts', 'post type general name' ),
            'singular_name'      => _x( 'Alert', 'post type singular name' ),
            'add_new'            => _x( 'Add New', 'post type alert' ),
            'add_new_item'       => __( 'Add New Alert' ),
            'edit_item'          => __( 'Edit Alert' ),
            'new_item'           => __( 'New Alert' ),
            'all_items'          => __( 'All Alerts' ),
            'view_item'          => __( 'View Alert' ),
            'search_items'       => __( 'Search Alerts' ),
            'not_found'          => __( 'No alerts found' ),
            'not_found_in_trash' => __( 'No alerts found in the Trash' ),
            'parent_item_colon'  => '',
            'menu_name'          => 'Alerts'
            );

    $args = array(
            'labels'        => $labels,
            'description'   => 'Custom alerts to be placed on the front page',
            'public'        => true,
            'menu_position' => 5,
            'supports'      => array( 'editor' ),
            'has_archive'   => true
            );

    register_post_type( 'alert', $args );
}
add_action( 'init', 'create_custom_post_alert' );

/*
  Reduce alert columns to relevant entries.
 */
function edit_alert_columns( $columns ) {
    $columns = array(
            'cb' => '<input type="checkbox" />',
            'date' => __( 'Date' ),
            'title' => __( 'Alert' ),
            'content' => __( 'Content' )
            );

    return $columns;
}
add_filter( 'manage_edit-alert_columns', 'edit_alert_columns' ) ;

# TODO: we would like to have the functionality of the 'title' column
# but with only the excerpt being displayed. This does not seem to be easily possible.
function manage_alert_columns( $column, $post_id ) {
    global $post;

    the_excerpt();
}
add_action( 'manage_alert_posts_custom_column', 'manage_alert_columns', 10, 2 );

/*
  Retrieve garden opening times for the following 7 days from Events Manager plugin.
 */
function get_garden_opening() {
    $args = array(
        # Retrieve garden events for the next 7 days including today
        'scope' => date('Y-m-d', strtotime('today')).','.date('Y-m-d', strtotime('today +6 days')),
        'category' => '7', # TODO: garden category - should be customizable
        'pagination' => false,
        'order_by' => 'event_start_date,event_start_time,event_end_time',
    );

    $events = EM_Events::get( $args );

#    echo EM_Events::output( $args );

    # TODO: A sweep algorithm could be a better solution.
    # Create a bucket for every day and collect opening times into each bucket
    $buckets = array(
        strtotime('today') => [],
        strtotime('today +1 day') => [],
        strtotime('today +2 days') => [],
        strtotime('today +3 days') => [],
        strtotime('today +4 days') => [],
        strtotime('today +5 days') => [],
        strtotime('today +6 days') => [],
    );

    # PHP REPL: https://repl.it/repls/
    # http://www.the-art-of-web.com/php/strtotime/
    # http://wp-events-plugin.com/documentation/event-search-attributes/
    # https://github.com/bippo/events-manager-wordpress/blob/23cc0d4bbd9b9f3a8aacb1dc0428c1298dba5dbc/templates/templates/rss.php#L22
    foreach ( $events as $event ) {
        # Put all events belonging to a certain day into the same bucket
        $bucket =& $buckets[strtotime($event->event_start_date)];
        $last = count($bucket) - 1;

        if($last > 0 && $event->event_start_time <= $bucket[$last]) {
            if($event->event_end_time > $bucket[$last]) {
                # Create a consecutive time span
                $bucket[$last] = $event->event_end_time;
            } // Otherwise $event is completely contained
        } else {
            # Create a new time span
            array_push($bucket, $event->event_start_time, $event->event_end_time);
        }

        # The following statement is essential! See https://repl.it/repls/MistyModestNlp
        unset($bucket);
    }

#    echo '<pre>'; var_dump($buckets); echo '</pre>';

    $timeline = array();
    foreach ( $buckets as $day => $bucket ) {
        # Now each bucket contains a list of non-consecutive start and end times.
        # Try to find commonalities in the days, i.e. compute the list of individual entries
        $isMatch = false;

        foreach($timeline as $refDay => $days) {
            # Create a common output if consecutive days have the same opening times.
            # Using count and equality comparison seems to yield better results than array_diff
            if($day == end($days) + 86400 &&
               count($bucket) == count($buckets[$refDay]) && $bucket == $buckets[$refDay]) {
                array_push($timeline[$refDay], $day);
                $isMatch = true;
                break;
            }
        }

        if(!$isMatch) {
            $timeline[$day] = [$day];
        }
    }

#    echo '<pre>'; var_dump($timeline); echo '</pre>';

    # Create beautiful keys from $timeline, i.e. [Mo, Tu, We] => Mo-We
    $result = array();
    foreach( $timeline as $refDay => $days ) {
        # TODO: respect l18n in date function
        if(count($days) > 2) {
            $result[date_i18n('D', $days[0]).'-'.date_i18n('D', end($days))] = $buckets[$refDay];
        } else {
            $result[join(', ', array_map(function($v) { return date_i18n('D', $v); }, $days))] = $buckets[$refDay];
        }
    }

    return $result;
}

/*
  Add long description and cover image settings for blogs. These will be
  displayed on the respective front pages as well as on the network front
  page's sub site selection.
  TODO: Maybe it's easier to just use a plugin for this. For example:
  https://github.com/CMB2/CMB2
  https://metabox.io/
 */
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
