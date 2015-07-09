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

use Workerman\Connection\AsyncTcpConnection;
use \Workerman\Worker;
use \Workerman\Lib\Timer;

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
     * worker进程的通讯地址，例如127.0.0.1:2015
     * @var string
     */
    public $workerAddress = '127.0.0.1:2015';
    
    /**
     * 标签标记的连接
     * @var array
     */
    protected $_tagConnections = array();
    
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
     * 当worker启动时
     * @var callback
     */
    protected $_onWorkerStart = null;
    
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
    
    /**
     * 给每次推送的消息做编号，目的是去重
     * @var int
     */
    protected $_messageId = 0;
    
    /**
     * 进程启动时间
     * @var int
     */
    protected $_startTime = 0;
    
    /**
     * 运行
     * @see Workerman.Worker::run()
     */
    public function run()
    {
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onWorkerStart = $this->onWorkerStart;
        $this->onWorkerStart = array($this, 'onWorkerStart');
        
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onMessage = $this->onMessage;
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
     * 进程启动时触发
     * @return void
     */
    public function onWorkerStart()
    {
        $this->connectWorker();
        if($this->_onWorkerStart)
        {
            call_user_func($this->_onWorkerStart, $this);
        }
    }
    
    public function connectWorker()
    {
        if($this->_workerConnection)
        {
            $this->_workerConnection->close();
        }
        $this->_workerConnection = new AsyncTcpConnection("Text://{$this->workerAddress}");
        $this->_workerConnection->onMessage = array($this, 'onWorkerMessage');
        $this->_workerConnection->onClose = array($this, 'onWorkerClose');
        $this->_workerConnection->onError= array($this, 'onWorkerError');
        $this->_workerConnection->connect();
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
            $this->_tagConnections[$tag][$connection->id] = $connection;
        }
        
        if($this->_onMessage)
        {
            call_user_func($this->_onMessage, $connection);
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
                unset($this->_tagConnections[$tag][$connection->id]);
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
        $this->_messageId++;
        $data = json_decode($data, true);
        $type = $data['type'];
        $content = $data['content'];
        switch($type)
        {
            // 向某客户端发送数据，Gateway::sendToClient($client_id, $message);
            case 'send_to_all':
                foreach($this->connections as $client_connection)
                {
                    $client_connection->send($content);
                }
                break;
            case 'send_by_tag':
                foreach($data['tags'] as $tag)
                {
                    if(!isset($this->_tagConnections[$tag]))
                    {
                       continue;
                    }
                    foreach($this->_tagConnections[$tag] as $client_connection)
                    {
                        if(!isset($client_connection->messageId))
                        {
                            $client_connection->messageId = 0;
                        }
                        // 发过了就不再发了
                        if($this->_messageId == $client_connection->messageId)
                        {
                            continue;
                        }
                        // 发送消息
                        $client_connection->send($content);
                        // 标记这个消息已经发过
                        $client_connection->messageId = $this->_messageId;
                    }
                }
                break;
        }
    }
    
    /**
     * 当与Worker的连接出现错误时，定时重连
     * @param TcpConnection $connection
     * @param int $code
     * @param string $msg
     */
    public function onWorkerError($connection, $code, $msg)
    {
        Timer::add(1, array($this, 'connectWorker'));
    }
    
    /**
     * 向所有在线客户端发送数据
     * @param string $content
     */
    public function sendToAll($content)
    {
        if($this->_workerConnection)
        {
            $data = array(
                'type' => 'send_to_all',
                'content' => $content,
            );
            return $this->_workerConnection->send(json_encode($data));
        }
        echo "inner connection not ready\n";
    }
    
    /**
     * 根据标签发送
     * @param string/array $tag 可以是单个tag字符串，也可以是tag数组
     * @param string $content
     */
    public function sendByTag($tag, $content)
    {
        if($this->_workerConnection)
        {
            $data = array(
                    'type' => 'send_by_tag',
                    'content' => $content,
            );
            return $this->_workerConnection->send(json_encode($data));
        }
        echo "inner connection not ready\n";
    }
    
    /**
     * 当worker连接关闭时
     * @param TcpConnection $connection
     */
    public function onWorkerClose($connection)
    {
        Timer::add(1, array($this, 'connectWorker'));
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