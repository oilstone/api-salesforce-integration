<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Api\Schema;

use Api\Schema\Schema as BaseSchema;

class Schema extends BaseSchema
{
    public function __construct(
        protected ?string $contentType = null,
    ) {
        parent::__construct();
    }

    public function contentType(?string $contentType): static
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }
}
