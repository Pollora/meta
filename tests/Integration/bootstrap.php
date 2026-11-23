<?php

declare(strict_types=1);

/**
 * Bootstrap of the integration suite: loads a real WordPress, once, before the first test.
 *
 * Copy it to tests/Integration/bootstrap.php (the phpunit.integration.xml next to it points there; the paths below
 * assume that location). The `WordPress integration` job of the harness workflow installs WordPress and exports WP_PATH (the directory that holds
 * wp-load.php); locally, install WordPress anywhere and export WP_PATH, or pass it on the command line:
 *
 *     WP_PATH=/path/to/wordpress vendor/bin/pest -c phpunit.integration.xml
 *
 * Composer's autoloader is loaded first, by this file, so that the classes under test and Pest itself are available.
 * WordPress is then loaded with `wp-load.php`: the site is installed, its plugins are active, its hooks fire.
 */
$autoload = dirname(__DIR__, 2).'/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "Composer's autoloader is missing: run composer install.\n");
    exit(1);
}

require_once $autoload;

$wordpressPath = getenv('WP_PATH') ?: (getenv('WP_CORE_DIR') ?: '');

if ($wordpressPath === '' || ! is_file($wordpressPath.'/wp-load.php')) {
    fwrite(STDERR, "WordPress not found: set WP_PATH to the directory that holds wp-load.php (got \"{$wordpressPath}\").\n");
    exit(1);
}

// No theme output. The wp-config.php of the harness job already turns WP-Cron off: defining DISABLE_WP_CRON here, before
// wp-load.php, makes that file's own define() raise "Constant already defined" (a warning, in PHP 8).
define('WP_USE_THEMES', false);

require_once $wordpressPath.'/wp-load.php';

unset($autoload, $wordpressPath);
