<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api;

use Api\Pipeline\Pipes\Pipe;
use Api\Repositories\Contracts\Resource as RepositoryInterface;
use Api\Result\Contracts\Collection as ResultCollectionInterface;
use Api\Result\Contracts\Record as ResultRecordInterface;
use Api\Schema\Schema;
use Api\Transformers\Contracts\Transformer;
use Oilstone\ApiSalesforceIntegration\Collection;
use Oilstone\ApiSalesforceIntegration\Exceptions\MethodNotAllowedException;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Bridge\QueryResolver;
use Oilstone\ApiSalesforceIntegration\Query;
use Oilstone\ApiSalesforceIntegration\Repository as BaseRepository;
use Psr\Http\Message\ServerRequestInterface;

class Repository implements RepositoryInterface
{
    protected ?Schema $schema = null;

    protected ?Transformer $transformer = null;

    protected array $defaultConstraints = [];

    protected array $defaultIncludes = [];

    public function __construct(
        protected string $object,
    ) {}

    public function getSchema(): ?Schema
    {
        return $this->schema;
    }

    public function setSchema(Schema $schema): static
    {
        $this->schema = $schema;

        return $this;
    }

    public function getTransformer(): ?Transformer
    {
        return $this->transformer;
    }

    public function setTransformer(Transformer $transformer): static
    {
        $this->transformer = $transformer;

        return $this;
    }

    public function setDefaultConstraints(array $constraints): static
    {
        $this->defaultConstraints = $constraints;

        return $this;
    }

    public function setDefaultIncludes(array $includes): static
    {
        $this->defaultIncludes = $includes;

        return $this;
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
                $this->newQuery($request->getQueryParams()['object'] ?? null),
                $pipe,
                $this->getDefaultFields(),
            ))->collection($request)->all()
        );
    }

    public function getRecord(Pipe $pipe, ServerRequestInterface $request): ?ResultRecordInterface
    {
        return (new QueryResolver(
            $this->newQuery($request->getQueryParams()['object'] ?? null),
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

    protected function newQuery(?string $object = null): Query
    {
        return (new BaseRepository($object ?? $this->object, $this->defaultConstraints, $this->defaultIncludes))->newQuery();
    }

    protected function getDefaultFields(): array
    {
        $fields = [];

        if ($this->schema && $this->schema->getPrimary()) {
            $fields[] = $this->schema->getPrimary()->alias ?: $this->schema->getPrimary()->getName();
        }

        if ($this->schema && $this->schema->getProperties()) {
            foreach ($this->schema->getProperties() as $property) {
                $fields[] = $property->alias ?: $property->getName();
            }
        }

        return array_unique($fields);
    }
}
