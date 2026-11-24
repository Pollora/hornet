<?php

/**
 * Plugin Name: Pollora Hornet
 * Description: Security audit and hardening for WordPress (pollora/hornet).
 *
 * Copy this file to wp-content/mu-plugins/ (app/mu-plugins/ on Bedrock). Composer's autoloader
 * must be loaded before: Bedrock does it, a classic install can require vendor/autoload.php
 * from wp-config.php. Settings: define('HORNET_CONFIG', [...]) in wp-config.php.
 * To switch Hornet off without touching the code: define('HORNET_DISABLED', true).
 */

declare(strict_types=1);

use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;

if (! class_exists(Bootstrap::class)) {
    return;
}

Bootstrap::fromWordPress();
