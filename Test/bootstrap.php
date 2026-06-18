<?php
$magentoRoot = '/var/www/html';
$moduleRoot  = '/var/www/html/app/code/PayStand/PayStandMagento';

require_once $magentoRoot . '/vendor/autoload.php';

$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('PayStand\\PayStandMagento\\', $moduleRoot . '/');
$loader->register(true);

// Explicitly require files with non-standard PSR-4 casing.
// Controller/webhook/PayStand.php has lowercase 'w' and uppercase 'S' which
// doesn't match the PSR-4 derivation of the class name on Linux (case-sensitive).
require_once $moduleRoot . '/Controller/webhook/PayStand.php';

if (!class_exists('PHPUnit_Framework_TestCase')) {
    class_alias(\PHPUnit\Framework\TestCase::class, 'PHPUnit_Framework_TestCase');
}
if (!interface_exists('PHPUnit_Framework_MockObject_MockObject')) {
    class_alias(\PHPUnit\Framework\MockObject\MockObject::class, 'PHPUnit_Framework_MockObject_MockObject');
}
