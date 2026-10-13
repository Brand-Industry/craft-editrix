<?php

/**
 * Boots a real Craft install so the tests run against actual elements, the
 * real queue tables and the real plugin. Run from the root of a Craft project
 * that has Editrix installed (see tests/README.md); the project's database is
 * used, so never point this at production.
 */

$base = rtrim(getenv('EDITRIX_TEST_CRAFT_PATH') ?: getcwd(), '/');

if (!is_file($base . '/vendor/craftcms/cms/bootstrap/console.php')) {
    fwrite(STDERR, "No Craft project found at {$base}. Run the tests from a Craft project root, or set EDITRIX_TEST_CRAFT_PATH.\n");
    exit(1);
}

define('CRAFT_BASE_PATH', $base);
define('CRAFT_VENDOR_PATH', $base . '/vendor');

require CRAFT_VENDOR_PATH . '/autoload.php';

if (class_exists(Dotenv\Dotenv::class)) {
    // Craft 4 ships phpdotenv 3, which has no createUnsafeMutable().
    if (method_exists(Dotenv\Dotenv::class, 'createUnsafeMutable')) {
        Dotenv\Dotenv::createUnsafeMutable(CRAFT_BASE_PATH)->safeLoad();
    } elseif (is_file(CRAFT_BASE_PATH . '/.env')) {
        Dotenv\Dotenv::create(CRAFT_BASE_PATH)->load();
    }
}

if (strtolower((string) getenv('CRAFT_ENVIRONMENT')) === 'production') {
    fwrite(STDERR, "Refusing to run against a production environment.\n");
    exit(1);
}

// Craft's console bootstrap reads argv; keep PHPUnit's own arguments out of it.
$_SERVER['argv'] = ['craft'];

$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

if (!Craft::$app->getPlugins()->getPlugin('editrix')) {
    fwrite(STDERR, "The editrix plugin is not installed in this Craft project.\n");
    exit(1);
}

require __DIR__ . '/php/Compat.php';
require __DIR__ . '/php/EditrixTestCase.php';
