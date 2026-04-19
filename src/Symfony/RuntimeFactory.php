<?php

declare(strict_types=1);

namespace Henderkes\Fork\Symfony;

use Henderkes\Fork\Runtime;

/**
 * @internal produces {@see Runtime} instances pre-wired with the hooks
 *           collected by {@see ForkBundle}
 */
final class RuntimeFactory
{
    /**
     * Process-local stash that keeps abandoned resources alive for the
     * lifetime of a forked child, so PHP's GC doesn't call destructors
     * that would close an fd the parent still uses.
     *
     * @var list<object>
     *
     * @phpstan-ignore property.onlyWritten
     * @noinspection PhpPropertyOnlyWrittenInspection
     */
    private static array $forkChildStash = [];

    /** @var list<\Closure(Runtime): Runtime> */
    private array $configurators = [];

    /**
     * @param object|null                              $doctrineRegistry a Doctrine\Persistence\ManagerRegistry
     * @param list<array{ref: object, method: string}> $taggedServices
     */
    public function __construct(
        ?object $doctrineRegistry = null,
        ?object $httpClient = null,
        array $taggedServices = [],
    ) {
        if ($doctrineRegistry !== null) {
            $this->configurators[] = self::doctrineConfigurator($doctrineRegistry);
        }

        if ($httpClient !== null) {
            $this->configurators[] = self::httpClientConfigurator($httpClient);
        }

        foreach ($taggedServices as $entry) {
            $service = $entry['ref'];
            $method = $entry['method'];
            $this->configurators[] = $service->$method(...);
        }
    }

    public function create(): Runtime
    {
        $runtime = new Runtime();

        foreach ($this->configurators as $configurator) {
            $runtime = $configurator($runtime);
        }

        return $runtime;
    }

    private static function doctrineConfigurator(object $registry): \Closure
    {
        return static fn (Runtime $runtime): Runtime => $runtime->before(
            child: static function () use ($registry): void {
                if (!Runtime::inChild()) {
                    return;
                }
                if (!\method_exists($registry, 'getManagers')) {
                    return;
                }

                foreach ($registry->getManagers() as $manager) {
                    if (\is_object($manager)) {
                        self::resetDoctrineConnection($manager);
                    }
                }
            },
            name: 'doctrine',
        );
    }

    private static function resetDoctrineConnection(object $emOrConnection): void
    {
        $conn = $emOrConnection;
        if (\method_exists($emOrConnection, 'getConnection')) {
            $result = $emOrConnection->getConnection();
            if (\is_object($result)) {
                $conn = $result;
            }
        }

        $ref = new \ReflectionClass($conn);

        $prop = null;
        foreach (['_conn', 'connection'] as $name) {
            if ($ref->hasProperty($name)) {
                $prop = $ref->getProperty($name);
                break;
            }
        }
        if ($prop === null) {
            return;
        }

        $old = $prop->getValue($conn);
        if (\is_object($old)) {
            self::$forkChildStash[] = $old;
            $prop->setValue($conn, null);
        }
    }

    private static function httpClientConfigurator(object $client): \Closure
    {
        return static fn (Runtime $runtime): Runtime => $runtime->before(
            child: static function () use ($client): void {
                if (!Runtime::inChild()) {
                    return;
                }
                try {
                    self::resetCurlState($client);
                } catch (\Throwable) {
                }
            },
            name: 'http_client',
        );
    }

    private static function resetCurlState(object $client, int $depth = 0): void
    {
        if ($depth > 10) {
            return;
        }

        $ref = new \ReflectionClass($client);

        if ($ref->hasProperty('multi')) {
            $multiProp = $ref->getProperty('multi');
            $multi = $multiProp->getValue($client);

            if (\is_object($multi)) {
                $multiRef = new \ReflectionClass($multi);

                if ($multiRef->hasProperty('handle') && isset($multi->handle)) {
                    $handle = $multi->handle;
                    unset($multi->handle);
                    if (\is_object($handle)) {
                        self::$forkChildStash[] = $handle;
                    }
                }

                if ($multiRef->hasProperty('share') && isset($multi->share)) {
                    $share = $multi->share;
                    unset($multi->share);
                    if (\is_object($share)) {
                        self::$forkChildStash[] = $share;
                    }
                }
            }

            return;
        }

        if ($ref->hasProperty('client')) {
            $innerProp = $ref->getProperty('client');
            $inner = $innerProp->getValue($client);
            if (\is_object($inner)) {
                self::resetCurlState($inner, $depth + 1);
            }
        }
    }
}
