<?php

declare(strict_types=1);

namespace Cloudfort\Tez;

use Cloudfort\Tez\Exceptions\TezException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;

final class TezClient
{
    private string $endpoint;
    private string $apiKey;
    private string $secret;
    private ClientInterface $http;
    /** @var \Closure():int */
    private \Closure $clock;
    /** @var \Closure():string */
    private \Closure $nonce;

    public function __construct(
        string $endpoint,
        string $apiKey,
        string $secret,
        ?ClientInterface $http = null,
        ?\Closure $clock = null,
        ?\Closure $nonce = null,
    ) {
        self::validateEndpoint($endpoint);
        self::validateApiKey($apiKey);
        $decodedSecret = self::validateSecret($secret);

        $this->endpoint = $endpoint;
        $this->apiKey = $apiKey;
        $this->secret = $decodedSecret;
        $this->http = $http ?? new Client([
            'timeout' => 10,
            'connect_timeout' => 3,
            'verify' => true,
            'allow_redirects' => false,
        ]);
        $this->clock = $clock ?? static fn (): int => time();
        $this->nonce = $nonce ?? static fn (): string => bin2hex(random_bytes(16));
    }

    /**
     * Publish an event to one or more channels.
     *
     * @param list<string> $channels
     */
    public function publish(array $channels, string $event, mixed $data = null, ?string $excludeSocketId = null): array
    {
        // --- validate channels ---
        if ($channels === [] || array_is_list($channels) === false) {
            throw new InvalidArgumentException('Channels must be a non-empty list of strings.');
        }
        $seen = [];
        foreach ($channels as $ch) {
            $ch = Protocol::channel($ch);
            $seen[$ch] = $ch;
        }
        if (count($seen) > Protocol::MAX_CHANNELS) {
            throw new InvalidArgumentException('At most '.Protocol::MAX_CHANNELS.' channels per publish.');
        }

        // --- validate event ---
        Protocol::event($event);

        // --- validate data (reject NAN / INF / non-UTF-8) ---
        $body = ['channels' => array_values($seen), 'event' => $event, 'data' => $data];
        if ($excludeSocketId !== null) {
            $body['exclude_socket_id'] = Protocol::socketId($excludeSocketId);
        }
        $json = Protocol::json($body);
        if (strlen($json) > Protocol::MAX_BODY_BYTES) {
            throw new InvalidArgumentException('Body exceeds '.Protocol::MAX_BODY_BYTES.' bytes.');
        }

        // --- sign ---
        $timestamp = self::validateClock(($this->clock)());
        $nonce = self::validateNonce(($this->nonce)());
        $signature = $this->signPublish((string) $timestamp, $nonce, $json);

        // --- send ---
        try {
            $response = $this->http->request('POST', rtrim($this->endpoint, '/').'/v1/channels/events', [
                'headers' => [
                    'X-Tez-Key' => $this->apiKey,
                    'X-Tez-Timestamp' => (string) $timestamp,
                    'X-Tez-Nonce' => $nonce,
                    'X-Tez-Signature' => $signature,
                    'Content-Type' => 'application/json',
                ],
                'body' => $json,
                'verify' => true,
                'allow_redirects' => false,
                'http_errors' => false,
                'timeout' => 10,
                'connect_timeout' => 3,
            ]);
        } catch (ConnectException $e) {
            throw $e;
        }

        $status = $response->getStatusCode();
        $responseBody = (string) $response->getBody();

        if ($status !== 202) {
            $decoded = json_decode($responseBody, true);
            $code = $decoded['error']['code'] ?? 'unknown';
            $message = $decoded['error']['message'] ?? 'Unknown error';
            throw new TezException($message, $status, $code);
        }

        $result = json_decode($responseBody, true);
        if (! is_array($result)
            || ! array_key_exists('accepted', $result) || $result['accepted'] !== true
            || ! array_key_exists('event_id', $result) || ! is_string($result['event_id']) || $result['event_id'] === '') {
            throw new TezException('Unexpected response from Tez server.', $status, 'unexpected_response');
        }

        return $result;
    }

    /**
     * Create a subscription grant for a guarded channel.
     * Returns null for public (non-guarded) channels.
     */
    public function createSubscriptionGrant(
        string $channel,
        string $socketId,
        ?string $userId = null,
        mixed $userInfo = null,
    ): ?string {
        $channel = Protocol::channel($channel);
        $socketId = Protocol::socketId($socketId);
        $timestamp = self::validateClock(($this->clock)());

        if (! Protocol::isGuarded($channel)) {
            if ($userId !== null) {
                throw new InvalidArgumentException('User identifier is only valid for presence channels.');
            }

            return null;
        }

        if (! Protocol::isPresence($channel)) {
            // Private (non-presence) channel.
            if ($userId !== null) {
                throw new InvalidArgumentException('User identifier is only valid for presence channels.');
            }

            $json = Protocol::json([
                'v' => 1,
                'api_key' => $this->apiKey,
                'socket_id' => $socketId,
                'channel' => $channel,
                'exp' => $timestamp + Protocol::GRANT_TTL,
            ]);
            $encoded = Protocol::base64url($json);
            $signature = hash_hmac('sha256', "TEZ-SUBSCRIBE-V1\n".$encoded, $this->secret, true);

            return $encoded.'.'.Protocol::base64url($signature);
        }

        // Presence channel.
        if ($userId === null || $userId === '') {
            throw new InvalidArgumentException('Presence channel requires a non-empty user identifier.');
        }
        $userId = Protocol::userId($userId);
        if (! is_array($userInfo) || $userInfo === []) {
            throw new InvalidArgumentException('Presence channel requires a non-empty array for user info.');
        }
        $infoJson = Protocol::json($userInfo);
        if (strlen($infoJson) > Protocol::MAX_USER_INFO_BYTES) {
            throw new InvalidArgumentException('User info exceeds '.Protocol::MAX_USER_INFO_BYTES.' bytes.');
        }

        $payload = [
            'v' => 1,
            'api_key' => $this->apiKey,
            'socket_id' => $socketId,
            'channel' => $channel,
            'exp' => $timestamp + Protocol::GRANT_TTL,
            'user_id' => $userId,
            'user_info' => $userInfo,
        ];
        $json = Protocol::json($payload);
        $encoded = Protocol::base64url($json);
        $signature = hash_hmac('sha256', "TEZ-SUBSCRIBE-V1\n".$encoded, $this->secret, true);

        return $encoded.'.'.Protocol::base64url($signature);
    }

    // ─── Configuration validators ────────────────────────────────────

    private static function validateEndpoint(string $endpoint): void
    {
        if ($endpoint === '' || preg_match('/[\r\n]/', $endpoint) === 1) {
            throw new InvalidArgumentException('Endpoint must be a valid HTTP(S) URL.');
        }
        $parts = parse_url($endpoint);
        if ($parts === false
            || ! isset($parts['scheme']) || ! in_array($parts['scheme'], ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['path']) && $parts['path'] !== '/'
            || isset($parts['query'])
            || array_key_exists('fragment', $parts) && $parts['fragment'] !== null && $parts['fragment'] !== ''
            || isset($parts['user'])
        ) {
            throw new InvalidArgumentException('Endpoint must be a plain HTTP(S) URL with scheme and host only.');
        }
    }

    private static function validateApiKey(string $key): void
    {
        if ($key === '' || strlen($key) > 200 || preg_match('/\A[A-Za-z0-9._:-]+\z/D', $key) !== 1) {
            throw new InvalidArgumentException('API key must be 1-200 alphanumeric characters (plus . _ : -).');
        }
    }

    private static function validateSecret(string $secret): string
    {
        if ($secret === '' || preg_match('/[^A-Za-z0-9+\/=]/', $secret) === 1) {
            throw new InvalidArgumentException('Secret must be standard base64-encoded.');
        }
        // Reject base64 with missing padding (must satisfy len % 4 == 0).
        if (strlen($secret) % 4 !== 0) {
            throw new InvalidArgumentException('Secret must be standard base64-encoded with proper padding.');
        }
        $decoded = base64_decode($secret, true);
        if ($decoded === false || strlen($decoded) < 32) {
            throw new InvalidArgumentException('Secret must decode to at least 32 bytes.');
        }

        return $decoded;
    }

    private static function validateClock(mixed $value): int
    {
        if (! is_int($value) || $value <= 0 || $value === PHP_INT_MAX) {
            throw new InvalidArgumentException('Clock must return a positive integer less than PHP_INT_MAX.');
        }

        return $value;
    }

    private static function validateNonce(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{32}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Nonce must be exactly 32 lowercase hex characters.');
        }

        return $value;
    }

    // ─── Signing helpers ─────────────────────────────────────────────

    private function signPublish(string $timestamp, string $nonce, string $body): string
    {
        $bodyHash = hash('sha256', $body);
        $canonical = implode("\n", [
            'TEZ-PUBLISH-V1',
            'POST',
            Protocol::PUBLISH_PATH,
            $this->apiKey,
            $timestamp,
            $nonce,
            $bodyHash,
        ]);

        return hash_hmac('sha256', $canonical, $this->secret);
    }
}
