<?php

namespace brandindustry\editrix\models;

use craft\base\Model;

class SearchResult extends Model
{
    public string $elementType;
    public int $elementId;
    public string $elementTitle;
    public string $sectionHandle;
    public string $sectionName;
    public string $fieldHandle;
    public string $fieldName;
    public int $siteId;
    public string $siteHandle;
    public string $matchContext;
    public string $fieldValue;
    public int $matchStart;
    public int $matchEnd;
    public ?int $parentId = null;
    public ?string $parentTitle = null;
    public ?string $blockTypeHandle = null;

    /**
     * True for matches that can be found but not safely overwritten by
     * Replace - shown for discovery in Search mode only. See
     * $readOnlyReason for why.
     */
    public bool $readOnly = false;

    /**
     * Why this match is readOnly: "tag" (a Tags field's tag titles - there's
     * no single field value to overwrite) or "formatting" (a rich text
     * match that straddles an HTML tag, e.g. a phrase with a word in the
     * middle italicized - rewriting it as plain substring would corrupt the
     * markup). Null when the match isn't readOnly.
     */
    public ?string $readOnlyReason = null;

    /**
     * Whether this field stores HTML (Redactor/CKEditor) - Replace uses
     * this to rewrite only the part of the raw HTML that actually changed,
     * rather than a blind substring replace that would corrupt markup.
     */
    public bool $isRichText = false;

    public function getCpEditUrl(): string
    {
        return match ($this->elementType) {
            "entry" => "entries/{$this->sectionHandle}/{$this->elementId}",
            "global" => "globals/{$this->siteHandle}/{$this->sectionHandle}",
            "matrixBlock", "neoBlock"
                => "entries/{$this->sectionHandle}/{$this->parentId}",
            "category"
                => "categories/{$this->sectionHandle}/{$this->elementId}",
            default => "#",
        };
    }

    public function getUniqueKey(): string
    {
        return "{$this->elementType}_{$this->elementId}_{$this->fieldHandle}_{$this->matchStart}_{$this->siteId}";
    }

    public function getTypeIcon(): string
    {
        return match ($this->elementType) {
            "entry" => "file-text",
            "global" => "globe",
            "matrixBlock", "neoBlock" => "grid",
            "category" => "folder",
            default => "file",
        };
    }

    public function getTypeLabel(): string
    {
        return match ($this->elementType) {
            "entry" => "Entry",
            "global" => "Global",
            "matrixBlock" => "Matrix Block",
            "neoBlock" => "Neo Block",
            "category" => "Category",
            default => "Element",
        };
    }
}
