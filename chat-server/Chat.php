<?php

require_once __DIR__ . '/MessageComponentInterface.php';
require_once __DIR__ . '/Connection.php';

class Chat implements MessageComponentInterface {
    /** @var \SplObjectStorage */
    private $clients;

    public function __construct() {
        $this->clients = new \SplObjectStorage();
    }

    public function onOpen(Connection $conn): void {
        $this->clients->attach($conn);
    }

    public function onMessage(Connection $from, string $msg): void {
        $topics = $from->getSubscriptions();
        foreach ($this->clients as $client) {
            if ($client === $from) {
                continue;
            }
            foreach ($topics as $topic) {
                if ($client->hasSubscription($topic)) {
                    $client->send($msg);
                    break;
                }
            }
        }
    }

    public function onClose(Connection $conn): void {
        $this->clients->detach($conn);
    }

    public function onError(Connection $conn, \Exception $e): void {
        $conn->close();
    }
}
