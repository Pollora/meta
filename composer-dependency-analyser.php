<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * ARCH-07: the harness preset (WordPress classes and functions, the tests), plus what is specific to this package.
 */
$configuration = require __DIR__.'/vendor/pollora/harness/presets/composer-dependency-analyser.php';

return $configuration
    // class_basename() comes with the framework (illuminate/support), which this package runs under; the discovery package brings spatie/php-structure-discoverer.
    ->ignoreErrorsOnPackages(['illuminate/support', 'spatie/php-structure-discoverer'], [ErrorType::SHADOW_DEPENDENCY])
    // Colt is optional: its model classes are only read when it is installed (suggested).
    ->ignoreErrorsOnPackage('pollora/colt', [ErrorType::DEV_DEPENDENCY_IN_PROD]);
