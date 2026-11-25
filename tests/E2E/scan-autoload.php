<?php

declare(strict_types=1);

/*
 * Prints how the option of the last scan is loaded: `yes`/`on`/`auto-on` would mean on every page. Run with `wp eval-file`.
 */

global $wpdb;

echo $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'hornet_scan')), "\n";
