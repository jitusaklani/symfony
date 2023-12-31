<?php

namespace DAMA\DoctrineTestBundle\DependencyInjection;

use DAMA\DoctrineTestBundle\Doctrine\Cache\Psr6StaticArrayCache;
use DAMA\DoctrineTestBundle\Doctrine\Cache\StaticArrayCache;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\Middleware;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticConnectionFactory;
use Doctrine\Common\Cache\Cache;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class DoctrineTestCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        /** @var DAMADoctrineTestExtension $extension */
        $extension = $container->getExtension('dama_doctrine_test');
        $config = $extension->getProcessedConfig();

        /** @var bool|array $enableStaticConnectionsConfig */
        $enableStaticConnectionsConfig = $config[Configuration::ENABLE_STATIC_CONNECTION];

        if ($enableStaticConnectionsConfig !== false) {
            $factoryDef = new Definition(StaticConnectionFactory::class);
            $factoryDef
                ->setDecoratedService('doctrine.dbal.connection_factory')
                ->addArgument(new Reference('dama.doctrine.dbal.connection_factory.inner'))
            ;
            $container->setDefinition('dama.doctrine.dbal.connection_factory', $factoryDef);
        }

        $container->register('dama.doctrine.dbal.middleware', Middleware::class);

        $cacheNames = [];

        if ($config[Configuration::STATIC_META_CACHE]) {
            $cacheNames[] = 'doctrine.orm.%s_metadata_cache';
        }

        if ($config[Configuration::STATIC_QUERY_CACHE]) {
            $cacheNames[] = 'doctrine.orm.%s_query_cache';
        }

        $connectionNames = array_keys($container->getParameter('doctrine.connections'));
        if (is_array($enableStaticConnectionsConfig)) {
            $this->validateConnectionNames(array_keys($enableStaticConnectionsConfig), $connectionNames);
        }

        foreach ($connectionNames as $name) {
            if ($enableStaticConnectionsConfig === true
                || isset($enableStaticConnectionsConfig[$name]) && $enableStaticConnectionsConfig[$name] === true
            ) {
                $this->addConnectionOptions($container, $name);
            }

            foreach ($cacheNames as $cacheName) {
                $cacheServiceId = sprintf($cacheName, $name);

                if (!$container->has($cacheServiceId)) {
                    // might happen if ORM is not used
                    continue;
                }

                $definition = $container->findDefinition($cacheServiceId);
                while (!$definition->getClass() && $definition instanceof ChildDefinition) {
                    $definition = $container->findDefinition($definition->getParent());
                }

                $this->registerStaticCache($container, $definition, $cacheServiceId);
            }
        }
    }

    private function validateConnectionNames(array $configNames, array $existingNames): void
    {
        $unknown = array_diff($configNames, $existingNames);

        if (count($unknown)) {
            throw new \InvalidArgumentException(sprintf('Unknown doctrine dbal connection name(s): %s.', implode(', ', $unknown)));
        }
    }

    private function addConnectionOptions(ContainerBuilder $container, string $name): void
    {
        $connectionDefinition = $container->getDefinition(sprintf('doctrine.dbal.%s_connection', $name));
        $connectionOptions = $connectionDefinition->getArgument(0);
        $connectionOptions['dama.keep_static'] = true;
        $connectionOptions['dama.connection_name'] = $name;
        $connectionDefinition->replaceArgument(0, $connectionOptions);

        $connectionConfig = $container->getDefinition(sprintf('doctrine.dbal.%s_connection.configuration', $name));
        $methodCalls = $connectionConfig->getMethodCalls();
        $middlewareRef = new Reference('dama.doctrine.dbal.middleware');
        $hasMiddlewaresMethodCall = false;
        foreach ($methodCalls as &$methodCall) {
            if ($methodCall[0] === 'setMiddlewares') {
                $hasMiddlewaresMethodCall = true;
                // our middleware needs to be the first one here so we wrap the "native" driver
                $methodCall[1][0] = array_merge([$middlewareRef], $methodCall[1][0]);
            }
        }

        if (!$hasMiddlewaresMethodCall) {
            $methodCalls[] = ['setMiddlewares', [[$middlewareRef]]];
        }

        $connectionConfig->setMethodCalls($methodCalls);
    }

    private function registerStaticCache(
        ContainerBuilder $container,
        Definition $originalCacheServiceDefinition,
        string $cacheServiceId
    ): void {
        $cache = new Definition();
        $namespace = sha1($cacheServiceId);

        if (is_a($originalCacheServiceDefinition->getClass(), CacheItemPoolInterface::class, true)) {
            $cache->setClass(Psr6StaticArrayCache::class);
            $cache->setArgument(0, $namespace); // make sure we have no key collisions
        } elseif (is_a($originalCacheServiceDefinition->getClass(), Cache::class, true)) {
            $cache->setClass(StaticArrayCache::class);
            $cache->addMethodCall('setNamespace', [$namespace]); // make sure we have no key collisions
        } else {
            throw new \InvalidArgumentException(sprintf('Unsupported cache class "%s" found on service "%s".', $originalCacheServiceDefinition->getClass(), $cacheServiceId));
        }

        if ($container->hasAlias($cacheServiceId)) {
            $container->removeAlias($cacheServiceId);
        }
        $container->setDefinition($cacheServiceId, $cache);
    }
}
