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
$worker = new Worker("Text://0.0.0.0:2015");
// 设置名称，方便status时查看
$worker->name = 'senderWorker';
// 设置进程数1
$worker->count = 1;
// 进程启动后，在当前进程初始化一个http协议的端口，用来推送数据
$worker->onWorkerStart = function()
{
    $http_worker = new Worker('Http://0.0.0.0:2016');
    $http_worker->onMessage = function($connection, $data)
    {
        if(!isset($_GET['type']) || !isset($_GET['content']))
        {
            return $connection->close('bad request');
        }
        broadcast(json_encode($_GET));
        return $connection->close('ok');
    };
    $http_worker->listen();
};

// 将消息转发给所有的proxy进程
$worker->onMessage = function($connection ,$data)
{
    broadcast($data);
};

// 广播给所有的proxy进程
function broadcast($message)
{
    global $worker;
    foreach($worker->connections as $connection)
    {
        $connection->send($message);
    }
}

// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
