<?php

require_once __DIR__ . '/MessageComponentInterface.php';
require_once __DIR__ . '/Connection.php';

class IoServer {
    private MessageComponentInterface $app;
    private string $address;
    private int $port;
    private $serverSocket;
    private array $clients = [];
    private bool $running = false;

    public function __construct(MessageComponentInterface $app, string $address = '127.0.0.1', int $port = 8080) {
        $this->app = $app;
        $this->address = $address;
        $this->port = $port;
    }

    public static function factory(MessageComponentInterface $app, string $address = '127.0.0.1', int $port = 8080): self {
        return new self($app, $address, $port);
    }

    public function run(): int {
        $this->serverSocket = stream_socket_server("tcp://{$this->address}:{$this->port}", $errno, $errstr);
        if (!$this->serverSocket) {
            throw new RuntimeException("Cannot bind socket ({$errno}): {$errstr}");
        }
        stream_set_blocking($this->serverSocket, false);
        $this->running = true;

        while ($this->running) {
            $read = [$this->serverSocket];
            foreach ($this->clients as $conn) {
                $read[] = $conn->resource();
            }
            $write = $except = [];
            if (false === stream_select($read, $write, $except, null)) {
                break;
            }
            if (in_array($this->serverSocket, $read, true)) {
                $socket = @stream_socket_accept($this->serverSocket, 0);
                if ($socket) {
                    $topic = $this->handshake($socket);
                    stream_set_blocking($socket, false);
                    $conn = new Connection($socket);
                    $conn->subscribe($topic);
                    $this->clients[(int)$socket] = $conn;
                    try {
                        $this->app->onOpen($conn);
                    } catch (\Exception $e) {
                        $this->app->onError($conn, $e);
                    }
                }
                $key = array_search($this->serverSocket, $read, true);
                if ($key !== false) unset($read[$key]);
            }
            foreach ($read as $socket) {
                $conn = $this->clients[(int)$socket] ?? null;
                if ($conn === null) continue;
                $msg = $this->receiveFrame($socket);
                if ($msg === '' && feof($socket)) {
                    $this->removeClient($socket, $conn);
                    continue;
                }
                try {
                    $this->app->onMessage($conn, $msg);
                } catch (\Exception $e) {
                    $this->app->onError($conn, $e);
                }
            }
        }

        foreach ($this->clients as $conn) {
            $conn->close();
        }
        if (is_resource($this->serverSocket)) {
            fclose($this->serverSocket);
        }
        return 0;
    }

    private function removeClient($socket, Connection $conn): void {
        unset($this->clients[(int)$socket]);
        try {
            $this->app->onClose($conn);
        } catch (\Exception $e) {
            $this->app->onError($conn, $e);
        }
        $conn->close();
    }

    private function handshake($socket): string {
        stream_set_blocking($socket, true);
        $header = '';
        while (($line = fgets($socket)) !== false) {
            $header .= $line;
            if (rtrim($line) === '') break;
        }
        $topic = 'default';
        if (preg_match('/GET\s+([^\s]+)\s+HTTP\//', $header, $m)) {
            $uri = $m[1];
            $parts = parse_url($uri);
            parse_str($parts['query'] ?? '', $query);
            $tmp = $query['inbox'] ?? trim($parts['path'], '/');
            if ($tmp !== '') $topic = $tmp;
        }
        if (!preg_match('/Sec-WebSocket-Key: (.*)\r\n/', $header, $m)) {
            throw new RuntimeException('Invalid WebSocket handshake');
        }
        $key = trim($m[1]);
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n" .
                   "Upgrade: websocket\r\n" .
                   "Connection: Upgrade\r\n" .
                   "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
        fwrite($socket, $response);
        stream_set_blocking($socket, false);
        return $topic;
    }

    private function readBytes($socket, int $length): string {
        $data = '';
        while (strlen($data) < $length && is_resource($socket)) {
            $chunk = fread($socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function receiveFrame($socket): string {
        $header = $this->readBytes($socket, 2);
        if ($header === '') return '';
        $byte1 = ord($header[0]);
        $byte2 = ord($header[1]);
        $final = ($byte1 >> 7) & 1;
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 >> 7) & 1;
        $length = $byte2 & 0x7F;
        if ($length === 126) {
            $extended = $this->readBytes($socket, 2);
            $length = unpack('n', $extended)[1];
        } elseif ($length === 127) {
            $extended = $this->readBytes($socket, 8);
            $length = 0;
            for ($i = 0; $i < 8; $i++) {
                $length = ($length << 8) | ord($extended[$i]);
            }
        }
        $mask = '';
        if ($masked) {
            $mask = $this->readBytes($socket, 4);
        }
        $payload = $this->readBytes($socket, $length);
        if ($masked) {
            $decoded = '';
            for ($i = 0; $i < $length; $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $decoded;
        }
        if ($opcode === 0x8) {
            return '';
        }
        return $payload;
    }
}
