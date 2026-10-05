<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use brandindustry\editrix\jobs\SearchJob;
use PHPUnit\Framework\TestCase;

abstract class EditrixTestCase extends TestCase
{
    protected const TABLE = '{{%editrix_searches}}';

    /** @var string[] */
    protected array $searchIds = [];

    protected function tearDown(): void
    {
        foreach ($this->searchIds as $searchId) {
            Craft::$app->getDb()->createCommand()
                ->delete(self::TABLE, ['searchId' => $searchId])
                ->execute();
        }
        $this->searchIds = [];
    }

    protected function siteId(): int
    {
        return Craft::$app->getSites()->getPrimarySite()->id;
    }

    protected function createSearchRow(string $status = 'queued'): string
    {
        $userId = (new Query())
            ->select(['id'])
            ->from('{{%users}}')
            ->orderBy(['admin' => SORT_DESC, 'id' => SORT_ASC])
            ->scalar();

        if (!$userId) {
            $this->markTestSkipped('The Craft project has no users to own a search row.');
        }

        $searchId = StringHelper::UUID();
        $now = Db::prepareDateForDb(new \DateTime());

        Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
            'searchId' => $searchId,
            'userId' => (int) $userId,
            'status' => $status,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $this->searchIds[] = $searchId;

        return $searchId;
    }

    protected function row(string $searchId): array
    {
        return (new Query())->from(self::TABLE)->where(['searchId' => $searchId])->one();
    }

    protected function setStatus(string $searchId, string $status): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(self::TABLE, ['status' => $status], ['searchId' => $searchId])
            ->execute();
    }

    /**
     * Options for an entries-only search limited to one section, so a test
     * on a large site scans a bounded number of entries.
     */
    protected function sectionOptions(string $handle, array $extra = []): array
    {
        return array_merge([
            'searchEntries' => true,
            'searchGlobals' => false,
            'searchMatrix' => false,
            'searchCategories' => false,
            'sections' => [$handle],
        ], $extra);
    }

    /**
     * The section with the fewest entries that still has at least $min,
     * as [handle, entryCount], or null if the site has no such section.
     */
    protected function smallestSection(int $min): ?array
    {
        $best = null;

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $count = (int) Entry::find()
                ->siteId($this->siteId())
                ->section($section->handle)
                ->status(null)
                ->drafts(false)
                ->revisions(false)
                ->count();

            if ($count >= $min && ($best === null || $count < $best[1])) {
                $best = [$section->handle, $count];
            }
        }

        return $best;
    }

    /**
     * Runs a SearchJob directly instead of through Craft's queue, so the
     * test never executes unrelated jobs waiting in the host site's queue.
     */
    protected function runJob(string $searchId, array $props): array
    {
        $job = new SearchJob(array_merge([
            'searchId' => $searchId,
            'siteId' => $this->siteId(),
            'caseSensitive' => false,
        ], $props));

        $job->execute(Craft::$app->getQueue());

        return $this->row($searchId);
    }
}
