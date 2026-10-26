<?php

declare(strict_types=1);
use Composer\Autoload\ClassLoader;

require __DIR__.'/../vendor/autoload.php';

// Explicit test-only source overlay; this is not an installed/released dependency.
$sdkRoot = getenv('LINKADO_TEST_PHP_SDK_PATH');

if (is_string($sdkRoot) && $sdkRoot !== '') {
    $manifest = json_decode((string) file_get_contents($sdkRoot.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    if (($manifest['name'] ?? null) !== 'linkado-ru/php-sdk') {
        throw new RuntimeException('Expected the canonical Linkado PHP SDK test source.');
    }
    $loader = new ClassLoader;
    $loader->addPsr4('Linkado\\PhpSdk\\', $sdkRoot.'/src');
    $loader->register(prepend: true);
}
