<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Laravel\Console;

use Illuminate\Console\Command;
use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;

class ClearCache extends Command
{
    protected $signature = 'salesforce:cache:clear {resource?} {id?} {--field=Id} {--schema}';

    protected $description = 'Clear Salesforce cache entries';

    public function handle(): int
    {
        /** @var QueryCacheHandler $handler */
        $handler = app(QueryCacheHandler::class);

        if ($this->option('schema')) {
            $handler->flushSchemaCache();
            $this->info('Cleared Salesforce schema cache (object describes and picklist values).');

            return 0;
        }

        $resource = $this->argument('resource');
        $id = $this->argument('id');
        $field = (string) $this->option('field');

        if (! $resource) {
            $handler->flushQueryCache();
            $this->info('Cleared Salesforce query cache for all objects.');

            return 0;
        }

        $handler->flushQueryCacheForObject((string) $resource);

        if ($id) {
            $handler->forgetEntryByConditions((string) $resource, [$field => $id]);
            $this->info(sprintf(
                'Cleared query cache for %1$s and entry cache for %1$s where %2$s = %3$s.',
                $resource,
                $field,
                $id
            ));

            return 0;
        }

        $this->info(sprintf('Cleared Salesforce query cache for %s.', $resource));

        return 0;
    }
}
