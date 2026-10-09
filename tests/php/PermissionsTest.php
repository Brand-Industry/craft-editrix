<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\helpers\Access;
use brandindustry\editrix\services\ReplaceService;

/**
 * Editrix's own permissions say who may use the tool. Craft's say which
 * entries a person may open and change, and a search and a replace have to
 * respect those too.
 *
 * Needs two sections with an entry each; skips otherwise. Creates one user
 * who may work in the first section only, and removes it afterwards.
 */
class PermissionsTest extends EditrixTestCase
{
    private ?User $user = null;
    private ?Entry $allowed = null;
    private ?Entry $denied = null;
    private ?string $allowedTitle = null;

    protected function setUp(): void
    {
        parent::setUp();
        Access::flush();

        $found = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $entry = Entry::find()
                ->siteId($this->siteId())
                ->section($section->handle)
                ->status(null)
                ->drafts(false)
                ->revisions(false)
                ->one();

            if ($entry && trim((string) $entry->title) !== '' && $entry->getType()->hasTitleField) {
                $found[] = [$section, $entry];
            }
            if (count($found) === 2) {
                break;
            }
        }

        if (count($found) < 2) {
            $this->markTestSkipped('The site needs two sections that each have an entry with a title.');
        }

        [[$sectionA, $this->allowed], [, $this->denied]] = $found;
        $this->allowedTitle = (string) $this->allowed->title;

        $user = new User();
        $user->username = 'editrix-perm-' . random_int(1000, 9999);
        $user->email = $user->username . '@example.test';
        $user->admin = false;
        if (!Craft::$app->getElements()->saveElement($user, false)) {
            $this->markTestSkipped('Could not create a test user.');
        }
        $this->user = $user;

        Craft::$app->getUserPermissions()->saveUserPermissions($user->id, [
            "viewEntries:{$sectionA->uid}",
            "viewPeerEntries:{$sectionA->uid}",
            "saveEntries:{$sectionA->uid}",
            "savePeerEntries:{$sectionA->uid}",
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->allowed && $this->allowedTitle !== null) {
            $fresh = Entry::find()->id($this->allowed->id)->siteId($this->siteId())->status(null)->one();
            if ($fresh && (string) $fresh->title !== $this->allowedTitle) {
                $fresh->title = $this->allowedTitle;
                Craft::$app->getElements()->saveElement($fresh, false);
            }
        }

        if ($this->user) {
            Craft::$app->getElements()->deleteElement($this->user, true);
        }

        Access::flush();
        parent::tearDown();
    }

    private function adminId(): int
    {
        return (int) (new Query())->select(['id'])->from('{{%users}}')->where(['admin' => true])->orderBy(['id' => SORT_ASC])->scalar();
    }

    /** @return array[] the rows a search for this entry's title returns for that user */
    private function rowsFor(Entry $entry, ?int $userId): array
    {
        $results = Editrix::$plugin->search->search(
            (string) $entry->title,
            $this->siteId(),
            false,
            true,
            [
                'searchEntries' => true,
                'searchGlobals' => false,
                'searchMatrix' => false,
                'fields' => ['title'],
                'elementIds' => [$entry->id],
                'userId' => $userId,
            ]
        );

        $rows = [];
        foreach (Editrix::$plugin->search->groupResultsBySite($results) as $site) {
            array_push($rows, ...$site['results']);
        }

        return $rows;
    }

    private function resultRow(Entry $entry): array
    {
        return [
            'elementType' => 'entry',
            'elementId' => $entry->id,
            'siteId' => $this->siteId(),
            'fieldHandle' => 'title',
            'sectionHandle' => $entry->getSection()->handle,
            'elementTitle' => $entry->title,
            'valueHash' => ReplaceService::hashValue((string) $entry->title),
        ];
    }

    public function testASearchShowsTheUserTheirSectionAndNotTheOther(): void
    {
        $this->assertNotSame([], $this->rowsFor($this->allowed, $this->user->id), 'The section the user may view is searched.');
        $this->assertSame([], $this->rowsFor($this->denied, $this->user->id), 'A section the user may not view is left out.');
    }

    public function testAnAdminSeesEverything(): void
    {
        $this->assertNotSame([], $this->rowsFor($this->denied, $this->adminId()));
    }

    public function testNoUserMeansNothingIsChecked(): void
    {
        $this->assertNotSame([], $this->rowsFor($this->denied, null));
    }

    public function testAReplaceLeavesAnEntryTheUserMayNotSaveAlone(): void
    {
        $title = (string) $this->denied->title;
        $skipped = [];
        $failed = [];

        $replacements = Editrix::$plugin->replace->replace(
            [$this->resultRow($this->denied)],
            $title,
            'EditrixNope',
            false,
            true,
            false,
            $skipped,
            $failed,
            ['userId' => $this->user->id]
        );

        $this->assertSame([], $replacements);
        $this->assertSame('permission', $skipped[0]['reason']);

        $fresh = Entry::find()->id($this->denied->id)->siteId($this->siteId())->status(null)->one();
        $this->assertSame($title, (string) $fresh->title);
    }

    public function testAReplaceChangesAnEntryTheUserMaySave(): void
    {
        $skipped = [];
        $failed = [];

        $replacements = Editrix::$plugin->replace->replace(
            [$this->resultRow($this->allowed)],
            $this->allowedTitle,
            'EditrixYes',
            false,
            true,
            false,
            $skipped,
            $failed,
            ['userId' => $this->user->id]
        );

        $this->assertSame([], $skipped);
        $this->assertSame([], $failed);
        $this->assertCount(1, $replacements);

        $fresh = Entry::find()->id($this->allowed->id)->siteId($this->siteId())->status(null)->one();
        $this->assertSame('EditrixYes', (string) $fresh->title);
    }

    public function testTheTextOfAnEntryTheUserMayNotOpenIsNotHandedOver(): void
    {
        $service = Editrix::$plugin->replace;

        $this->assertNull($service->currentValueFor($this->resultRow($this->denied), $this->user->id));
        $this->assertNotNull($service->currentValueFor($this->resultRow($this->allowed), $this->user->id));
        $this->assertNotNull($service->currentValueFor($this->resultRow($this->denied), $this->adminId()));
    }

    public function testAUserWhoNoLongerExistsMayNotDoAnything(): void
    {
        $this->assertFalse(Access::canView($this->allowed, 2147483000));
        $this->assertFalse(Access::canSave($this->allowed, 2147483000));
    }

    public function testARevertLeavesAnEntryTheUserMayNotSaveAlone(): void
    {
        $title = (string) $this->denied->title;

        $out = Editrix::$plugin->replace->revertEntries(
            [[
                'elementType' => 'entry',
                'elementId' => $this->denied->id,
                'siteId' => $this->siteId(),
                'fieldHandle' => 'title',
                'elementTitle' => $title,
                'oldValue' => $title . ' before',
                'newValue' => $title,
            ]],
            false,
            null,
            $this->user->id
        );

        $this->assertSame(0, $out['reverted']);
        $this->assertSame('permission', $out['failed'][0]['reason']);

        $fresh = Entry::find()->id($this->denied->id)->siteId($this->siteId())->status(null)->one();
        $this->assertSame($title, (string) $fresh->title);
    }
}
