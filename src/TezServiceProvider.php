<?php

declare(strict_types=1);

namespace Cloudfort\Tez;

use Cloudfort\Tez\Broadcasting\TezBroadcaster;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class TezServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tez.php', 'tez');

        $this->app->singletonIf('tez.http_client', static fn (): ClientInterface => new Client([
            'timeout' => 10,
            'connect_timeout' => 3,
            'verify' => true,
            'allow_redirects' => false,
        ]));

        $this->app->singleton(TezClient::class, static function ($app): TezClient {
            $config = $app['config']['tez'] ?? [];
            foreach (['endpoint', 'api_key', 'secret'] as $key) {
                if (! isset($config[$key]) || ! is_string($config[$key]) || $config[$key] === '') {
                    throw new InvalidArgumentException('Tez configuration requires a nonempty string "'.$key.'".');
                }
            }
            $http = $app->make('tez.http_client');
            if (! $http instanceof ClientInterface) {
                throw new InvalidArgumentException('tez.http_client must resolve to a Guzzle client.');
            }

            return new TezClient($config['endpoint'], $config['api_key'], $config['secret'], $http);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tez.php' => $this->app->configPath('tez.php'),
            ], 'tez-config');
        }

        $this->app->resolving(BroadcastManager::class, static function (BroadcastManager $manager): void {
            $manager->extend('tez', static function ($app, array $config): TezBroadcaster {
                foreach (['endpoint', 'api_key', 'secret'] as $key) {
                    if (! isset($config[$key]) || ! is_string($config[$key]) || $config[$key] === '') {
                        throw new InvalidArgumentException('Tez connection requires a nonempty string "'.$key.'".');
                    }
                }
                $http = $app->make('tez.http_client');
                if (! $http instanceof ClientInterface) {
                    throw new InvalidArgumentException('tez.http_client must resolve to a Guzzle client.');
                }

                return new TezBroadcaster(
                    new TezClient($config['endpoint'], $config['api_key'], $config['secret'], $http),
                );
            });
        });
    }
}
