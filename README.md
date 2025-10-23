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

Configure your Salesforce instance in `config/salesforce.php` and the provider will handle authentication and caching of access tokens. When the `debug` option is enabled each request and response is logged via Laravel's logger. Queries served from the cache are also logged with a `cache` flag so they can be distinguished from live requests.

When authenticating with the client credentials grant you must supply at least one OAuth scope. Set the `SALESFORCE_SCOPES` environment variable to a comma separated list (or define the `scopes` array in the published configuration) and the service provider will include them in the token request.

If the cache store supports tagging, query results are tagged by the Salesforce object name. Collection queries receive an additional `<object>:findMany` tag while single-record queries are tagged with `<object>:findOne`. When fetching by ID the record tag `<object>:<id>` is also applied. You can clear cached results using the provided Artisan command:

```bash
php artisan salesforce:cache:clear Account
php artisan salesforce:cache:clear Account 001XXXXXXXXXXXXXXX
```

Cached results for a specific record are automatically cleared when the
repository's `update` or `delete` methods are used with a configured
`QueryCacheHandler`. The record tag `<object>:<id>` and any list caches tagged
with `<object>:findMany` are flushed so collection results stay in sync. Newly
created records trigger only the `<object>:findMany` flush.

Object descriptions fetched via the client are also cached through the same
handler, preventing repetitive calls to Salesforce's `describe` endpoint.

## Basic usage

### Stand‑alone

```php
use GuzzleHttp\Client;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;
use Oilstone\ApiSalesforceIntegration\Repository;

$http = new Client();
$salesforce = new Salesforce($http, $instanceUrl, $accessToken);
$accounts = (new Repository('Account'))
    ->setClient($salesforce)
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
    // Optionally customise the identifier column
    protected string $identifier = 'External_Id__c';
}
```

The package's query resolver and transformer bridge the API pipeline to Salesforce so existing endpoints defined in `garethhudson07/api` continue to work with Salesforce data.

### Including related records

Use the `with` method when building a query to fetch related records. Pass the
child object name (or relationship name) to include the `Id` and `Name` fields
for that relationship by default:

```php
$account = (new Repository('Account'))
    ->setClient($salesforce)
    ->newQuery()
    ->with('Museum_Facility__c')
    ->first();
```

You can target specific fields on the related object using a colon syntax:

```php
$account = (new Repository('Account'))
    ->setClient($salesforce)
    ->newQuery()
    ->with('Contacts:FirstName,LastName')
    ->first();
```

Related data is returned as a simple array of child records without the
Salesforce metadata wrappers.

### Creating a fresh repository

When you need a repository for a different object without inheriting the
current repository's default constraints, includes or schema defaults, use
`freshRepository`:

```php
$accountRepo = (new AccountRepository())->setClient($salesforce);
$facilityRepo = $accountRepo->freshRepository('Museum_Facility__c');
```

The returned repository is clean and can be configured independently.

## Schema meta properties

When a resource is backed by an `Api\Schema\Schema`, meta properties on the
schema fields control how values are fetched from Salesforce, transformed for
API consumers and written back. The integration recognises the following meta
keys:

| Meta key | Behaviour |
| --- | --- |
| `validationOnly` | Excludes the field from every read/write operation so it can still be validated by the API schema without hitting Salesforce. |
| `needs` | Ensures additional Salesforce fields are always selected with the property (accepts a string or array of field names). |
| `calculated` | Marks the property as derived so it is never selected, has no defaults extracted and is ignored when writing back. |
| `isRelation` | Skips the property when building field lists and payloads because the value comes from relationship includes. |
| `readonly` | Omits the property from create/update payloads unless `forceReverse` is used on the transformer. |
| `fixed` | Forces the property to a constant value for reads and writes, and seeds that value when building defaults. |
| `default` | Provides a fallback when no explicit value is supplied and is also surfaced by `Repository::getDefaultValues()`. |
| `beforeTransform` / `afterTransform` | Callables that run before/after a record is transformed for API output, letting you massage inbound values. |
| `beforeReverse` / `afterReverse` | Callables that run before/after values are prepared for Salesforce, giving hooks for last-mile tweaks. |
| `delimited` | Treats the field as a delimited list. Responses are exploded into arrays and outbound arrays are imploded using the delimiter string stored on the property. |
| `isYesNo` | Converts 'Yes'/'No' Salesforce strings to booleans when reading, back to Salesforce-friendly strings when writing, and applies the same mapping to query constraints. |
| `isAddressLine` | Maps a specific numbered line of a multi-line address field when transforming in either direction, rebuilding the combined field on writes. |

These meta keys can be combined to tailor how each schema property interacts
with Salesforce while keeping the resource definition declarative.

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
