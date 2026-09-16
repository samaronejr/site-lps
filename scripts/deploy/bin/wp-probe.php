<?php
require '/wordpress/wp-load.php';
echo 'WP_LOADED ' . get_bloginfo('version') . ' home=' . home_url('/') . "\n";
echo 'env=' . (defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : '') . "\n";
echo 'debug=' . (defined('WP_DEBUG') && WP_DEBUG ? 'on' : 'off') . "\n";
echo 'plugins=' . wp_json_encode(get_option('active_plugins')) . "\n";
echo 'theme=' . get_stylesheet() . "\n";
echo 'object_cache=' . (wp_using_ext_object_cache() ? 'persistent' : 'none') . "\n";
echo 'languages=' . (function_exists('pll_languages_list') ? wp_json_encode(pll_languages_list()) : '[]') . "\n";
