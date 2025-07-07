<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\ApiResourceLoader;

use Api\Guards\OAuth2\Sentinel;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Transformers\Transformer;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Repository;
use Oilstone\ApiResourceLoader\Resources\Resource as BaseResource;

class Resource extends BaseResource
{
    protected string $object;

    protected array $constraints = [];

    protected array $includes = [];

    protected ?string $transformer = Transformer::class;

    protected ?string $repository = Repository::class;

    protected ?int $cacheTtl = null;

    public function setCacheTtl(?int $ttl): static
    {
        $this->cacheTtl = $ttl;

        return $this;
    }

    public function cacheTtl(): ?int
    {
        return $this->cacheTtl;
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
            ->setDefaultIncludes(array_merge($this->includes(), $this->includes));

        if (method_exists($repository, 'setCacheHandler')) {
            $handler = clone app(\Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler::class);

            if ($this->cacheTtl !== null) {
                $handler->setTtl($this->cacheTtl);
            }

            $repository->setCacheHandler($handler);
        }

        if (method_exists($repository, 'setSentinel')) {
            $repository->setSentinel($sentinel);
        }

        $this->cached['repository'] = $repository;

        return $repository;
    }

    public function object(): string
    {
        return $this->object;
    }

    public function setObject(string $object): static
    {
        $this->object = $object;

        return $this;
    }

    public function constraints(): array
    {
        return $this->constraints;
    }

    public function setConstraints(array $constraints): static
    {
        $this->constraints = $constraints;

        return $this;
    }

    public function includes(): array
    {
        return $this->includes;
    }

    public function setIncludes(array $includes): static
    {
        $this->includes = $includes;

        return $this;
    }
}
