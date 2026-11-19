<?php

declare(strict_types=1);

$autoload = getenv('ALMA_TEST_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Set ALMA_TEST_AUTOLOAD to an existing Composer vendor/autoload.php.');
}

require $autoload;

// The host may already map this package to another checkout.
spl_autoload_register(static function (string $class): void {
    foreach ([
        'Alma\\SyliusPaymentPlugin\\' => dirname(__DIR__) . '/src/',
        'Tests\\Alma\\SyliusPaymentPlugin\\' => __DIR__ . '/',
    ] as $prefix => $directory) {
        if (0 !== strpos($class, $prefix)) {
            continue;
        }

        $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }

        return;
    }
}, true, true);
