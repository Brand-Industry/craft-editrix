<?php

namespace brandindustry\editrix\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;

/**
 * Finds which entries have a given category or tag assigned to them - a
 * relationship lookup, not a text search. Search-only for now: it reports
 * assignments, it doesn't let you add/remove them.
 */
class AssignmentService extends Component
{
    public function searchCategoryAssignments(
        string $query,
        ?int $siteId,
        array $sectionFilter = []
    ): array {
        return $this->searchAssignments(
            Category::class,
            $query,
            $siteId,
            $sectionFilter
        );
    }

    public function searchTagAssignments(
        string $query,
        ?int $siteId,
        array $sectionFilter = []
    ): array {
        return $this->searchAssignments(
            Tag::class,
            $query,
            $siteId,
            $sectionFilter
        );
    }

    private function searchAssignments(
        string $elementClass,
        string $query,
        ?int $siteId,
        array $sectionFilter
    ): array {
        $sites = $siteId !== null
            ? array_filter([Craft::$app->getSites()->getSiteById($siteId)])
            : Craft::$app->getSites()->getAllSites();

        $needle = mb_strtolower(trim($query));
        $results = [];

        foreach ($sites as $site) {
            /** @var Category[]|Tag[] $candidates */
            $candidates = $elementClass::find()
                ->siteId($site->id)
                ->status(null)
                ->all();

            foreach ($candidates as $element) {
                $title = mb_strtolower($element->title ?? "");
                if ($needle !== "" && !str_contains($title, $needle)) {
                    continue;
                }

                $entryQuery = Entry::find()
                    ->relatedTo($element)
                    ->siteId($site->id)
                    ->status(null)
                    ->drafts(false)
                    ->revisions(false);

                if (!empty($sectionFilter)) {
                    $entryQuery->section($sectionFilter);
                }

                $entries = $entryQuery->all();

                if (empty($entries)) {
                    continue;
                }

                $results[] = [
                    "id" => $element->id,
                    "title" => $element->title,
                    "groupName" => $this->getGroupName($element),
                    "siteId" => $site->id,
                    "siteHandle" => $site->handle,
                    "cpEditUrl" => $element->getCpEditUrl(),
                    "entryCount" => count($entries),
                    "entries" => array_map(
                        fn(Entry $entry) => [
                            "id" => $entry->id,
                            "title" => $entry->title ?? "Untitled",
                            "sectionName" => $entry->getSection()?->name,
                            "siteHandle" => $site->handle,
                            "cpEditUrl" => $entry->getCpEditUrl(),
                        ],
                        $entries
                    ),
                ];
            }
        }

        return $results;
    }

    private function getGroupName(Element $element): ?string
    {
        if (method_exists($element, "getGroup")) {
            return $element->getGroup()?->name;
        }

        return null;
    }
}
