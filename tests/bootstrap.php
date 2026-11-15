<?php

declare(strict_types=1);

/*
 * The package's own vendor/ when it is installed; until the kernel it needs is
 * published, the kernel checked out beside it (../winter-kernel), with this package's
 * classes registered on its autoloader.
 */
$own = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($own)) {
    require $own;
    return;
}
$kernel = dirname(__DIR__, 2) . '/winter-kernel/vendor/autoload.php';
if (!is_file($kernel)) {
    fwrite(STDERR, "Run composer install, or check winter-kernel out beside this package.\n");
    exit(1);
}
/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $kernel;
$loader->addPsr4('Flytachi\\Winter\\Service\\Redis\\', dirname(__DIR__) . '/src/');
$loader->addPsr4('Flytachi\\Winter\\Service\\Redis\\Tests\\', __DIR__ . '/');
