<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\TaggableStore;

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

        $tags = [$resource];
        if ($id) {
            $tags[] = $resource . ':' . $id;
        }

        Cache::tags($tags)->flush();

        $this->info('Cache cleared for: ' . implode(', ', $tags));

        return 0;
    }
}
