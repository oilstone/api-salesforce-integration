<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api\Bridge;

use Api\Pipeline\Pipes\Pipe;
use Oilstone\ApiSalesforceIntegration\Query as QueryBuilder;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Results\Collection;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Results\Record;
use Psr\Http\Message\ServerRequestInterface;

class QueryResolver
{
    public function __construct(
        protected QueryBuilder $queryBuilder,
        protected Pipe $pipe,
        protected array $defaultFields = [],
    ) { }

    public function byKey(): ?Record
    {
        $query = $this->keyedQuery();

        $query->setCacheTags([
            $query->getObject(),
            $query->getObject() . ':' . $this->pipe->getKey(),
            $query->getObject() . ':findOne',
        ]);

        return (new Query($query))
            ->select($this->defaultFields ?: ['FIELDS(ALL)'])
            ->first();
    }

    public function record(ServerRequestInterface $request): ?Record
    {
        return $this->resolve($this->keyedQuery($request->getQueryParams()['key'] ?? null), $request)->first();
    }

    public function collection(ServerRequestInterface $request): Collection
    {
        return $this->resolve($this->baseQuery(), $request)->get();
    }

    public function resolve(QueryBuilder $queryBuilder, ServerRequestInterface $request): Query
    {
        $parsedQuery = $request->getAttribute('parsedQuery');

        return (new Query($queryBuilder))->include($parsedQuery->getRelations())
            ->select($parsedQuery->getFields() ?: $this->defaultFields ?: ['FIELDS(ALL)'])
            ->where($parsedQuery->getFilters())
            ->orderBy($parsedQuery->getSort())
            ->limit($parsedQuery->getLimit())
            ->offset($parsedQuery->getOffset());
    }

    public function keyedQuery(?string $primaryKey = null): QueryBuilder
    {
        if ($primaryKey) {
            $primaryKey = $this->pipe->getResource()->getSchema()->getProperty($primaryKey);
        } else {
            $primaryKey = $this->pipe->getResource()->getSchema()->getPrimary();
        }

        if (! $primaryKey) {
            return $this->baseQuery();
        }

        return $this->baseQuery()->where($primaryKey->alias ?: $primaryKey->getName(), $this->pipe->getKey());
    }

    public function baseQuery(): QueryBuilder
    {
        if ($this->pipe->isScoped()) {
            $scope = $this->pipe->getScope();

            return $this->queryBuilder->where($scope->getKey(), $scope->getValue());
        }

        return $this->queryBuilder;
    }
}
