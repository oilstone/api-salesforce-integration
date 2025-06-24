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

    protected ?string $transformer = Transformer::class;

    protected ?string $repository = Repository::class;

    public function makeRepository(?Sentinel $sentinel = null, ...$params): ?Repository
    {
        $repositoryClass = $this->repository;
        $schema = $this->makeSchema();

        return (new $repositoryClass($this->object, $sentinel, ...$params))
            ->setSchema($schema)
            ->setTransformer($this->makeTransformer($schema))
            ->setDefaultConstraints(array_merge($this->constraints(), $this->constraints));
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
}
