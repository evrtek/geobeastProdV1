# GeoBeasts WebSocket Server

Real-time chat server using Ratchet WebSocket library.

## Installation

1. Install dependencies via Composer:
```bash
cd /path/to/geobeasts
composer install
```

2. Ensure PHP CLI is available (PHP 7.4 or higher)

## Running the Server

Start the WebSocket server:

```bash
php backend/websocket/ChatServer.php
```

The server will listen on port 8443 (default). SSL termination is handled by reverse proxy.
Production URL: `wss://your-domain.com:8443`

## Client Connection

### Authentication

When connecting to the WebSocket server, clients must authenticate:

```javascript
const ws = new WebSocket('wss://localhost:8443');

ws.onopen = () => {
  // Send auth token (from cookie)
  ws.send(JSON.stringify({
    type: 'authenticate',
    auth_token: 'user_id:timestamp:signature'
  }));
};

ws.onmessage = (event) => {
  const data = JSON.parse(event.data);

  if (data.type === 'authenticated') {
    console.log('Authenticated as user', data.user_id);
  }
};
```

### Sending Messages

```javascript
ws.send(JSON.stringify({
  type: 'chat_message',
  recipient_user_id: 123,
  message_text: 'Hello!'
}));
```

### Typing Indicators

```javascript
// Start typing
ws.send(JSON.stringify({
  type: 'typing',
  recipient_user_id: 123,
  is_typing: true
}));

// Stop typing
ws.send(JSON.stringify({
  type: 'typing',
  recipient_user_id: 123,
  is_typing: false
}));
```

### Keep-Alive Ping

```javascript
// Send ping every 30 seconds
setInterval(() => {
  ws.send(JSON.stringify({ type: 'ping' }));
}, 30000);
```

## Message Types

### Client → Server

- `authenticate` - Authenticate with auth token
- `chat_message` - Send chat message to friend
- `typing` - Send typing indicator
- `ping` - Keep-alive ping

### Server → Client

- `authenticated` - Authentication successful
- `auth_error` - Authentication failed
- `chat_message` - Incoming chat message
- `message_sent` - Message delivery confirmation
- `typing` - Friend is typing
- `pong` - Ping response
- `error` - General error

## Production Deployment

For production, consider:

1. **Process Manager**: Use supervisor or systemd to keep the WebSocket server running
2. **Reverse Proxy**: Use Nginx to proxy WebSocket connections
3. **SSL/TLS**: Use `wss://` instead of `ws://` for secure connections
4. **Horizontal Scaling**: Use Redis pub/sub for multi-server deployments

### Supervisor Configuration

```ini
[program:geobeasts-websocket]
command=php /path/to/geobeasts/backend/websocket/ChatServer.php
directory=/path/to/geobeasts
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/geobeasts-websocket.log
stderr_logfile=/var/log/geobeasts-websocket-error.log
```

### Nginx Reverse Proxy (SSL)

```nginx
location /ws {
    proxy_pass http://localhost:8443;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 86400;
}
```

## Troubleshooting

**Connection refused:**
- Ensure the WebSocket server is running
- Check firewall rules allow port 8443

**Authentication fails:**
- Verify AUTH_SECRET environment variable matches backend config
- Check auth token format is correct

**Messages not delivering:**
- Ensure both sender and recipient are authenticated
- Check friendship exists between users
- Verify WebSocket connection is active

## Architecture

The WebSocket server:
- Maintains persistent connections to authenticated users
- Routes messages between friends in real-time
- Handles typing indicators
- Provides connection status updates
- Integrates with the REST API for message persistence

Messages sent via WebSocket are ephemeral for real-time delivery. All messages are also stored in the database via the Chat API (`/api/chat`) for message history.
