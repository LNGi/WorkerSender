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
     * 心跳间隔
     * @var int
     */
    public $pingInterval = 0;
    
    /**
     * worker进程的通讯地址，例如Text://127.0.0.1:3000
     * @var string
     */
    public $workerAddress = 'Text://127.0.0.1:3000';
    
    /**
     * 订阅的主题对应的连接
     * @var array
     */
    protected $_subjectConnections = array();
    
    /**
     * 保存客户端的所有connection对象
     * @var array
     */
    protected $_clientConnections = array();
    
    /**
     * 保存到worker的内部连接的connection对象
     * @var array
     */
    protected $_workerConnections = array();
    
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
        $this->checkWorkerConnections();
        // 定时心跳
        if($this->pingInterval > 0 && $this->pingData)
        {
            Timer::add($this->pingInterval, array($this, 'ping'));
        }
        // 定时检查与worker的连接
        Timer::add(1, array($this, 'checkWorkerConnections'));
        if($this->_onWorkerStart)
        {
            call_user_func($this->_onWorkerStart, $this);
        }
    }
    
    /**
     * 检查与worker的连接
     * @return void
     */
    public function checkWorkerConnections()
    {
        $address = (array)$this->workerAddress;
        foreach($address as $address)
        {
            if(!isset($this->_workerConnections[$address]))
            {
                $connection_to_worker = new AsyncTcpConnection($address);
                $connection_to_worker->onMessage = array($this, 'onWorkerMessage');
                $connection_to_worker->onClose = array($this, 'onWorkerClose');
                $connection_to_worker->onError = array($this, 'onWorkerError');
                $connection_to_worker->address = $address;
                $connection_to_worker->connect();
                $this->_workerConnections[$address] = $connection_to_worker;
            }
        }
    }
    
    /**
     * 当客户端发来数据时，根据subject保存连接对象
     * @param TcpConnection $connection
     * @param mixed $data
     */
    public function onClientMessage($connection, $data)
    {
        // 如果是http协议，则数据通过GET传递
        if(isset($data['get']))
        {
            $data = $data['get'];
        }
        else 
        {
            $data = json_decode($data, true);
        }
        
        if(!isset($data['type']) || $data['type'] !== 'subscribe')
        {
            return $connection->close('type error');
        }
        
        // 没有设置订阅的主题，则直接返回
        if(!isset($data['subjects']))
        {
            return;
        }
        // 数据中多个subject由逗号分隔
        $subjects = explode(',', $data['subjects']);
        // 根据subject保存连接
        foreach($subjects as $key=>$subject)
        {
            $subject = trim($subject);
            if($subject === '')
            {
                unset($subjects[$key]);
                continue;
            }
            $this->_subjectConnections[$subject][$connection->id] = $connection;
        }
        $connection->subjects = $subjects;
        
        if($this->_onMessage)
        {
            call_user_func($this->_onMessage, $connection);
        }
    }
    
    /**
     * 当客户端关闭时
     * @param TcpConnection $connection
     */
    public function onClientClose($connection)
    {
        if(!empty($connection->subjects))
        {
            foreach($connection->subjects as $subject)
            {
                unset($this->_subjectConnections[$subject][$connection->id]);
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
        if(isset($data['subjects']))
        {
            $subjects = explode(',', $data['subjects']);
        }
        else
        {
            $subjects = '';
        }
        
        switch($type)
        {
            // 向主题订阅者发布数据
            case 'publish':
                // 没用给明主题则发送给所有订阅者
                if(empty($subjects))
                {
                    foreach($this->connections as $client_connection)
                    {
                        $client_connection->send($content);
                    }
                    return;
                }
                // 主题不为空，给这些主题的订阅者发送
                foreach($subjects as $subject)
                {
                    if(!isset($this->_subjectConnections[$subject]))
                    {
                       continue;
                    }
                    foreach($this->_subjectConnections[$subject] as $client_connection)
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
     * 根据主题向订阅者发送
     * @param string $subject 多个主题用逗号分隔
     * @param string $content
     */
    public function publish($subjects, $content)
    {
        if($this->_workerConnections)
        {
            $data = array(
                    'type' => 'publish',
                    'subjects' => $subjects,
                    'content' => $content,
            );
            $buffer = json_encode($data);
            foreach($this->_workerConnections as $connection)
            {
                $connection->send($buffer);
            }
            return;
        }
        echo "inner connection not ready\n";
    }
    
    public function onWorkerError($connection, $code, $msg)
    {
        if($code === WORKERMAN_CONNECT_FAIL)
        {
            unset($this->_workerConnections[$connetion->address]);
            echo "can not connect to {$connetion->address}\n";
        }
    }
    
    /**
     * 当worker连接关闭时
     * @param TcpConnection $connection
     */
    public function onWorkerClose($connection)
    {
        unset($this->_workerConnections[$connetion->address]);
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