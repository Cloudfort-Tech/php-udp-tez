# Tez — PHP SDK & Laravel Broadcaster

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-red)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-green)](LICENSE)

**Tez** is a PHP SDK and Laravel broadcaster for the [Tez](https://tez.live) realtime broadcasting service. Publish events to channels, authenticate private and presence subscriptions, and integrate seamlessly with Laravel's broadcasting system.

---

## Contents

- [Installation](#installation)
- [Configuration](#configuration)
  - [Environment Variables](#environment-variables)
  - [Publishing the Config](#publishing-the-config)
- [Standalone PHP SDK](#standalone-php-sdk)
  - [Creating a Client](#creating-a-client)
  - [Publishing Events](#publishing-events)
  - [Creating Subscription Grants](#creating-subscription-grants)
- [Laravel Integration](#laravel-integration)
  - [Broadcast Driver Setup](#broadcast-driver-setup)
  - [Broadcasting Events](#broadcasting-events)
  - [Channel Authentication](#channel-authentication)
  - [Presence Channels](#presence-channels)
- [Channel Types](#channel-types)
- [Protocol Constants](#protocol-constants)
- [Error Handling](#error-handling)
- [Testing](#testing)
- [License](#license)

---

## Installation

```bash
composer require cloudfort/tez
```

**Requirements:** PHP 8.2+

For Laravel integration, the package auto-discovers its service provider. No manual registration needed.

---

## Configuration

### Environment Variables

Add these to your `.env` file:

```env
TEZ_ENDPOINT=https://your-tez-server.example.com
TEZ_KEY=your-api-key
TEZ_SECRET=your-base64-secret
```

| Variable | Description |
|---|---|
| `TEZ_ENDPOINT` | The base URL of your Tez server (scheme + host only, no path) |
| `TEZ_KEY` | Your API key (alphanumeric, plus `.`, `_`, `:`, `-`) |
| `TEZ_SECRET` | Your base64-encoded secret (must decode to at least 32 bytes) |

### Publishing the Config

To customize the configuration file, publish it with:

```bash
php artisan vendor:publish --tag=tez-config
```

This copies `config/tez.php` into your application's `config/` directory.

---

## Standalone PHP SDK

The SDK can be used without Laravel.

### Creating a Client

```php
use Cloudfort\Tez\TezClient;

$client = new TezClient(
    endpoint: 'https://your-tez-server.example.com',
    apiKey:   'your-api-key',
    secret:   'your-base64-secret',
);
```

Optional constructor parameters:

| Parameter | Type | Default | Purpose |
|---|---|---|---|
| `$http` | `GuzzleHttp\ClientInterface` | Internal Guzzle client | Custom HTTP client |
| `$clock` | `Closure(): int` | `time()` | Injectable clock (useful for testing) |
| `$nonce` | `Closure(): string` | `bin2hex(random_bytes(16))` | Injectable nonce generator |

### Publishing Events

```php
$result = $client->publish(
    channels: ['orders', 'notifications'],
    event:    'order.created',
    data:     ['id' => 42, 'total' => 99.99],
);

// $result = ['accepted' => true, 'event_id' => '...']
```

To exclude a specific socket (e.g., the sender's own connection):

```php
$client->publish(
    channels:        ['orders'],
    event:           'order.created',
    data:            ['id' => 42],
    excludeSocketId: '550e8400-e29b-41d4-a716-446655440000',
);
```

**Constraints:**
- Up to 16 channels per publish
- Body must not exceed 65,536 bytes
- Channel names: 1-200 ASCII characters (`A-Za-z0-9._:-`)
- Event names: non-empty UTF-8, up to 200 bytes, no control characters

### Creating Subscription Grants

Generate authentication tokens for private and presence channels:

```php
// Private channel
$grant = $client->createSubscriptionGrant(
    channel:  'private-orders',
    socketId: '550e8400-e29b-41d4-a716-446655440000',
);

// Presence channel
$grant = $client->createSubscriptionGrant(
    channel:  'presence-chat',
    socketId: '550e8400-e29b-41d4-a716-446655440000',
    userId:   '42',
    userInfo: ['name' => 'Jane', 'role' => 'admin'],
);
```

Returns `null` for public channels (no authentication required).

---

## Laravel Integration

### Broadcast Driver Setup

In `config/broadcasting.php`, add a `tez` connection:

```php
'connections' => [
    'tez' => [
        'driver'  => 'tez',
        'endpoint' => env('TEZ_ENDPOINT', 'http://localhost:8080'),
        'api_key'  => env('TEZ_KEY'),
        'secret'   => env('TEZ_SECRET'),
    ],
],
```

Then set the broadcast driver in your `.env`:

```env
BROADCAST_CONNECTION=tez
```

### Broadcasting Events

Use Laravel's built-in broadcasting. Create an event that implements `ShouldBroadcast`:

```php
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public int $orderId) {}

    public function broadcastOn(): array
    {
        return [new Channel('orders')];
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->orderId];
    }
}
```

Dispatch it as usual:

```php
event(new OrderCreated(42));
```

### Channel Authentication

Tez handles authentication routes automatically through the `TezBroadcaster`. Register your channel authorization callbacks in `routes/channels.php`:

```php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('orders.{orderId}', function ($user, int $orderId) {
    return $user->canViewOrder($orderId);
});
```

### Presence Channels

For presence channels, return a non-empty array of user info from the channel callback:

```php
Broadcast::channel('chat.{roomId}', function ($user, int $roomId) {
    return ['name' => $user->name, 'avatar' => $user->avatar_url];
});
```

The array is sent as `user_info` in the subscription grant. It must not exceed 1,024 bytes when JSON-encoded.

---

## Channel Types

| Type | Prefix | Auth Required | Description |
|---|---|---|---|
| **Public** | _(none)_ | No | Any client can subscribe |
| **Private** | `private-` | Yes | Server-authorizes each subscription |
| **Presence** | `presence-` | Yes | Private + tracks connected users with metadata |

> **Note:** Encrypted channels (`private-encrypted-*`) are not supported.

---

## Protocol Constants

| Constant | Value | Description |
|---|---|---|
| `Protocol::MAX_CHANNELS` | 16 | Max channels per publish call |
| `Protocol::MAX_BODY_BYTES` | 65,536 | Max request body size |
| `Protocol::MAX_USER_INFO_BYTES` | 1,024 | Max user info payload for presence |
| `Protocol::GRANT_TTL` | 60s | Subscription grant time-to-live |
| `Protocol::PUBLISH_PATH` | `/v1/channels/events` | API publish endpoint |

---

## Error Handling

All server errors throw `Cloudfort\Tez\Exceptions\TezException`:

```php
use Cloudfort\Tez\Exceptions\TezException;

try {
    $client->publish(['orders'], 'order.created', ['id' => 1]);
} catch (TezException $e) {
    echo $e->getMessage();     // Human-readable error
    echo $e->statusCode;       // HTTP status code (e.g. 401, 403, 422)
    echo $e->errorCode;        // Machine-readable code (e.g. 'invalid_signature')
}
```

Validation errors throw `InvalidArgumentException` before any HTTP request is made.

---

## Testing

```bash
composer test
```

Or directly:

```bash
vendor/bin/phpunit
```

---

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
