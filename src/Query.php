<?php

namespace Oilstone\ApiSalesforceIntegration;

use Aggregate\Set;
use Api\Exceptions\InvalidQueryArgumentsException;
use Api\Exceptions\UnknownOperatorException;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

class Query
{
    protected string $object;

    protected Salesforce $client;

    protected array $selects = ['Id'];

    protected array $relationships = [];

    protected static array $descriptions = [];

    protected array $conditions = [];

    protected array $orders = [];

    protected ?int $limit = null;

    protected ?int $offset = null;

    public function __construct(string $object, Salesforce $client)
    {
        $this->object = $object;
        $this->client = $client;
    }

    public static function make(string $object, Salesforce $client): static
    {
        return new static($object, $client);
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
        if (str_contains($relation, ':')) {
            [$child, $fields] = explode(':', $relation, 2);
            $childRelationship = $this->childRelationshipName($child) ?? $child;

            $fieldList = array_filter(array_map(function ($field) {
                return trim($field);
            }, explode(',', $fields)));

            $fieldString = $fieldList ? implode(', ', $fieldList) : 'FIELDS(ALL)';

            return sprintf('(SELECT %s FROM %s)', $fieldString, $childRelationship);
        }

        $parts = explode('.', $relation, 2);

        $child = $parts[0];
        $childRelationship = $this->childRelationshipName($child) ?? $child;

        if (count($parts) === 1) {
            return sprintf('(SELECT FIELDS(ALL) FROM %s)', $childRelationship);
        }

        $field = $parts[1];
        if (str_ends_with($field, '__c')) {
            $field = substr($field, 0, -3).'__r';
        }

        return sprintf('(SELECT %s.FIELDS(ALL) FROM %s)', $field, $childRelationship);
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
        if (! array_key_exists($object, self::$descriptions)) {
            self::$descriptions[$object] = $this->client->describe($object);
        }

        return self::$descriptions[$object];
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

    protected function toSoql(): string
    {
        $select = implode(', ', array_merge($this->selects ?: ['Id'], $this->relationships));
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
                $values = array_map(function ($v) {
                    return is_numeric($v) ? $v : "'".addslashes($v)."'";
                }, $condition['values']);

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
                $value = is_numeric($value) ? $value : "'".addslashes($value)."'";
                $operator = strtoupper($operator);
            }

            $sql .= sprintf('%s %s %s', $condition['field'], $operator, $value);
        }

        return $sql;
    }
}
