<?php 
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;
use \Workerman\Lib\Timer;

// 自动加载类
require_once __DIR__ . '/../workerman-for-win/Autoloader.php';

// proxy 进程
$proxy = new Worker("Text://0.0.0.0:2015");
// 设置名称，方便status时查看
$proxy->name = 'worker';
// 设置进程数1
$proxy->count = 1;

$proxy->onWorkerStart = function($proxy)
{
    
};

$proxy->onMessage = function($connection ,$data)
{
    
};

// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}

function creat_worker_connection()
{
    global $proxy;
    
    $worker_connection = new AsyncTcpConnection('Text://127.0.0.1:2016');
    
    $worker_connection->onError = function($connection, $code, $msg)
    {
        echo $msg;
    };
    
    $worker_connection->onClose = function($connection)
    {
        // 断开后定时重连
        Timer::add(1, 'creat_worker_connection', array(), false);
    };
    
    $worker_connection->onMessage = function($connection, $data)
    {
        if(!isset($data['type']) || !isset($data['tag']) || !isset($data['content']))
        {
            return $worker_connection->close('bad request');
        }
        $type = $data['type'];
        $tag = $data['tag'];
        $content = $data['content'];
        
    };
    $worker_connection->connect();
}
