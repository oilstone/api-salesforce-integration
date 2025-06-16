<?php

namespace Oilstone\ApiSalesforceIntegration\Integrations\Laravel;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/salesforce.php', 'salesforce');

        $this->app->singleton(Salesforce::class, function () {
            $config = config('salesforce');

            $client = new Client();

            $token = Cache::remember('salesforce.access_token', 55 * 60, function () use ($client, $config) {
                $response = $client->post($config['instance_url'].'/services/oauth2/token', [
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $config['client_id'],
                        'client_secret' => $config['client_secret'],
                    ],
                ]);

                $data = json_decode((string) $response->getBody(), true);

                return $data['access_token'] ?? null;
            });

            return new Salesforce($client, $config['instance_url'], $token, $config['instance_version']);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/salesforce.php' => config_path('salesforce.php'),
        ], 'config');
    }
}
