<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api\Bridge;

use Aggregate\Set;
use Api\Pipeline\Pipes\Pipe;
use Oilstone\ApiSalesforceIntegration\Query as QueryBuilder;
use Oilstone\ApiSalesforceIntegration\Record;
use Psr\Http\Message\ServerRequestInterface;

class QueryResolver
{
    public function __construct(
        protected QueryBuilder $queryBuilder,
        protected Pipe $pipe,
    ) { }

    public function byKey(): ?Record
    {
        return $this->keyedQuery()->first();
    }

    public function record(ServerRequestInterface $request): ?Record
    {
        return $this->resolve($this->keyedQuery($request->getQueryParams()['key'] ?? null), $request)->first();
    }

    public function collection(ServerRequestInterface $request): Set
    {
        return $this->resolve($this->baseQuery(), $request)->get();
    }

    public function resolve(QueryBuilder $queryBuilder, ServerRequestInterface $request): Query
    {
        $parsedQuery = $request->getAttribute('parsedQuery');

        return (new Query($queryBuilder))->include($parsedQuery->getRelations())
            ->select($parsedQuery->getFields())
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
