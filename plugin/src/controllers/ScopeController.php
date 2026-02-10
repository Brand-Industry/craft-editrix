<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use brandindustry\editrix\Editrix;

class ScopeController extends Controller
{
    /**
     * Get available sections
     */
    public function actionSections(): Response
    {
        $this->requireAcceptsJson();

        $sections = Craft::$app->getSections()->getAllSections();

        $data = array_map(fn($section) => [
            'id' => $section->id,
            'name' => $section->name,
            'handle' => $section->handle,
            'type' => $section->type,
        ], $sections);

        return $this->asJson([
            'success' => true,
            'sections' => array_values($data),
        ]);
    }

    /**
     * Get available sites
     */
    public function actionSites(): Response
    {
        $this->requireAcceptsJson();

        $sites = Craft::$app->getSites()->getAllSites();

        $data = array_map(fn($site) => [
            'id' => $site->id,
            'name' => $site->name,
            'handle' => $site->handle,
            'primary' => $site->primary,
        ], $sites);

        return $this->asJson([
            'success' => true,
            'sites' => array_values($data),
        ]);
    }

    /**
     * Get available fields
     */
    public function actionFields(): Response
    {
        $this->requireAcceptsJson();

        $settings = Editrix::$plugin->getSettings();
        $allFields = Craft::$app->getFields()->getAllFields();

        // Filter to only searchable field types
        $fields = array_filter($allFields, function ($field) use ($settings) {
            return in_array(get_class($field), $settings->searchableFieldTypes);
        });

        $data = array_map(fn($field) => [
            'id' => $field->id,
            'name' => $field->name,
            'handle' => $field->handle,
            'type' => (new \ReflectionClass($field))->getShortName(),
        ], $fields);

        return $this->asJson([
            'success' => true,
            'fields' => array_values($data),
        ]);
    }

    /**
     * Get available entry types
     */
    public function actionEntryTypes(): Response
    {
        $this->requireAcceptsJson();

        $sections = Craft::$app->getSections()->getAllSections();
        $entryTypes = [];

        foreach ($sections as $section) {
            foreach ($section->getEntryTypes() as $type) {
                $entryTypes[] = [
                    'id' => $type->id,
                    'name' => $type->name,
                    'handle' => $type->handle,
                    'sectionId' => $section->id,
                    'sectionName' => $section->name,
                ];
            }
        }

        return $this->asJson([
            'success' => true,
            'entryTypes' => $entryTypes,
        ]);
    }
}
