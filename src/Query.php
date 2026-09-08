<?php

namespace Oilstone\ApiSalesforceIntegration;

use Api\Exceptions\InvalidQueryArgumentsException;
use Api\Exceptions\UnknownOperatorException;
use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\ApiSalesforceIntegration\Values\RawValue;

class Query
{
    protected string $object;

    protected Salesforce $client;

    protected string $identifier;

    protected array $selects = [];

    protected array $relationships = [];

    protected array $relationshipGroups = [];

    protected array $conditions = [];

    protected array $orders = [];

    protected ?int $limit = null;

    protected ?int $offset = null;

    protected ?QueryCacheHandler $cacheHandler = null;

    protected array $cacheOptions = [];

    protected array $indexableFields = [];

    /**
     * Child objects pulled in through relationship includes, so cached results for this
     * query are invalidated when any of them changes.
     *
     * @var array<int, string>
     */
    protected array $relatedObjects = [];

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

    public function setCacheOptions(array $options): static
    {
        $this->cacheOptions = $options;

        return $this;
    }

    public function setIndexableFields(array $fields): static
    {
        $this->indexableFields = array_values(array_unique(array_filter($fields, 'is_string')));

        return $this;
    }

    public function getIndexableFields(): array
    {
        return $this->indexableFields;
    }

    public static function make(string $object, Salesforce $client, string $identifier = 'Id'): static
    {
        return new static($object, $client, $identifier);
    }

    /**
     * Wrap a value so it is written into SOQL verbatim (unquoted).
     */
    public static function raw(string $value): RawValue
    {
        return new RawValue($value);
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

        $this->addIncludeToGroups($relation);

        return $this;
    }

    protected function addIncludeToGroups(string $relation): void
    {
        [$relationPath, $fields] = $this->splitIncludeAndFields($relation);

        $parts = array_values(array_filter(explode('.', $relationPath), fn ($part) => trim($part) !== ''));

        if (! $parts) {
            return;
        }

        $rootRelationship = $this->childRelationshipMetadata($this->object, $parts[0]);
        $root = $rootRelationship['relationshipName'] ?? $parts[0];
        $rootObject = $rootRelationship['childSObject'] ?? null;

        if ($rootObject) {
            $this->trackRelatedObject($rootObject);
        }

        if (! isset($this->relationshipGroups[$root])) {
            $this->relationshipGroups[$root] = ['fields' => [], 'subqueries' => []];
        }

        if (count($parts) === 1) {
            foreach ($this->normaliseFieldList($fields) as $field) {
                $this->relationshipGroups[$root]['fields'][$field] = true;
            }

            return;
        }

        $path = implode('.', array_slice($parts, 1));

        $nestedRelationship = $rootObject && ! str_contains($path, '.')
            ? $this->childRelationshipMetadata($rootObject, $path)
            : null;

        if ($nestedRelationship) {
            if (! empty($nestedRelationship['childSObject'])) {
                $this->trackRelatedObject($nestedRelationship['childSObject']);
            }

            $subquery = $this->compileNestedSubquery($path, $fields);
            $this->relationshipGroups[$root]['subqueries'][$subquery] = true;

            return;
        }

        $traversalPath = $this->normaliseTraversalPath($path);

        foreach ($this->normaliseFieldList($fields) as $field) {
            $this->relationshipGroups[$root]['fields'][$traversalPath.'.'.$field] = true;
        }
    }

    protected function normaliseTraversalPath(string $path): string
    {
        return implode('.', array_map(function (string $segment) {
            if (str_ends_with($segment, '__c')) {
                return substr($segment, 0, -3).'__r';
            }

            return $segment;
        }, explode('.', $path)));
    }

    protected function splitIncludeAndFields(string $relation): array
    {
        if (! str_contains($relation, ':')) {
            return [$relation, ''];
        }

        return explode(':', $relation, 2);
    }

    protected function normaliseFieldList(string $fields): array
    {
        $fieldList = array_filter(array_map(function ($field) {
            return trim($field);
        }, explode(',', $fields)));

        if (! $fieldList) {
            return [$this->identifier, 'Name'];
        }

        return $fieldList;
    }

    protected function trackRelatedObject(string $object): void
    {
        foreach ($this->relatedObjects as $existing) {
            if (strcasecmp($existing, $object) === 0) {
                return;
            }
        }

        $this->relatedObjects[] = $object;
    }

    /**
     * @return array<int, string>
     */
    public function getRelatedObjects(): array
    {
        return $this->relatedObjects;
    }

    /**
     * Every object this query reads: the root object plus included child objects.
     *
     * @return array<int, string>
     */
    public function getCacheObjects(): array
    {
        return array_merge([$this->object], $this->relatedObjects);
    }

    protected function compileNestedSubquery(string $relationship, string $fields): string
    {
        $fieldString = implode(', ', $this->normaliseFieldList($fields));

        return sprintf('(SELECT %s FROM %s)', $fieldString, $relationship);
    }

    protected function childRelationshipMetadata(string $object, string $relationshipOrChildObject): ?array
    {
        $describe = $this->describe($object);

        foreach ($describe['childRelationships'] ?? [] as $relationship) {
            if (strcasecmp($relationship['childSObject'] ?? '', $relationshipOrChildObject) === 0) {
                return $relationship;
            }

            if (strcasecmp($relationship['relationshipName'] ?? '', $relationshipOrChildObject) === 0) {
                return $relationship;
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
        $columns = is_array($columns) ? $columns : explode(',', $columns);

        $this->selects = array_values(array_filter(
            array_map(static fn ($column) => trim((string) $column), $columns),
            static fn (string $column) => $column !== ''
        ));

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

        $options = array_merge(['log_request' => true], $this->cacheOptions);

        $results = $this->cacheHandler
            ? $this->cacheHandler->rememberQuery($soql, $callback, $options, $this->getCacheObjects())
            : $callback();

        return $results;
    }

    public function first(): ?array
    {
        $query = clone $this;
        $query->limit = 1;

        $soql = $query->toSoql();

        $callback = function () use ($soql) {
            $results = $this->client->query($soql);

            return $results[0] ?? null;
        };

        $options = array_merge(['log_request' => true], $this->cacheOptions);

        $result = $this->cacheHandler
            ? $this->cacheHandler->rememberEntry($query, $soql, $callback, $options)
            : $callback();

        return $result ?? null;
    }

    public function pluck(string $column, ?string $index = null): array
    {
        $query = clone $this;

        $selects = $query->selects;

        if (! in_array($column, $selects, true)) {
            $selects[] = $column;
        }

        if ($index && ! in_array($index, $selects, true)) {
            $selects[] = $index;
        }

        $query->select($selects);

        $results = $query->get();

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
        // COUNT() must be the only item in the SELECT list and cannot be combined with ORDER BY.
        $query = clone $this;
        $query->selects = ['COUNT()'];
        $query->relationships = [];
        $query->relationshipGroups = [];
        $query->orders = [];

        $soql = $query->toSoql();

        $callback = fn () => $this->client->rawQuery($soql);

        $options = array_merge(['log_request' => true], $this->cacheOptions);

        $result = $this->cacheHandler
            ? $this->cacheHandler->rememberQuery($soql, $callback, $options, $this->getCacheObjects())
            : $callback();

        return $result['totalSize'] ?? 0;
    }

    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function getConditionSignature(): array
    {
        return $this->mapConditionsForSignature($this->conditions);
    }

    /**
     * Describe which fields and relationships this query selects, so cached results
     * are never shared between queries that return different columns.
     */
    public function getSelectSignature(): array
    {
        return [
            'selects' => array_values(array_map('trim', $this->selects ?: [$this->identifier])),
            'relationships' => array_values($this->relationships),
            'relationshipGroups' => $this->relationshipGroups,
        ];
    }

    public function getOrders(): array
    {
        return $this->orders;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    protected function mapConditionsForSignature(array $conditions): array
    {
        return array_map(function (array $condition) {
            $signature = [
                'boolean' => $condition['boolean'],
                'type' => $condition['type'],
            ];

            if ($condition['type'] === 'nested') {
                $signature['conditions'] = $this->mapConditionsForSignature($condition['conditions']);
                return $signature;
            }

            $signature['field'] = $condition['field'];
            $signature['operator'] = $condition['operator'];

            if ($condition['type'] === 'in') {
                $signature['values'] = $condition['values'];
            } else {
                $signature['value'] = $condition['value'];
            }

            return $signature;
        }, $conditions);
    }

    public function toSoql(): string
    {
        $groupedRelationships = array_map(function (string $relation, array $parts) {
            $fields = array_keys($parts['fields']);
            $subqueries = array_keys($parts['subqueries']);

            $fieldString = implode(', ', array_merge($fields, $subqueries));

            return sprintf('(SELECT %s FROM %s)', $fieldString, $relation);
        }, array_keys($this->relationshipGroups), $this->relationshipGroups);

        $select = implode(', ', array_merge($this->selects ?: [$this->identifier], $this->relationships, $groupedRelationships));
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
                $inValues = is_array($condition['values']) ? $condition['values'] : [$condition['values']];

                if ($inValues === []) {
                    // "IN ()" is a SOQL syntax error. Every sObject has a non-null Id, so these
                    // literals are always-false (IN) and always-true (NOT IN) respectively.
                    $sql .= $condition['operator'] === 'in' ? 'Id = null' : 'Id != null';

                    continue;
                }

                $values = array_map(fn($v) => $this->formatValue($v), $inValues);

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
                $value = (string) $value;

                // Preserve caller-supplied wildcards (e.g. "Acme%" prefix searches). Only fall
                // back to a contains search when no wildcard was given.
                if (! str_contains($value, '%')) {
                    $value = '%'.$value.'%';
                }

                $value = "'".addslashes($value)."'";
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
        if ($value === null) {
            return 'null';
        }

        if ($value instanceof RawValue) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            // SOQL datetime literals are unquoted ISO 8601 values.
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        }

        return "'".addslashes((string) $value)."'";
    }
}
