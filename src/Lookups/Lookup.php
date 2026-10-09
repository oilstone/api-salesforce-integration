<?php

namespace Oilstone\ApiSalesforceIntegration\Lookups;

abstract class Lookup
{
    protected const MULTI_SELECT_DELIMITER = ';';

    /**
     * Holds the result of all() for the lifetime of the process. Lookups that manage
     * their own cache expiry (e.g. a TTL in a cache store) should set this to false,
     * otherwise long-running processes such as queue workers will never see changes.
     */
    protected static bool $memoise = true;

    private static array $resolved = [];

    abstract public static function all(): array;

    public static function items(): array
    {
        if (! static::$memoise) {
            return static::all();
        }

        return self::$resolved[static::class] ??= static::all();
    }

    public static function flush(): void
    {
        unset(self::$resolved[static::class]);
    }

    public static function get(int|string|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $default;
        }

        return static::items()[$key] ?? $default;
    }

    public static function has(int|string|null $key): bool
    {
        return $key !== null && array_key_exists($key, static::items());
    }

    public static function hasAll(array|string|null $keys): bool
    {
        $keys = static::normaliseKeys($keys);

        return $keys !== [] && count(static::only($keys)) === count($keys);
    }

    public static function keys(): array
    {
        return array_keys(static::items());
    }

    public static function labels(): array
    {
        return array_values(static::items());
    }

    public static function count(): int
    {
        return count(static::items());
    }

    public static function keyFor(string $label, bool $caseSensitive = false): int|string|null
    {
        $needle = $caseSensitive ? $label : mb_strtolower($label);

        foreach (static::items() as $key => $value) {
            $haystack = $caseSensitive ? (string) $value : mb_strtolower((string) $value);

            if ($haystack === $needle) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Accepts an array of keys or a Salesforce multi-select picklist string ("A;B;C").
     * Unknown keys are dropped and the order of the given keys is preserved.
     */
    public static function only(array|string|null $keys): array
    {
        $items = static::items();
        $result = [];

        foreach (static::normaliseKeys($keys) as $key) {
            if (array_key_exists($key, $items)) {
                $result[$key] = $items[$key];
            }
        }

        return $result;
    }

    public static function except(array|string|null $keys): array
    {
        return array_diff_key(static::items(), array_flip(static::normaliseKeys($keys)));
    }

    public static function labelsFor(array|string|null $keys): array
    {
        return array_values(static::only($keys));
    }

    /**
     * The callback receives ($label, $key).
     */
    public static function filter(callable $callback): array
    {
        return array_filter(static::items(), $callback, ARRAY_FILTER_USE_BOTH);
    }

    public static function search(string $term): array
    {
        $term = mb_strtolower(trim($term));

        if ($term === '') {
            return static::items();
        }

        return static::filter(fn ($label) => str_contains(mb_strtolower((string) $label), $term));
    }

    public static function sorted(bool $descending = false): array
    {
        $items = static::items();

        if ($descending) {
            arsort($items, SORT_NATURAL | SORT_FLAG_CASE);
        } else {
            asort($items, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $items;
    }

    /**
     * Keys are cast to strings as PHP converts numeric string keys to integers,
     * whereas Salesforce always treats picklist values and Ids as strings.
     */
    public static function options(bool $sorted = false): array
    {
        $items = $sorted ? static::sorted() : static::items();

        return array_map(
            fn ($key, $label) => ['value' => (string) $key, 'label' => $label],
            array_keys($items),
            $items
        );
    }

    public static function toMultiSelect(array|string|null $keys): string
    {
        return implode(static::MULTI_SELECT_DELIMITER, array_keys(static::only($keys)));
    }

    protected static function normaliseKeys(array|string|null $keys): array
    {
        if ($keys === null || $keys === '') {
            return [];
        }

        if (is_string($keys)) {
            $keys = explode(static::MULTI_SELECT_DELIMITER, $keys);
        }

        $keys = array_map(fn ($key) => is_string($key) ? trim($key) : $key, $keys);

        return array_values(array_unique(array_filter($keys, fn ($key) => $key !== null && $key !== '')));
    }
}
