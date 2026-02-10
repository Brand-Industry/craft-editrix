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

        Craft::$app->getDb()->createCommand()->insert(
            Install::TABLE_LOGS,
            [
                'siteId' => $siteId,
                'userId' => $userId,
                'searchQuery' => $searchQuery,
                'replaceWith' => $replaceWith,
                'useRegex' => $useRegex,
                'caseSensitive' => $caseSensitive,
                'wholeWords' => $wholeWords,
                'replacementCount' => count($replacements),
                'replacements' => json_encode($replacements),
                'status' => 'success',
                'dateCreated' => Db::prepareDateForDb($now),
                'dateUpdated' => Db::prepareDateForDb($now),
                'uid' => Craft::$app->getSecurity()->generateRandomString(),
            ]
        )->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * Get log by ID
     */
    public function getLogById(int $id): ?array
    {
        $row = (new Query())
            ->select('*')
            ->from(Install::TABLE_LOGS)
            ->where(['id' => $id])
            ->one();

        return $row ?: null;
    }

    /**
     * Get logs with filters
     */
    public function getLogs(array $filters = []): array
    {
        $query = (new Query())
            ->select([
                'l.*',
                'u.username',
                'u.firstName',
                'u.lastName',
                's.name as siteName',
            ])
            ->from(['l' => Install::TABLE_LOGS])
            ->leftJoin(['u' => '{{%users}}'], '[[u.id]] = [[l.userId]]')
            ->leftJoin(['s' => '{{%sites}}'], '[[s.id]] = [[l.siteId]]');

        // Site filter
        if (!empty($filters['siteId'])) {
            $query->andWhere(['l.siteId' => $filters['siteId']]);
        }

        // User filter
        if (!empty($filters['userId'])) {
            $query->andWhere(['l.userId' => $filters['userId']]);
        }

        // Status filter
        if (!empty($filters['status'])) {
            $query->andWhere(['l.status' => $filters['status']]);
        }

        // Date range filter
        if (!empty($filters['dateFrom'])) {
            $query->andWhere(['>=', 'l.dateCreated', Db::prepareDateForDb($filters['dateFrom'])]);
        }
        if (!empty($filters['dateTo'])) {
            $query->andWhere(['<=', 'l.dateCreated', Db::prepareDateForDb($filters['dateTo'])]);
        }

        // Pagination
        $limit = $filters['limit'] ?? 20;
        $offset = $filters['offset'] ?? 0;

        $query->orderBy(['l.dateCreated' => SORT_DESC])
            ->limit($limit)
            ->offset($offset);

        return $query->all();
    }

    /**
     * Get logs count with filters
     */
    public function getLogsCount(array $filters = []): int
    {
        $query = (new Query())
            ->from(['l' => Install::TABLE_LOGS]);

        if (!empty($filters['siteId'])) {
            $query->andWhere(['l.siteId' => $filters['siteId']]);
        }

        if (!empty($filters['userId'])) {
            $query->andWhere(['l.userId' => $filters['userId']]);
        }

        if (!empty($filters['status'])) {
            $query->andWhere(['l.status' => $filters['status']]);
        }

        return (int)$query->count();
    }

    /**
     * Mark log as reverted
     */
    public function markAsReverted(int $logId): bool
    {
        $userId = Craft::$app->getUser()->getId();

        $affected = Craft::$app->getDb()->createCommand()->update(
            Install::TABLE_LOGS,
            [
                'status' => 'reverted',
                'revertedAt' => Db::prepareDateForDb(new DateTime()),
                'revertedBy' => $userId,
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            ],
            ['id' => $logId]
        )->execute();

        return $affected > 0;
    }

    /**
     * Delete log
     */
    public function deleteLog(int $logId): bool
    {
        $affected = Craft::$app->getDb()->createCommand()->delete(
            Install::TABLE_LOGS,
            ['id' => $logId]
        )->execute();

        return $affected > 0;
    }

    /**
     * Cleanup old logs based on retention setting
     */
    public function cleanupOldLogs(): int
    {
        $settings = Editrix::$plugin->getSettings();
        $retentionDays = $settings->getEffectiveLogRetention();

        $cutoffDate = (new DateTime())->modify("-{$retentionDays} days");

        $affected = Craft::$app->getDb()->createCommand()->delete(
            Install::TABLE_LOGS,
            ['<', 'dateCreated', Db::prepareDateForDb($cutoffDate)]
        )->execute();

        return $affected;
    }

    /**
     * Export logs to CSV
     */
    public function exportToCsv(array $logs): string
    {
        $output = fopen('php://temp', 'r+');

        // Header
        fputcsv($output, [
            'ID',
            'Date',
            'User',
            'Search Query',
            'Replace With',
            'Count',
            'Status',
        ]);

        foreach ($logs as $log) {
            $user = Craft::$app->getUsers()->getUserById($log['userId']);
            fputcsv($output, [
                $log['id'],
                $log['dateCreated'],
                $user ? $user->email : 'Unknown',
                $log['searchQuery'],
                $log['replaceWith'],
                $log['replacementCount'],
                $log['status'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Export logs to JSON
     */
    public function exportToJson(array $logs): string
    {
        $data = [];

        foreach ($logs as $log) {
            $user = Craft::$app->getUsers()->getUserById($log['userId']);
            $data[] = [
                'id' => $log['id'],
                'date' => $log['dateCreated'],
                'user' => $user ? $user->email : 'Unknown',
                'searchQuery' => $log['searchQuery'],
                'replaceWith' => $log['replaceWith'],
                'count' => $log['replacementCount'],
                'status' => $log['status'],
                'replacements' => json_decode($log['replacements'], true),
            ];
        }

        return json_encode($data, JSON_PRETTY_PRINT);
    }
}

        $cutoffDate = (new DateTime())->modify("-{$retentionDays} days");

        $affected = Craft::$app->getDb()->createCommand()->delete(
            Install::TABLE_LOGS,
            ['<', 'dateCreated', Db::prepareDateForDb($cutoffDate)]
        )->execute();

        if ($affected > 0) {
            Craft::info("Editrix: Cleaned up {$affected} old log entries", __METHOD__);
        }

        return $affected;
    }

    /**
     * Export logs to CSV
     */
    public function exportToCsv(array $filters = []): string
    {
        $logs = $this->getLogs(array_merge($filters, ['limit' => 10000]));

        $output = fopen('php://temp', 'r+');

        // Header
        fputcsv($output, [
            'ID',
            'Date',
            'User',
            'Site',
            'Search Query',
            'Replace With',
            'Count',
            'Status',
        ]);

        // Data
        foreach ($logs as $log) {
            fputcsv($output, [
                $log['id'],
                $log['dateCreated'],
                $log['username'] ?? 'Unknown',
                $log['siteName'] ?? 'Unknown',
                $log['searchQuery'],
                $log['replaceWith'],
                $log['replacementCount'],
                $log['status'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Export logs to JSON
     */
    public function exportToJson(array $filters = []): string
    {
        $logs = $this->getLogs(array_merge($filters, ['limit' => 10000]));

        $data = array_map(function ($log) {
            return [
                'id' => $log['id'],
                'date' => $log['dateCreated'],
                'user' => $log['username'] ?? 'Unknown',
                'site' => $log['siteName'] ?? 'Unknown',
                'searchQuery' => $log['searchQuery'],
                'replaceWith' => $log['replaceWith'],
                'count' => (int)$log['replacementCount'],
                'status' => $log['status'],
                'replacements' => json_decode($log['replacements'], true),
            ];
        }, $logs);

        return json_encode($data, JSON_PRETTY_PRINT);
    }
}
