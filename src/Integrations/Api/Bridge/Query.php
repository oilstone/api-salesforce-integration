<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api\Bridge;

use Aggregate\Set;
use Api\Queries\Expression;
use Api\Queries\Paths\Path;
use Api\Queries\Relations as RequestRelations;
use Oilstone\ApiSalesforceIntegration\Query as SalesforceQuery;
use Oilstone\ApiSalesforceIntegration\Record;
use Carbon\Carbon;

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
            $this->baseQuery->select($fields);
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
            $this->baseQuery->orderBy(method_exists($order, 'getProperty') ? $order->getProperty() : $order->getPropertyName(), $order->getDirection());
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
                    $this->resolveConstraintValue($operator, $constraint->getValue(), $constraint->getPath())
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
    protected function resolveConstraintValue($operator, $value, ?Path $path = null)
    {
        $property = $path?->getEntity();

        if (array_key_exists($operator, $this::VALUE_MAP)) {
            $value = $this::VALUE_MAP[$operator];
        }

        if ($property) {
            if ($property->hasMeta('isYesNo') && $value !== null) {
                $value = $value ? 'Yes' : 'No';
            }

            switch ($property->getType()) {
                case 'boolean':
                    if (is_string($value)) {
                        $lower = strtolower($value);
                        if (in_array($lower, ['true', '1', 'yes'])) {
                            $value = 1;
                        } elseif (in_array($lower, ['false', '0', 'no'])) {
                            $value = 0;
                        }
                    }

                    $value = $value ? 1 : 0;
                    break;

                case 'integer':
                    $value = $value !== null ? (int) $value : $value;
                    break;

                case 'float':
                case 'decimal':
                case 'number':
                    $value = $value !== null ? (float) $value : $value;
                    break;

                case 'date':
                    if ($value) {
                        $value = Carbon::parse($value)->toDateString();
                    }
                    break;

                case 'datetime':
                case 'timestamp':
                    if ($value) {
                        $value = Carbon::parse($value)->toDateTimeString();
                    }
                    break;
            }
        }

        return $value;
    }
}
