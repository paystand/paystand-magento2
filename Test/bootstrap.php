<?php

$magentoRoot = '/var/www/html/magento/src';
$moduleRoot  = '/var/www/html/module';

require_once $magentoRoot . '/vendor/autoload.php';

// Register the module's own PSR-4 namespace
$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('PayStand\\PayStandMagento\\', $moduleRoot . '/');
$loader->register(true);

// Backward-compatibility aliases for tests written against PHPUnit 4/5 naming
if (!class_exists('PHPUnit_Framework_TestCase')) {
    class_alias(\PHPUnit\Framework\TestCase::class, 'PHPUnit_Framework_TestCase');
}
if (!interface_exists('PHPUnit_Framework_MockObject_MockObject')) {
    class_alias(\PHPUnit\Framework\MockObject\MockObject::class, 'PHPUnit_Framework_MockObject_MockObject');
}
