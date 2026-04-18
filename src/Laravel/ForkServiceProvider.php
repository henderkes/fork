<?php

declare(strict_types=1);

namespace Henderkes\Fork\Laravel;

use Henderkes\Fork\Runtime;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel integration: registers {@see Runtime} as a non-singleton
 * service with a `before(child:)` hook that purges DB connections.
 */
final class ForkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Runtime::class, static function (Container $app): Runtime {
            $runtime = new Runtime();
            $runtime->before(child: static function () use ($app): void {
                if ($app->bound('db')) {
                    try {
                        /** @var \Illuminate\Database\DatabaseManager $db */
                        $db = $app->make('db');
                        foreach ($db->getConnections() as $name => $_) {
                            $db->purge($name);
                        }
                    } catch (\Throwable) {
                    }
                }
            }, name: 'laravel.db');

            return $runtime;
        });
    }
}
