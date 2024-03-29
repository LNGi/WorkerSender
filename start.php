<?php
/**
 * 注意：windows系统不要启动这个文件，
 * windows系统请使用start_for_win.bat启动
 * run with command 
 * php start.php start
 */

ini_set('display_errors', 'on');
use Workerman\Worker;

// 检查扩展
if(!extension_loaded('pcntl'))
{
    exit("Please install pcntl extension. See http://doc3.workerman.net/install/install.html\n");
}

if(!extension_loaded('posix'))
{
    exit("Please install posix extension. See http://doc3.workerman.net/install/install.html\n");
}

// 标记是全局启动
define('GLOBAL_START', 1);

require_once __DIR__ . '/loader.php';

// 加载所有Applications/*/start.php，以便启动所有服务
foreach(glob(__DIR__.'/start_*.php') as $start_file)
{
    require_once $start_file;
}
// 运行所有服务
Worker::runAll();
