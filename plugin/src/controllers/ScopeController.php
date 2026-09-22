<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\web\Controller;
use craft\fields\Categories;
use craft\fields\Matrix;
use craft\fields\Tags;
use craft\models\Section;
use yii\web\Response;
use brandindustry\editrix\Editrix;

class ScopeController extends Controller
{
    public function actionSections(): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $sections = Craft::$app->getEntries()->getAllSections();

        $data = array_map(
            fn($section) => [
                "id" => $section->id,
                "name" => $section->name,
                "handle" => $section->handle,
                "type" => $section->type,
            ],
            $sections
        );

        return $this->asJson([
            "success" => true,
            "sections" => array_values($data),
        ]);
    }

    /**
     * Sections worth offering in the Category/Tag assignment search's
     * "Limit to sections" filter - only ones where at least one entry type
     * actually has a Categories (or Tags) field somewhere in its layout, so
     * the list doesn't include sections that could never match anything.
     */
    public function actionAssignableSections(): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $type = Craft::$app->getRequest()->getParam("type", "category");
        $targetClass = $type === "tag" ? Tags::class : Categories::class;

        $sections = Craft::$app->getEntries()->getAllSections();

        $data = [];
        foreach ($sections as $section) {
            if ($this->sectionHasRelationField($section, $targetClass)) {
                $data[] = [
                    "id" => $section->id,
                    "name" => $section->name,
                    "handle" => $section->handle,
                    "type" => $section->type,
                ];
            }
        }

        return $this->asJson([
            "success" => true,
            "sections" => $data,
        ]);
    }

    private function sectionHasRelationField(
        Section $section,
        string $targetClass
    ): bool {
        foreach ($section->getEntryTypes() as $entryType) {
            $fieldLayout = $entryType->getFieldLayout();

            if ($fieldLayout && $this->layoutHasRelationField($fieldLayout, $targetClass)) {
                return true;
            }
        }

        return false;
    }

    private function layoutHasRelationField(
        $fieldLayout,
        string $targetClass
    ): bool {
        foreach ($fieldLayout->getCustomFields() as $field) {
            if ($field instanceof $targetClass) {
                return true;
            }

            if ($field instanceof Matrix) {
                foreach ($field->getEntryTypes() as $blockType) {
                    foreach ($blockType->getCustomFields() as $subField) {
                        if ($subField instanceof $targetClass) {
                            return true;
                        }
                    }
                }
                continue;
            }

            if ($this->isNeoField($field)) {
                foreach ($field->getBlockTypes() as $blockType) {
                    foreach ($blockType->getCustomFields() as $subField) {
                        if ($subField instanceof $targetClass) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    public function actionSites(): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $sites = Craft::$app->getSites()->getAllSites();

        $data = array_map(
            fn($site) => [
                "id" => $site->id,
                "name" => $site->name,
                "handle" => $site->handle,
                "primary" => $site->primary,
            ],
            $sites
        );

        return $this->asJson([
            "success" => true,
            "sites" => array_values($data),
        ]);
    }

    public function actionFields(): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $settings = Editrix::$plugin->getSettings();

        $entryTypeIds = array_map(
            "intval",
            (array) Craft::$app->getRequest()->getParam("entryTypeId", [])
        );

        $isSearchable = fn($field) => in_array(
            get_class($field),
            $settings->searchableFieldTypes
        );

        if (!empty($entryTypeIds)) {
            $fieldsByHandle = [];

            foreach ($entryTypeIds as $entryTypeId) {
                $entryType = Craft::$app
                    ->getEntries()
                    ->getEntryTypeById($entryTypeId);

                if (!$entryType) {
                    continue;
                }

                if ($entryType->hasTitleField && !isset($fieldsByHandle["title"])) {
                    $fieldsByHandle["title"] = [
                        "id" => null,
                        "name" => Craft::t("editrix", "Title"),
                        "handle" => "title",
                        "type" => "Title",
                        "readOnly" => false,
                    ];
                }

                $fieldLayout = $entryType->getFieldLayout();

                if (!$fieldLayout) {
                    continue;
                }

                foreach ($fieldLayout->getCustomFields() as $field) {
                    if ($isSearchable($field)) {
                        $fieldsByHandle[$field->handle] = [
                            "id" => $field->id,
                            "name" => $field->name,
                            "handle" => $field->handle,
                            "type" => (new \ReflectionClass(
                                $field
                            ))->getShortName(),
                            "readOnly" => false,
                        ];
                        continue;
                    }

                    if ($field instanceof Matrix) {
                        // One checkbox for the whole Matrix field, not one
                        // per nested sub-field - block types often reuse
                        // names like "Title" across themselves, so listing
                        // every sub-field individually reads as duplicates
                        // with no way to tell them apart. Checking it
                        // searches every field inside every block type; the
                        // Results table already shows which one matched.
                        if (
                            !empty($this->matrixSubFields($field, $isSearchable))
                        ) {
                            $fieldsByHandle[$field->handle] = [
                                "id" => $field->id,
                                "name" => $field->name,
                                "handle" => $field->handle,
                                "type" => "Matrix",
                                "readOnly" => false,
                            ];
                        }
                        continue;
                    }

                    if ($this->isNeoField($field)) {
                        if (!empty($this->neoSubFields($field, $isSearchable))) {
                            $fieldsByHandle[$field->handle] = [
                                "id" => $field->id,
                                "name" => $field->name,
                                "handle" => $field->handle,
                                "type" => "Neo",
                                "readOnly" => false,
                            ];
                        }
                        continue;
                    }

                    if ($field instanceof Tags) {
                        $fieldsByHandle[$field->handle] = [
                            "id" => $field->id,
                            "name" => $field->name,
                            "handle" => $field->handle,
                            "type" => "Tags",
                            "readOnly" => true,
                        ];
                    }
                }
            }

            return $this->asJson([
                "success" => true,
                "fields" => array_values($fieldsByHandle),
            ]);
        }

        $allFields = Craft::$app->getFields()->getAllFields();
        $fields = array_filter($allFields, $isSearchable);

        $data = array_map(
            fn($field) => [
                "id" => $field->id,
                "name" => $field->name,
                "handle" => $field->handle,
                "type" => (new \ReflectionClass($field))->getShortName(),
                "readOnly" => false,
            ],
            $fields
        );

        return $this->asJson([
            "success" => true,
            "fields" => array_values($data),
        ]);
    }

    /**
     * Searchable fields nested inside a Matrix field's block types, keyed by
     * "matrixHandle.fieldHandle" - the same compound handle SearchService
     * matches against when a Matrix field is scoped down to specific fields.
     */
    private function matrixSubFields(Matrix $matrixField, callable $isSearchable): array
    {
        $subFields = [];

        foreach ($matrixField->getEntryTypes() as $blockType) {
            foreach ($blockType->getCustomFields() as $field) {
                if (!$isSearchable($field)) {
                    continue;
                }

                $handle = "{$matrixField->handle}.{$field->handle}";
                $subFields[$handle] = [
                    "id" => $field->id,
                    "name" => "{$matrixField->name} → {$field->name}",
                    "handle" => $handle,
                    "type" => (new \ReflectionClass($field))->getShortName(),
                    "readOnly" => false,
                ];
            }
        }

        return $subFields;
    }

    /**
     * Whether the Neo plugin (an optional third-party dependency, not
     * required by this plugin) is installed and this field is one of its
     * Neo fields.
     */
    private function isNeoField($field): bool
    {
        return class_exists(\benf\neo\Field::class) &&
            $field instanceof \benf\neo\Field;
    }

    /**
     * Same as matrixSubFields(), for a Neo field's block types.
     */
    private function neoSubFields($neoField, callable $isSearchable): array
    {
        $subFields = [];

        foreach ($neoField->getBlockTypes() as $blockType) {
            foreach ($blockType->getCustomFields() as $field) {
                if (!$isSearchable($field)) {
                    continue;
                }

                $handle = "{$neoField->handle}.{$field->handle}";
                $subFields[$handle] = [
                    "id" => $field->id,
                    "name" => "{$neoField->name} → {$field->name}",
                    "handle" => $handle,
                    "type" => (new \ReflectionClass($field))->getShortName(),
                    "readOnly" => false,
                ];
            }
        }

        return $subFields;
    }

    public function actionEntryTypes(): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $sectionIds = array_map(
            "intval",
            (array) Craft::$app->getRequest()->getParam("sectionId", [])
        );

        $sections = Craft::$app->getEntries()->getAllSections();

        if (!empty($sectionIds)) {
            $sections = array_filter(
                $sections,
                fn($section) => in_array($section->id, $sectionIds)
            );
        }

        $entryTypes = [];

        foreach ($sections as $section) {
            foreach ($section->getEntryTypes() as $type) {
                $entryTypes[] = [
                    "id" => $type->id,
                    "name" => $type->name,
                    "handle" => $type->handle,
                    "sectionId" => $section->id,
                    "sectionName" => $section->name,
                ];
            }
        }

        return $this->asJson([
            "success" => true,
            "entryTypes" => $entryTypes,
        ]);
    }
}
