<?php
date_default_timezone_set('UTC');
include 'init_autoloader.php';
define('RINDOW_TEST_CACHE',     __DIR__.'/cache');
define('RINDOW_TEST_CLEAR_CACHE_INTERVAL',100000);
Rindow\Stdlib\Cache\CacheFactory::$fileCachePath = RINDOW_TEST_CACHE;
Rindow\Stdlib\Cache\CacheFactory::$enableMemCache = true;
Rindow\Stdlib\Cache\CacheFactory::$enableFileCache = false;
//Rindow\Stdlib\Cache\CacheFactory::$notRegister = true;
Rindow\Stdlib\Cache\CacheFactory::clearCache();

if(!class_exists('PHPUnit\Framework\TestCase')) {
    include __DIR__.'/travis/patch55.php';
}
