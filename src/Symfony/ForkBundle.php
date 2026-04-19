<?php

declare(strict_types=1);

namespace Henderkes\Fork\Symfony;

use Henderkes\Fork\Runtime;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Wires {@see Runtime} into the Symfony container with sensible defaults.
 *
 *  - Any service implementing {@see ForkAwareInterface} is auto-tagged
 *    with `henderkes_fork.configure` and its `configure()` method is
 *    invoked on each Runtime instance produced by the container.
 *  - Manual tagging also works with a `method` attribute:
 *        tags: [{ name: henderkes_fork.configure, method: configure }]
 *    The named method must accept a {@see Runtime} and return one.
 *
 * {@see Runtime} itself is registered as a non-shared service so each
 * autowired call gets its own instance.
 */
final class ForkBundle extends Bundle implements CompilerPassInterface
{
    public const string TAG = 'henderkes_fork.configure';

    public function build(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(ForkAwareInterface::class)
            ->addTag(self::TAG);

        $container->addCompilerPass($this);
    }

    public function process(ContainerBuilder $container): void
    {
        $doctrineRegistry = $container->has('doctrine')
            ? new Reference('doctrine')
            : null;

        $httpClient = $container->has('http_client')
            ? new Reference('http_client')
            : null;

        $tagged = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $method = $tags[0]['method'] ?? 'configure';
            $tagged[] = ['ref' => new Reference($id), 'method' => $method];
        }

        $container->register(RuntimeFactory::class, RuntimeFactory::class)
            ->setArguments([$doctrineRegistry, $httpClient, $tagged]);

        $container->register(Runtime::class, Runtime::class)
            ->setFactory([new Reference(RuntimeFactory::class), 'create'])
            ->setShared(false);
    }
}
