<?php

namespace brandindustry\editrix\tests;

use Craft;
use craft\elements\Entry;
use craft\helpers\Json;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\helpers\FieldValue;
use brandindustry\editrix\services\SearchService;

/**
 * CKEditor stores nested entries as <craft-entry> markers. Reading the
 * rendered value instead of the stored one used to flatten them on Replace,
 * so these tests check that the markers survive a replace and a revert.
 *
 * They write to the host project's database: the stored content of the
 * entry under test is snapshotted first and restored in tearDown. Skipped
 * when the site has no CKEditor field with nested entries.
 */
class RichTextNestedEntriesTest extends EditrixTestCase
{
    private ?int $rowId = null;
    private ?string $snapshot = null;

    protected function tearDown(): void
    {
        if ($this->rowId !== null && $this->snapshot !== null) {
            // Decoded, so Craft writes it back as a JSON object. Passing the
            // text itself would store it as one JSON string.
            Craft::$app->getDb()->createCommand()
                ->update('{{%elements_sites}}', ['content' => Json::decode($this->snapshot)], ['id' => $this->rowId])
                ->execute();
        }
        parent::tearDown();
    }

    /** [entryId, fieldHandle, word], found once per run. */
    private static ?array $found = null;

    private function load(int $id): ?Entry
    {
        return Entry::find()
            ->id($id)
            ->siteId($this->siteId())
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->one();
    }

    /**
     * An entry with a CKEditor field that embeds at least one entry, plus a
     * word of the field's own text that we can replace. Returns
     * [entry, fieldHandle, rawHtml, word]. Looks at the newest entries of
     * each section rather than querying the stored JSON, which is slow on a
     * large site.
     */
    private function pickEntry(): array
    {
        if (!class_exists(\craft\ckeditor\Field::class)) {
            $this->markTestSkipped('CKEditor is not installed.');
        }

        if (self::$found === null) {
            self::$found = $this->findCandidate() ?? [];
        }

        if (!self::$found) {
            $this->markTestSkipped('No entry with a CKEditor field that embeds entries was found.');
        }

        [$id, $handle, $word] = self::$found;
        $entry = $this->load($id);
        $raw = FieldValue::toString($entry->getFieldValue($handle));

        $this->rowId = (int) (new \craft\db\Query())
            ->select(['id'])
            ->from('{{%elements_sites}}')
            ->where(['elementId' => $id, 'siteId' => $this->siteId()])
            ->scalar();
        $this->snapshot = (string) (new \craft\db\Query())
            ->select(['content'])
            ->from('{{%elements_sites}}')
            ->where(['id' => $this->rowId])
            ->scalar();

        return [$entry, $handle, $raw, $word];
    }

    private function findCandidate(): ?array
    {
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $entries = Entry::find()
                ->siteId($this->siteId())
                ->section($section->handle)
                ->status(null)
                ->drafts(false)
                ->revisions(false)
                ->orderBy('elements.id DESC')
                ->limit(150)
                ->all();

            foreach ($entries as $entry) {
                $layout = $entry->getFieldLayout();
                if (!$layout) {
                    continue;
                }

                foreach ($layout->getCustomFields() as $field) {
                    if (!$field instanceof \craft\ckeditor\Field) {
                        continue;
                    }

                    $raw = FieldValue::toString($entry->getFieldValue($field->handle));
                    $plain = html_entity_decode(strip_tags($raw));

                    if (
                        str_contains($raw, '<craft-entry') &&
                        preg_match('/\b(\p{L}{6,})\b/u', $plain, $m)
                    ) {
                        return [$entry->id, $field->handle, $m[1]];
                    }
                }
            }
        }

        return null;
    }

    private function searchEntry(Entry $entry, string $word, array $fieldFilter = []): array
    {
        $service = new class extends SearchService {
            public function one(Entry $entry, string $word, int $siteId, array $fieldFilter): array
            {
                $results = [];
                $this->searchOneEntry(
                    $entry,
                    $word,
                    $siteId,
                    Craft::$app->getSites()->getSiteById($siteId),
                    false,
                    true,
                    true,
                    false,
                    $fieldFilter,
                    $results
                );
                return $results;
            }
        };

        $out = [];
        foreach ($service->one($entry, $word, $this->siteId(), $fieldFilter) as $result) {
            $out[] = (array) $result;
        }

        return $out;
    }

    private function rawOf(int $entryId, string $handle): string
    {
        return FieldValue::toString($this->load($entryId)->getFieldValue($handle));
    }

    public function testTheSearchResultCarriesTheStoredHtmlNotTheRenderedOne(): void
    {
        [$entry, $handle, $raw, $word] = $this->pickEntry();

        $results = $this->searchEntry($entry, $word, [$handle]);
        $own = array_values(array_filter(
            $results,
            fn($r) => $r['elementType'] === 'entry' && $r['fieldHandle'] === $handle
        ));

        $this->assertNotEmpty($own, "\"{$word}\" should be found in {$handle}.");
        $this->assertSame($raw, $own[0]['fieldValue']);
        $this->assertSame(
            substr_count($raw, '<craft-entry'),
            substr_count($own[0]['fieldValue'], '<craft-entry')
        );
    }

    public function testReplaceKeepsTheNestedEntryMarkers(): void
    {
        [$entry, $handle, $raw, $word] = $this->pickEntry();
        $markers = substr_count($raw, '<craft-entry');
        $replacement = 'EditrixQA' . random_int(1000, 9999);

        $results = array_values(array_filter(
            $this->searchEntry($entry, $word, [$handle]),
            fn($r) => $r['elementType'] === 'entry' && $r['fieldHandle'] === $handle
        ));
        $this->assertNotEmpty($results);

        $replacements = Editrix::$plugin->replace->replace($results, $word, $replacement, false, true, false);
        $this->assertCount(1, $replacements);

        $after = $this->rawOf($entry->id, $handle);
        $this->assertSame($markers, substr_count($after, '<craft-entry'), 'Replace flattened a nested entry.');
        $this->assertStringContainsString($replacement, $after);

        $this->assertSame('safe', Editrix::$plugin->replace->validateRevert($replacements)[0]['status']);
    }

    public function testRevertRestoresTheStoredHtmlExactly(): void
    {
        [$entry, $handle, $raw, $word] = $this->pickEntry();

        $results = array_values(array_filter(
            $this->searchEntry($entry, $word, [$handle]),
            fn($r) => $r['elementType'] === 'entry' && $r['fieldHandle'] === $handle
        ));
        $replacements = Editrix::$plugin->replace->replace(
            $results,
            $word,
            'EditrixQA' . random_int(1000, 9999),
            false,
            true,
            false
        );
        $this->assertCount(1, $replacements);

        $this->assertTrue(Editrix::$plugin->replace->revert($replacements[0]));
        $this->assertSame(
            FieldValue::withoutRefFallbacks($raw),
            FieldValue::withoutRefFallbacks($this->rawOf($entry->id, $handle))
        );
    }

    public function testTextInsideANestedEntryIsFoundOnTheNestedEntry(): void
    {
        [$entry, $handle] = $this->pickEntry();

        $nested = $entry->getFieldValue($handle)->getEntries()->status(null)->all();
        $target = null;

        foreach ($nested as $block) {
            foreach ($block->getType()->getCustomFields() as $field) {
                $text = FieldValue::toString($block->getFieldValue($field->handle));
                $plain = html_entity_decode(strip_tags($text));
                if (
                    in_array(get_class($field), Editrix::$plugin->getSettings()->searchableFieldTypes, true) &&
                    preg_match('/\b(\p{L}{6,})\b/u', $plain, $m)
                ) {
                    $target = [$block, $field->handle, $m[1]];
                    break 2;
                }
            }
        }

        if (!$target) {
            $this->markTestSkipped('The nested entries have no searchable text.');
        }

        [$block, $blockField, $word] = $target;
        $results = $this->searchEntry($entry, $word);
        $hits = array_filter(
            $results,
            fn($r) => $r['elementId'] === $block->id && $r['fieldHandle'] === $blockField
        );

        $this->assertNotEmpty($hits, "\"{$word}\" should be found on nested entry {$block->id}.");
    }
}
