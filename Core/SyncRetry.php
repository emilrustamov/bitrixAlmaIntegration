<?php

final class SyncRetry
{
    private const DELAY_SECONDS = [3600, 10800, 21600, 43200];

    public static function delaysSeconds(): array
    {
        return self::DELAY_SECONDS;
    }

    public static function bypassExecutionTimeLimit(): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
    }

    public static function isRetryableThrowable(Throwable $e): bool
    {
        if ($e instanceof InvalidArgumentException) {
            return false;
        }
        $m = $e->getMessage();
        if (stripos($m, 'CURL error') !== false) {
            return true;
        }
        if (stripos($m, 'timed out') !== false) {
            return true;
        }
        if (stripos($m, 'Operation timed out') !== false) {
            return true;
        }
        if (preg_match('/API request failed \(5\d\d\)/', $m)) {
            return true;
        }
        if (stripos($m, 'API request failed (429') !== false) {
            return true;
        }
        if (preg_match('/"code"\s*:\s*5\d\d/', $m)) {
            return true;
        }
        if (preg_match('/"code"\s*:\s*429/', $m)) {
            return true;
        }
        return false;
    }

    public static function isRetryableContractHttpResult($rawResult, ?array $decoded): bool
    {
        if ($rawResult === false) {
            return true;
        }
        if (!is_array($decoded)) {
            return true;
        }
        if (!empty($decoded['success'])) {
            return false;
        }
        $blob = json_encode($decoded);
        if ($blob === false) {
            return true;
        }
        if (preg_match('/forbidden to edit the archive usage|Contract is already archived|No TenantContract matches/', $blob)) {
            return false;
        }
        if (preg_match('/"code"\s*:\s*404\b/', $blob)) {
            return false;
        }
        if (preg_match('/"code"\s*:\s*5\d\d/', $blob)) {
            return true;
        }
        if (preg_match('/API request failed \(5\d\d\)/', $blob)) {
            return true;
        }
        if (preg_match('/"code"\s*:\s*429/', $blob)) {
            return true;
        }
        return false;
    }

    public static function run(callable $fn, ?callable $beforeSleep = null): void
    {
        $delays = self::DELAY_SECONDS;
        $last = null;
        for ($i = 0; $i <= count($delays); $i++) {
            try {
                $fn();
                return;
            } catch (Throwable $e) {
                $last = $e;
                if ($i === count($delays) || !self::isRetryableThrowable($e)) {
                    throw $e;
                }
                self::bypassExecutionTimeLimit();
                if ($beforeSleep !== null) {
                    $beforeSleep($delays[$i], $i + 1);
                }
                sleep($delays[$i]);
            }
        }
        throw $last;
    }
}
