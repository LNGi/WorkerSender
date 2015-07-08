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

use Workerman\Connection\TcpConnection;
use \Workerman\Worker;
use \Workerman\Lib\Timer;
use \Workerman\Autoloader;

/**
 * 
 * Gateway，基于Worker开发
 * 用于转发客户端的数据给Worker处理，以及转发Worker的数据给客户端
 * 
 * @author walkor<walkor@workerman.net>
 *
 */
class Proxy extends Worker
{
    /**
     * 是否可以平滑重启，gateway不能平滑重启，否则会导致连接断开
     * @var bool
     */
    public $reloadable = false;
    
    /**
     * 服务端向客户端发送的心跳数据
     * @var string
     */
    public $pingData = '';
    
    /**
     * 标签标记的连接
     * @var array
     */
    protected $tagConnections = array();
    
    /**
     * 保存客户端的所有connection对象
     * @var array
     */
    protected $_clientConnections = array();
    
    /**
     * 保存到worker的内部连接的connection对象
     * @var array
     */
    protected $_workerConnection = null;
    
    /**
     * 当客户端发来消息时
     * @var callback
     */
    protected $_onMessage = null;
    
    /**
     * 当客户端连接关闭时
     * @var callback
     */
    protected $_onClose = null;
    
    //protedted $
    
    /**
     * 进程启动时间
     * @var int
     */
    protected $_startTime = 0;
    
    /**
     * 构造函数
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct($socket_name, $context_option = array())
    {
        parent::__construct($socket_name, $context_option);
        
        $backrace = debug_backtrace();
        $this->_appInitPath = dirname($backrace[0]['file']);
    }
    
    /**
     * 运行
     * @see Workerman.Worker::run()
     */
    public function run()
    {
        // onMessage禁止用户设置回调
        $this->onMessage = array($this, 'onClientMessage');
        
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onClose = $this->onClose;
        $this->onClose = array($this, 'onClientClose');
        
        // 记录进程启动的时间
        $this->_startTime = time();
        // 运行父方法
        parent::run();
    }
    
    /**
     * 当客户端发来数据时，根据tag保存连接对象
     * @param TcpConnection $connection
     * @param mixed $data
     */
    public function onClientMessage($connection, $data)
    {
        // 如果是http协议，则数据通过GET传递
        if(!empty($_GET))
        {
            $data = $_GET;
        }
        // 没有设置标签，则直接返回
        if(!isset($data['tags']))
        {
            return;
        }
        // 数据中多个tag由逗号分隔
        $tags = explode(',', $data['tags']);
        // 根据tag保存连接
        foreach($tags as $key=>$tag)
        {
            $tag = trim($tag);
            if($tag === '')
            {
                unset($tags[$key]);
                continue;
            }
            $this->tagConnections[$tag][$connection->id] = $connection;
        }
    }
    
    /**
     * 当客户端关闭时
     * @param unknown_type $connection
     */
    public function onClientClose($connection)
    {
        if(!empty($connection->tags))
        {
            foreach($connection->tags as $tag)
            {
                unset($this->tagConnections[$tag][$connection->id]);
            }
        }
        if($this->_onClose)
        {
            call_user_func($this->_onClose, $connection);
        }
    }
    
    
    /**
     * 当worker发来数据时
     * @param TcpConnection $connection
     * @param mixed $data
     * @throws \Exception
     */
    public function onWorkerMessage($connection, $data)
    {
        $cmd = $data['cmd'];
        switch($cmd)
        {
            // 向某客户端发送数据，Gateway::sendToClient($client_id, $message);
            case 'send_to_all':
                foreach($this->connections as $client_connection)
                {
                    $client_connection->send($data['content']);
                }
                break;
            case 'send_to_tag':
                foreach($data['tags'] as $tag)
                {
                    
                }
                
        }
    }
    
    public function sendToAll()
    {
        
    }
    
    public function sendToTags()
    {
        
    }
    
    /**
     * 当worker连接关闭时
     * @param TcpConnection $connection
     */
    public function onWorkerClose($connection)
    {
        //$this->log("{$connection->remoteAddress} CLOSE INNER_CONNECTION\n");
        unset($this->_workerConnections[$connection->remoteAddress]);
    }
    
    
    /**
     * 心跳逻辑
     * @return void
     */
    public function ping()
    {
        // 遍历所有客户端连接
        foreach($this->connections as $connection)
        {
            if($this->pingData)
            {
                $connection->send($this->pingData);
            }
        }
    }
}