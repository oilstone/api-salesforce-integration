<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;

class ClearCache extends Command
{
    protected $signature = 'salesforce:cache:clear {resource} {id?} {--field=Id}';

    protected $description = 'Clear Salesforce cache entries';

    public function handle(): int
    {
        /** @var QueryCacheHandler $handler */
        $handler = app(QueryCacheHandler::class);

        $resource = (string) $this->argument('resource');
        $id = $this->argument('id');
        $field = (string) $this->option('field');

        $handler->flushQueryCache();

        if ($id) {
            $handler->forgetEntryByConditions($resource, [$field => $id]);
            $this->info(sprintf(
                'Cleared query cache and entry cache for %s where %s = %s.',
                $resource,
                $field,
                $id
            ));

            return 0;
        }

        $this->info(sprintf('Cleared query cache for %s queries.', $resource));

        return 0;
    }
}
