# API Salesforce Integration

A lightweight integration for interacting with Salesforce from PHP. The package supplies a stand‑alone client and query builder while also providing adapters for the [garethhudson07/api](https://github.com/garethhudson07/api) framework.

## Features

- **Salesforce HTTP client** built on Guzzle with convenience helpers for common endpoints.
- **Fluent SOQL query builder** supporting nested conditions, `IN` clauses, ordering, limits and relationship includes. `LIKE` values keep any `%` wildcard you supply and fall back to a contains match when none is given; `null`, booleans and `DateTimeInterface` values are written as proper SOQL literals, and `Query::raw()` wraps date functions such as `LAST_N_DAYS:7`.
- **Repository layer** exposing `find`, `first`, `get`, `create`, `update`, `upsert`, `upsertByIdentifier` and `delete` methods for Salesforce objects. Writes re-read the record in the same HTTP round trip via the Composite API, or skip the re-read entirely with `setRefreshAfterWrite(false)`.
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

OAuth access tokens are managed by `SalesforceTokenManager`, which caches the
token under the key `salesforce.access_token`, serialises concurrent token
fetches with a cache lock to avoid thundering-herd requests, and transparently
refreshes the token if any Salesforce call returns HTTP 401. Salesforce's client
credentials flow does not return an `expires_in` value, so the cache TTL falls
back to a configurable default (55 minutes, minus a 60 second safety margin);
pass a different `defaultExpiresIn` to the manager if your org's session timeout
is shorter. This works out of the box with the Redis cache store.

When authenticating with the client credentials grant you must supply at least one OAuth scope. Set the `SALESFORCE_SCOPES` environment variable to a comma separated list (or define the `scopes` array in the published configuration) and the service provider will include them in the token request.

The package divides caching into three independent layers via
`QueryCacheHandler`:

* **Query cache** entries persist the results of SOQL queries for a short TTL.
  The cache key combines the SOQL string with a global namespace token and one
  token per Salesforce object the query reads (the root object plus any child
  objects pulled in through `with` includes). Repository mutations (`create`,
  `update`, `upsert`, `upsertByIdentifier`, `delete`) rotate only the mutated
  object's token, so writing a Contact leaves cached Account queries intact
  unless they include Contacts. `flushQueryCacheForObject` does the same by
  hand and `flushQueryCache` rotates the global token to invalidate everything.
  Queries that filter or sort on cross-object fields (relationship paths,
  formula or roll-up fields) are not invalidated by writes to the other object
  and rely on the TTL.
* **Entry cache** entries store individual records returned from `find` /
  `first` lookups for a longer TTL. A lookup is stored as a small pointer keyed
  by its conditions, selected fields and includes, which references the record
  keyed by identifier value. Both keys embed version tokens for the "indexable
  fields" involved (the record's `Id` by default, plus any extra unique columns
  you register via `setIndexableFields`). Mutations rotate the tokens for the
  old and new values of every indexable field, which orphans every pointer and
  record that embedded them, so a record cached by `Email` remains in sync
  after an update keyed by `Id`. Invalidation is a single write with no
  read-modify-write step, so concurrent lookups cannot lose track of an entry.
  A lookup that selected only `Id` is never served to a caller expecting the
  full record. Negative results (`null` for "not found") are deliberately not
  cached, so a subsequent `create` is never fooled by a stale miss.
* **Schema cache** entries store object describe payloads and picklist value
  responses, which rarely change. Schema entries live in their own namespace
  and are **not** affected by `flushQueryCache`, so the (expensive) describe
  call is preserved across data mutations. Use `flushSchemaCache` or the
  `--schema` flag on the Artisan command to invalidate them after a Salesforce
  metadata change.

### Configuring entry cache invalidation

By default the entry cache is keyed by the repository's identifier (`Id`, or
whatever you set with `setIdentifier`). If you also look records up by other
unique fields (an external ID, an email column, etc.), register them so
mutations can invalidate every cached copy:

```php
$repository = (new Repository('Contact'))
    ->setIdentifier('Id')
    ->setIndexableFields(['Id', 'Email', 'External_Id__c']);
```

Only register fields that uniquely identify a record. Non-unique fields (e.g.
`Status`) are not appropriate as indexable fields. When a repository has
indexable fields beyond its identifier, mutations need the record's current
values so entries keyed under the old value of a changed field are invalidated
as well as the new one. That read comes from the entry cache when the record is
there; otherwise it is sent as the first subrequest of the same composite call
as the write (selecting only the indexable fields), so it never costs a
separate HTTP request.

Invalidation tokens are memoised per process so each request reads them at
most once. The Laravel service provider resets that memo at queue job and
Octane request boundaries; in other long-running processes call
`QueryCacheHandler::resetLocalState()` at your own boundaries.

### Clearing caches manually

```bash
php artisan salesforce:cache:clear                                          # Flushes query cache entries for every object
php artisan salesforce:cache:clear Account                                  # Flushes query cache entries that read Account
php artisan salesforce:cache:clear Account 001XXXXXXXXXXXXXXX               # Also forgets the entry cache for that Id
php artisan salesforce:cache:clear Account 001XXXXXXXXXXXXXXX --field=External_Id__c
php artisan salesforce:cache:clear --schema                                 # Flushes only the schema cache
```

### Configuration

Default TTLs and behaviour can be configured via environment variables (or the
corresponding keys in the published `salesforce.php` configuration file):

| Variable | Default | Purpose |
| --- | --- | --- |
| `SALESFORCE_QUERY_CACHE_DEFAULT_TTL` | `3600` | TTL (seconds) for cached SOQL query results. |
| `SALESFORCE_ENTRY_CACHE_DEFAULT_TTL` | `86400` | TTL (seconds) for cached individual records. |
| `SALESFORCE_SCHEMA_CACHE_DEFAULT_TTL` | `86400` | TTL (seconds) for cached describe / picklist payloads. |
| `SALESFORCE_SKIP_RETRIEVAL_DEFAULT` | `false` | When `true`, every cached lookup bypasses the cache on the way in (cache is still populated). Useful for long-running queue workers that need fresh reads. |

You can also opt out of the cache on a single call by passing
`'skip_retrieval' => true` in the repository options array.

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

## Writes and the follow-up read

`create`, `update`, `upsert` and `upsertByIdentifier` return the record as
Salesforce now holds it, including server-computed fields. Rather than issuing a
second HTTP request for that read, the write and the read are sent together as
one [Composite API](https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/resources_composite.htm)
request, which counts as a single API call against your limits. The re-read uses
the repository's default constraints and includes and selects `FIELDS(ALL)`
unless you narrow it:

```php
$repository->update($id, ['Name' => 'Acme'], ['select' => ['Id', 'Name', 'LastModifiedDate']]);
```

The re-read selects the repository's default fields (see below), falling back
to `FIELDS(ALL)`. When you do not need the stored record back, skip the read
altogether. The methods then return the payload merged with the identifier:

```php
$repository->create($attributes, ['refresh' => false]);

// or for every write on this repository
$repository->setRefreshAfterWrite(false);
```

## Selecting fields on single-record reads

`find`, `first`, `firstOrCreate` and the re-read after a write select
`FIELDS(ALL)` when nothing narrower is known. That returns every column,
including long text areas, and the query cache stores all of it. Give the
repository a default select so those reads only fetch what you use:

```php
$repository = (new Repository('Account'))
    ->setClient($salesforce)
    ->setDefaultSelect(['Id', 'Name', 'Industry']);

$repository->find($id);                                   // SELECT Id, Name, Industry ...
$repository->find($id, ['select' => ['Id', 'Website']]);  // an explicit select still wins
```

Repositories created through the `garethhudson07/api` adapter set this to the
schema's field list automatically, so API-driven reads and write re-reads never
use `FIELDS(ALL)`.

In a nested route such as `/accounts/{id}/contacts` the framework resolves the
account only to scope the contact query, so the adapter selects just `Id`, the
identifier and the relation's local key for that ancestor lookup. If your
application reads other attributes from an ancestor pipe's result (for example
in a CRUD event listener), register them on the resource's repository:

```php
$repository->setAncestorFields(['Name', 'OwnerId']);
```

## Apex REST requests

Some Salesforce integrations expose Apex REST endpoints under
`/services/apexrest`. Use the `apexRest` helper on the client to call these
resources directly:

```php
use GuzzleHttp\Client;
use Oilstone\ApiSalesforceIntegration\Clients\Salesforce;

$http = new Client();
$salesforce = new Salesforce($http, $instanceUrl, $accessToken);

$paymentMethods = $salesforce->apexRest('GET', 'cpm/v2/PaymentMethods');
```

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

## Resource-level transform callbacks

For cross-field shaping that should happen once around the whole schema
transformation, register callbacks on the resource itself:

```php
class AccountResource extends \Oilstone\ApiSalesforceIntegration\Integrations\ApiResourceLoader\Resource
{
    public function __construct()
    {
        parent::__construct();

        $this->beforeTransform(function (array $attributes) {
            $attributes['FullName'] = trim(($attributes['FirstName'] ?? '') . ' ' . ($attributes['LastName'] ?? ''));

            return $attributes;
        });

        $this->afterTransform(function (array $attributes) {
            $attributes['display_name'] = strtoupper($attributes['full_name'] ?? '');

            return $attributes;
        });
    }
}
```

`beforeTransform()` runs before schema mapping and receives the raw Salesforce
attributes. `afterTransform()` runs after schema mapping and receives the
transformed attributes. Both callbacks may also accept the current record and
schema as later arguments when needed.

## Resource-level collection transform callbacks

The record callbacks above run once per record. To shape an entire collection
in a single pass — for example to batch-load related data before transformation
or to compute cross-record values afterwards — register collection callbacks on
the resource:

```php
class AccountResource extends \Oilstone\ApiSalesforceIntegration\Integrations\ApiResourceLoader\Resource
{
    public function __construct()
    {
        parent::__construct();

        $this->beforeTransformCollection(function (array $records) {
            // $records is the list of raw result records about to be transformed.
            // Return the (optionally reordered or filtered) set.
            return $records;
        });

        $this->afterTransformCollection(function (array $transformed, array $records) {
            $total = count($transformed);

            foreach ($transformed as &$attributes) {
                $attributes['result_count'] = $total;
            }

            return $transformed;
        });
    }
}
```

`beforeTransformCollection()` receives the raw result records before any record
is transformed, letting you preload lookups, reorder or filter the set. Each
record is then passed through the per-record transformer (so the record-level
callbacks and schema mapping still apply), and `afterTransformCollection()`
receives the full list of transformed attribute arrays alongside the raw records.
Both callbacks may also accept the schema as a later argument.

These callbacks apply to collections fetched through the API `index` endpoint as
well as programmatic reads via `Repository::getRecords()`. When reshaping a
collection for an API read, prefer `beforeTransformCollection()` for structural
changes (reordering, filtering) so relationship includes stay aligned with their
source records; `afterTransformCollection()` is best suited to enriching the
transformed rows in place.

## Resource-level cache toggle

Salesforce resource classes cache query results by default. To disable caching
for a single resource, set `cacheEnabled` to `false` (or call
`setCacheEnabled(false)`):

```php
class LiveAccountResource extends \Oilstone\ApiSalesforceIntegration\Integrations\ApiResourceLoader\Resource
{
    protected bool $cacheEnabled = false;
}
```

This applies only to repositories created through that resource; other
resources continue using cache by default.

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
