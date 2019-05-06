<?php
date_default_timezone_set('UTC');
include 'init_autoloader.php';

if(!class_exists('PHPUnit\Framework\TestCase')) {
    include __DIR__.'/travis/patch55.php';
}
