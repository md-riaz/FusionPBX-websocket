<?php

class Connection {
    private $socket;
    private array $subscriptions = [];

    public function __construct($socket) {
        $this->socket = $socket;
    }

    public function send(string $msg): void {
        $chunkSize = 4096;
        $payloadLen = strlen($msg);
        $offset = 0;
        $first = true;
        while ($offset < $payloadLen) {
            $remaining = $payloadLen - $offset;
            $chunk = substr($msg, $offset, min($chunkSize, $remaining));
            $chunkLen = strlen($chunk);
            $fin = ($offset + $chunkSize >= $payloadLen) ? 0x80 : 0x00;
            $opcode = $first ? 0x1 : 0x0;
            $first = false;
            $header = chr($fin | $opcode);
            if ($chunkLen <= 125) {
                $header .= chr($chunkLen);
            } elseif ($chunkLen <= 65535) {
                $header .= chr(126) . pack('n', $chunkLen);
            } else {
                $lenBytes = '';
                for ($i = 7; $i >= 0; $i--) {
                    $lenBytes .= chr(($chunkLen >> ($i * 8)) & 0xFF);
                }
                $header .= chr(127) . $lenBytes;
            }
            @fwrite($this->socket, $header . $chunk);
            $offset += $chunkLen;
        }
    }

    public function close(): void {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, "\x88\x00");
            @fclose($this->socket);
        }
    }

    public function subscribe(string $topic): void {
        $this->subscriptions[$topic] = true;
    }

    public function hasSubscription(string $topic): bool {
        return isset($this->subscriptions[$topic]);
    }

    public function getSubscriptions(): array {
        return array_keys($this->subscriptions);
    }

    public function resource() {
        return $this->socket;
    }
}
