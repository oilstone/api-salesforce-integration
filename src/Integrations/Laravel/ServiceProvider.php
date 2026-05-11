<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Laravel;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Oilstone\ApiSalesforceIntegration\Auth\TokenManager;
use Oilstone\ApiSalesforceIntegration\Cache\QueryCacheHandler;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\ApiSalesforceIntegration\Integrations\Laravel\Auth\SalesforceTokenManager;
use Oilstone\ApiSalesforceIntegration\Integrations\Laravel\Console\ClearCache;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/salesforce.php', 'salesforce');

        $config = config('salesforce');

        $this->app->singleton(QueryCacheHandler::class, function ($app) use ($config) {
            $cache = $app->make('cache.store');
            $logger = ! empty($config['debug']) ? Log::channel() : null;

            return (new QueryCacheHandler(
                $cache,
                $config['query_cache_default_ttl'],
                $config['entry_cache_default_ttl'],
                $logger,
                $config['schema_cache_default_ttl'],
            ))->skipRetrievalByDefault((bool) ($config['skip_retrieval_default'] ?? false));
        });

        $this->app->singleton(TokenManager::class, function ($app) use ($config) {
            return new SalesforceTokenManager(
                new Client,
                $app->make('cache.store'),
                $config['instance_url'],
                $config['client_id'],
                $config['client_secret'],
                $config['scopes'] ?? null,
            );
        });

        $this->app->singleton(Salesforce::class, function ($app) use ($config) {
            $client = new Client;
            $tokenManager = $app->make(TokenManager::class);
            $queryCacheHandler = $app->make(QueryCacheHandler::class);
            $logger = ! empty($config['debug']) ? Log::channel() : null;

            return new Salesforce(
                $client,
                $config['instance_url'],
                $tokenManager,
                $config['instance_version'],
                $logger,
                $queryCacheHandler,
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/salesforce.php' => config_path('salesforce.php'),
        ], 'config');

        $this->commands([
            ClearCache::class,
        ]);
    }
}
