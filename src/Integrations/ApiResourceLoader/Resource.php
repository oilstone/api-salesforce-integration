<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\ApiResourceLoader;

use Api\Guards\OAuth2\Sentinel;
use Api\Repositories\Contracts\Resource as RepositoryContract;
use Api\Schema\Schema as BaseSchema;
use Api\Transformers\Contracts\Transformer as TransformerContract;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Transformers\Transformer;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Repository;
use Oilstone\ApiResourceLoader\Resources\Resource as BaseResource;

class Resource extends BaseResource
{
    protected ?string $object;

    /**
     * @var array<int, callable>
     */
    protected array $constraints = [];

    /**
     * @return array<int, callable>
     */
    protected function constraints(): array
    {
        return [];
    }

    public function repository(?Sentinel $sentinel = null): ?RepositoryContract
    {
        return (new Repository($this->object, $sentinel))
            ->setSchema($this->makeSchema())
            ->setDefaultConstraints(array_merge($this->constraints(), $this->constraints));
    }

    public function transformer(BaseSchema $schema): ?TransformerContract
    {
        return new Transformer($schema);
    }

    public function getObject(): string
    {
        return $this->object;
    }

    public function setObject(?string $object): static
    {
        $this->object = $object;

        return $this;
    }

    public function setConstraints(array $constraints): static
    {
        $this->constraints = [];

        foreach ($constraints as $constraint) {
            $this->addConstraint($constraint);
        }

        return $this;
    }

    public function addConstraint(callable $constraint): static
    {
        $this->constraints[] = $constraint;

        return $this;
    }
}
