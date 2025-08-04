<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Laravel\Console;

use Illuminate\Cache\TaggableStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearCache extends Command
{
    protected $signature = 'salesforce:cache:clear {resource} {id?}';

    protected $description = 'Clear Salesforce query cache by tag';

    public function handle(): int
    {
        $resource = $this->argument('resource');
        $id = $this->argument('id');

        $store = Cache::store();

        if (! method_exists($store, 'tags') || ! ($store->getStore() instanceof TaggableStore)) {
            $this->error('Cache store does not support tagging.');
            return 1;
        }

        if ($id) {
            $tags = [
                $resource . ':' . $id,
                $resource . ':findMany',
            ];
        } else {
            $tags = [
                $resource,
                $resource . ':findMany',
                $resource . ':findOne',
            ];
        }

        Cache::tags($tags)->flush();

        $this->info('Cache cleared for: ' . implode(', ', $tags));

        return 0;
    }
}
