<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\ApiResourceLoader;

use Api\Guards\OAuth2\Sentinel;
use Api\Repositories\Contracts\Resource as RepositoryContract;
use Api\Schema\Schema as BaseSchema;
use Api\Transformers\Contracts\Transformer as TransformerContract;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Schema\Schema;
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Transformers\Transformer;
use Oilstone\ApiSalesforceIntegration\Repository;
use Oilstone\ApiResourceLoader\Resources\Resource as BaseResource;

class Resource extends BaseResource
{
    protected ?string $object;

    public function repository(?Sentinel $sentinel = null): ?RepositoryContract
    {
        return new Repository($this->object, $sentinel);
    }

    public function transformer(BaseSchema $schema): ?TransformerContract
    {
        return new Transformer($schema);
    }

    protected function newSchemaObject(): BaseSchema
    {
        return new Schema($this->object);
    }

    public function getObject(): string
    {
        return $this->object;
    }

    public function setObject(?string $object): static
    {
        $this->object = $object;

        return $this;
    }
}
