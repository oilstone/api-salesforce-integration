<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api\Bridge;

use Aggregate\Set;
use Api\Queries\Expression;
use Api\Queries\Paths\Path;
use Api\Queries\Relations as RequestRelations;
use Oilstone\ApiSalesforceIntegration\Query as SalesforceQuery;
use Oilstone\ApiSalesforceIntegration\Record;

class Query
{
    protected const OPERATOR_MAP = [
        'IS NULL' => '=',
        'IS NOT NULL' => '!=',
    ];

    protected const VALUE_MAP = [
        'IS NULL' => null,
        'IS NOT NULL' => null,
    ];

    /**
     * Query constructor.
     */
    public function __construct(
        protected SalesforceQuery $baseQuery
    ) { }

    public function getBaseQuery(): SalesforceQuery
    {
        return $this->baseQuery;
    }

    public function get(): Set
    {
        return $this->baseQuery->get();
    }

    public function first(): ?Record
    {
        return $this->baseQuery->first();
    }

    public function include(RequestRelations $relations): self
    {
        foreach ($relations->collapse() as $relation) {
            $this->baseQuery->with($relation->getPath());
        }

        return $this;
    }

    public function select(array $fields): self
    {
        if ($fields) {
            $this->baseQuery->select(...$fields);
        }

        return $this;
    }

    public function where(Expression $expression): self
    {
        return $this->applyExpression($this->baseQuery, $expression);
    }

    public function orderBy(array $orders): self
    {
        foreach ($orders as $order) {
            $this->baseQuery->orderBy(method_exists($order, 'getProperty') ? $order->getProperty() : 'fields.' . $order->getPropertyName(), $order->getDirection());
        }

        return $this;
    }

    public function limit($limit): self
    {
        if ($limit) {
            $this->baseQuery->limit($limit);
        }

        return $this;
    }

    public function offset($offset): self
    {
        if ($offset) {
            $this->baseQuery->offset($offset);
        }

        return $this;
    }

    protected function applyExpression($query, Expression $expression): self
    {
        foreach ($expression->getItems() as $item) {
            $method = $item['operator'] === 'OR' ? 'orWhere' : 'where';
            $constraint = $item['constraint'];

            if ($constraint instanceof Expression) {
                $query->{$method}(function ($query) use ($constraint) {
                    $this->applyExpression($query, $constraint);
                });
            } else {
                $operator = $constraint->getOperator();

                $query->{$method}(
                    $this->resolvePropertyPath($constraint->getPath()),
                    $this->resolveConstraintOperator($operator),
                    $this->resolveConstraintValue($operator, $constraint->getValue())
                );
            }
        }

        return $this;
    }

    protected function resolvePropertyPath(Path $path): string
    {
        $property = $path->getEntity();

        return implode(
            '.',
            array_filter([$path->prefix()->implode(), $property?->alias ?? $property?->getPropertyName() ?? null])
        );
    }

    /**
     * @return mixed
     */
    protected function resolveConstraintOperator($operator)
    {
        if (array_key_exists($operator, $this::OPERATOR_MAP)) {
            $operator = $this::OPERATOR_MAP[$operator];
        }

        return $operator;
    }

    /**
     * @return mixed
     */
    protected function resolveConstraintValue($operator, $value)
    {
        if (array_key_exists($operator, $this::VALUE_MAP)) {
            $value = $this::VALUE_MAP[$operator];
        }

        return $value;
    }
}
