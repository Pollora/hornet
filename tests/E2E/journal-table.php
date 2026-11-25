<?php

declare(strict_types=1);

/*
 * Prints the name of the table of the journal if it exists, `none` if it does not. Run with `wp eval 'require "...";'`.
 */

global $wpdb;

echo $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix.'hornet_journal')) ?: 'none', "\n";
