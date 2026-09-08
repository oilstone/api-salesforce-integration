<?php

namespace Oilstone\ApiSalesforceIntegration\Exceptions;

/**
 * Raised when an sObject Collections request succeeds at the HTTP level (200) but one or
 * more records in it failed. With allOrNone the whole request has already been rolled back
 * by Salesforce when this is thrown.
 */
class SalesforceCollectionException extends SalesforceException
{
    /**
     * @param  array<int, array>  $errors  per-record errors, each tagged with the index of the record
     *                                     that produced it and, where known, its identifier value
     * @param  array<int, array>  $results  the full results array as Salesforce returned it
     * @param  array<int, mixed>  $failedIdentifiers  identifier value of each failed record, keyed by index
     */
    public function __construct(
        array $errors,
        protected array $results,
        protected array $failedIdentifiers = [],
        int $code = 200,
    ) {
        parent::__construct($errors, $code);

        $this->message = sprintf(
            '%d of %d records failed: %s',
            count($this->getFailedIndexes()),
            count($results),
            $this->cause($errors)['message'] ?? 'unknown error'
        );
    }

    /**
     * The error that caused the failure: with allOrNone every other record only reports the
     * rollback, so the first non-rollback error is the one worth reading.
     */
    protected function cause(array $errors): array
    {
        foreach ($errors as $error) {
            $code = $error['statusCode'] ?? $error['errorCode'] ?? null;

            if ($code !== 'ALL_OR_NONE_OPERATION_ROLLED_BACK') {
                return $error;
            }
        }

        return $errors[0] ?? [];
    }

    /**
     * Build from a SaveResult / UpsertResult / DeleteResult list.
     *
     * @param  array<int, mixed>  $identifiers  identifier value per record index, when known
     */
    public static function fromResults(array $results, array $identifiers = []): static
    {
        $errors = [];
        $failedIdentifiers = [];

        foreach (array_values($results) as $index => $result) {
            if (($result['success'] ?? false) === true) {
                continue;
            }

            if (array_key_exists($index, $identifiers)) {
                $failedIdentifiers[$index] = $identifiers[$index];
            }

            $recordErrors = is_array($result['errors'] ?? null) && $result['errors'] !== []
                ? $result['errors']
                : [['message' => 'Record failed without an error message', 'errorCode' => 'UNKNOWN']];

            foreach ($recordErrors as $error) {
                $tagged = is_array($error) ? $error : ['message' => (string) $error];
                $tagged['index'] = $index;

                if (array_key_exists($index, $identifiers)) {
                    $tagged['identifier'] = $identifiers[$index];
                }

                $errors[] = $tagged;
            }
        }

        return new static($errors, array_values($results), $failedIdentifiers);
    }

    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * @return array<int, mixed>  identifier values keyed by the failed record's index
     */
    public function getFailedIdentifiers(): array
    {
        return $this->failedIdentifiers;
    }

    /**
     * @return array<int, int>
     */
    public function getFailedIndexes(): array
    {
        $indexes = [];

        foreach ($this->results as $index => $result) {
            if (($result['success'] ?? false) !== true) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }
}
