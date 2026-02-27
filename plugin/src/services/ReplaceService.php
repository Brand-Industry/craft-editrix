<?php

namespace brandindustry\editrix\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Category;
use craft\elements\MatrixBlock;
use brandindustry\editrix\models\SearchResult;
use brandindustry\editrix\Editrix;

class ReplaceService extends Component
{
    public function replace(
        array $results,
        string $searchQuery,
        string $replaceWith,
        bool $useRegex = false,
        bool $caseSensitive = true,
        bool $wholeWords = false
    ): array {
        $replacements = [];
        $processedElements = [];

        foreach ($results as $result) {
            $key = "{$result["elementType"]}_{$result["elementId"]}_{$result["fieldHandle"]}";

            if (!isset($processedElements[$key])) {
                $processedElements[$key] = [
                    "result" => $result,
                    "oldValue" => $result["fieldValue"],
                ];
            }
        }

        foreach ($processedElements as $data) {
            $result = $data["result"];
            $oldValue = $data["oldValue"];

            $newValue = $this->performReplacement(
                $oldValue,
                $searchQuery,
                $replaceWith,
                $useRegex,
                $caseSensitive,
                $wholeWords
            );

            if ($newValue === $oldValue) {
                continue;
            }

            $saved = $this->saveElementField($result, $newValue);

            if ($saved) {
                $replacements[] = [
                    "elementType" => $result["elementType"],
                    "elementId" => $result["elementId"],
                    "elementTitle" => $result["elementTitle"],
                    "sectionHandle" => $result["sectionHandle"],
                    "fieldHandle" => $result["fieldHandle"],
                    "fieldName" => $result["fieldName"] ?? "",
                    "oldValue" => $oldValue,
                    "newValue" => $newValue,
                    "siteId" => $result["siteId"],
                    "parentId" => $result["parentId"] ?? null,
                ];
            }
        }

        return $replacements;
    }

    private function performReplacement(
        string $value,
        string $query,
        string $replaceWith,
        bool $useRegex,
        bool $caseSensitive,
        bool $wholeWords
    ): string {
        if ($useRegex) {
            $flags = $caseSensitive ? "" : "i";
            $pattern = "/{$query}/{$flags}";
            return @preg_replace($pattern, $replaceWith, $value) ?? $value;
        }

        if ($wholeWords) {
            $escapedQuery = preg_quote($query, "/");
            $flags = $caseSensitive ? "" : "i";
            $pattern = "/\\b{$escapedQuery}\\b/{$flags}u";
            return preg_replace($pattern, $replaceWith, $value);
        }

        if ($caseSensitive) {
            return str_replace($query, $replaceWith, $value);
        }

        $pattern = "/" . preg_quote($query, "/") . "/i";
        return preg_replace($pattern, $replaceWith, $value);
    }

    private function saveElementField(array $result, string $newValue): bool
    {
        try {
            $element = $this->getElement($result);

            if (!$element) {
                return false;
            }

            $element->setFieldValue($result["fieldHandle"], $newValue);

            if (
                $result["elementType"] === "matrixBlock" &&
                isset($result["parentId"])
            ) {
                $parentEntry = Entry::find()
                    ->id($result["parentId"])
                    ->siteId($result["siteId"])
                    ->status(null)
                    ->one();

                if ($parentEntry) {
                    return Craft::$app
                        ->getElements()
                        ->saveElement($parentEntry);
                }
                return false;
            }

            return Craft::$app->getElements()->saveElement($element);
        } catch (\Throwable $e) {
            Craft::error(
                "Editrix: Failed to save element - " . $e->getMessage(),
                __METHOD__
            );
            return false;
        }
    }

    private function getElement(array $result): mixed
    {
        return match ($result["elementType"]) {
            "entry" => Entry::find()
                ->id($result["elementId"])
                ->siteId($result["siteId"])
                ->status(null)
                ->drafts(false)
                ->revisions(false)
                ->one(),
            "global" => GlobalSet::find()
                ->id($result["elementId"])
                ->siteId($result["siteId"])
                ->one(),
            "matrixBlock" => MatrixBlock::find()
                ->id($result["elementId"])
                ->siteId($result["siteId"])
                ->one(),
            "category" => Category::find()
                ->id($result["elementId"])
                ->siteId($result["siteId"])
                ->one(),
            default => null,
        };
    }

    /**
     * Validate whether a revert is safe (content hasn't changed since replacement)
     *
     * Returns an array of discrepancies for each replacement:
     * - 'safe' = current value matches newValue, revert is clean
     * - 'modified' = current value was changed after the replacement
     * - 'missing' = element no longer exists
     */
    public function validateRevert(array $replacements): array
    {
        $results = [];

        foreach ($replacements as $i => $replacement) {
            $element = $this->getElement($replacement);

            if (!$element) {
                $results[] = [
                    "index" => $i,
                    "status" => "missing",
                    "elementId" => $replacement["elementId"],
                    "elementTitle" =>
                        $replacement["elementTitle"] ?? "(unknown)",
                    "fieldHandle" => $replacement["fieldHandle"],
                ];
                continue;
            }

            $currentValue = (string) $element->getFieldValue(
                $replacement["fieldHandle"]
            );
            $expectedValue = (string) ($replacement["newValue"] ?? "");

            if ($currentValue === $expectedValue) {
                $results[] = [
                    "index" => $i,
                    "status" => "safe",
                    "elementId" => $replacement["elementId"],
                    "elementTitle" =>
                        $replacement["elementTitle"] ??
                        ($element->title ?? "(unknown)"),
                    "fieldHandle" => $replacement["fieldHandle"],
                ];
            } else {
                $results[] = [
                    "index" => $i,
                    "status" => "modified",
                    "elementId" => $replacement["elementId"],
                    "elementTitle" =>
                        $replacement["elementTitle"] ??
                        ($element->title ?? "(unknown)"),
                    "fieldHandle" => $replacement["fieldHandle"],
                    "expectedValue" => mb_substr($expectedValue, 0, 100),
                    "currentValue" => mb_substr($currentValue, 0, 100),
                ];
            }
        }

        return $results;
    }

    public function revert(array $replacement): bool
    {
        try {
            $element = $this->getElement($replacement);

            if (!$element) {
                return false;
            }

            $element->setFieldValue(
                $replacement["fieldHandle"],
                $replacement["oldValue"]
            );

            if (
                $replacement["elementType"] === "matrixBlock" &&
                isset($replacement["parentId"])
            ) {
                $parentEntry = Entry::find()
                    ->id($replacement["parentId"])
                    ->siteId($replacement["siteId"])
                    ->status(null)
                    ->drafts(false)
                    ->revisions(false)
                    ->one();

                if ($parentEntry) {
                    return Craft::$app
                        ->getElements()
                        ->saveElement($parentEntry);
                }
                return false;
            }

            return Craft::$app->getElements()->saveElement($element);
        } catch (\Throwable $e) {
            Craft::error(
                "Editrix: Failed to revert - " . $e->getMessage(),
                __METHOD__
            );
            return false;
        }
    }
}
