<?php
$magentoRoot = '/var/www/html';
$moduleRoot  = '/var/www/html/app/code/PayStand/PayStandMagento';

require_once $magentoRoot . '/vendor/autoload.php';

$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('PayStand\\PayStandMagento\\', $moduleRoot . '/');
$loader->register(true);

if (!class_exists('PHPUnit_Framework_TestCase')) {
    class_alias(\PHPUnit\Framework\TestCase::class, 'PHPUnit_Framework_TestCase');
}
if (!interface_exists('PHPUnit_Framework_MockObject_MockObject')) {
    class_alias(\PHPUnit\Framework\MockObject\MockObject::class, 'PHPUnit_Framework_MockObject_MockObject');
}
