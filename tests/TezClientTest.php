<?php

declare(strict_types=1);

namespace Cloudfort\Tez\Tests;

use Cloudfort\Tez\Exceptions\TezException;
use Cloudfort\Tez\TezClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TezClientTest extends TestCase
{
    private array $fixture;
    private array $history = [];
    private MockHandler $mock;

    protected function setUp(): void
    {
        $this->fixture = json_decode(file_get_contents(__DIR__.'/fixtures/protocol.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->mock = new MockHandler();
    }

    private function client(?\Closure $clock = null, ?\Closure $nonce = null): TezClient
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        return new TezClient(
            'https://tez.example/', $this->fixture['api_key'], $this->fixture['secret_base64'],
            new Client(['handler' => $stack, 'verify' => false, 'allow_redirects' => true]),
            $clock ?? fn () => $this->fixture['timestamp'],
            $nonce ?? fn () => $this->fixture['nonce'],
        );
    }

    private function accept(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->mock->append(new Response(202, [], '{"accepted":true,"event_id":"550e8400-e29b-41d4-a716-446655440001"}'));
        }
    }

    public function testExactIndependentUnicodePublishFixture(): void
    {
        $this->accept();
        $body = json_decode($this->fixture['publish']['body'], true, 512, JSON_THROW_ON_ERROR);
        $result = $this->client()->publish($body['channels'], $body['event'], $body['data']);
        self::assertTrue($result['accepted']);
        self::assertSame('550e8400-e29b-41d4-a716-446655440001', $result['event_id']);
        $request = $this->history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://tez.example/v1/channels/events', (string) $request->getUri());
        self::assertSame($this->fixture['publish']['body'], (string) $request->getBody());
        self::assertSame($this->fixture['publish']['sha256'], hash('sha256', (string) $request->getBody()));
        self::assertSame($this->fixture['publish']['signature'], $request->getHeaderLine('X-Tez-Signature'));
        self::assertSame('app-1', $request->getHeaderLine('X-Tez-Key'));
        self::assertSame('1700000000', $request->getHeaderLine('X-Tez-Timestamp'));
        self::assertSame($this->fixture['nonce'], $request->getHeaderLine('X-Tez-Nonce'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $options = $this->history[0]['options'];
        self::assertTrue($options['verify']);
        self::assertFalse($options['allow_redirects']);
        self::assertFalse($options['http_errors']);
        self::assertSame(3, $options['connect_timeout']);
        self::assertSame(10, $options['timeout']);
    }

    #[DataProvider('jsonValues')]
    public function testGeneralJsonDataAndSocketExclusion(mixed $data): void
    {
        $this->accept();
        $this->client()->publish(['a', 'private-a', 'presence-a', 'a'], 'App\\Events\\Changed', $data, $this->fixture['socket_id']);
        $body = json_decode((string) $this->history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($data, $body['data']);
        self::assertSame(['a', 'private-a', 'presence-a'], $body['channels']);
        self::assertSame($this->fixture['socket_id'], $body['exclude_socket_id']);
    }

    public static function jsonValues(): array
    {
        return [[null], [false], [true], [42], [1.5], ['سلام'], [[]], [['socket' => 'application-data']], [[1, 'two']]];
    }

    public function testObjectData(): void
    {
        $this->accept();
        $this->client()->publish(['a'], 'event', (object) ['name' => 'José']);
        self::assertStringContainsString('"data":{"name":"José"}', (string) $this->history[0]['request']->getBody());
    }

    #[DataProvider('badChannels')]
    public function testRejectsInvalidChannelsBeforeHttp(mixed $channel): void
    {
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->client()->publish([$channel], 'event');
        } finally {
            self::assertCount(0, $this->history);
        }
    }

    public static function badChannels(): array
    {
        return [[''], [str_repeat('a', 201)], ['é'], ['a/b'], ['a b'], ['a*'], ['a{b}'],
            ["a\n"], ['private-encrypted-room'], [123], [null], [[]], ['a\\b']];
    }

    #[DataProvider('badEvents')]
    public function testRejectsInvalidEvents(string $event): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client()->publish(['a'], $event);
    }

    public static function badEvents(): array
    {
        return [[''], [str_repeat('a', 201)], [str_repeat('é', 101)], ["a\0"], ["a\n"], ["a\x7f"], ["a\u{0085}"], ["\xff"]];
    }

    public function testIdentifierAndBodyBoundaries(): void
    {
        $this->accept(3);
        $client = $this->client();
        $client->publish([str_repeat('a', 200)], str_repeat('é', 100));
        $client->publish(array_map(fn ($i) => 'channel.'.$i, range(1, 16)), 'event');
        $base = strlen('{"channels":["a"],"event":"e","data":""}');
        $client->publish(['a'], 'e', str_repeat('x', 65536 - $base));
        self::assertSame(65536, $this->history[2]['request']->getBody()->getSize());
        $this->expectException(InvalidArgumentException::class);
        $client->publish(['a'], 'e', str_repeat('x', 65537 - $base));
    }

    #[DataProvider('badPublishArguments')]
    public function testRejectsInvalidPublishInputs(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client()->publish(...$arguments);
    }

    public static function badPublishArguments(): array
    {
        return [[ [[], 'event'] ], [ [['key' => 'a'], 'event'] ],
            [ [array_map(fn ($i) => 'c'.$i, range(1, 17)), 'event'] ],
            [ [['a'], 'event', NAN] ], [ [['a'], 'event', INF] ],
            [ [['a'], 'event', "\xff"] ], [ [['a'], 'event', null, '1.2'] ],
            [ [['a'], 'event', null, ''] ]];
    }

    #[DataProvider('badConfigurations')]
    public function testRejectsInvalidConfiguration(string $endpoint, string $key, string $secret): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TezClient($endpoint, $key, $secret);
    }

    public static function badConfigurations(): array
    {
        $secret = base64_encode(str_repeat('x', 32));
        $result = [];
        foreach (['ftp://host', 'https://host/path', 'https://host?x=1', 'https://host?', 'https://host#x',
            'https://user:password@host', 'https://', "https://host\n"] as $endpoint) {
            $result[] = [$endpoint, 'key', $secret];
        }
        foreach (['', "key\n", 'key value', 'کلید'] as $key) {
            $result[] = ['https://host', $key, $secret];
        }
        foreach (['', str_repeat('x', 32), base64_encode(str_repeat('x', 31)), $secret."\n", rtrim($secret, '='), strtr(base64_encode(str_repeat("\xff", 32)), '+/', '-_')] as $invalid) {
            $result[] = ['https://host', 'key', $invalid];
        }

        return $result;
    }

    #[DataProvider('errors')]
    public function testStructuredErrorsSurfaceWithoutRetry(int $status, string $code): void
    {
        $this->mock->append(new Response($status, [], json_encode(['error' => ['code' => $code, 'message' => 'Rejected']])));
        $this->accept();
        try {
            $this->client()->publish(['a'], 'event');
            self::fail('Expected a rejection.');
        } catch (TezException $exception) {
            self::assertSame($status, $exception->statusCode);
            self::assertSame($code, $exception->errorCode);
            self::assertSame('Rejected', $exception->getMessage());
            self::assertCount(1, $this->history);
            self::assertSame(1, $this->mock->count());
        }
    }

    public static function errors(): array
    {
        return [[400, 'invalid_request'], [401, 'nonce_replay'], [403, 'forbidden'], [413, 'payload_too_large'], [429, 'rate_limited'], [503, 'unavailable']];
    }

    #[DataProvider('badResponses')]
    public function testRejectsUnexpectedResponsesWithoutFollowingRedirects(int $status, string $body): void
    {
        $this->mock->append(new Response($status, ['Location' => 'https://other.example'], $body));
        $this->accept();
        $this->expectException(TezException::class);
        try {
            $this->client()->publish(['a'], 'event');
        } finally {
            self::assertCount(1, $this->history);
            self::assertSame(1, $this->mock->count());
        }
    }

    public static function badResponses(): array
    {
        return [[302, '{}'], [200, '{"accepted":true,"event_id":"x"}'], [202, 'not-json'],
            [202, '{}'], [202, 'null'], [202, '{"accepted":1,"event_id":"x"}'],
            [202, '{"accepted":true,"event_id":""}'], [503, '"unavailable"']];
    }

    public function testTransportFailureIsNotRetried(): void
    {
        $this->mock->append(new ConnectException('Timed out', new Request('POST', 'https://tez.example')));
        $this->expectException(ConnectException::class);
        try {
            $this->client()->publish(['a'], 'event');
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    public function testDefaultNonceHasFresh128BitsOfLowercaseHex(): void
    {
        $this->accept(2);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $client = new TezClient('http://localhost:8081', 'app-1', $this->fixture['secret_base64'], new Client(['handler' => $stack]));
        $client->publish(['a'], 'event');
        $client->publish(['a'], 'event');
        $nonces = array_map(fn ($entry) => $entry['request']->getHeaderLine('X-Tez-Nonce'), $this->history);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $nonces[0]);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $nonces[1]);
        self::assertNotSame($nonces[0], $nonces[1]);
    }

    #[DataProvider('badNonces')]
    public function testRejectsMalformedInjectedNonce(mixed $nonce): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client(nonce: fn () => $nonce)->publish(['a'], 'event');
    }

    public static function badNonces(): array
    {
        return [[''], [str_repeat('A', 32)], [str_repeat('0', 31)], [str_repeat('0', 33)], [32]];
    }

    #[DataProvider('badClocks')]
    public function testRejectsMalformedClock(mixed $now): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client(clock: fn () => $now)->createSubscriptionGrant('private-a', $this->fixture['socket_id']);
    }

    public static function badClocks(): array
    {
        return [[-1], [1.5], ['1700000000'], [PHP_INT_MAX]];
    }

    public function testExactIndependentPresenceGrantFixtureAndExpiryBoundary(): void
    {
        $presence = $this->fixture['presence'];
        $grant = $this->client()->createSubscriptionGrant($presence['channel'], $this->fixture['socket_id'], $presence['user_id'], $presence['user_info']);
        self::assertSame($presence['grant'], $grant);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}\z/', $grant);
        [$encoded, $signature] = explode('.', $grant);
        $payload = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['v']);
        self::assertSame('app-1', $payload['api_key']);
        self::assertSame($this->fixture['socket_id'], $payload['socket_id']);
        self::assertSame($presence['channel'], $payload['channel']);
        self::assertSame($presence['user_id'], $payload['user_id']);
        self::assertSame($presence['user_info'], $payload['user_info']);
        self::assertSame(1700000060, $payload['exp']);
        self::assertGreaterThan(1700000059, $payload['exp']);
        self::assertLessThanOrEqual(1700000060, $payload['exp']);
        self::assertTrue($this->signatureMatches($encoded, $signature));
        foreach (['v' => 2, 'api_key' => 'other-key', 'socket_id' => '550e8400-e29b-41d4-a716-446655440002',
            'channel' => 'presence-other', 'exp' => 1700000061, 'user_id' => 'forged', 'user_info' => ['admin' => true]] as $claim => $value) {
            $tampered = $payload;
            $tampered[$claim] = $value;
            $encodedTampered = rtrim(strtr(base64_encode(json_encode($tampered)), '+/', '-_'), '=');
            self::assertFalse($this->signatureMatches($encodedTampered, $signature), $claim);
        }
        self::assertFalse($this->signatureMatches($encoded, str_repeat('A', 43)));
        self::assertFalse($this->signatureMatches($encoded, $signature, str_repeat('x', 32)));
    }

    private function signatureMatches(string $payload, string $signature, ?string $key = null): bool
    {
        $expected = hash_hmac('sha256', "TEZ-SUBSCRIBE-V1\n".$payload, $key ?? base64_decode($this->fixture['secret_base64']), true);

        return hash_equals($expected, base64_decode(strtr($signature, '-_', '+/')));
    }

    public function testPrivateAndPublicGrants(): void
    {
        $client = $this->client();
        self::assertNull($client->createSubscriptionGrant('public-room', $this->fixture['socket_id']));
        $grant = $client->createSubscriptionGrant('private-presence-room', $this->fixture['socket_id']);
        $payload = json_decode(base64_decode(strtr(explode('.', $grant)[0], '-_', '+/')), true);
        self::assertSame('private-presence-room', $payload['channel']);
        self::assertArrayNotHasKey('user_id', $payload);
        self::assertArrayNotHasKey('user_info', $payload);
    }

    #[DataProvider('badGrants')]
    public function testRejectsInvalidGrantInputs(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client()->createSubscriptionGrant(...$arguments);
    }

    public static function badGrants(): array
    {
        $socket = '550e8400-e29b-41d4-a716-446655440000';
        return [[['private-a', '1.2']], [['private-a', $socket, 'unsigned', ['name' => 'x']]],
            [['a', $socket, 'unsigned']], [['private-encrypted-a', $socket]],
            [['presence-a', $socket]], [['presence-a', $socket, '', ['name' => 'x']]],
            [['presence-a', $socket, 'u', []]], [['presence-a', $socket, 'u', null]],
            [['presence-a', $socket, str_repeat('é', 65), ['name' => 'x']]],
            [['presence-a', $socket, "u\u{0085}", ['name' => 'x']]],
            [['presence-a', $socket, "\xff", ['name' => 'x']]],
            [['presence-a', $socket, 'u', ['name' => str_repeat('x', 1024)]]]];
    }

    public function testPresenceMetadataAndIdentifierByteBoundaries(): void
    {
        $client = $this->client();
        $grant = $client->createSubscriptionGrant('presence-a', $this->fixture['socket_id'], str_repeat('é', 64), ['x' => str_repeat('a', 1016)]);
        self::assertNotEmpty($grant);
        $this->expectException(InvalidArgumentException::class);
        $client->createSubscriptionGrant('presence-a', $this->fixture['socket_id'], 'u', ['x' => str_repeat('a', 1017)]);
    }
}
