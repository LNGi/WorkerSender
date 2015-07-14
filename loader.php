<?php

$workerman_dir_name = 'Workerman';
if(strrpos(strtolower(PHP_OS),"win") !== FALSE)
{
    $workerman_dir_name = 'workerman-for-win';
}

require_once __DIR__ . '/../' . $workerman_dir_name . '/Autoloader.php';
