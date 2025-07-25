<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api;

use Api\Pipeline\Pipes\Pipe;
use Api\Repositories\Contracts\Resource as RepositoryInterface;
use Api\Result\Contracts\Collection as ResultCollectionInterface;
use Api\Result\Contracts\Record as ResultRecordInterface;
use Api\Schema\Schema;
use Api\Transformers\Contracts\Transformer;
use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiSalesforceIntegration\Collection;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Bridge\QueryResolver;
use Oilstone\ApiSalesforceIntegration\Query;
use Oilstone\ApiSalesforceIntegration\Repository as BaseRepository;
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

        return $result ? Record::make($result->toArray()) : null;
    }

    public function getById(string $id): ResultRecordInterface
    {
        $result = $this->repository()->findOrFail($id);

        return Record::make($result->toArray());
    }

    public function getCollection(Pipe $pipe, ServerRequestInterface $request): ResultCollectionInterface
    {
        return Collection::make(
            (new QueryResolver(
                $this->newQuery($request->getQueryParams()['object'] ?? null),
                $pipe,
                $this->getDefaultFields(),
            ))->collection($request)->all()
        );
    }

    public function getRecord(Pipe $pipe, ServerRequestInterface $request): ?ResultRecordInterface
    {
        $object = $request->getQueryParams()['object'] ?? null;
        $query = $this->newQuery($object);

        $query->setCacheTags([
            $object ?? $this->object,
            ($object ?? $this->object) . ':' . $pipe->getKey(),
        ]);

        return (new QueryResolver(
            $query,
            $pipe,
            $this->getDefaultFields(),
        ))->record($request);
    }

    public function create(Pipe $pipe, ServerRequestInterface $request): ResultRecordInterface
    {
        $object = $request->getQueryParams()['object'] ?? null;

        $repository = $this->repository($object);

        $result = $repository->create($request->getParsedBody()->toArray());

        $result = $repository->find($result[$this->identifier]);

        return Record::make($result->toArray());
    }

    public function update(Pipe $pipe, ServerRequestInterface $request): ResultRecordInterface
    {
        $object = $request->getQueryParams()['object'] ?? null;

        $repository = $this->repository($object);

        $result = $repository->update($pipe->getKey(), $this->reverseAttributes($request->getParsedBody()->toArray()));

        $result = $repository->find($result[$this->identifier]);

        return Record::make($result->toArray());
    }

    public function delete(Pipe $pipe): ResultRecordInterface
    {
        $record = $this->getByKey($pipe);

        $this->repository()->delete($pipe->getKey());

        return $record;
    }

    /**
     * Create a record using the underlying repository after transforming the
     * provided attributes using the configured transformer.
     */
    public function sfCreate(array $attributes, ?string $object = null): array
    {
        $fields = $this->reverseAttributes($attributes);

        $result = $this->repository($object)->create($fields);

        return $this->sfFind($result[$this->identifier], [], $object);
    }

    /**
     * Update a record using the underlying repository after transforming the
     * provided attributes using the configured transformer.
     */
    public function sfUpdate(string $id, array $attributes, ?string $object = null): array
    {
        $fields = $this->reverseAttributes($attributes);

        $this->repository($object)->update($id, $fields);

        return $this->sfFind($id, [], $object);
    }

    /**
     * Proxy the firstOrCreate method on the underlying repository with schema
     * transformation of the provided values.
     */
    public function sfFirstOrCreate(array $attributes, array $extra = [], ?string $object = null): array
    {
        $attrs = $this->reverseAttributes($attributes);
        $extraFields = $this->reverseAttributes($extra);

        $record = $this->repository($object)->firstOrCreate($attrs, $extraFields);

        return $this->sfFind($record[$this->identifier], [], $object);
    }

    /**
     * Proxy the updateOrCreate method on the underlying repository with schema
     * transformation of the provided values.
     */
    public function sfUpdateOrCreate(array $attributes, array $values = [], ?string $object = null): array
    {
        $attrs = $this->reverseAttributes($attributes);
        $valueFields = $this->reverseAttributes($values);

        $record = $this->repository($object)->updateOrCreate($attrs, $valueFields);

        return $this->sfFind($record[$this->identifier], [], $object);
    }

    /**
     * Proxy the get method on the underlying repository with optional
     * transformation of the returned records.
     */
    public function sfGet(array $conditions = [], array $options = [], ?string $object = null): array
    {
        $options['select'] = $options['select'] ?? $this->getDefaultFields();

        $conditions = $this->reverseConditions($conditions);

        $collection = $this->repository($object)->get($conditions, $options);

        return array_map(function (Record $record) {
            return $this->transformer
                ? $this->transformer->transform($record)
                : $record->getAttributes();
        }, $collection->getItems());
    }

    /**
     * Proxy the find method on the underlying repository with optional
     * transformation of the returned record.
     */
    public function sfFind(string $id, array $options = [], ?string $object = null): ?array
    {
        $options['select'] = $options['select'] ?? $this->getDefaultFields();

        $record = $this->repository($object)->find($id, $options);

        if (! $record) {
            return null;
        }

        return $this->transformer
            ? $this->transformer->transform($record)
            : $record->getAttributes();
    }

    public function sfFindOrFail(string $id, array $options = [], ?string $object = null): array
    {
        $options['select'] = $options['select'] ?? $this->getDefaultFields();

        $record = $this->repository($object)->findOrFail($id, $options);

        return $this->transformer
            ? $this->transformer->transform($record)
            : $record->getAttributes();
    }

    /**
     * Proxy the first method on the underlying repository with optional
     * transformation of the returned record.
     */
    public function sfFirst(array $conditions = [], array $options = [], ?string $object = null): ?array
    {
        $options['select'] = $options['select'] ?? $this->getDefaultFields();

        $conditions = $this->reverseConditions($conditions);

        $record = $this->repository($object)->first($conditions, $options);

        if (! $record) {
            return null;
        }

        return $this->transformer
            ? $this->transformer->transform($record)
            : $record->getAttributes();
    }

    public function sfFirstOrFail(array $conditions = [], array $options = [], ?string $object = null): array
    {
        $options['select'] = $options['select'] ?? $this->getDefaultFields();

        $conditions = $this->reverseConditions($conditions);

        $record = $this->repository($object)->firstOrFail($conditions, $options);

        return $this->transformer
            ? $this->transformer->transform($record)
            : $record->getAttributes();
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
     * Reverse transform only the provided attributes.
     */
    protected function reverseAttributes(array $attributes): array
    {
        if ($this->transformer) {
            $attributes = $this->transformer->reverse($attributes);
        }

        return array_filter($attributes, fn ($value) => isset($value));
    }

    /**
     * Reverse transform conditions using the configured transformer.
     */
    protected function reverseConditions(array $conditions): array
    {
        return $conditions ? $this->reverseAttributes($conditions) : [];
    }

    protected function newQuery(?string $object = null): Query
    {
        return $this->repository($object)->newQuery();
    }

    /**
     * Gets the default fields from the schema, including nested schema properties.
     *
     * @return array A flat array of unique field names.
     */
    protected function getDefaultFields(): array
    {
        $fields = [];

        if ($this->schema) {
            $fields = $this->extractSchemaFields($this->schema);
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

            if ($property->hasMeta('default')) {
                $value = $property->default;

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
