<?php

namespace Oilstone\ApiSalesforceIntegration;

use Api\Exceptions\InvalidQueryArgumentsException;
use Api\Exceptions\UnknownOperatorException;
use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

class Query
{
    protected string $object;

    protected Salesforce $client;

    protected string $identifier;

    protected array $selects = [];

    protected array $relationships = [];


    protected array $conditions = [];

    protected array $orders = [];

    protected ?int $limit = null;

    protected ?int $offset = null;

    protected ?QueryCacheHandler $cacheHandler = null;

    protected array $cacheTags = [];

    public function __construct(string $object, Salesforce $client, string $identifier = 'Id')
    {
        $this->object = $object;
        $this->client = $client;
        $this->identifier = $identifier;
        $this->selects = [$identifier];
    }

    public function setCacheHandler(QueryCacheHandler $handler): static
    {
        $this->cacheHandler = $handler;

        return $this;
    }

    public function setCacheTags(array $tags): static
    {
        $this->cacheTags = $tags;

        return $this;
    }

    public function getCacheTags(): array
    {
        return $this->cacheTags;
    }

    public static function make(string $object, Salesforce $client, string $identifier = 'Id'): static
    {
        return new static($object, $client, $identifier);
    }

    public function getObject(): ?string
    {
        return $this->object;
    }

    public function setObject(string $object): static
    {
        $this->object = $object;

        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function with(string $relation): static
    {
        $relation = trim($relation);

        if (str_contains(strtoupper($relation), 'SELECT')) {
            $this->relationships[] = $relation;

            return $this;
        }

        $compiled = $this->compileInclude($relation);
        $this->relationships[] = $compiled;

        return $this;
    }

    protected function compileInclude(string $relation): string
    {
        $fields = '';

        if (str_contains($relation, ':')) {
            [$relation, $fields] = explode(':', $relation, 2);
        }

        $parts = explode('.', $relation, 2);

        $child = $parts[0];
        $childRelationship = $this->childRelationshipName($child) ?? $child;

        $prefix = null;
        if (count($parts) > 1) {
            $prefix = $parts[1];
            if (str_ends_with($prefix, '__c')) {
                $prefix = substr($prefix, 0, -3).'__r';
            }
        }

        $fieldList = array_filter(array_map(function ($field) {
            return trim($field);
        }, explode(',', $fields)));

        if (! $fieldList) {
            $fieldList = [$this->identifier, 'Name'];
        }

        if ($prefix) {
            $fieldList = array_map(fn ($field) => sprintf('%s.%s', $prefix, $field), $fieldList);
        }

        $fieldString = implode(', ', $fieldList);

        return sprintf('(SELECT %s FROM %s)', $fieldString, $childRelationship);
    }

    protected function childRelationshipName(string $object): ?string
    {
        $describe = $this->describe($this->object);

        foreach ($describe['childRelationships'] ?? [] as $relationship) {
            if (strcasecmp($relationship['childSObject'] ?? '', $object) === 0) {
                return $relationship['relationshipName'] ?? null;
            }

            if (strcasecmp($relationship['relationshipName'] ?? '', $object) === 0) {
                return $relationship['relationshipName'];
            }
        }

        return null;
    }

    protected function describe(string $object): array
    {
        return $this->client->describe($object);
    }

    public function select(array|string $columns): static
    {
        $this->selects = is_array($columns) ? $columns : explode(',', $columns);

        return $this;
    }

    public function where(...$arguments): static
    {
        return $this->addCondition('and', ...$arguments);
    }

    public function orWhere(...$arguments): static
    {
        return $this->addCondition('or', ...$arguments);
    }

    public function whereIn(string $field, array $values): static
    {
        return $this->addCondition('and', $field, 'in', $values);
    }

    public function orWhereIn(string $field, array $values): static
    {
        return $this->addCondition('or', $field, 'in', $values);
    }

    public function whereNotIn(string $field, array $values): static
    {
        return $this->addCondition('and', $field, 'not in', $values);
    }

    public function orWhereNotIn(string $field, array $values): static
    {
        return $this->addCondition('or', $field, 'not in', $values);
    }

    protected function addCondition(string $boolean, ...$arguments): static
    {
        if (! $arguments) {
            throw new InvalidQueryArgumentsException;
        }

        if ($arguments[0] instanceof \Closure) {
            $nested = new static($this->object, $this->client);
            $arguments[0]($nested);

            $this->conditions[] = [
                'boolean' => $boolean,
                'type' => 'nested',
                'conditions' => $nested->conditions,
            ];

            return $this;
        }

        if (count($arguments) < 2 || count($arguments) > 3) {
            throw new InvalidQueryArgumentsException;
        }

        $field = $arguments[0];
        $value = $arguments[2] ?? $arguments[1];
        $operator = count($arguments) === 3 ? mb_strtolower($arguments[1]) : '=';

        $supported = ['=', '!=', '>', '>=', '<', '<=', 'like', 'in', 'not in'];

        if (! in_array($operator, $supported)) {
            throw new UnknownOperatorException($operator);
        }

        $type = in_array($operator, ['in', 'not in']) ? 'in' : 'basic';

        $condition = [
            'boolean' => $boolean,
            'type' => $type,
            'field' => $field,
            'operator' => $operator,
        ];

        if ($type === 'in') {
            $condition['values'] = $value;
        } else {
            $condition['value'] = $value;
        }

        $this->conditions[] = $condition;

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

    public function get(): array
    {
        $soql = $this->toSoql();

        $callback = fn () => $this->client->query($soql);

        $results = $this->cacheHandler
            ? $this->cacheHandler->remember($soql, $callback, $this->cacheTags, ['log_request' => true])
            : $callback();

        return $results;
    }

    public function first(): ?array
    {
        $result = $this->limit(1)->get();

        return $result[0] ?? null;
    }

    public function pluck(string $column, ?string $index = null): array
    {
        $originalSelects = $this->selects;

        $selects = $this->selects;

        if (! in_array($column, $selects, true)) {
            $selects[] = $column;
        }

        if ($index && ! in_array($index, $selects, true)) {
            $selects[] = $index;
        }

        $this->select($selects);

        $results = $this->get();

        $this->selects = $originalSelects;

        $values = [];

        foreach ($results as $record) {
            $value = $record[$column] ?? null;

            if ($index) {
                $key = $record[$index] ?? null;
                $values[$key] = $value;
            } else {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function count(): int
    {
        $originalSelects = $this->selects;
        $originalRelationships = $this->relationships;

        $this->selects = ['COUNT()'];
        $this->relationships = [];

        $soql = $this->toSoql();

        $this->selects = $originalSelects;
        $this->relationships = $originalRelationships;

        $callback = fn () => $this->client->rawQuery($soql);

        $result = $this->cacheHandler
            ? $this->cacheHandler->remember($soql, $callback, $this->cacheTags, ['log_request' => true])
            : $callback();

        return $result['totalSize'] ?? 0;
    }

    protected function toSoql(): string
    {
        $select = implode(', ', array_merge($this->selects ?: [$this->identifier], $this->relationships));
        $query = "SELECT {$select} FROM {$this->object}";

        if ($this->conditions) {
            $query .= ' WHERE '.$this->compileConditions($this->conditions);
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

    protected function compileConditions(array $conditions): string
    {
        $sql = '';
        foreach ($conditions as $index => $condition) {
            if ($index > 0) {
                $sql .= ' '.strtoupper($condition['boolean']).' ';
            }

            if ($condition['type'] === 'nested') {
                $sql .= '('.$this->compileConditions($condition['conditions']).')';
                continue;
            }

            if ($condition['type'] === 'in') {
                $values = array_map(fn($v) => $this->formatValue($v), $condition['values']);

                $sql .= sprintf(
                    '%s %s (%s)',
                    $condition['field'],
                    strtoupper($condition['operator']),
                    implode(', ', $values)
                );

                continue;
            }

            $value = $condition['value'];
            $operator = $condition['operator'];

            if ($operator === 'like') {
                $value = "'%".addslashes(trim($value, '%'))."%'";
                $operator = 'LIKE';
            } else {
                $value = $this->formatValue($value);
                $operator = strtoupper($operator);
            }

            $sql .= sprintf('%s %s %s', $condition['field'], $operator, $value);
        }

        return $sql;
    }

    protected function formatValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        return is_numeric($value) ? (string) $value : "'".addslashes($value)."'";
    }
}
