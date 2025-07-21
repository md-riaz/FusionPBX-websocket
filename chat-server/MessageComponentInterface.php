<?php

interface MessageComponentInterface {
    public function onOpen(Connection $conn): void;
    public function onMessage(Connection $from, string $msg): void;
    public function onClose(Connection $conn): void;
    public function onError(Connection $conn, \Exception $e): void;
}

