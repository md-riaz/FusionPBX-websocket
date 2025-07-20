#!/usr/bin/env php
<?php
require_once __DIR__ . '/../../resources/classes/websocket_server.php';
require_once __DIR__ . '/../../resources/classes/subscriber.php';

class InboxChatServer extends websocket_server {
    private $pending_inbox = [];
    private $subscribers = [];

    protected function handshake($socket) {
        stream_set_blocking($socket, true);
        $header = '';
        while (($line = fgets($socket)) !== false) {
            $header .= $line;
            if (rtrim($line) === '') break;
        }
        $inbox = 'default';
        if (preg_match('/GET\s+([^\s]+)\s+HTTP\//', $header, $m)) {
            $uri = $m[1];
            $parts = parse_url($uri);
            parse_str($parts['query'] ?? '', $query);
            $tmp = $query['inbox'] ?? trim($parts['path'], '/');
            if ($tmp !== '') $inbox = $tmp;
        }
        if (!preg_match('/Sec-WebSocket-Key: (.*)\r\n/', $header, $m)) {
            throw new RuntimeException('Invalid WebSocket handshake');
        }
        $key = trim($m[1]);
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n".
                   "Upgrade: websocket\r\n".
                   "Connection: Upgrade\r\n".
                   "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
        fwrite($socket, $response);
        $this->pending_inbox[(int)$socket] = $inbox;
    }

    public function run_chat(string $address = '127.0.0.1', int $port = 8080) {
        $this->address = $address;
        $this->port = $port;
        $self = $this;
        $this->on_connect(function($socket) use ($self) {
            $inbox = $self->pending_inbox[(int)$socket] ?? 'default';
            $sub = new subscriber($socket, [InboxChatServer::class, 'send']);
            $sub->subscribe($inbox);
            $self->subscribers[(int)$socket] = $sub;
        });
        $this->on_message(function($socket, $msg) use ($self) {
            $sender = $self->subscribers[(int)$socket] ?? null;
            if ($sender === null) return;
            $inbox = $sender->subscribed_to()[0] ?? 'default';
            foreach ($self->subscribers as $id => $sub) {
                if ($id !== (int)$socket && $sub->has_subscribed_to($inbox)) {
                    $sub->send($msg);
                }
            }
        });
        $this->on_disconnect(function($socket) use ($self) {
            unset($self->pending_inbox[(int)$socket]);
            unset($self->subscribers[(int)$socket]);
        });
        return parent::run();
    }
}

$server = new InboxChatServer('127.0.0.1', 8080);
$server->run_chat();
