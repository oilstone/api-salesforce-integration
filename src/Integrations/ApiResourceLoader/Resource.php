<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\ApiResourceLoader;

use Api\Guards\OAuth2\Sentinel;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Transformers\Transformer;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Repository;
use Oilstone\ApiResourceLoader\Resources\Resource as BaseResource;
use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;

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

    public function setCacheHandler(?QueryCacheHandler $handler): static
    {
        $this->cacheHandler = $handler;

        return $this;
    }

    public function getCacheHandler(): ?QueryCacheHandler
    {
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

        if (method_exists($repository, 'setCacheHandler') && $this->cacheHandler) {
            $handler = clone $this->cacheHandler;

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
}
