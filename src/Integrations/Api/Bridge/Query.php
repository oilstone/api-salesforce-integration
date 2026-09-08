<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api\Bridge;

use Api\Queries\Expression;
use Api\Queries\Paths\Path;
use Api\Queries\Relations as RequestRelations;
use Oilstone\ApiSalesforceIntegration\Query as SalesforceQuery;
use Oilstone\ApiSalesforceIntegration\Values\RawValue;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Results\Collection;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Results\Record;
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

    public function get(): Collection
    {
        return Collection::make($this->baseQuery->get());
    }

    public function first(): ?Record
    {
        $result = $this->baseQuery->first();

        return $result ? Record::make($result) : null;
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
            $property = $order->getPath()->getEntity();

            $this->baseQuery->orderBy(
                $property?->alias ?? $property?->getPropertyName() ?? $order->getPropertyName(),
                $order->getDirection()
            );
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

    protected function resolveConstraintOperator($operator): mixed
    {
        if (array_key_exists($operator, $this::OPERATOR_MAP)) {
            $operator = $this::OPERATOR_MAP[$operator];
        }

        return $operator;
    }

    protected function resolveConstraintValue($operator, $value, ?Path $path = null): mixed
    {
        $property = $path?->getEntity();

        if (array_key_exists($operator, $this::VALUE_MAP)) {
            $value = $this::VALUE_MAP[$operator];
        }

        if ($property) {
            $type = $property->getType();

            $value = is_array($value)
                ? array_map(fn ($item) => $this->castValue($type, $item), $value)
                : $this->castValue($type, $value);

            if ($property->hasMeta('isYesNo') && $value !== null) {
                $value = is_array($value)
                    ? array_map(fn ($item) => $item ? 'Yes' : 'No', $value)
                    : ($value ? 'Yes' : 'No');
            }
        }

        return $value;
    }

    /**
     * Cast a single constraint value to the representation SOQL expects for the
     * property type. Booleans become real booleans (SOQL requires TRUE/FALSE, not
     * 1/0) and date/datetime values become unquoted ISO 8601 literals.
     */
    protected function castValue(?string $type, mixed $value): mixed
    {
        switch ($type) {
            case 'boolean':
                if (is_string($value)) {
                    $lower = strtolower(trim($value));

                    if (in_array($lower, ['true', '1', 'yes'], true)) {
                        return true;
                    }

                    if (in_array($lower, ['false', '0', 'no'], true)) {
                        return false;
                    }
                }

                return (bool) $value;

            case 'integer':
                return $value !== null ? (int) $value : null;

            case 'float':
            case 'decimal':
            case 'number':
                return $value !== null ? (float) $value : null;

            case 'date':
                if ($value instanceof RawValue || ! $value) {
                    return $value;
                }

                return new RawValue(Carbon::parse($value)->toDateString());

            case 'datetime':
            case 'timestamp':
                if ($value instanceof RawValue || ! $value) {
                    return $value;
                }

                return new RawValue(Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s\Z'));
        }

        return $value;
    }
}
