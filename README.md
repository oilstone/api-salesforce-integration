# api-salesforce-integration
A Salesforce integration package for garethhudson07/api

## Laravel Integration

The package ships with an optional Laravel service provider that handles
authentication and caching of access tokens. Register the provider and publish
the configuration file:

```php
// config/app.php
'providers' => [
    Oilstone\ApiSalesforceIntegration\Integrations\Laravel\ServiceProvider::class,
],
```

```bash
php artisan vendor:publish --tag=config --provider="Oilstone\\ApiSalesforceIntegration\\Integrations\\Laravel\\ServiceProvider"
```

The `config/salesforce-integration.php` file allows you to specify your
Salesforce instance details and credentials:

```php
return [
    'instance_url' => env('SALESFORCE_INSTANCE_URL'),
    'instance_version' => env('SALESFORCE_INSTANCE_VERSION', 'v52.0'),
    'client_id' => env('SALESFORCE_CLIENT_ID'),
    'client_secret' => env('SALESFORCE_CLIENT_SECRET'),
];
```

The service provider retrieves an access token using these values and caches it
for subsequent requests using Laravel's cache facade.
