<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\helpers\FieldValue;

/**
 * Craft 5 lets one field have a different handle in each layout. A search
 * reports the handle the layout gives, so a replace has to find the field
 * the same way; looking it up by its global handle refused every such row
 * with "this field cannot be replaced".
 *
 * Needs a site with such a field; skips otherwise. Edits one entry field
 * and puts it back, so keep it to local copies.
 */
class LayoutHandleTest extends EditrixTestCase
{
    private ?Entry $entry = null;
    private ?string $handle = null;
    private ?string $original = null;
    /** @var int[] */
    private array $logIds = [];

    protected function tearDown(): void
    {
        foreach ($this->logIds as $id) {
            Editrix::$plugin->log->deleteLog($id);
        }

        if ($this->entry && $this->handle !== null && $this->original !== null) {
            $fresh = Entry::find()->id($this->entry->id)->siteId($this->siteId())->status(null)->one();
            if ($fresh && FieldValue::toString($fresh->getFieldValue($this->handle)) !== $this->original) {
                $fresh->setFieldValue($this->handle, $this->original);
                Craft::$app->getElements()->saveElement($fresh, false);
            }
        }

        parent::tearDown();
    }

    /**
     * An entry with a field whose handle in its layout is not the field's
     * own, holding a word long enough to search for.
     *
     * @return array{0: Entry, 1: string, 2: string} entry, layout handle, word
     */
    private function pick(): array
    {
        $settings = Editrix::$plugin->getSettings();
        $fields = Craft::$app->getFields();

        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $type) {
            foreach ($type->getFieldLayout()->getCustomFields() as $field) {
                $global = $fields->getFieldById($field->id);

                if (!$global || $global->handle === $field->handle || !in_array(get_class($global), $settings->searchableFieldTypes, true)) {
                    continue;
                }

                $entries = Entry::find()->typeId($type->id)->siteId($this->siteId())->status(null)->drafts(false)->revisions(false)->limit(25)->all();

                foreach ($entries as $entry) {
                    $text = strip_tags(FieldValue::toString($entry->getFieldValue($field->handle)));
                    if (preg_match('/\b(\p{L}{6,})\b/u', $text, $m)) {
                        return [$entry, $field->handle, $m[1]];
                    }
                }
            }
        }

        $this->markTestSkipped('No entry has a text field under a handle of its own in its layout.');
    }

    public function testTheLayoutHandleIsFoundWhereTheGlobalOneIsNot(): void
    {
        [$entry, $handle] = $this->pick();

        $this->assertNull(Craft::$app->getFields()->getFieldByHandle($handle), 'The premise: no global field has this handle.');
        $this->assertTrue(Editrix::$plugin->replace->isReplaceableField([
            'elementType' => 'entry',
            'elementId' => $entry->id,
            'siteId' => $this->siteId(),
            'fieldHandle' => $handle,
        ]));
    }

    public function testAFieldUnderALayoutHandleCanBeReplaced(): void
    {
        [$entry, $handle, $word] = $this->pick();
        $this->entry = $entry;
        $this->handle = $handle;
        $this->original = FieldValue::toString($entry->getFieldValue($handle));

        $results = Editrix::$plugin->search->search(
            $word,
            $this->siteId(),
            false,
            true,
            ['searchEntries' => true, 'searchGlobals' => false, 'searchMatrix' => false, 'fields' => [$handle], 'elementIds' => [$entry->id]]
        );

        $row = null;
        foreach (Editrix::$plugin->search->groupResultsBySite($results) as $site) {
            foreach ($site['results'] as $candidate) {
                if ($candidate['elementId'] === $entry->id && $candidate['fieldHandle'] === $handle) {
                    $row = $candidate;
                }
            }
        }

        if (!$row) {
            $this->markTestSkipped('The search did not return the field under its layout handle.');
        }

        $userId = (int) (new Query())->select(['id'])->from('{{%users}}')->orderBy(['admin' => SORT_DESC, 'id' => SORT_ASC])->scalar();
        $logId = Editrix::$plugin->log->beginReplaceLog($this->siteId(), $word, 'x', false, true, false, $userId);
        $this->logIds[] = $logId;

        $replacement = 'EditrixQA' . random_int(100, 999);
        $summary = Editrix::$plugin->replace->run($logId, [$row], $word, $replacement, false, true, false);

        $this->assertSame([], $summary['failed'], json_encode($summary['failed']));
        $this->assertSame(1, $summary['replacedCount']);

        $saved = Entry::find()->id($entry->id)->siteId($this->siteId())->status(null)->one();
        $this->assertStringContainsString($replacement, FieldValue::toString($saved->getFieldValue($handle)));
    }
}
