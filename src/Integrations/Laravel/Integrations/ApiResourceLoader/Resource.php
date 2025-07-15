<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Laravel\Integrations\ApiResourceLoader;

use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiSalesforceIntegration\Integrations\ApiResourceLoader\Resource as BaseResource;

class Resource extends BaseResource
{
    public function __construct()
    {
        $this->cacheHandler = clone app(QueryCacheHandler::class);

        parent::__construct();
    }
}
