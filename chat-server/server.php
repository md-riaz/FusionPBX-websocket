<?php

require_once __DIR__ . '/Chat.php';
require_once __DIR__ . '/IoServer.php';

$chat = new Chat();
$server = IoServer::factory($chat, '127.0.0.1', 8080);
$server->run();

