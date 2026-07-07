<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api\Transformers\Contracts;

use Api\Result\Contracts\Record;
use Api\Transformers\Contracts\Transformer;

/**
 * A transformer that can additionally shape a whole collection in a single pass,
 * bracketing the per-record transformation with collection-level hooks.
 */
interface CollectionTransformer extends Transformer
{
    public function beforeCollection(callable $callback): static;

    public function afterCollection(callable $callback): static;

    public function hasCollectionCallbacks(): bool;

    /**
     * @param array<int, Record> $records
     * @return array<int, Record>
     */
    public function applyBeforeCollection(array $records): array;

    /**
     * @param array<int, array> $transformed
     * @param array<int, Record> $records
     * @return array<int, array>
     */
    public function applyAfterCollection(array $transformed, array $records): array;

    /**
     * @param iterable<int, Record> $records
     * @return array<int, array>
     */
    public function transformCollection(iterable $records): array;
}
