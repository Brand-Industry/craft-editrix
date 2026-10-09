<?php

namespace brandindustry\editrix\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use brandindustry\editrix\migrations\Install;
use brandindustry\editrix\Editrix;

class LogService extends Component
{
    /**
     * Create a log entry
     */
    public function createLog(
        int $siteId,
        string $searchQuery,
        string $replaceWith,
        array $replacements,
        bool $useRegex = false,
        bool $caseSensitive = true,
        bool $wholeWords = false
    ): ?int {
        $userId = Craft::$app->getUser()->getId();

        if (!$userId) {
            return null;
        }

        $now = new DateTime();

        Craft::$app
            ->getDb()
            ->createCommand()
            ->insert(Install::TABLE_LOGS, [
                "siteId" => $siteId,
                "userId" => $userId,
                "type" => "replace",
                "searchQuery" => $searchQuery,
                "replaceWith" => $replaceWith,
                "useRegex" => $useRegex,
                "caseSensitive" => $caseSensitive,
                "wholeWords" => $wholeWords,
                "replacementCount" => count($replacements),
                "replacements" => json_encode($replacements),
                "status" => "success",
                "dateCreated" => Db::prepareDateForDb($now),
                "dateUpdated" => Db::prepareDateForDb($now),
                "uid" => Craft::$app->getSecurity()->generateRandomString(),
            ])
            ->execute();

        return (int) Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * The older way: one log row holding every replacement as JSON, written
     * after the replace. Kept so logs made before items existed stay
     * readable, and so a log can be made for a given user in tests.
     */
    public function createLogForUser(int $userId, int $siteId, string $searchQuery, string $replaceWith, array $replacements): int
    {
        $now = Db::prepareDateForDb(new DateTime());

        Craft::$app->getDb()->createCommand()->insert(Install::TABLE_LOGS, [
            "siteId" => $siteId,
            "userId" => $userId,
            "type" => "replace",
            "searchQuery" => $searchQuery,
            "replaceWith" => $replaceWith,
            "useRegex" => false,
            "caseSensitive" => true,
            "wholeWords" => false,
            "replacementCount" => count($replacements),
            "replacements" => json_encode($replacements),
            "status" => "success",
            "dateCreated" => $now,
            "dateUpdated" => $now,
            "uid" => Craft::$app->getSecurity()->generateRandomString(),
        ])->execute();

        return (int) Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * Opens the log of a replace before anything is changed. Its values are
     * then written one by one with addItem(), each before the entry is
     * saved, so a request that is cut off partway still leaves a log that
     * can be reverted. $userId is for the queue, which has no session.
     */
    public function beginReplaceLog(
        int $siteId,
        string $searchQuery,
        string $replaceWith,
        bool $useRegex = false,
        bool $caseSensitive = true,
        bool $wholeWords = false,
        ?int $userId = null
    ): ?int {
        $userId ??= Craft::$app->getUser()->getId();

        if (!$userId) {
            return null;
        }

        $now = Db::prepareDateForDb(new DateTime());

        Craft::$app
            ->getDb()
            ->createCommand()
            ->insert(Install::TABLE_LOGS, [
                "siteId" => $siteId,
                "userId" => $userId,
                "type" => "replace",
                "searchQuery" => $searchQuery,
                "replaceWith" => $replaceWith,
                "useRegex" => $useRegex,
                "caseSensitive" => $caseSensitive,
                "wholeWords" => $wholeWords,
                "replacementCount" => 0,
                "replacements" => null,
                "status" => "running",
                "dateCreated" => $now,
                "dateUpdated" => $now,
                "uid" => Craft::$app->getSecurity()->generateRandomString(),
            ])
            ->execute();

        return (int) Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * Records one value of a replace. Called with state "pending" just
     * before the entry is saved.
     *
     * @param array $entry elementType, elementId, siteId, fieldHandle,
     *                     fieldName, elementTitle, sectionHandle,
     *                     parentId, oldValue, newValue
     */
    public function addItem(int $logId, array $entry, int $position, string $state = "pending"): int
    {
        $now = Db::prepareDateForDb(new DateTime());

        Craft::$app
            ->getDb()
            ->createCommand()
            ->insert(Install::TABLE_LOG_ITEMS, [
                "logId" => $logId,
                "position" => $position,
                "elementType" => $entry["elementType"],
                "elementId" => $entry["elementId"],
                "siteId" => $entry["siteId"],
                "fieldHandle" => $entry["fieldHandle"],
                "fieldName" => $entry["fieldName"] ?? "",
                "elementTitle" => $entry["elementTitle"] ?? "",
                "sectionHandle" => $entry["sectionHandle"] ?? null,
                "parentId" => $entry["parentId"] ?? null,
                "oldValue" => $entry["oldValue"] ?? null,
                "newValue" => $entry["newValue"] ?? null,
                "matches" => $entry["matches"] ?? null,
                "state" => $state,
                "message" => $entry["message"] ?? null,
                "dateCreated" => $now,
                "dateUpdated" => $now,
                "uid" => Craft::$app->getSecurity()->generateRandomString(),
            ])
            ->execute();

        return (int) Craft::$app->getDb()->getLastInsertID();
    }

    public function setItemState(int $itemId, string $state, ?string $message = null): void
    {
        Craft::$app
            ->getDb()
            ->createCommand()
            ->update(
                Install::TABLE_LOG_ITEMS,
                [
                    "state" => $state,
                    "message" => $message,
                    "dateUpdated" => Db::prepareDateForDb(new DateTime()),
                ],
                ["id" => $itemId]
            )
            ->execute();
    }

    /** How many items a log has in each state. @return array<string, int> */
    public function itemCounts(int $logId): array
    {
        $rows = (new Query())
            ->select(["state", "n" => "COUNT(*)"])
            ->from(Install::TABLE_LOG_ITEMS)
            ->where(["logId" => $logId])
            ->groupBy(["state"])
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row["state"]] = (int) $row["n"];
        }

        return $counts;
    }

    /**
     * Closes a replace's log. The count is what was actually changed.
     */
    public function finishReplaceLog(int $logId, string $status, int $count): void
    {
        Craft::$app
            ->getDb()
            ->createCommand()
            ->update(
                Install::TABLE_LOGS,
                [
                    "status" => $status,
                    "replacementCount" => $count,
                    "dateUpdated" => Db::prepareDateForDb(new DateTime()),
                ],
                ["id" => $logId]
            )
            ->execute();
    }

    /** How many rows a queued replace is working through, kept in the log's scope column. */
    public function setTotal(int $logId, int $total): void
    {
        Craft::$app
            ->getDb()
            ->createCommand()
            ->update(Install::TABLE_LOGS, ["scope" => json_encode(["total" => $total])], ["id" => $logId])
            ->execute();
    }

    public function getTotal(int $logId): int
    {
        $scope = (new Query())
            ->select(["scope"])
            ->from(Install::TABLE_LOGS)
            ->where(["id" => $logId])
            ->scalar();

        return (int) (json_decode((string) $scope, true)["total"] ?? 0);
    }

    /** Updates the running count and the "still alive" timestamp. */
    public function touchReplaceLog(int $logId, int $count): void
    {
        Craft::$app
            ->getDb()
            ->createCommand()
            ->update(
                Install::TABLE_LOGS,
                [
                    "replacementCount" => $count,
                    "dateUpdated" => Db::prepareDateForDb(new DateTime()),
                ],
                ["id" => $logId]
            )
            ->execute();
    }

    /** How many values a revert of this log would still look at. */
    public function revertableCount(array $log): int
    {
        $counts = $this->itemCounts((int) $log["id"]);

        if ($counts === []) {
            return count(json_decode($log["replacements"] ?? "[]", true) ?? []);
        }

        return (int) (($counts["applied"] ?? 0) + ($counts["pending"] ?? 0));
    }

    /**
     * The values a replace changed, in the shape revert and export read:
     * from the items table for logs written value by value, from the JSON
     * column for older logs. A generator, because a large replace holds
     * far more text than should sit in memory at once.
     *
     * Items that were skipped or failed changed nothing and are left out;
     * "pending" ones are included, since the entry may or may not have been
     * saved before the request ended.
     *
     * @return \Generator<array>
     */
    public function replacementsFor(array $log, bool $includeReverted = false): \Generator
    {
        $hasItems = (new Query())
            ->from(Install::TABLE_LOG_ITEMS)
            ->where(["logId" => $log["id"]])
            ->exists();

        if (!$hasItems) {
            foreach (json_decode($log["replacements"] ?? "[]", true) ?? [] as $entry) {
                yield $entry;
            }

            return;
        }

        $states = $includeReverted ? ["pending", "applied", "reverted"] : ["pending", "applied"];
        $after = 0;

        while (true) {
            $rows = (new Query())
                ->from(Install::TABLE_LOG_ITEMS)
                ->where(["logId" => $log["id"], "state" => $states])
                ->andWhere([">", "id", $after])
                ->orderBy(["id" => SORT_ASC])
                ->limit(100)
                ->all();

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $after = (int) $row["id"];

                yield [
                    "itemId" => $after,
                    "state" => $row["state"],
                    "elementType" => $row["elementType"],
                    "elementId" => (int) $row["elementId"],
                    "elementTitle" => $row["elementTitle"],
                    "sectionHandle" => $row["sectionHandle"],
                    "fieldHandle" => $row["fieldHandle"],
                    "fieldName" => $row["fieldName"],
                    "oldValue" => $row["oldValue"],
                    "newValue" => $row["newValue"],
                    "siteId" => (int) $row["siteId"],
                    "parentId" => $row["parentId"] === null ? null : (int) $row["parentId"],
                ];
            }
        }
    }

    /**
     * Log a plain search - no field content is touched, so we only keep
     * enough to show it in History and let the user re-run the same
     * query/scope later, not a snapshot of the results themselves.
     */
    public function createSearchLog(
        int $siteId,
        string $searchQuery,
        int $resultsCount,
        array $scope,
        bool $useRegex = false,
        bool $caseSensitive = true,
        bool $wholeWords = false,
        ?int $userId = null
    ): ?int {
        // A background SearchJob has no web user session to read - it
        // passes the id it captured when the search was started instead.
        $userId ??= Craft::$app->getUser()->getId();

        if (!$userId) {
            return null;
        }

        $now = new DateTime();

        Craft::$app
            ->getDb()
            ->createCommand()
            ->insert(Install::TABLE_LOGS, [
                "siteId" => $siteId,
                "userId" => $userId,
                "type" => "search",
                "searchQuery" => $searchQuery,
                "replaceWith" => "",
                "useRegex" => $useRegex,
                "caseSensitive" => $caseSensitive,
                "wholeWords" => $wholeWords,
                "scope" => json_encode($scope),
                "replacementCount" => $resultsCount,
                "replacements" => null,
                "status" => "success",
                "dateCreated" => Db::prepareDateForDb($now),
                "dateUpdated" => Db::prepareDateForDb($now),
                "uid" => Craft::$app->getSecurity()->generateRandomString(),
            ])
            ->execute();

        return (int) Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * The newest replace of this user that is still running. One silent for
     * longer than $window seconds is a leftover from a dead worker.
     */
    public function activeReplaceFor(int $userId, int $window): ?array
    {
        $row = (new Query())
            ->select("*")
            ->from(Install::TABLE_LOGS)
            ->where(["userId" => $userId, "status" => ["running", "cancelling"]])
            ->andWhere([
                ">=",
                "dateUpdated",
                Db::prepareDateForDb((new DateTime())->modify("-{$window} seconds")),
            ])
            ->orderBy(["id" => SORT_DESC])
            ->one();

        return $row ?: null;
    }

    public function getLogById(int $id): ?array
    {
        $row = (new Query())
            ->select("*")
            ->from(Install::TABLE_LOGS)
            ->where(["id" => $id])
            ->one();

        return $row ?: null;
    }

    public function getLogs(array $filters = []): array
    {
        $query = (new Query())
            ->select([
                "l.*",
                "u.username",
                "u.firstName",
                "u.lastName",
                "s.name as siteName",
            ])
            ->from(["l" => Install::TABLE_LOGS])
            ->leftJoin(["u" => "{{%users}}"], "[[u.id]] = [[l.userId]]")
            ->leftJoin(["s" => "{{%sites}}"], "[[s.id]] = [[l.siteId]]");

        if (!empty($filters["siteId"])) {
            $query->andWhere(["l.siteId" => $filters["siteId"]]);
        }

        if (!empty($filters["userId"])) {
            $query->andWhere(["l.userId" => $filters["userId"]]);
        }

        if (!empty($filters["type"])) {
            $query->andWhere(["l.type" => $filters["type"]]);
        }

        if (!empty($filters["status"])) {
            $query->andWhere(["l.status" => $filters["status"]]);
        }

        if (!empty($filters["dateFrom"])) {
            $query->andWhere([
                ">=",
                "l.dateCreated",
                Db::prepareDateForDb($filters["dateFrom"]),
            ]);
        }
        if (!empty($filters["dateTo"])) {
            $query->andWhere([
                "<=",
                "l.dateCreated",
                Db::prepareDateForDb($filters["dateTo"]),
            ]);
        }

        $limit = $filters["limit"] ?? 20;
        $offset = $filters["offset"] ?? 0;

        $query
            ->orderBy(["l.dateCreated" => SORT_DESC])
            ->limit($limit)
            ->offset($offset);

        return $query->all();
    }

    /**
     * Operation counts per day for the last $days days, split by type - for
     * the activity chart on Logs & History. Buckets are built and summed in
     * PHP (not a DB date-truncation function) so this works the same on
     * MySQL and Postgres. Days with no activity are included as zero, so
     * the chart's x-axis stays continuous.
     */
    public function getDailyCounts(int $days = 14, ?int $siteId = null): array
    {
        $days = max(1, $days);
        $since = (new DateTime())->modify(
            "-" . ($days - 1) . " days"
        );
        $since->setTime(0, 0, 0);

        $query = (new Query())
            ->select(["dateCreated", "type"])
            ->from(Install::TABLE_LOGS)
            ->where([">=", "dateCreated", Db::prepareDateForDb($since)]);

        if ($siteId !== null) {
            $query->andWhere(["siteId" => $siteId]);
        }

        $buckets = [];
        for ($i = 0; $i < $days; $i++) {
            $day = (new DateTime())
                ->modify("-" . ($days - 1 - $i) . " days")
                ->format("Y-m-d");
            $buckets[$day] = [
                "date" => $day,
                "search" => 0,
                "replace" => 0,
            ];
        }

        foreach ($query->all() as $row) {
            $day = (new DateTime($row["dateCreated"]))->format("Y-m-d");
            if (!isset($buckets[$day])) {
                continue;
            }
            $type = ($row["type"] ?? "replace") === "search"
                ? "search"
                : "replace";
            $buckets[$day][$type]++;
        }

        return array_values($buckets);
    }

    public function getLogsCount(array $filters = []): int
    {
        $query = (new Query())->from(["l" => Install::TABLE_LOGS]);

        if (!empty($filters["siteId"])) {
            $query->andWhere(["l.siteId" => $filters["siteId"]]);
        }

        if (!empty($filters["userId"])) {
            $query->andWhere(["l.userId" => $filters["userId"]]);
        }

        if (!empty($filters["type"])) {
            $query->andWhere(["l.type" => $filters["type"]]);
        }

        if (!empty($filters["status"])) {
            $query->andWhere(["l.status" => $filters["status"]]);
        }

        return (int) $query->count();
    }

    public function markAsReverted(int $logId, ?int $userId = null): bool
    {
        // A queued revert has no session, so it passes the user it started with.
        $userId ??= Craft::$app->getUser()->getId();

        $affected = Craft::$app
            ->getDb()
            ->createCommand()
            ->update(
                Install::TABLE_LOGS,
                [
                    "status" => "reverted",
                    "revertedAt" => Db::prepareDateForDb(new DateTime()),
                    "revertedBy" => $userId,
                    "dateUpdated" => Db::prepareDateForDb(new DateTime()),
                ],
                ["id" => $logId]
            )
            ->execute();

        return $affected > 0;
    }

    /**
     * Some values are back and the rest were left alone (edited since).
     */
    public function markAsPartial(int $logId): bool
    {
        return Craft::$app
            ->getDb()
            ->createCommand()
            ->update(
                Install::TABLE_LOGS,
                ["status" => "partial", "dateUpdated" => Db::prepareDateForDb(new DateTime())],
                ["id" => $logId]
            )
            ->execute() > 0;
    }

    public function deleteLog(int $logId): bool
    {
        $affected = Craft::$app
            ->getDb()
            ->createCommand()
            ->delete(Install::TABLE_LOGS, ["id" => $logId])
            ->execute();

        return $affected > 0;
    }

    public function cleanupOldLogs(): int
    {
        $settings = Editrix::$plugin->getSettings();
        $retentionDays = $settings->getEffectiveLogRetention();

        $cutoffDate = (new DateTime())->modify("-{$retentionDays} days");

        $affected = Craft::$app
            ->getDb()
            ->createCommand()
            ->delete(Install::TABLE_LOGS, [
                "<",
                "dateCreated",
                Db::prepareDateForDb($cutoffDate),
            ])
            ->execute();

        return $affected;
    }

    /**
     * A cell that starts with = + - or @ is run as a formula by a
     * spreadsheet, and search and replace text is typed by users.
     */
    private function csvSafe(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'" . $value;
        }

        return $value;
    }

    public function exportToCsv(array $filters = []): string
    {
        $logs = $this->getLogs(array_merge($filters, ["limit" => 10000]));

        $output = fopen("php://temp", "r+");

        fputcsv($output, [
            "ID",
            "Date",
            "Type",
            "User",
            "Site",
            "Search Query",
            "Replace With",
            "Count",
            "Status",
        ]);

        foreach ($logs as $log) {
            fputcsv($output, array_map([$this, "csvSafe"], [
                $log["id"],
                $log["dateCreated"],
                $log["type"] ?? "replace",
                $log["username"] ?? "Unknown",
                $log["siteName"] ?? "Unknown",
                $log["searchQuery"],
                $log["replaceWith"],
                $log["replacementCount"],
                $log["status"],
            ]));
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    public function exportToJson(array $filters = []): string
    {
        $logs = $this->getLogs(array_merge($filters, ["limit" => 10000]));

        $data = array_map(function ($log) {
            return [
                "id" => $log["id"],
                "date" => $log["dateCreated"],
                "type" => $log["type"] ?? "replace",
                "user" => $log["username"] ?? "Unknown",
                "site" => $log["siteName"] ?? "Unknown",
                "searchQuery" => $log["searchQuery"],
                "replaceWith" => $log["replaceWith"],
                "count" => (int) $log["replacementCount"],
                "status" => $log["status"],
                "scope" => json_decode($log["scope"] ?? "", true),
                "replacements" => iterator_to_array($this->replacementsFor($log), false),
            ];
        }, $logs);

        return json_encode($data, JSON_PRETTY_PRINT);
    }
}
