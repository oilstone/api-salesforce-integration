<?php

namespace Oilstone\ApiSalesforceIntegration;

use Api\Guards\Contracts\Sentinel;
use Api\Pipeline\Pipes\Pipe;
use Api\Repositories\Contracts\Resource as RepositoryInterface;
use Api\Result\Contracts\Collection as ResultCollectionInterface;
use Api\Result\Contracts\Record as ResultRecordInterface;
use Api\Schema\Schema;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Bridge\QueryResolver;
use Psr\Http\Message\ServerRequestInterface;
use Oilstone\ApiSalesforceIntegration\Exceptions\MethodNotAllowedException;

class Repository implements RepositoryInterface
{
    protected ?string $object;

    protected ?Schema $schema;

    /**
     * @var array<int, callable>
     */
    protected array $defaultConstraints = [];

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

    public function __construct(?string $object = null, ?Schema $schema = null, ?Sentinel $sentinel = null)
    {
        if (property_exists($this, 'sentinel')) {
            $this->sentinel = $sentinel;
        }

        $this->object = $object;
        $this->schema = $schema;
    }

    public function getByKey(Pipe $pipe): ?ResultRecordInterface
    {
        return (new QueryResolver(
            $this->newQuery(),
            $pipe,
            $this->getDefaultFields(),
        ))->byKey();
    }

    public function getCollection(Pipe $pipe, ServerRequestInterface $request): ResultCollectionInterface
    {
        return Collection::make(
            (new QueryResolver(
                $this->newQuery($request, $request->getQueryParams()['object'] ?? null),
                $pipe,
                $this->getDefaultFields(),
            ))->collection($request)->all()
        );
    }

    public function getRecord(Pipe $pipe, ServerRequestInterface $request): ?ResultRecordInterface
    {
        return (new QueryResolver(
            $this->newQuery($request, $request->getQueryParams()['object'] ?? null),
            $pipe,
            $this->getDefaultFields(),
        ))->record($request);
    }

    public function create(Pipe $pipe, ServerRequestInterface $request): ResultRecordInterface
    {
        throw new MethodNotAllowedException;
    }

    public function update(Pipe $pipe, ServerRequestInterface $request): ResultRecordInterface
    {
        throw new MethodNotAllowedException;
    }

    public function delete(Pipe $pipe): ResultRecordInterface
    {
        throw new MethodNotAllowedException;
    }

    protected function getClient(?ServerRequestInterface $request = null): Salesforce
    {
        return app(Salesforce::class);
    }

    protected function newQuery(?ServerRequestInterface $request = null, ?string $object = null): Query
    {
        $query = new Query($object ?? $this->object, $this->getClient($request));

        foreach ($this->defaultConstraints as $constraint) {
            $constraint($query);
        }

        return $query;
    }

    protected function getDefaultFields(): array
    {
        $fields = [];

        if ($this->schema && $this->schema->getPrimary()) {
            $fields[] = $this->schema->getPrimary()->alias ?: $this->schema->getPrimary()->getName();
        }

        if ($this->schema && $this->schema->getProperties()) {
            foreach ($this->schema->getProperties() as $property) {
                if ($property->alias) {
                    $fields[] = $property->alias;
                } else {
                    $fields[] = $property->getName();
                }
            }
        }

        return array_unique($fields);
    }
}
