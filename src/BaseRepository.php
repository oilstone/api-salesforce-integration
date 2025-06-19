<?php

namespace Oilstone\ApiSalesforceIntegration;

use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

class BaseRepository
{
    protected ?string $object;

    /**
     * @var array<int, callable>
     */
    protected array $defaultConstraints = [];

    public function __construct(?string $object = null)
    {
        $this->object = $object;
    }

    public function setDefaultConstraints(array $constraints): static
    {
        $this->defaultConstraints = [];

        foreach ($constraints as $constraint) {
            $this->addDefaultConstraint($constraint);
        }

        return $this;
    }

    public function addDefaultConstraint(callable $constraint): static
    {
        $this->defaultConstraints[] = $constraint;

        return $this;
    }

    protected function getClient(): Salesforce
    {
        return app(Salesforce::class);
    }

    protected function newQuery(?string $object = null): Query
    {
        $query = new Query($object ?? $this->object, $this->getClient());

        foreach ($this->defaultConstraints as $constraint) {
            $constraint($query);
        }

        return $query;
    }
}
