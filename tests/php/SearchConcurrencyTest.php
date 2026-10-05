<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use brandindustry\editrix\controllers\SearchController;

/**
 * Which searches count against the one-per-user and server-wide limits.
 * The start action itself needs a control panel session, so this checks
 * the query it relies on.
 */
class SearchConcurrencyTest extends EditrixTestCase
{
    /** @return string[] ids of my test rows that count as active */
    private function active(): array
    {
        $controller = new SearchController('search', Craft::$app->getModule('editrix'));
        $method = new \ReflectionMethod($controller, 'activeSearches');
        $method->setAccessible(true);

        /** @var Query $query */
        $query = $method->invoke($controller);

        return array_values(array_intersect(
            $this->searchIds,
            array_column($query->all(), 'searchId')
        ));
    }

    private function silentFor(string $searchId, int $seconds): void
    {
        Craft::$app->getDb()->createCommand()
            ->update(
                self::TABLE,
                ['dateUpdated' => Db::prepareDateForDb((new \DateTime())->modify("-{$seconds} seconds"))],
                ['searchId' => $searchId]
            )
            ->execute();
    }

    public function testQueuedAndRunningSearchesCount(): void
    {
        $queued = $this->createSearchRow('queued');
        $running = $this->createSearchRow('running');

        $this->assertEqualsCanonicalizing([$queued, $running], $this->active());
    }

    public function testFinishedSearchesDoNotCount(): void
    {
        $this->createSearchRow('completed');
        $this->createSearchRow('cancelled');
        $this->createSearchRow('error');

        $this->assertSame([], $this->active());
    }

    public function testASearchWhoseWorkerDiedStopsBlocking(): void
    {
        $dead = $this->createSearchRow('running');
        $alive = $this->createSearchRow('running');
        $this->silentFor($dead, 601);
        $this->silentFor($alive, 30);

        $this->assertSame([$alive], $this->active());
    }
}
