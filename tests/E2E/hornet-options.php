<?php

declare(strict_types=1);

/*
 * Prints every option Hornet keeps, one per line (name, value), for the end-to-end script to read: `wp eval-file`.
 */

global $wpdb;

foreach ($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%hornet%'", ARRAY_A) as $row) {
    echo $row['option_name'], ' ', $row['option_value'], "\n";
}
