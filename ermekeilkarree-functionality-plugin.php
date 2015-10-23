<?php
/*
   Plugin Name: Ermekeilkarree Functionality Plugin
   Description: Plugin for site ermekeilkarree.de offering functionality that is independent from themes. Activate this plugin for the whole multisite network to make at an integral part of the network.
   Version: 0.1
   License: MIT
   Author: Daniel Appelt
   Author URI: https://github.com/danielappelt
   See also: http://wpcandy.com/teaches/how-to-create-a-functionality-plugin/

 */

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
?>
