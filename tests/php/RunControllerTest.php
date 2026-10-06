<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use yii\base\Action;
use brandindustry\editrix\console\controllers\RunController;
use brandindustry\editrix\Editrix;

/**
 * The command line. Its work is in plan(), applyPlan() and revertLog(), so
 * it can be tested without capturing what it prints.
 *
 * Edits a real entry title and puts it back, like ReplaceRevertTest.
 */
class RunControllerTest extends EditrixTestCase
{
    private ?int $entryId = null;
    private ?string $originalTitle = null;
    private array $logIds = [];

    protected function tearDown(): void
    {
        foreach ($this->logIds as $id) {
            Editrix::$plugin->log->deleteLog($id);
        }

        if ($this->entryId !== null) {
            $entry = Entry::find()->id($this->entryId)->siteId($this->siteId())->status(null)->one();
            if ($entry && $entry->title !== $this->originalTitle) {
                $entry->title = $this->originalTitle;
                Craft::$app->getElements()->saveElement($entry, false);
            }
        }

        parent::tearDown();
    }

    private function controller(array $props = []): RunController
    {
        $controller = new RunController('run', Craft::$app);
        foreach ($props as $name => $value) {
            $controller->$name = $value;
        }

        return $controller;
    }

    /** @return array{0: string, 1: string} a section with entries, and a word from one title */
    private function sectionAndWord(): array
    {
        $section = $this->smallestSection(1);
        if (!$section) {
            $this->markTestSkipped('The site has no entries.');
        }

        $entries = Entry::find()->siteId($this->siteId())->section($section[0])->status(null)->drafts(false)->revisions(false)->limit(60)->all();
        foreach ($entries as $entry) {
            if (preg_match('/\b(\p{L}{5,})\b/u', (string) $entry->title, $m)) {
                $this->entryId = $entry->id;
                $this->originalTitle = (string) $entry->title;

                return [$section[0], $m[1]];
            }
        }

        $this->markTestSkipped('No entry with a title word of 5+ letters.');
    }

    private function currentTitle(): string
    {
        return (string) Entry::find()->id($this->entryId)->siteId($this->siteId())->status(null)->one()->title;
    }

    public function testListsAreSplitOnCommasAndTrimmed(): void
    {
        $this->assertSame(['a', 'b', 'c'], RunController::parseList(' a, b ,,c '));
        $this->assertSame([], RunController::parseList(''));
    }

    public function testEverythingDefaultsToADryRun(): void
    {
        $this->assertTrue($this->controller()->dryRun);
    }

    public function testNothingToFindIsRefused(): void
    {
        $plan = $this->controller(['find' => '  '])->plan(10);

        $this->assertArrayHasKey('error', $plan);
    }

    public function testThePlanFindsTheMatchesInTheScope(): void
    {
        [$section, $word] = $this->sectionAndWord();

        $plan = $this->controller(['find' => $word, 'sections' => $section, 'fields' => 'title'])->plan(100);

        $this->assertArrayNotHasKey('error', $plan);
        $this->assertGreaterThan(0, $plan['rowCount']);
        $this->assertSame($plan['rowCount'], $plan['replaceable']);
        $this->assertContains($this->entryId, array_column($plan['rows'], 'elementId'));
    }

    public function testElementIdsLimitTheSearchToThoseEntries(): void
    {
        [$section, $word] = $this->sectionAndWord();

        $only = $this->controller(['find' => $word, 'sections' => $section, 'fields' => 'title', 'elements' => (string) $this->entryId])->plan(100);
        $other = $this->controller(['find' => $word, 'sections' => $section, 'fields' => 'title', 'elements' => (string) ($this->entryId + 999999)])->plan(100);

        $this->assertSame([$this->entryId], array_values(array_unique(array_column($only['rows'], 'elementId'))));
        $this->assertSame(0, $other['rowCount']);
    }

    public function testAppliedThenRevertedLeavesTheTitleAsItWas(): void
    {
        [$section, $word] = $this->sectionAndWord();
        $replacement = 'EditrixQA' . random_int(100, 999);

        $cli = $this->controller([
            'find' => $word,
            'replace' => $replacement,
            'sections' => $section,
            'fields' => 'title',
            'elements' => (string) $this->entryId,
            'dryRun' => false,
        ]);

        $summary = $cli->applyPlan($cli->plan(100));
        $this->logIds[] = $summary['logId'];

        $this->assertSame(1, $summary['replacedCount']);
        $this->assertStringContainsString($replacement, $this->currentTitle());
        $this->assertSame('success', Editrix::$plugin->log->getLogById($summary['logId'])['status']);

        $result = $cli->revertLog($summary['logId']);

        $this->assertSame(1, $result['reverted']);
        $this->assertSame($this->originalTitle, $this->currentTitle());
        $this->assertSame('reverted', Editrix::$plugin->log->getLogById($summary['logId'])['status']);
    }

    public function testRevertOfAnUnknownLogSaysSo(): void
    {
        $this->assertArrayHasKey('error', $this->controller()->revertLog(999999999));
    }

    public function testTheCommandLineNeedsPro(): void
    {
        $controller = $this->controller();
        $real = Editrix::$plugin->edition;

        try {
            Editrix::$plugin->edition = Editrix::EDITION_STANDARD;
            $this->assertFalse($controller->beforeAction(new Action('search', $controller)));

            Editrix::$plugin->edition = Editrix::EDITION_PRO;
            $this->assertTrue($controller->beforeAction(new Action('search', $controller)));
        } finally {
            Editrix::$plugin->edition = $real;
        }
    }

    public function testAReplaceLoggedFromTheConsoleNeedsAUser(): void
    {
        $cli = $this->controller(['user' => 'no-such-user-exists']);

        $this->assertArrayHasKey('error', $cli->applyPlan(['siteId' => 1, 'replaceRows' => []]));
    }
}
