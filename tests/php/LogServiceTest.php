<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use brandindustry\editrix\Editrix;

/**
 * History: a search is logged, listed, exported, marked reverted and
 * deleted. Rows are created with a throwaway query string and removed in
 * tearDown.
 */
class LogServiceTest extends EditrixTestCase
{
    /** @var int[] */
    private array $logIds = [];

    protected function tearDown(): void
    {
        foreach ($this->logIds as $id) {
            Editrix::$plugin->log->deleteLog($id);
        }
        $this->logIds = [];
        parent::tearDown();
    }

    private function userId(): int
    {
        $id = (new Query())->select(['id'])->from('{{%users}}')->scalar();
        if (!$id) {
            $this->markTestSkipped('The Craft project has no users.');
        }
        return (int) $id;
    }

    private function ageLog(int $id, int $days): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(
                '{{%editrix_logs}}',
                ['dateCreated' => Db::prepareDateForDb((new \DateTime())->modify("-{$days} days"))],
                ['id' => $id]
            )
            ->execute();
    }

    private function logSearch(string $query, int $results = 7): int
    {
        $id = Editrix::$plugin->log->createSearchLog(
            $this->siteId(),
            $query,
            $results,
            ['sections' => ['news']],
            false,
            true,
            false,
            $this->userId()
        );
        $this->assertNotNull($id);
        $this->logIds[] = $id;
        return $id;
    }

    public function testASearchIsLoggedWithItsScopeAndResultCount(): void
    {
        $id = $this->logSearch('qa-log-' . random_int(1000, 9999), 7);
        $log = Editrix::$plugin->log->getLogById($id);

        $this->assertSame('search', $log['type']);
        $this->assertSame(7, (int) $log['replacementCount']);
        $this->assertSame(['sections' => ['news']], json_decode($log['scope'], true));
    }

    public function testLoggedSearchesAppearInTheListAndTheCount(): void
    {
        $query = 'qa-list-' . random_int(1000, 9999);
        $before = Editrix::$plugin->log->getLogsCount();
        $id = $this->logSearch($query);

        $this->assertSame($before + 1, Editrix::$plugin->log->getLogsCount());

        $ids = array_map('intval', array_column(Editrix::$plugin->log->getLogs(), 'id'));
        $this->assertContains($id, $ids);
    }

    public function testExportsContainTheLoggedQuery(): void
    {
        $query = 'qa-export-' . random_int(1000, 9999);
        $this->logSearch($query);

        $this->assertStringContainsString($query, Editrix::$plugin->log->exportToCsv());

        $json = json_decode(Editrix::$plugin->log->exportToJson(), true);
        $this->assertIsArray($json);
        $this->assertContains($query, array_column($json, 'searchQuery'));
    }

    public function testDailyCountsCoverTheRequestedWindow(): void
    {
        $this->logSearch('qa-daily-' . random_int(1000, 9999));
        $counts = Editrix::$plugin->log->getDailyCounts(14);

        $this->assertIsArray($counts);
        $this->assertNotEmpty($counts);
    }

    public function testDeletingALogRemovesIt(): void
    {
        $id = $this->logSearch('qa-delete-' . random_int(1000, 9999));

        $this->assertTrue(Editrix::$plugin->log->deleteLog($id));
        $this->assertNull(Editrix::$plugin->log->getLogById($id));
    }

    public function testMarkingALogRevertedUpdatesItsStatus(): void
    {
        $id = $this->logSearch('qa-revert-' . random_int(1000, 9999));

        $this->assertTrue(Editrix::$plugin->log->markAsReverted($id));
        $this->assertSame('reverted', Editrix::$plugin->log->getLogById($id)['status']);
    }

    /**
     * The window is ten years so the cleanup cannot touch a real log of the
     * project that runs the tests.
     */
    public function testCleanupUsesTheConfiguredRetentionAndNotTheEditionCap(): void
    {
        $settings = Editrix::$plugin->getSettings();
        $original = $settings->logRetentionDays;
        $settings->logRetentionDays = 3650;

        try {
            $this->assertGreaterThan(
                Editrix::$plugin->license->getLimit('logRetentionDays'),
                $settings->getEffectiveLogRetention()
            );

            $recent = $this->logSearch('qa-retain-' . random_int(1000, 9999));
            $beyondEditionCap = $this->logSearch('qa-cap-' . random_int(1000, 9999));
            $expired = $this->logSearch('qa-expired-' . random_int(1000, 9999));
            $this->ageLog($beyondEditionCap, 400);
            $this->ageLog($expired, 4000);

            Editrix::$plugin->log->cleanupOldLogs();

            $this->assertNotNull(Editrix::$plugin->log->getLogById($recent));
            $this->assertNotNull(
                Editrix::$plugin->log->getLogById($beyondEditionCap),
                'A log older than the edition cap must stay while the setting allows it.'
            );
            $this->assertNull(Editrix::$plugin->log->getLogById($expired));
        } finally {
            $settings->logRetentionDays = $original;
        }
    }

    public function testCleanupRunsWithCraftsGarbageCollection(): void
    {
        $this->assertTrue(
            \yii\base\Event::hasHandlers(\craft\services\Gc::class, \craft\services\Gc::EVENT_RUN)
        );
    }
}
