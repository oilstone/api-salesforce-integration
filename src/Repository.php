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
            new Query($this->object, $this->getClient()),
            $pipe,
            $this->getDefaultFields(),
        ))->byKey();
    }

    public function getCollection(Pipe $pipe, ServerRequestInterface $request): ResultCollectionInterface
    {
        return Collection::make(
            (new QueryResolver(
                new Query($request->getQueryParams()['object'] ?? $this->object, $this->getClient($request)),
                $pipe,
                $this->getDefaultFields(),
            ))->collection($request)->all()
        );
    }

    public function getRecord(Pipe $pipe, ServerRequestInterface $request): ?ResultRecordInterface
    {
        return (new QueryResolver(
            new Query($request->getQueryParams()['object'] ?? $this->object, $this->getClient($request)),
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
