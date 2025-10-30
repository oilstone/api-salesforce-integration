<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\ApiResourceLoader;

use Api\Guards\OAuth2\Sentinel;
use Illuminate\Container\Container as IlluminateContainer;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Transformers\Transformer;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Repository;
use Oilstone\ApiResourceLoader\Resources\Resource as BaseResource;
use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Psr\Container\ContainerInterface;
use Throwable;

class Resource extends BaseResource
{
    protected ?string $object = null;

    protected array $constraints = [];

    protected array $includes = [];

    protected string $identifier = 'Id';

    protected ?string $transformer = Transformer::class;

    protected ?string $repository = Repository::class;

    protected ?int $cacheTtl = null;

    protected ?QueryCacheHandler $cacheHandler = null;

    protected bool $cacheHandlerManuallySet = false;

    public function __construct()
    {
        parent::__construct();

        $this->initialiseCacheHandlerFromContainer();
    }

    public function setCacheHandler(?QueryCacheHandler $handler): static
    {
        $this->cacheHandlerManuallySet = true;
        $this->cacheHandler = $handler;

        return $this;
    }

    public function getCacheHandler(): ?QueryCacheHandler
    {
        if (! $this->cacheHandlerManuallySet && ! $this->cacheHandler instanceof QueryCacheHandler) {
            $this->initialiseCacheHandlerFromContainer();
        }

        return $this->cacheHandler;
    }

    public function makeRepository(?Sentinel $sentinel = null, ...$params): ?Repository
    {
        if (isset($this->cached['repository'])) {
            return $this->cached['repository'];
        }

        $repositoryClass = $this->repository;
        $schema = $this->makeSchema();

        $repository = (new $repositoryClass($this->object))
            ->setSchema($schema)
            ->setTransformer($this->makeTransformer($schema))
            ->setDefaultConstraints(array_merge($this->constraints(), $this->constraints))
            ->setDefaultIncludes(array_merge($this->includes(), $this->includes))
            ->setIdentifier($this->identifier);

        $cacheHandler = $this->getCacheHandler();

        if (method_exists($repository, 'setCacheHandler') && $cacheHandler) {
            $handler = clone $cacheHandler;

            if ($this->cacheTtl !== null) {
                $handler->setQueryTtl($this->cacheTtl);
            }

            $repository->setCacheHandler($handler);
        }

        if (method_exists($repository, 'setSentinel')) {
            $repository->setSentinel($sentinel);
        }

        $this->cached['repository'] = $repository;

        return $repository;
    }

    public function object(): ?string
    {
        return $this->object;
    }

    public function setObject(?string $object): static
    {
        $this->object = $object;

        return $this;
    }

    public function constraints(): array
    {
        return [];
    }

    public function setConstraints(array $constraints): static
    {
        $this->constraints = $constraints;

        return $this;
    }

    public function includes(): array
    {
        return [];
    }

    public function setIncludes(array $includes): static
    {
        $this->includes = $includes;

        return $this;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function cacheTtl(): ?int
    {
        return $this->cacheTtl;
    }

    public function setCacheTtl(?int $ttl): static
    {
        $this->cacheTtl = $ttl;

        return $this;
    }

    protected function initialiseCacheHandlerFromContainer(): void
    {
        if ($this->cacheHandlerManuallySet || $this->cacheHandler instanceof QueryCacheHandler) {
            return;
        }

        $handler = $this->resolveCacheHandlerFromContainer();

        if ($handler instanceof QueryCacheHandler) {
            $this->cacheHandler = $handler;
        }
    }

    protected function resolveCacheHandlerFromContainer(): ?QueryCacheHandler
    {
        if ($handler = $this->resolveCacheHandlerViaHelper()) {
            return $handler;
        }

        $container = $this->resolveContainerInstance();

        if (! $container) {
            return null;
        }

        return $this->resolveHandlerFromContainer($container);
    }

    protected function resolveCacheHandlerViaHelper(): ?QueryCacheHandler
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            $resolved = app(QueryCacheHandler::class);
        } catch (Throwable) {
            return null;
        }

        return $resolved instanceof QueryCacheHandler ? clone $resolved : null;
    }

    protected function resolveContainerInstance(): ?object
    {
        if (class_exists(IlluminateContainer::class)) {
            $instance = IlluminateContainer::getInstance();

            if ($instance) {
                return $instance;
            }
        }

        if (function_exists('app')) {
            try {
                $app = app();

                if (is_object($app)) {
                    return $app;
                }
            } catch (Throwable) {
                // Ignore helpers that are not ready yet.
            }
        }

        return null;
    }

    protected function resolveHandlerFromContainer(object $container): ?QueryCacheHandler
    {
        try {
            if ($container instanceof ContainerInterface) {
                if (! $container->has(QueryCacheHandler::class)) {
                    return null;
                }

                $resolved = $container->get(QueryCacheHandler::class);
            } elseif (method_exists($container, 'bound') && method_exists($container, 'make')) {
                if (! $container->bound(QueryCacheHandler::class)) {
                    return null;
                }

                $resolved = $container->make(QueryCacheHandler::class);
            } elseif (method_exists($container, 'has') && method_exists($container, 'get')) {
                if (! $container->has(QueryCacheHandler::class)) {
                    return null;
                }

                $resolved = $container->get(QueryCacheHandler::class);
            } elseif (method_exists($container, 'get')) {
                $resolved = $container->get(QueryCacheHandler::class);
            } elseif (method_exists($container, 'make')) {
                $resolved = $container->make(QueryCacheHandler::class);
            } else {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $resolved instanceof QueryCacheHandler ? clone $resolved : null;
    }
}
