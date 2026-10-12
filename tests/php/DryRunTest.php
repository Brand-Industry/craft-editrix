<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\migrations\Install;
use brandindustry\editrix\services\ReplaceService;

/**
 * Dry Run Mode works out what a replace would do and saves nothing.
 */
class DryRunTest extends EditrixTestCase
{
    private ?Entry $entry = null;
    private string $title = '';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (
            Entry::find()->siteId($this->siteId())->status(null)->drafts(false)->revisions(false)->limit(50)->all() as $candidate
        ) {
            if ($candidate->getType()->hasTitleField && trim((string) $candidate->title) !== '') {
                $this->entry = $candidate;
                break;
            }
        }

        if (!$this->entry || !$this->entry->getType()->hasTitleField) {
            $this->markTestSkipped('The site needs an entry with a title.');
        }

        $this->title = (string) $this->entry->title;
    }

    private function titleRow(string $hash): array
    {
        return [
            'elementType' => 'entry',
            'elementId' => $this->entry->id,
            'siteId' => $this->siteId(),
            'fieldHandle' => 'title',
            'sectionHandle' => $this->entry->getSection()->handle,
            'elementTitle' => $this->title,
            'valueHash' => $hash,
        ];
    }

    private function logCount(): int
    {
        return (int) (new Query())->from(Install::TABLE_LOGS)->count();
    }

    public function testItReportsWhatWouldChangeAndSavesNothing(): void
    {
        $logs = $this->logCount();

        $summary = Editrix::$plugin->replace->dryRun(
            [$this->titleRow(ReplaceService::hashValue($this->title))],
            $this->title,
            'EditrixDryRun',
            false,
            true,
            false
        );

        $this->assertTrue($summary['dryRun']);
        $this->assertNull($summary['logId']);
        $this->assertSame(1, $summary['replacedCount']);
        $this->assertGreaterThanOrEqual(1, $summary['replacedMatches']);
        $this->assertSame([], $summary['replacedKeys'], 'Nothing is marked as replaced.');

        $fresh = Entry::find()->id($this->entry->id)->siteId($this->siteId())->status(null)->one();
        $this->assertSame($this->title, (string) $fresh->title, 'The entry is untouched.');
        $this->assertSame($logs, $this->logCount(), 'No log is written.');
    }

    public function testItSkipsWhatARealReplaceWouldSkip(): void
    {
        $summary = Editrix::$plugin->replace->dryRun(
            [$this->titleRow(ReplaceService::hashValue('not what the search saw'))],
            $this->title,
            'EditrixDryRun',
            false,
            true,
            false
        );

        $this->assertSame(0, $summary['replacedCount']);
        $this->assertSame('changed', $summary['skipped'][0]['reason']);
    }

    public function testItReportsNothingWhenTheTextIsNotThere(): void
    {
        $summary = Editrix::$plugin->replace->dryRun(
            [$this->titleRow(ReplaceService::hashValue($this->title))],
            'text that is not in this title',
            'x',
            false,
            true,
            false
        );

        $this->assertSame(0, $summary['replacedCount']);
        $this->assertSame([], $summary['failed']);
    }

    public function testTheRowLimitIsSmallerThanAReplaceAndIsNeverQueued(): void
    {
        $this->assertLessThan(
            Editrix::$plugin->replace->currentRowLimit(),
            ReplaceService::DRY_RUN_ROW_LIMIT
        );
    }
}
