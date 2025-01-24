<?php

require 'vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop as EventLoop;
use React\Socket\SocketServer;
use Clue\React\Redis\RedisClient;

class PubSubServer implements MessageComponentInterface
{
    protected $clients;
    protected $hostPort;
    protected $data;
    protected $redis;
    protected $pendingConnections = [];

    public function __construct($hostPort)
    {
        $this->clients = new \SplObjectStorage();

        $this->hostPort = $hostPort;

        $this->data = [];

        $this->redis = new RedisClient($this->hostPort);

        $this->subscribe('message');
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);

        $this->pendingConnections[$conn->resourceId] = $conn;

        echo "New connection! Socket: {$conn->resourceId}" . PHP_EOL;

        $conn->send(
            json_encode([
                'type' => 'request_token',
                'message' => 'Please provide your authentication token.',
            ]),
        );
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);

        
        if (!is_array($data) || !isset($data['type'])) {
            $from->send(
                json_encode([
                    'type' => 'error',
                    'message' => 'Invalid payload format.',
                ]),
            );
            return;
        }

        if ($data['type'] === 'token') {
            
            if (!isset($data['token'])) {
                $from->send(
                    json_encode([
                        'type' => 'error',
                        'message' => 'Token is required.',
                    ]),
                );
                return;
            }

            $token = $data['token'];

            $this->redis->set("socket:{$token}", $from->resourceId);

            $from->token = $token;

            unset($this->pendingConnections[$from->resourceId]);

            $from->send(
                json_encode([
                    'type' => 'token_acknowledged',
                    'message' => "Token {$token} has been registered.",
                ]),
            );

            echo "Token {$token} registered for Socket: {$from->resourceId}" . PHP_EOL;
            return;
        }

        if ($data['type'] === 'message') {
            
            if (!isset($data['token'], $data['toToken'], $data['message'])) {
                $from->send(
                    json_encode([
                        'type' => 'error',
                        'message' => 'Message payload must include token, toToken, and message.',
                    ]),
                );
                return;
            }

            $fromToken = $data['token'];
            $toToken = $data['toToken'];
            $message = $data['message'];

            $this->redis->get("socket:{$fromToken}")->then(function ($fromResourceId) use ($from, $toToken, $message, $fromToken) {
                
                if (!$fromResourceId) {
                    $from->send(
                        json_encode([
                            'type' => 'error',
                            'message' => 'Invalid sender token.',
                        ]),
                    );
                    return;
                }

                $this->redis->get("socket:{$toToken}")->then(function ($toResourceId) use ($from, $message, $fromToken, $toToken) {
                    
                    if (!$toResourceId) {
                        $from->send(
                            json_encode([
                                'type' => 'error',
                                'message' => 'Invalid recipient token.',
                            ]),
                        );
                        return;
                    }

                    
                    foreach ($this->clients as $client) {
                        if ($client->resourceId == $toResourceId) {
                            $client->send(
                                json_encode([
                                    'type' => 'message',
                                    'fromToken' => $fromToken,
                                    'message' => $message,
                                ]),
                            );

                            echo "Message from {$fromToken} to {$toToken}: {$message}" . PHP_EOL;
                            return;
                        }
                    }

                    $from->send(
                        json_encode([
                            'type' => 'error',
                            'message' => 'Recipient is not connected.',
                        ]),
                    );
                });
            });
        }
    }

    public function onClose(ConnectionInterface $conn)
    {

        if (isset($conn->token)) {

            echo "Removing token: {$conn->token}" . PHP_EOL;
            $this->redis->del("socket:{$conn->token}");
        }
    
        $this->clients->detach($conn);
        unset($this->pendingConnections[$conn->resourceId]);

        echo "Connection {$conn->resourceId} has disconnected." . PHP_EOL;
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "An error occurred: {$e->getMessage()}" . PHP_EOL;
        $conn->close();
    }

    public function subscribe($channel)
    {
        $redis = new RedisClient($this->hostPort);

        $redis->subscribe($channel);

        $redis->on('message', function ($channel, $messageJson) 
        {

            echo "Received message on Redis channel '{$channel}': {$messageJson}" . PHP_EOL;

            $message = json_decode($messageJson, true);
           
            if (!isset($message['token'], $message['toToken'], $message['type'], $message['message'])) {
                echo "Some data is missing" . PHP_EOL;
                return;
            }

            if (!in_array($message['type'], ['message', 'notification'])) {
                echo "Invalid message type" . PHP_EOL;
                return;
            }

            $fromToken = $message['token'];
            $toToken = $message['toToken'];


            $this->redis->get("socket:{$toToken}")->then(function ($toResourceId) use ($fromToken, $message, $toToken) {
                

                echo "toResourceId: {$toResourceId}" . PHP_EOL;

                if (!$toResourceId) {
                    echo "Recipient is not connected" . PHP_EOL;
                    return;
                }

                foreach ($this->clients as $client) {

                    if ($client->resourceId == $toResourceId) {

                        $client->send(
                            json_encode([
                                'type' => $message['type'],
                                'fromToken' => $fromToken,
                                'message' => $message['message'],
                            ]),
                        );
                        echo "Message from {$fromToken} to {$toToken}: {$message['message']}" . PHP_EOL;
                        return;
                    }
                }
                
            });
        });
    }
}

$pubsub = '127.0.0.1:6379';
$socket = '127.0.0.1:8080';

$loop = EventLoop::get();

$pubSubServer = new PubSubServer($pubsub);
$socketServer = new SocketServer($socket, [], $loop);

$ioServer = new IoServer(new HttpServer(new WsServer($pubSubServer)), $socketServer, $loop);

echo "WebSocket Socket Server has started on $socket" . PHP_EOL;

$loop->run();
