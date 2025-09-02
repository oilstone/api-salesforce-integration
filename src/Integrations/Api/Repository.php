<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api;

use Api\Pipeline\Pipes\Pipe;
use Api\Repositories\Contracts\Resource as RepositoryInterface;
use Api\Result\Contracts\Collection as ResultCollectionInterface;
use Api\Result\Contracts\Record as ResultRecordInterface;
use Api\Schema\Schema;
use Api\Transformers\Contracts\Transformer;
use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Bridge\QueryResolver;
use Oilstone\ApiSalesforceIntegration\Query;
use Oilstone\ApiSalesforceIntegration\Repository as BaseRepository;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Results\Record as ApiResultRecord;
use Oilstone\ApiSalesforceIntegration\RecordCollection;
use Oilstone\ApiSalesforceIntegration\Record;
use Psr\Http\Message\ServerRequestInterface;

class Repository implements RepositoryInterface
{
    protected ?Schema $schema = null;

    protected ?Transformer $transformer = null;

    protected array $defaultConstraints = [];

    protected array $defaultIncludes = [];

    protected ?QueryCacheHandler $cacheHandler = null;

    protected string $identifier = 'Id';

    public function __construct(
        protected ?string $object = null,
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

    public function setCacheHandler(QueryCacheHandler $handler): static
    {
        $this->cacheHandler = $handler;

        return $this;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getByKey(Pipe $pipe): ?ResultRecordInterface
    {
        $result = $this->repository()->find($pipe->getKey());

        return $result ? ApiResultRecord::make($result) : null;
    }

    public function getCollection(Pipe $pipe, ServerRequestInterface $request): ResultCollectionInterface
    {
        return (new QueryResolver($this->newQuery($request->getQueryParams()['object'] ?? null), $pipe, $this->getDefaultFields()))->collection($request);
    }

    public function getRecord(Pipe $pipe, ServerRequestInterface $request): ?ResultRecordInterface
    {
        $object = $request->getQueryParams()['object'] ?? null;
        $query = $this->newQuery($object);

        $query->setCacheTags([
            $object ?? $this->object,
            ($object ?? $this->object) . ':' . $pipe->getKey(),
            ($object ?? $this->object) . ':findOne',
        ]);

        return (new QueryResolver($query, $pipe, $this->getDefaultFields()))->record($request);
    }

    public function create(Pipe $pipe, ServerRequestInterface $request): ResultRecordInterface
    {
        $object = $request->getQueryParams()['object'] ?? null;
        $repository = $this->repository($object);

        $fields = $this->reverseAttributes($request->getParsedBody()->toArray(), true);

        $result = $repository->create($fields);

        $record = $repository->findOrFail(['Id' => $result['id']]);

        return ApiResultRecord::make($record);
    }

    public function update(Pipe $pipe, ServerRequestInterface $request): ResultRecordInterface
    {
        $object = $request->getQueryParams()['object'] ?? null;
        $repository = $this->repository($object);

        $id = $pipe->getKey();

        $fields = $this->reverseAttributes($request->getParsedBody()->toArray(), true);

        $this->repository($object)->update($id, $fields);

        $record = $repository->findOrFail($id);

        return ApiResultRecord::make($record);
    }

    public function delete(Pipe $pipe): ResultRecordInterface
    {
        $repository = $this->repository();

        $record = $repository->findOrFail($pipe->getKey());

        $repository->delete($pipe->getKey());

        return ApiResultRecord::make($record);
    }

    /**
     * Create a record using the underlying repository after transforming the
     * provided attributes using the configured transformer.
     */
    public function createRecord(array $attributes, ?string $object = null): Record
    {
        $fields = $this->reverseAttributes($attributes, true);

        $result = $this->repository($object)->create($fields);

        $record = $this->repository($object)->findOrFail(['Id' => $result['id']]);

        return $this->transformRecord($record);
    }

    /**
     * Force create a record bypassing readonly and fixed field protections.
     */
    public function forceCreateRecord(array $attributes, ?string $object = null): Record
    {
        $fields = $this->reverseAttributes($attributes, true, true);

        $result = $this->repository($object)->create($fields);

        $record = $this->repository($object)->findOrFail(['Id' => $result['id']]);

        return $this->transformRecord($record);
    }

    /**
     * Update a record using the underlying repository after transforming the
     * provided attributes using the configured transformer.
     */
    public function updateRecord(string $id, array $attributes, ?string $object = null): Record
    {
        $fields = $this->reverseAttributes($attributes, true);

        $this->repository($object)->update($id, $fields);

        return $this->findRecordOrFail($id, [], $object);
    }

    /**
     * Force update a record bypassing readonly and fixed field protections.
     */
    public function forceUpdateRecord(string $id, array $attributes, ?string $object = null): Record
    {
        $fields = $this->reverseAttributes($attributes, true, true);

        $this->repository($object)->update($id, $fields);

        return $this->findRecordOrFail($id, [], $object);
    }

    /**
     * Delete a record using the underlying repository.
     */
    public function deleteRecord(string $id, ?string $object = null): Record
    {
        $record = $this->findRecordOrFail($id, [], $object);

        $this->repository($object)->delete($id);

        return $record;
    }

    /**
     * Proxy the firstOrCreate method on the underlying repository with schema
     * transformation of the provided values.
     */
    public function firstOrCreateRecord(array $attributes, array $extra = [], ?string $object = null): Record
    {
        $attrs = $this->reverseConditions($attributes);
        $extraFields = $this->reverseAttributes($extra, true);

        $record = $this->repository($object)->firstOrCreate($attrs, $extraFields);

        return $this->transformRecord($record);
    }

    /**
     * Proxy the updateOrCreate method on the underlying repository with schema
     * transformation of the provided values.
     */
    public function updateOrCreateRecord(array $attributes, array $values = [], ?string $object = null): Record
    {
        $attrs = $this->reverseConditions($attributes);
        $valueFields = $this->reverseAttributes($values, true);

        $record = $this->repository($object)->updateOrCreate($attrs, $valueFields);

        return $this->transformRecord($record);
    }

    /**
     * Proxy the get method on the underlying repository with optional
     * transformation of the returned records.
     */
    public function getRecords(array $conditions = [], array $options = [], ?string $object = null): RecordCollection
    {
        $options['select'] = $options['select'] ?? $this->getDefaultFields();

        $conditions = $this->reverseConditions($conditions);

        $records = $this->repository($object)->get($conditions, $options);

        $records = array_map(fn (array $record) => $this->transformRecord($record), $records);

        return RecordCollection::make($records);
    }

    /**
     * Proxy the find method on the underlying repository with optional
     * transformation of the returned record.
     */
    public function findRecord(string $id, array $options = [], ?string $object = null): ?Record
    {
        $options['select'] = $options['select'] ?? $this->getDefaultFields();

        $record = $this->repository($object)->find($id, $options);

        if (! $record) {
            return null;
        }

        return $this->transformRecord($record);
    }

    /**
     * Proxy the findOrFail method on the underlying repository with optional
     * transformation of the returned record.
     */
    public function findRecordOrFail(string $id, array $options = [], ?string $object = null): Record
    {
        $options['select'] = $options['select'] ?? $this->getDefaultFields();

        $record = $this->repository($object)->findOrFail($id, $options);

        return $this->transformRecord($record);
    }

    /**
     * Proxy the count method on the underlying repository.
     */
    public function countRecords(array $conditions = [], array $options = [], ?string $object = null): int
    {
        $conditions = $this->reverseConditions($conditions);

        return $this->repository($object)->count($conditions, $options);
    }

    /**
     * Proxy the first method on the underlying repository with optional
     * transformation of the returned record.
     */
    public function firstRecord(array $conditions = [], array $options = [], ?string $object = null): ?Record
    {
        $options['select'] = $options['select'] ?? $this->getDefaultFields();

        $conditions = $this->reverseConditions($conditions);

        $record = $this->repository($object)->first($conditions, $options);

        if (! $record) {
            return null;
        }

        return $this->transformRecord($record);
    }

    /**
     * Proxy the firstOrFail method on the underlying repository with optional
     * transformation of the returned record.
     */
    public function firstRecordOrFail(array $conditions = [], array $options = [], ?string $object = null): Record
    {
        $options['select'] = $options['select'] ?? $this->getDefaultFields();

        $conditions = $this->reverseConditions($conditions);

        $record = $this->repository($object)->firstOrFail($conditions, $options);

        return $this->transformRecord($record);
    }

    public function repository(?string $object = null): BaseRepository
    {
        $object ??= $this->object;

        if (! $object) {
            throw new \Oilstone\ApiSalesforceIntegration\Exceptions\ObjectNotSpecifiedException();
        }

        return new BaseRepository(
            $object,
            $this->defaultConstraints,
            $this->defaultIncludes,
            $this->getDefaultValues(),
            $this->identifier,
            $this->cacheHandler,
        );
    }

    /**
     * Create a repository instance with no inherited defaults.
     */
    public function freshRepository(?string $object = null): BaseRepository
    {
        $object ??= $this->object;

        if (! $object) {
            throw new \Oilstone\ApiSalesforceIntegration\Exceptions\ObjectNotSpecifiedException();
        }

        return new BaseRepository(
            $object,
            [],
            [],
            [],
            'Id',
            $this->cacheHandler,
        );
    }

    protected function newQuery(?string $object = null): Query
    {
        return $this->repository($object)->newQuery();
    }

    public function __call(string $method, array $parameters)
    {
        $repository = $this->repository();

        if (method_exists($repository, $method)) {
            return $repository->{$method}(...$parameters);
        }

        throw new \BadMethodCallException(sprintf(
            'Method %s::%s does not exist.',
            static::class,
            $method
        ));
    }

    /**
     * Transform a record array using the configured transformer.
     */
    protected function transformRecord(array $record): Record
    {
        $raw = $record;

        if ($this->transformer) {
            $recordObj = ApiResultRecord::make($record);
            $record = $this->transformer->transform($recordObj);
        }

        return Record::make($record, $raw);
    }

    /**
     * Reverse transform only the provided attributes.
     *
     * @param array $attributes
     * @param bool $allowNull When true, `null` values will be retained instead
     *                        of being filtered out.
     * @param bool $force     When true, readonly and fixed field protection is bypassed.
     */
    protected function reverseAttributes(array $attributes, bool $allowNull = false, bool $force = false): array
    {
        if (! $this->transformer) {
            return $allowNull
                ? $attributes
                : array_filter($attributes, static fn ($value) => isset($value));
        }

        $reversed = $force && method_exists($this->transformer, 'forceReverse')
            ? $this->transformer->forceReverse($attributes)
            : $this->transformer->reverse($attributes);

        return $allowNull ? $reversed : array_filter($reversed, fn ($value) => isset($value));
    }

    /**
     * Reverse transform conditions using the configured transformer.
     */
    protected function reverseConditions(array $conditions): array
    {
        if (! $conditions) {
            return [];
        }

        // Force reversing so readonly fields remain available for querying.
        $reversed = $this->reverseAttributes($conditions, false, true);

        if ($this->schema) {
            $reversed = $this->stripDefaultValues($reversed, $this->getDefaultValues(), $conditions);
        }

        return $reversed;
    }

    /**
     * Remove any values from the reversed array that were not provided in the
     * original attributes and merely came from schema defaults or fixed values.
     */
    protected function stripDefaultValues(array $reversed, array $defaults, array $provided): array
    {
        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $reversed)) {
                continue;
            }

            if (is_array($value)) {
                $childProvided = is_array($provided[$key] ?? null) ? $provided[$key] : [];
                $reversed[$key] = $this->stripDefaultValues($reversed[$key], $value, $childProvided);

                if ($reversed[$key] === []) {
                    unset($reversed[$key]);
                }

                continue;
            }

            if (! array_key_exists($key, $provided)) {
                unset($reversed[$key]);
            }
        }

        return $reversed;
    }

    /**
     * Gets the default fields from the schema, including nested schema properties,
     * and ensures identifier fields such as the Salesforce 'Id' and any custom
     * identifier are present.
     *
     * @return array A flat array of unique field names.
     */
    protected function getDefaultFields(): array
    {
        $fields = [];

        if ($this->schema) {
            $fields = $this->extractSchemaFields($this->schema);
        }

        if (! in_array('Id', $fields, true)) {
            $fields[] = 'Id';
        }

        if ($this->identifier !== 'Id' && ! in_array($this->identifier, $fields, true)) {
            $fields[] = $this->identifier;
        }

        return array_unique($fields);
    }

    /**
     * Recursively extracts fields from a schema, handling nested schemas and aliasing.
     *
     * @param Schema $schema The schema object to process.
     * @param string $prefix The prefix to prepend to all fields found in this schema.
     * @return array A flat array of field names.
     */
    protected function extractSchemaFields($schema, string $prefix = ''): array
    {
        $fields = [];

        if ($schema->getPrimary()) {
            $primaryField = $schema->getPrimary()->alias ?: $schema->getPrimary()->getName();
            $fields[] = $prefix . $primaryField;
        }

        if (!$schema->getProperties()) {
            return $fields;
        }

        foreach ($schema->getProperties() as $property) {
            if ($property->hasMeta('needs')) {
                $needs = is_array($property->needs) ? $property->needs : [$property->needs];

                foreach ($needs as $need) {
                    $fields[] = $prefix . $need;
                }
            }

            if ($property->hasMeta('validationOnly') || $property->hasMeta('calculated') || $property->hasMeta('isRelation')) {
                continue;
            }

            if ($property->getType() === 'schema' && $property->getAccepts()) {
                $nestedPrefix = $property->alias ? $property->alias . '.' : '';

                $nestedFields = $this->extractSchemaFields(
                    $property->getAccepts(),
                    $prefix . $nestedPrefix
                );

                $fields = array_merge($fields, $nestedFields);
            } else {
                $fieldName = $property->alias ?: $property->getName();
                $fields[] = $prefix . $fieldName;
            }
        }

        return $fields;
    }

    /**
     * Retrieve default attribute values defined in the schema.
     */
    protected function getDefaultValues(): array
    {
        return $this->schema ? $this->extractSchemaDefaults($this->schema) : [];
    }

    /**
     * Recursively extract default values from the provided schema.
     */
    protected function extractSchemaDefaults(Schema $schema, string $prefix = ''): array
    {
        $defaults = [];

        foreach ($schema->getProperties() as $property) {
            if ($property->hasMeta('readonly') || $property->hasMeta('calculated') || $property->hasMeta('validationOnly') || $property->hasMeta('isRelation')) {
                continue;
            }

            $key = $property->alias ?: $property->getName();

            if ($property->getAccepts() instanceof Schema && $property->getType() !== 'collection') {
                $nested = $this->extractSchemaDefaults($property->getAccepts(), $prefix . $key . '.');
                $defaults = array_replace_recursive($defaults, $nested);
                continue;
            }

            if ($property->hasMeta('default') || $property->hasMeta('fixed')) {
                $value = $property->hasMeta('fixed') ? $property->fixed : $property->default;

                if ($property->hasMeta('isYesNo') && $value !== null) {
                    $value = $value ? 'Yes' : 'No';
                }

                $path = explode('.', $prefix . $key);
                $current = &$defaults;

                while (count($path) > 1) {
                    $segment = array_shift($path);

                    if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                        $current[$segment] = [];
                    }

                    $current = &$current[$segment];
                }

                $current[$path[0]] = $value;
            }
        }

        return $defaults;
    }
}
