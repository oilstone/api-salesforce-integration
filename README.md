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

The `config/salesforce.php` file allows you to specify your
Salesforce instance details and credentials:

```php
return [
    'instance_url' => env('SALESFORCE_INSTANCE_URL'),
    'instance_version' => env('SALESFORCE_INSTANCE_VERSION', 'v52.0'),
    'client_id' => env('SALESFORCE_CLIENT_ID'),
    'client_secret' => env('SALESFORCE_CLIENT_SECRET'),
    'debug' => env('SALESFORCE_DEBUG', false),
];
```

The service provider retrieves an access token using these values and caches it
for subsequent requests using Laravel's cache facade.

When `SALESFORCE_DEBUG` is enabled the package will log each API request and
its corresponding response using Laravel's logger.

If a request results in an error response a `SalesforceException` will be
thrown containing the decoded error details which can be retrieved using
`getErrors()`.

## Repository constraints

When creating a repository you may specify default constraints that are applied
to every query. Constraints are provided as callbacks that receive the query
builder instance:

```php
$resource->setObject('Account')
    ->addConstraint(fn ($query) => $query->where('Type', 'Customer'))
    ->addConstraint(fn ($query) => $query->where('RecordTypeId', '0123...'));
```

When extending the provided `Resource` class you may override the
`constraints` method to declare default repository constraints:

```php
class University extends Resource
{
    protected ?string $object = 'Account';

    protected function constraints(): array
    {
        return [fn ($query) => $query->where('Type', 'University')];
    }
}
```
