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
     * True for matches inside a relational field (e.g. a Tags field's tag
     * titles) where there's no single field value to safely overwrite -
     * these are shown for discovery but can't go through Replace.
     */
    public bool $readOnly = false;

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
