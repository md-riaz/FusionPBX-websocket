# OOP Chat Server

This folder contains a minimal chat server built from scratch using
Ratchet-like callbacks. The implementation reuses the handshake logic
from the original `examples/inbox-chat` example but is completely
self contained.

Each connection subscribes to a topic ("inbox") extracted from the URL.
Messages are only broadcast to other clients subscribed to the same
topic.

## Files

- `MessageComponentInterface.php` – interface defining the four
  callback methods `onOpen`, `onMessage`, `onClose`, and `onError`.
- `Connection.php` – represents a single client connection and stores
  its topic subscriptions.
- `IoServer.php` – lightweight WebSocket server that performs the
  handshake, reads frames and invokes the callbacks on a provided
  application.
- `Chat.php` – simple chat application that forwards messages to all
  peers with matching subscriptions.
- `server.php` – entry point to run the server on `127.0.0.1:8080`.

Run the chat server with:

```bash
php server.php
```

Connecting to `ws://127.0.0.1:8080/?inbox=myroom` will subscribe the
client to `myroom`. Only clients in the same room receive each other's
messages.
