<?php

namespace brandindustry\editrix\jobs;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\queue\BaseJob;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\migrations\m260924_000000_add_search_sessions as SearchSessions;

/**
 * Runs a search in the background instead of inside a single web request -
 * on a large site (hundreds of thousands of entries), scanning everything
 * synchronously risks hitting PHP's execution time limit or starving other
 * requests of PHP-FPM workers. The frontend polls actionSearchStatus() for
 * progress and picks up the results once this finishes.
 */
class SearchJob extends BaseJob
{
    public string $searchId;
    public string $query;
    public ?int $siteId = null;
    public array $siteIds = [];
    public bool $useRegex = false;
    public bool $caseSensitive = true;
    public array $options = [];
    public ?int $userId = null;
    public array $logScope = [];

    public function execute($queue): void
    {
        try {
            $this->markRunning();

            $meta = [];
            $results = Editrix::$plugin->search->search(
                $this->query,
                $this->siteId,
                $this->useRegex,
                $this->caseSensitive,
                array_merge($this->options, ["siteIds" => $this->siteIds]),
                function (int $scanned, int $total) use ($queue) {
                    $this->updateProgress($scanned, $total);
                    if ($total > 0) {
                        $this->setProgress($queue, min($scanned / $total, 1));
                    }
                },
                function (): bool {
                    return $this->isCancelled();
                },
                $meta
            );

            $grouped = Editrix::$plugin->search->groupResultsBySite($results);

            if (!$this->isCancelled() && $this->userId) {
                $logSiteId = $this->siteId ?? Craft::$app
                    ->getSites()
                    ->getCurrentSite()->id;
                Editrix::$plugin->log->createSearchLog(
                    $logSiteId,
                    $this->query,
                    count($results),
                    $this->logScope,
                    $this->useRegex,
                    $this->caseSensitive,
                    (bool) ($this->options["wholeWords"] ?? false),
                    $this->userId
                );
            }

            $this->finalizeRow(
                $this->isCancelled() ? "cancelled" : "completed",
                Json::encode([
                    "totalResults" => count($results),
                    "groupedResults" => $grouped,
                    "truncated" => $meta["truncated"] ?? false,
                    "stopReason" => $meta["stopReason"] ?? null,
                ])
            );
        } catch (\Throwable $e) {
            Craft::error(
                "Editrix: search job {$this->searchId} failed - " .
                    $e->getMessage(),
                __METHOD__
            );
            $this->finalizeRow("error", null, $e->getMessage());
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t("editrix", "Searching for \"{query}\"", [
            "query" => $this->query,
        ]);
    }

    /**
     * The row starts as "queued" so the UI can tell a search that is
     * waiting for a queue runner from one that is actually scanning.
     * A search cancelled while still queued stays cancelled.
     */
    private function markRunning(): void
    {
        Craft::$app
            ->getDb()
            ->createCommand()
            ->update(
                SearchSessions::TABLE_SEARCHES,
                [
                    "status" => "running",
                    "dateUpdated" => Db::prepareDateForDb(new \DateTime()),
                ],
                ["searchId" => $this->searchId, "status" => "queued"]
            )
            ->execute();
    }

    private function isCancelled(): bool
    {
        $status = (new Query())
            ->select(["status"])
            ->from(SearchSessions::TABLE_SEARCHES)
            ->where(["searchId" => $this->searchId])
            ->scalar();

        return $status === "cancelled";
    }

    /**
     * Progress ticks are guarded to only apply while still "running" - once
     * a search is cancelled (or somehow already finalized), a late progress
     * tick from a loop that hasn't noticed yet must not overwrite that.
     */
    private function updateProgress(int $scanned, int $total): void
    {
        Craft::$app
            ->getDb()
            ->createCommand()
            ->update(
                SearchSessions::TABLE_SEARCHES,
                [
                    "scannedCount" => $scanned,
                    "totalCount" => $total,
                    "dateUpdated" => Db::prepareDateForDb(new \DateTime()),
                ],
                ["searchId" => $this->searchId, "status" => "running"]
            )
            ->execute();
    }

    /**
     * The job's own authoritative final state - written unconditionally
     * (not guarded by current status) so partial results scanned before a
     * cancellation still reach the row the frontend is polling.
     */
    private function finalizeRow(
        string $status,
        ?string $resultsJson,
        ?string $errorMessage = null
    ): void {
        Craft::$app
            ->getDb()
            ->createCommand()
            ->update(
                SearchSessions::TABLE_SEARCHES,
                [
                    "status" => $status,
                    "resultsJson" => $resultsJson,
                    "errorMessage" => $errorMessage,
                    "dateUpdated" => Db::prepareDateForDb(new \DateTime()),
                ],
                ["searchId" => $this->searchId]
            )
            ->execute();
    }
}
