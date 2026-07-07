<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api\Results;

use Api\Result\Contracts\Record as ApiRecordContract;

/**
 * A record whose attributes have already been passed through the transformer.
 *
 * The API framework transforms collection members one record at a time while
 * building their representation. When collection-level transform hooks are in
 * play the repository performs that transformation eagerly (so the hooks can see
 * the whole set) and hands the framework these wrappers instead. Attribute
 * transformation is therefore a passthrough, while relationship includes and
 * metadata continue to resolve from the original Salesforce payload.
 */
class TransformedRecord extends Record implements ApiRecordContract
{
    protected array $transformed = [];

    public static function fromRecord(Record $record, array $transformed): static
    {
        $instance = new static;

        $instance->items = $record->all();
        $instance->transformed = $transformed;
        $instance->setMetaData($record->getMetaData());

        return $instance;
    }

    public function getAttributes(): array
    {
        return $this->transformed;
    }

    public function getAttribute(string $key): mixed
    {
        return $this->transformed[$key] ?? null;
    }
}
