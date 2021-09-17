<?php

// ensure we get report on all possible php errors
error_reporting(-1);

define('YII_ENABLE_ERROR_HANDLER', false);
define('YII_DEBUG', true);
define('YII_ENV', 'test');
$_SERVER['SCRIPT_NAME'] = '/' . __DIR__;
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

define('VENDOR_PATH', __DIR__ . '/../vendor');
define('TESTS_PATH', __DIR__ . '/');

require_once(VENDOR_PATH . '/autoload.php');
require_once(VENDOR_PATH . '/yiisoft/yii2/Yii.php');

Yii::setAlias('@mhthnz/tarantool', __DIR__ . '/../src');
Yii::setAlias('@mhthnz/tarantool/tests', TESTS_PATH);
