# API Salesforce Integration

A lightweight integration for interacting with Salesforce from PHP. The package supplies a stand‑alone client and query builder while also providing adapters for the [garethhudson07/api](https://github.com/garethhudson07/api) framework.

## Features

- **Salesforce HTTP client** built on Guzzle with convenience helpers for common endpoints.
- **Fluent SOQL query builder** supporting nested conditions, `IN` clauses, ordering, limits and relationship includes.
- **Repository layer** exposing `find`, `first`, `get`, `create`, `update` and `delete` methods for Salesforce objects.
- **Integration with garethhudson07/api** through repository and query bridge classes and a data transformer so that resources defined in that package can query Salesforce seamlessly.
- **Laravel support** including a service provider for obtaining and caching OAuth tokens and optional request logging.
- **Lookup utilities** for retrieving and caching pick list values.
- **Adapters for api-resource-loader** allowing resources to be loaded from configuration files.

Although the package was designed to act as a bridge for `garethhudson07/api`, the client, query builder and repository classes can be used independently in any PHP project.

## Installation

```bash
composer require oilstone/api-salesforce-integration
```

### Optional Laravel setup

If your project uses Laravel you can register the service provider and publish the configuration file:

```php
// config/app.php
'providers' => [
    \Oilstone\ApiSalesforceIntegration\Integrations\Laravel\ServiceProvider::class,
],
```

```bash
php artisan vendor:publish --tag=config --provider="Oilstone\\ApiSalesforceIntegration\\Integrations\\Laravel\\ServiceProvider"
```

Configure your Salesforce instance in `config/salesforce.php` and the provider will handle authentication and caching of access tokens. When the `debug` option is enabled each request and response is logged via Laravel's logger.

## Basic usage

### Stand‑alone

```php
use GuzzleHttp\Client;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\ApiSalesforceIntegration\Repository;

$http = new Client();
$salesforce = new Salesforce($http, $instanceUrl, $accessToken);
$accounts = (new Repository('Account'))
    ->setDefaultConstraints([['Type', 'Customer']])
    ->newQuery()
    ->where('Name', 'like', 'Acme%')
    ->get();
```

### With garethhudson07/api

Create a resource repository that extends the provided API adapter and let the framework resolve queries against Salesforce:

```php
use Oilstone\ApiSalesforceIntegration\Integrations\Api\Repository as ApiRepository;

class AccountRepository extends ApiRepository
{
    protected string $object = 'Account';
}
```

The package's query resolver and transformer bridge the API pipeline to Salesforce so existing endpoints defined in `garethhudson07/api` continue to work with Salesforce data.

### Including related records

Use the `with` method when building a query to fetch related records. Pass the
child object name (or relationship name) to include all fields for that
relationship:

```php
$account = (new Repository('Account'))
    ->newQuery()
    ->with('Museum_Facility__c')
    ->first();
```

You can target specific fields on the related object using a colon syntax:

```php
$account = (new Repository('Account'))
    ->newQuery()
    ->with('Contacts:FirstName,LastName')
    ->first();
```

Related data is returned as a simple array of child records without the
Salesforce metadata wrappers.

## Lookups

Extend `Lookup` or `CachedLookup` to pull picklist values from Salesforce:

```php
class IndustryLookup extends CachedLookup
{
    public static function object(): string { return 'Account'; }
    public static function field(): string { return 'Industry'; }
    public static function recordTypeId(): string { return '0123...'; }
}

$industries = IndustryLookup::all();
```

## Exceptions

`SalesforceException` is thrown for non‑successful responses and provides access to the underlying error details via `getErrors()`.

## License

This package is released under the MIT license.
