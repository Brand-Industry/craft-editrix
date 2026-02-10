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
     * Get CP edit URL for this element
     */
    public function getCpEditUrl(): string
    {
        return match($this->elementType) {
            'entry' => "entries/{$this->sectionHandle}/{$this->elementId}",
            'global' => "globals/{$this->siteHandle}/{$this->sectionHandle}",
            'matrixBlock' => "entries/{$this->sectionHandle}/{$this->parentId}",
            'category' => "categories/{$this->sectionHandle}/{$this->elementId}",
            default => '#',
        };
    }

    /**
     * Get unique key for this result
     */
    public function getUniqueKey(): string
    {
        return "{$this->elementType}_{$this->elementId}_{$this->fieldHandle}_{$this->matchStart}_{$this->siteId}";
    }

    /**
     * Get icon for element type
     */
    public function getTypeIcon(): string
    {
        return match($this->elementType) {
            'entry' => 'file-text',
            'global' => 'globe',
            'matrixBlock' => 'grid',
            'category' => 'folder',
            default => 'file',
        };
    }

    /**
     * Get label for element type
     */
    public function getTypeLabel(): string
    {
        return match($this->elementType) {
            'entry' => 'Entry',
            'global' => 'Global',
            'matrixBlock' => 'Matrix Block',
            'category' => 'Category',
            default => 'Element',
        };
    }
}
