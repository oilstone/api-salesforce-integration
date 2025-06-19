<?php

namespace Oilstone\ApiSalesforceIntegration;

use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

class Repository
{
    public function __construct(
        protected ?string $object = null,
        protected array $defaultConstraints = [],
    ) {}

    public function setDefaultConstraints(array $constraints): static
    {
        $this->defaultConstraints = $constraints;

        return $this;
    }

    public function newQuery(?string $object = null): Query
    {
        $query = new Query($object ?? $this->object, $this->getClient());

        foreach ($this->defaultConstraints as $constraint) {
            if (is_array($constraint)) {
                $query->where(...$constraint);
                continue;
            }

            if (is_callable($constraint)) {
                $constraint($query);
                continue;
            }

            $query->where($constraint);
        }

        return $query;
    }

    public function find(string $id): ?Record
    {
        return $this->newQuery()->where('Id', $id)->first();
    }

    public function first(): ?Record
    {
        return $this->newQuery()->first();
    }

    public function get(): Collection
    {
        return $this->newQuery()->get();
    }

    protected function getClient(): Salesforce
    {
        return app(Salesforce::class);
    }
}
