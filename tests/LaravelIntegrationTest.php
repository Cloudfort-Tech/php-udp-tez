<?php

declare(strict_types=1);

namespace Cloudfort\Tez\Tests;

use Cloudfort\Tez\Broadcasting\TezBroadcaster;
use Cloudfort\Tez\Exceptions\TezException;
use Cloudfort\Tez\TezClient;
use Cloudfort\Tez\TezServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Auth\GenericUser;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class LaravelIntegrationTest extends TestCase
{
    private const SOCKET = '550e8400-e29b-41d4-a716-446655440000';
    private array $history = [];
    private MockHandler $mock;

    protected function getPackageProviders($app): array
    {
        return [TezServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        $app['config']->set('tez', $this->connectionConfig('plain-only', 'p'));
        $app['config']->set('broadcasting.default', 'null');
        $app['config']->set('broadcasting.connections', [
            'tenant-a' => $this->connectionConfig('key-a', 'a'),
            'tenant-b' => $this->connectionConfig('key-b', 'b'),
        ]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.guards.staff', ['driver' => 'staff-test']);
    }

    protected function defineRoutes($router): void
    {
        $router->post('/owned/channel-auth', fn (Request $request) => Broadcast::connection('tenant-a')->auth($request))
            ->middleware('web')->name('owned.channel-auth');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockHandler();
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $this->app->instance('tez.http_client', new Client(['handler' => $stack]));
        Auth::viaRequest('staff-test', static fn (Request $request) => $request->header('X-Staff') === 'yes'
            ? new TezBroadcastUser(['id' => 99, 'broadcast_id' => 'staff-99']) : null);
    }

    private function connectionConfig(string $key, string $secretByte): array
    {
        return ['driver' => 'tez', 'endpoint' => 'https://'.$key.'.example', 'api_key' => $key, 'secret' => base64_encode(str_repeat($secretByte, 32))];
    }

    private function broadcaster(string $connection = 'tenant-a'): TezBroadcaster
    {
        return $this->app->make(BroadcastManager::class)->connection($connection);
    }

    private function authRequest(mixed $channel, mixed $socket = self::SOCKET, ?object $user = null): Request
    {
        $request = Request::create('/owned/channel-auth', 'POST', ['channel_name' => $channel, 'socket_id' => $socket]);
        $request->setUserResolver(static fn ($guard = null) => $user);

        return $request;
    }

    private function claims(array $response, string $secretByte = 'a'): array
    {
        self::assertSame(['auth'], array_keys($response));
        [$encoded, $signature] = explode('.', $response['auth']);
        self::assertSame(hash_hmac('sha256', "TEZ-SUBSCRIBE-V1\n".$encoded, str_repeat($secretByte, 32), true), base64_decode(strtr($signature, '-_', '+/')));

        return json_decode(base64_decode(strtr($encoded, '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function accept(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->mock->append(new Response(202, [], '{"accepted":true,"event_id":"550e8400-e29b-41d4-a716-446655440001"}'));
        }
    }

    private function published(int $index = 0): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testProviderDiscoveryMetadataConfigPublishingAndApplicationIsolation(): void
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        self::assertContains(TezServiceProvider::class, $manifest['extra']['laravel']['providers']);
        self::assertInstanceOf(TezBroadcaster::class, $this->broadcaster());
        self::assertSame('null', config('broadcasting.default'));
        self::assertSame(['tenant-a', 'tenant-b'], array_keys(config('broadcasting.connections')));
        self::assertSame('web', config('auth.defaults.guard'));
        self::assertSame(['driver' => 'staff-test'], config('auth.guards.staff'));
        self::assertContains($this->app->configPath('tez.php'), ServiceProvider::pathsToPublish(TezServiceProvider::class, 'tez-config'));
        $route = $this->app['router']->getRoutes()->getByName('owned.channel-auth');
        self::assertContains('web', $route->middleware());
        self::assertSame([], $route->excludedMiddleware());
        foreach ($this->app['router']->getRoutes() as $registered) {
            self::assertNotSame('broadcasting/auth', $registered->uri());
            self::assertNotSame('broadcasting/user-auth', $registered->uri());
        }
    }

    public function testIndependentConnectionsAndPlainClientNeverShareCredentials(): void
    {
        $this->accept(3);
        $a = $this->broadcaster();
        $b = $this->broadcaster('tenant-b');
        self::assertNotSame($a, $b);
        self::assertSame($a, $this->broadcaster());
        $a->broadcast(['private-room'], 'Event');
        $b->broadcast(['private-room'], 'Event');
        $plain = $this->app->make(TezClient::class);
        self::assertSame($plain, $this->app->make(TezClient::class));
        $plain->publish(['private-room'], 'Event');
        foreach (['key-a' => 'a', 'key-b' => 'b', 'plain-only' => 'p'] as $key => $secretByte) {
            $entry = array_shift($this->history);
            $request = $entry['request'];
            self::assertSame($key, $request->getHeaderLine('X-Tez-Key'));
            self::assertSame($key.'.example', $request->getUri()->getHost());
            $canonical = implode("\n", ['TEZ-PUBLISH-V1', 'POST', '/v1/channels/events', $key,
                $request->getHeaderLine('X-Tez-Timestamp'), $request->getHeaderLine('X-Tez-Nonce'), hash('sha256', (string) $request->getBody())]);
            self::assertSame(hash_hmac('sha256', $canonical, str_repeat($secretByte, 32)), $request->getHeaderLine('X-Tez-Signature'));
        }
        $a->channel('room', fn ($user) => true);
        $b->channel('room', fn ($user) => true);
        $request = $this->authRequest('private-room', user: new GenericUser(['id' => 1]));
        self::assertSame('key-a', $this->claims($a->auth($request))['api_key']);
        self::assertSame('key-b', $this->claims($b->auth($request), 'b')['api_key']);
    }

    public function testMissingConnectionCredentialsDoNotFallBackToPlainConfig(): void
    {
        config(['broadcasting.connections.incomplete' => ['driver' => 'tez']]);
        $this->expectException(InvalidArgumentException::class);
        $this->broadcaster('incomplete');
    }

    public function testProviderAlsoExtendsAnAlreadyResolvedManager(): void
    {
        $manager = $this->app->make(BroadcastManager::class);
        (new TezServiceProvider($this->app))->boot();
        self::assertInstanceOf(TezBroadcaster::class, $manager->connection('tenant-b'));
    }

    public function testPublicAuthorizationNeedsNeitherUserNorCallbackNorGrant(): void
    {
        self::assertSame([], $this->broadcaster()->auth($this->authRequest('public-room')));
    }

    public function testPrivateCallbackReceivesNormalizedNameAndSignsFullName(): void
    {
        $broadcaster = $this->broadcaster();
        $broadcaster->channel('orders.{id}', fn (GenericUser $user, $id) => $user->getAuthIdentifier() === (int) $id);
        $request = $this->authRequest('private-orders.8', user: new GenericUser(['id' => 8]));
        $before = time();
        $payload = $this->claims($broadcaster->auth($request));
        self::assertSame('private-orders.8', $payload['channel']);
        self::assertSame(self::SOCKET, $payload['socket_id']);
        self::assertGreaterThanOrEqual($before + 60, $payload['exp']);
        self::assertLessThanOrEqual(time() + 60, $payload['exp']);
        self::assertArrayNotHasKey('user_id', $payload);
        self::assertSame('private-orders.8', $request->input('channel_name'));
    }

    public function testExactlyOnePrefixIsStripped(): void
    {
        $broadcaster = $this->broadcaster();
        $broadcaster->channel('private-room.{id}', fn ($user, $id) => $id === '8');
        $payload = $this->claims($broadcaster->auth($this->authRequest('private-private-room.8', user: new GenericUser(['id' => 8]))));
        self::assertSame('private-private-room.8', $payload['channel']);
    }

    #[DataProvider('deniedResults')]
    public function testDeniedCallbacksDoNotMintGrants(mixed $result): void
    {
        $this->broadcaster()->channel('room', fn ($user) => $result);
        $this->expectException(AccessDeniedHttpException::class);
        $this->broadcaster()->auth($this->authRequest('private-room', user: new GenericUser(['id' => 1])));
    }

    public static function deniedResults(): array
    {
        return [[false], [null], [0], [''], [[]]];
    }

    public function testUnauthenticatedPrivateRequestsNeverInvokeCallback(): void
    {
        $this->broadcaster()->channel('room', function ($user) {
            self::fail('Anonymous private authorization must not invoke the callback.');
        });
        $this->expectException(AccessDeniedHttpException::class);
        $this->broadcaster()->auth($this->authRequest('private-room'));
    }

    public function testUnregisteredChannelIsDenied(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->broadcaster()->auth($this->authRequest('private-missing', user: new GenericUser(['id' => 1])));
    }

    #[DataProvider('invalidAuthInputs')]
    public function testInvalidBrowserIdentifiersAlwaysDeny(mixed $channel, mixed $socket): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->broadcaster()->auth($this->authRequest($channel, $socket, new GenericUser(['id' => 1])));
    }

    public static function invalidAuthInputs(): array
    {
        return [[null, self::SOCKET], [[], self::SOCKET], [123, self::SOCKET], ['private-encrypted-room', self::SOCKET],
            ['private-room', null], ['private-room', []], ['private-room', '1.2'], ['private-room', ''], ['room*', self::SOCKET]];
    }

    public function testRealCustomGuardRouteAndPresenceUseOnlyAuthenticatedIdentity(): void
    {
        $this->broadcaster()->channel('room.{id}', fn (TezBroadcastUser $user, $id) => ['name' => 'Trusted José', 'user_id' => 'callback-not-identity'], ['guards' => ['staff']]);
        $response = $this->postJson('/owned/channel-auth', [
            'channel_name' => 'presence-room.8', 'socket_id' => self::SOCKET,
            'user_id' => 'browser-forgery', 'user_info' => ['admin' => true],
        ], ['X-Staff' => 'yes'])->assertOk();
        $payload = $this->claims($response->json());
        self::assertSame('staff-99', $payload['user_id']);
        self::assertSame(['name' => 'Trusted José', 'user_id' => 'callback-not-identity'], $payload['user_info']);
        self::assertSame('presence-room.8', $payload['channel']);
        self::assertSame('web', config('auth.defaults.guard'));
    }

    public function testConfiguredGuardDoesNotFallBackToDefaultUser(): void
    {
        $this->broadcaster()->channel('room', fn ($user) => true, ['guards' => ['staff']]);
        $request = $this->authRequest('private-room');
        $request->setUserResolver(fn ($guard = null) => $guard === null ? new GenericUser(['id' => 1]) : null);
        $this->expectException(AccessDeniedHttpException::class);
        $this->broadcaster()->auth($request);
    }

    public function testMultipleConfiguredGuardsAndAuthIdentifierFallback(): void
    {
        $this->broadcaster()->channel('room', fn ($user) => ['name' => 'José'], ['guards' => ['missing', 'staff']]);
        $request = $this->authRequest('presence-room');
        $request->setUserResolver(fn ($guard = null) => $guard === 'staff' ? new GenericUser(['id' => 0]) : null);
        self::assertSame('0', $this->claims($this->broadcaster()->auth($request))['user_id']);
    }

    #[DataProvider('invalidPresenceResults')]
    public function testPresenceRequiresNonemptyArray(mixed $result): void
    {
        $this->broadcaster()->channel('room', fn ($user) => $result);
        $this->expectException(AccessDeniedHttpException::class);
        $this->broadcaster()->auth($this->authRequest('presence-room', user: new GenericUser(['id' => 1])));
    }

    public static function invalidPresenceResults(): array
    {
        return [[true], [false], [null], [[]], ['name'], [1], [['name' => str_repeat('x', 1024)]]];
    }

    #[DataProvider('invalidPresenceIdentities')]
    public function testInvalidAuthenticatedPresenceIdentityIsDenied(mixed $id): void
    {
        $this->broadcaster()->channel('room', fn ($user) => ['name' => 'Trusted']);
        $this->expectException(AccessDeniedHttpException::class);
        $this->broadcaster()->auth($this->authRequest('presence-room', user: new GenericUser(['id' => $id])));
    }

    public static function invalidPresenceIdentities(): array
    {
        return [[''], [null], [false], [[]], [1.2], [str_repeat('é', 65)], ["x\n"]];
    }

    private function createTickets(): void
    {
        Schema::create('tez_test_tickets', function (Blueprint $table) {
            $table->id();
            $table->integer('owner_id');
        });
        TezTestTicket::create(['id' => 8, 'owner_id' => 8]);
    }

    public function testInheritedImplicitModelBindingAndContainerResolvedChannelClass(): void
    {
        $this->createTickets();
        $this->broadcaster()->channel('tickets.{ticket}', TezTicketChannel::class);
        $payload = $this->claims($this->broadcaster()->auth($this->authRequest('private-tickets.8', user: new GenericUser(['id' => 8]))));
        self::assertSame('private-tickets.8', $payload['channel']);
        $this->expectException(AccessDeniedHttpException::class);
        $this->broadcaster()->auth($this->authRequest('private-tickets.404', user: new GenericUser(['id' => 8])));
    }

    public function testInheritedExplicitModelBinding(): void
    {
        $this->createTickets();
        $this->app['router']->bind('ticket', fn ($value) => TezTestTicket::findOrFail($value));
        $this->broadcaster()->channel('tickets.{ticket}', fn ($user, $ticket) => $ticket instanceof TezTestTicket && $ticket->owner_id === $user->getAuthIdentifier());
        self::assertSame('private-tickets.8', $this->claims($this->broadcaster()->auth($this->authRequest('private-tickets.8', user: new GenericUser(['id' => 8]))))['channel']);
    }

    public function testNativeSynchronousQueueBroadcastEventAndToOthers(): void
    {
        $this->accept();
        $jobs = [];
        $this->app['events']->listen(JobProcessing::class, function (JobProcessing $event) use (&$jobs) {
            $jobs[] = $event->job->payload()['data']['commandName'];
        });
        $this->app['request']->headers->set('X-Socket-ID', self::SOCKET);
        $pending = Broadcast::event(new TezQueuedEvent())->via('tenant-a')->toOthers();
        unset($pending);
        self::assertSame([BroadcastEvent::class], $jobs);
        self::assertSame(self::SOCKET, $this->published()['exclude_socket_id']);
        self::assertSame(['public-room', 'private-orders.8', 'presence-room'], $this->published()['channels']);
        self::assertSame(TezQueuedEvent::class, $this->published()['event']);
        self::assertSame(['message' => 'سلام', 'nested' => ['socket' => 'application-data']], $this->published()['data']);
        self::assertArrayNotHasKey('socket', $this->published()['data']);
    }

    public function testRealDatabaseQueuedEventIsSerializedPoppedAndExecuted(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        config(['queue.default' => 'database', 'queue.connections.database' => [
            'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90,
        ]]);
        $event = (new TezQueuedEvent())->broadcastVia('tenant-b');
        $this->app['events']->dispatch($event);
        self::assertCount(0, $this->history);
        $job = $this->app['queue']->connection('database')->pop();
        self::assertNotNull($job);
        self::assertSame(BroadcastEvent::class, $job->payload()['data']['commandName']);
        $this->accept();
        $job->fire();
        self::assertCount(1, $this->history);
        self::assertSame('key-b', $this->history[0]['request']->getHeaderLine('X-Tez-Key'));
        self::assertSame(TezQueuedEvent::class, $this->published()['event']);
    }

    public function testShouldBroadcastNowExecutesImmediatelyWithoutQueue(): void
    {
        $this->accept();
        $jobs = [];
        $this->app['events']->listen(JobProcessing::class, function ($event) use (&$jobs) { $jobs[] = $event; });
        $this->app['events']->dispatch((new TezImmediateEvent())->broadcastVia('tenant-a'));
        self::assertCount(0, $jobs);
        self::assertCount(1, $this->history);
        self::assertSame('order.updated', $this->published()['event']);
        self::assertArrayNotHasKey('exclude_socket_id', $this->published());
    }

    public function testQueueFailuresSurfaceWithoutSdkRetries(): void
    {
        $this->mock->append(new Response(503, [], '{"error":{"code":"unavailable","message":"Redis unavailable"}}'));
        $this->expectException(TezException::class);
        try {
            $this->app['events']->dispatch((new TezQueuedEvent())->broadcastVia('tenant-a'));
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    #[DataProvider('invalidBroadcastArguments')]
    public function testLaravelRejectsInvalidIdentifiersWithoutCoercion(array $channels, mixed $event, array $payload): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->broadcaster()->broadcast($channels, $event, $payload);
    }

    public static function invalidBroadcastArguments(): array
    {
        return [[[123], 'event', []], [['a'], 123, []], [['a'], 'event', ['socket' => 123]],
            [['a'], 'event', ['socket' => '']], [['private-encrypted-room'], 'event', []]];
    }
}

class TezBroadcastUser extends GenericUser
{
    public function getAuthIdentifierForBroadcasting()
    {
        return $this->broadcast_id;
    }
}

class TezTestTicket extends Model
{
    protected $table = 'tez_test_tickets';
    protected $guarded = [];
    public $timestamps = false;
}

class TezTicketChannel
{
    public function __construct(private BroadcastManager $manager)
    {
    }

    public function join(GenericUser $user, TezTestTicket $ticket): bool
    {
        return $ticket->owner_id === $user->getAuthIdentifier();
    }
}

class TezQueuedEvent implements ShouldBroadcast
{
    use InteractsWithBroadcasting, InteractsWithSockets;

    public function broadcastOn(): array
    {
        return [new Channel('public-room'), new PrivateChannel('orders.8'), new PresenceChannel('room')];
    }

    public function broadcastWith(): array
    {
        return ['message' => 'سلام', 'nested' => ['socket' => 'application-data']];
    }
}

class TezImmediateEvent extends TezQueuedEvent implements ShouldBroadcastNow
{
    public function broadcastAs(): string
    {
        return 'order.updated';
    }
}
