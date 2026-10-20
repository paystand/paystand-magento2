<?php
$magentoRoot = '/var/www/html';
$moduleRoot  = dirname(__DIR__);

require_once $magentoRoot . '/vendor/autoload.php';

$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('PayStand\\PayStandMagento\\', $moduleRoot . '/');
$loader->register(true);

// Controller/webhook/PayStand.php does not match its PSR-4 path on case-sensitive systems.
require_once $moduleRoot . '/Controller/webhook/PayStand.php';

if (!class_exists('PHPUnit_Framework_TestCase')) {
    class_alias(\PHPUnit\Framework\TestCase::class, 'PHPUnit_Framework_TestCase');
}
if (!interface_exists('PHPUnit_Framework_MockObject_MockObject')) {
    class_alias(\PHPUnit\Framework\MockObject\MockObject::class, 'PHPUnit_Framework_MockObject_MockObject');
}
