<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\services\Elements;
use yii\base\Event;
use craft\events\ElementEvent;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\jobs\ReplaceJob;
use brandindustry\editrix\migrations\Install;
use brandindustry\editrix\services\ReplaceService;

/**
 * A replace is written to its log value by value, each before its entry is
 * saved, so an interrupted one can still be reverted; and a revert never
 * overwrites what was edited since.
 *
 * Edits real entry titles and puts them back, and leaves "EditrixQA"
 * revisions behind, like ReplaceRevertTest. Keep it to local copies.
 */
class ReplaceLogTest extends EditrixTestCase
{
    private ?int $entryId = null;
    private ?string $originalTitle = null;
    /** @var int[] */
    private array $logIds = [];

    protected function tearDown(): void
    {
        foreach ($this->logIds as $id) {
            Editrix::$plugin->log->deleteLog($id);
        }

        if ($this->entryId !== null) {
            $entry = $this->load();
            if ($entry && $entry->title !== $this->originalTitle) {
                $entry->title = $this->originalTitle;
                Craft::$app->getElements()->saveElement($entry, false);
            }
        }

        parent::tearDown();
    }

    private function load(): ?Entry
    {
        return Entry::find()->id($this->entryId)->siteId($this->siteId())->status(null)->one();
    }

    private function title(): string
    {
        return (string) $this->load()->title;
    }

    private function userId(): int
    {
        return (int) (new Query())->select(['id'])->from('{{%users}}')->orderBy(['admin' => SORT_DESC, 'id' => SORT_ASC])->scalar();
    }

    /** @return array{0: array, 1: string} the title row of a small section's entry, and its word */
    private function pickRow(): array
    {
        $section = $this->smallestSection(1);
        if (!$section) {
            $this->markTestSkipped('The site has no entries.');
        }

        $entries = Entry::find()->siteId($this->siteId())->section($section[0])->status(null)->drafts(false)->revisions(false)->limit(60)->all();

        foreach ($entries as $entry) {
            if (!preg_match('/\b(\p{L}{5,})\b/u', (string) $entry->title, $m)) {
                continue;
            }

            $this->entryId = $entry->id;
            $this->originalTitle = (string) $entry->title;

            $results = Editrix::$plugin->search->search(
                $m[1],
                $this->siteId(),
                false,
                true,
                $this->sectionOptions($section[0], ['fields' => ['title']])
            );

            foreach (Editrix::$plugin->search->groupResultsBySite($results) as $site) {
                foreach ($site['results'] as $row) {
                    if ($row['elementId'] === $entry->id && $row['fieldHandle'] === 'title') {
                        return [$row, $m[1]];
                    }
                }
            }
        }

        $this->markTestSkipped('No entry with a title word could be found by search.');
    }

    private function beginLog(): int
    {
        $id = Editrix::$plugin->log->beginReplaceLog($this->siteId(), 'q', 'r', false, true, false, $this->userId());
        $this->assertNotNull($id);
        $this->logIds[] = $id;

        return $id;
    }

    private function logRow(int $id): array
    {
        return Editrix::$plugin->log->getLogById($id);
    }

    public function testEveryValueIsInTheLogBeforeItsEntryIsSaved(): void
    {
        [$row, $word] = $this->pickRow();
        $logId = $this->beginLog();
        $seen = null;
        $skipped = [];
        $failed = [];

        $handler = function (ElementEvent $event) use (&$seen, $logId) {
            if ($event->element->id === $this->entryId && $seen === null) {
                $seen = (new Query())->from(Install::TABLE_LOG_ITEMS)->where(['logId' => $logId])->all();
            }
        };
        Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, $handler);

        try {
            Editrix::$plugin->replace->replace([$row], $word, 'EditrixQA' . random_int(100, 999), false, true, false, $skipped, $failed, ['logId' => $logId]);
        } finally {
            Event::off(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, $handler);
        }

        $this->assertNotNull($seen, 'The entry should have been saved.');
        $this->assertCount(1, $seen, 'The value must be in the log before the save.');
        $this->assertSame('pending', $seen[0]['state']);
        $this->assertSame($this->originalTitle, $seen[0]['oldValue']);
        $this->assertNotSame($this->originalTitle, $seen[0]['newValue']);
        $this->assertSame('applied', (new Query())->select(['state'])->from(Install::TABLE_LOG_ITEMS)->where(['logId' => $logId])->scalar());
    }

    public function testRunClosesTheLogWithWhatWasChanged(): void
    {
        [$row, $word] = $this->pickRow();
        $logId = $this->beginLog();

        $summary = Editrix::$plugin->replace->run($logId, [$row], $word, 'EditrixQA' . random_int(100, 999), false, true, false);

        $this->assertSame(1, $summary['replacedCount']);
        $this->assertSame($logId, $summary['logId']);
        $this->assertSame('success', $this->logRow($logId)['status']);
        $this->assertSame(1, (int) $this->logRow($logId)['replacementCount']);
        $this->assertSame([ReplaceService::keyFor($row)], $summary['replacedKeys']);
    }

    public function testNothingChangedLeavesNoLog(): void
    {
        [$row, $word] = $this->pickRow();
        $logId = $this->beginLog();

        // Searching for something that is not there changes nothing.
        $summary = Editrix::$plugin->replace->run($logId, [$row], 'zzqnosuchtext', 'x', false, true, false);

        $this->assertSame(0, $summary['replacedCount']);
        $this->assertNull($summary['logId']);
        $this->assertNull(Editrix::$plugin->log->getLogById($logId));
    }

    public function testAQueuedReplaceKeepsItsLogEvenWhenNothingChanged(): void
    {
        [$row] = $this->pickRow();
        $logId = $this->beginLog();

        $summary = Editrix::$plugin->replace->run($logId, [$row], 'zzqnosuchtext', 'x', false, true, false, null, '', null, null, true);

        $this->assertSame($logId, $summary['logId']);
        $this->assertNotNull(Editrix::$plugin->log->getLogById($logId), 'The status endpoint needs it to report the result.');
    }

    public function testAReloadedPageCanFindTheReplaceStillRunning(): void
    {
        $logs = Editrix::$plugin->log;
        $logId = $this->beginLog();

        $this->assertSame($logId, (int) $logs->activeReplaceFor($this->userId(), 600)['id']);
        $this->assertNull($logs->activeReplaceFor($this->userId() + 1000000, 600), 'Another user never sees it.');

        $logs->finishReplaceLog($logId, 'success', 0);
        $this->assertNotSame($logId, (int) ($logs->activeReplaceFor($this->userId(), 600)['id'] ?? 0), 'A finished replace is not running.');

        $logs->finishReplaceLog($logId, 'cancelling', 0);
        $this->assertSame($logId, (int) $logs->activeReplaceFor($this->userId(), 600)['id'], 'One being stopped still is.');

        Craft::$app->getDb()->createCommand()
            ->update(Install::TABLE_LOGS, ['dateUpdated' => gmdate('Y-m-d H:i:s', time() - 1200)], ['id' => $logId])
            ->execute();
        $this->assertNotSame($logId, (int) ($logs->activeReplaceFor($this->userId(), 600)['id'] ?? 0), 'Silent for too long means a dead worker.');
    }

    public function testSkippedRowsAreKeptOnTheLogWithTheirReason(): void
    {
        [$row, $word] = $this->pickRow();
        $logId = $this->beginLog();

        // Edited after the search, so the row is skipped.
        $edited = $this->load();
        $edited->title = $this->originalTitle . ' edited';
        Craft::$app->getElements()->saveElement($edited, false);

        $summary = Editrix::$plugin->replace->run($logId, [$row], $word, 'x1x1', false, true, false, null, '', null, null, true);
        $fromLog = Editrix::$plugin->replace->summaryFromLog($logId);

        $this->assertSame(0, $summary['replacedCount']);
        $this->assertCount(1, $fromLog['skipped']);
        $this->assertSame('changed', $fromLog['skipped'][0]['reason']);
        $this->assertSame(ReplaceService::keyFor($row), $fromLog['skipped'][0]['key']);
    }

    public function testASummaryReadBackFromTheLogMatchesTheOneReturned(): void
    {
        [$row, $word] = $this->pickRow();
        $logId = $this->beginLog();

        $summary = Editrix::$plugin->replace->run($logId, [$row], $word, 'EditrixQA' . random_int(100, 999), false, true, false);
        $fromLog = Editrix::$plugin->replace->summaryFromLog($logId);

        $this->assertSame($summary['replacedCount'], $fromLog['replacedCount']);
        $this->assertSame($summary['replacedKeys'], $fromLog['replacedKeys']);
        $this->assertSame($summary['replacedMatches'], $fromLog['replacedMatches']);
    }

    public function testACancelledReplaceStopsAndKeepsWhatItDid(): void
    {
        [$row, $word] = $this->pickRow();
        $logId = $this->beginLog();

        // Cancelled before the first row: nothing is changed, the log says so.
        $summary = Editrix::$plugin->replace->run($logId, [$row], $word, 'x2x2', false, true, false, null, '', null, fn() => true);

        $this->assertTrue($summary['cancelled']);
        $this->assertSame(0, $summary['replacedCount']);
        $this->assertSame('cancelled', $this->logRow($logId)['status']);
        $this->assertSame($this->originalTitle, $this->title());
    }

    public function testAReplaceCutOffAfterTheSaveCanStillBeReverted(): void
    {
        [$row, $word] = $this->pickRow();
        $logId = $this->beginLog();
        $new = 'EditrixQA' . random_int(100, 999);

        // What a killed request leaves: the value logged as pending, and
        // the entry already saved with the new text.
        $entry = $this->load();
        $entry->title = $new;
        Craft::$app->getElements()->saveElement($entry, false);
        Editrix::$plugin->log->addItem($logId, [
            'elementType' => 'entry', 'elementId' => $this->entryId, 'siteId' => $this->siteId(),
            'fieldHandle' => 'title', 'elementTitle' => $this->originalTitle,
            'oldValue' => $this->originalTitle, 'newValue' => $new,
        ], 0, 'pending');

        $result = Editrix::$plugin->replace->revertEntries(Editrix::$plugin->log->replacementsFor($this->logRow($logId)));

        $this->assertSame(1, $result['reverted']);
        $this->assertSame($this->originalTitle, $this->title());
    }

    public function testAReplaceCutOffBeforeTheSaveRevertsToNothingToDo(): void
    {
        [$row, $word] = $this->pickRow();
        $logId = $this->beginLog();

        // Logged as pending, but the entry was never saved: it still holds the old text.
        Editrix::$plugin->log->addItem($logId, [
            'elementType' => 'entry', 'elementId' => $this->entryId, 'siteId' => $this->siteId(),
            'fieldHandle' => 'title', 'elementTitle' => $this->originalTitle,
            'oldValue' => $this->originalTitle, 'newValue' => 'EditrixQA999',
        ], 0, 'pending');

        $result = Editrix::$plugin->replace->revertEntries(Editrix::$plugin->log->replacementsFor($this->logRow($logId)));

        $this->assertSame(0, $result['reverted']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame([], $result['conflicts']);
        $this->assertSame($this->originalTitle, $this->title());
    }

    public function testRevertNeverOverwritesAnEditMadeSince(): void
    {
        [$row, $word] = $this->pickRow();
        $logId = $this->beginLog();

        Editrix::$plugin->replace->run($logId, [$row], $word, 'EditrixQA' . random_int(100, 999), false, true, false);

        $edited = $this->load();
        $edited->title = 'Edited by someone else';
        Craft::$app->getElements()->saveElement($edited, false);

        $result = Editrix::$plugin->replace->revertEntries(Editrix::$plugin->log->replacementsFor($this->logRow($logId)));

        $this->assertSame(0, $result['reverted']);
        $this->assertCount(1, $result['conflicts']);
        $this->assertSame('Edited by someone else', $this->title(), 'The newer edit must survive.');
    }

    public function testForceIsAnExplicitWayToOverwriteThem(): void
    {
        [$row, $word] = $this->pickRow();
        $logId = $this->beginLog();

        Editrix::$plugin->replace->run($logId, [$row], $word, 'EditrixQA' . random_int(100, 999), false, true, false);

        $edited = $this->load();
        $edited->title = 'Edited by someone else';
        Craft::$app->getElements()->saveElement($edited, false);

        $result = Editrix::$plugin->replace->revertEntries(Editrix::$plugin->log->replacementsFor($this->logRow($logId)), true);

        $this->assertSame(1, $result['reverted']);
        $this->assertSame($this->originalTitle, $this->title());
    }

    public function testRevertingTwiceIsSafe(): void
    {
        [$row, $word] = $this->pickRow();
        $logId = $this->beginLog();

        Editrix::$plugin->replace->run($logId, [$row], $word, 'EditrixQA' . random_int(100, 999), false, true, false);

        $first = Editrix::$plugin->replace->revertEntries(Editrix::$plugin->log->replacementsFor($this->logRow($logId)));
        // Reverted items are no longer listed, so the second pass has nothing to do.
        $second = Editrix::$plugin->replace->revertEntries(Editrix::$plugin->log->replacementsFor($this->logRow($logId)));

        $this->assertSame(1, $first['reverted']);
        $this->assertSame(0, $second['reverted']);
        $this->assertSame($this->originalTitle, $this->title());
    }

    public function testAnOlderLogStoredAsJsonStillReverts(): void
    {
        [$row, $word] = $this->pickRow();
        $new = 'EditrixQA' . random_int(100, 999);

        $entry = $this->load();
        $entry->title = $new;
        Craft::$app->getElements()->saveElement($entry, false);

        $legacy = [[
            'elementType' => 'entry', 'elementId' => $this->entryId, 'siteId' => $this->siteId(),
            'fieldHandle' => 'title', 'elementTitle' => $this->originalTitle,
            'oldValue' => $this->originalTitle, 'newValue' => $new,
        ]];
        $logId = Editrix::$plugin->log->createLogForUser($this->userId(), $this->siteId(), 'q', 'r', $legacy);
        $this->logIds[] = $logId;

        $entries = iterator_to_array(Editrix::$plugin->log->replacementsFor($this->logRow($logId)), false);
        $this->assertCount(1, $entries);

        $result = Editrix::$plugin->replace->revertEntries($entries);

        $this->assertSame(1, $result['reverted']);
        $this->assertSame($this->originalTitle, $this->title());
    }

    public function testTheQueueJobRunsAReplaceIntoItsLog(): void
    {
        [$row, $word] = $this->pickRow();
        $logId = $this->beginLog();
        Editrix::$plugin->log->setTotal($logId, 1);

        $job = new ReplaceJob([
            'logId' => $logId,
            'rows' => [$row],
            'query' => $word,
            'replaceWith' => 'EditrixQA' . random_int(100, 999),
            'userId' => $this->userId(),
        ]);
        $job->execute(Craft::$app->getQueue());

        $this->assertSame('success', $this->logRow($logId)['status']);
        $this->assertSame(1, Editrix::$plugin->log->itemCounts($logId)['applied']);
        $this->assertSame(1, Editrix::$plugin->log->getTotal($logId));
        $this->assertNotSame($this->originalTitle, $this->title());
    }

    public function testTheQueueJobStopsWhenTheLogIsMarkedCancelling(): void
    {
        [$row, $word] = $this->pickRow();
        $logId = $this->beginLog();
        Editrix::$plugin->log->finishReplaceLog($logId, 'cancelling', 0);

        $job = new ReplaceJob([
            'logId' => $logId,
            'rows' => [$row],
            'query' => $word,
            'replaceWith' => 'x3x3',
            'userId' => $this->userId(),
        ]);
        $job->execute(Craft::$app->getQueue());

        $this->assertSame('cancelled', $this->logRow($logId)['status']);
        $this->assertSame($this->originalTitle, $this->title());
    }

    public function testRowLimitsFollowTheEditionAndASettingCanOnlyLowerThem(): void
    {
        $license = Editrix::$plugin->license;

        $this->assertSame(200, $license->getLimitFor(Editrix::EDITION_STANDARD, 'replaceRowLimit'));
        $this->assertSame(5000, $license->getLimitFor(Editrix::EDITION_PRO, 'replaceRowLimit'));
        $this->assertSame(200, ReplaceService::rowLimit(200));
        $this->assertSame(50, ReplaceService::rowLimit(200, 50));
        $this->assertSame(200, ReplaceService::rowLimit(200, 9999));
    }
}
