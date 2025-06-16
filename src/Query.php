<?php

namespace Oilstone\ApiSalesforceIntegration;

use Aggregate\Set;
use Api\Exceptions\InvalidQueryArgumentsException;
use Api\Exceptions\UnknownOperatorException;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

class Query
{
    protected ?string $object;

    protected Salesforce $client;

    protected array $selects = ['Id'];

    protected array $conditions = [];

    protected array $orders = [];

    protected ?int $limit = null;

    protected ?int $offset = null;

    public function __construct(?string $object, Salesforce $client)
    {
        $this->object = $object;
        $this->client = $client;
    }

    public static function make(?string $object, Salesforce $client): static
    {
        return new static($object, $client);
    }

    public function getObject(): ?string
    {
        return $this->object;
    }

    public function setObject(?string $object): static
    {
        $this->object = $object;

        return $this;
    }

    public function with(string $relation): static
    {
        // Relationships are resolved via SOQL joins or additional queries.
        // This method is as yet unsupported in this implementation.
        return $this;
    }

    public function select(array|string $columns): static
    {
        $this->selects = is_array($columns) ? $columns : explode(',', $columns);

        return $this;
    }

    public function where(...$arguments): static
    {
        if (count($arguments) < 2 || count($arguments) > 3) {
            throw new InvalidQueryArgumentsException;
        }

        $field = $arguments[0];
        $value = $arguments[2] ?? $arguments[1];
        $operator = count($arguments) === 3 ? mb_strtolower($arguments[1]) : '=';

        $supported = ['=', '!=', '>', '>=', '<', '<=', 'like'];

        if (! in_array($operator, $supported)) {
            throw new UnknownOperatorException($operator);
        }

        $this->conditions[] = [$field, $operator, $value];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orders[] = [$column, strtoupper($direction)];

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = $offset;

        return $this;
    }

    public function get(): Set
    {
        return (new Set)->fill(array_map(
            fn (array $item) => (new Record)->fill($item),
            $this->client->query($this->toSoql())
        ));
    }

    public function first(): ?Record
    {
        $result = $this->limit(1)->get();

        return $result->count() ? $result[0] : null;
    }

    public static function resolveRelation(string $relation): ?string
    {
        // Relationships are resolved via SOQL joins or additional queries.
        // This method is as yet unsupported in this implementation.

        return null;
    }

    protected function toSoql(): string
    {
        $select = implode(', ', $this->selects ?: ['Id']);
        $query = "SELECT {$select} FROM {$this->object}";

        if ($this->conditions) {
            $clauses = [];
            foreach ($this->conditions as [$field, $operator, $value]) {
                if ($operator === 'like') {
                    $value = "'%".addslashes(trim($value, '%'))."%'";
                    $operator = 'LIKE';
                } else {
                    $value = is_numeric($value) ? $value : "'".addslashes($value)."'";
                    $operator = strtoupper($operator);
                }
                $clauses[] = "{$field} {$operator} {$value}";
            }
            $query .= ' WHERE '.implode(' AND ', $clauses);
        }

        if ($this->orders) {
            $orderStrings = array_map(fn ($o) => "{$o[0]} {$o[1]}", $this->orders);
            $query .= ' ORDER BY '.implode(', ', $orderStrings);
        }

        if ($this->limit !== null) {
            $query .= ' LIMIT '.$this->limit;
        }

        if ($this->offset !== null) {
            $query .= ' OFFSET '.$this->offset;
        }

        return $query;
    }
}
