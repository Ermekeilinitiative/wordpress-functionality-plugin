<?php
/*
   Plugin Name: Ermekeilkarree Functionality Plugin
   Description: Plugin for site ermekeilkarree.de offering functionality that is independent from themes. Activate this plugin for the whole multisite network to make at an integral part of the network.
   Version: 0.3
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
