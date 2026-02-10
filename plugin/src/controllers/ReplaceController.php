<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\services\LicenseService;

class ReplaceController extends Controller
{
    /**
     * Execute replacement
     */
    public function actionExecute(): Response
    {
        // Enforce minimum edition for replacements (Standard or Pro)
        try {
            Editrix::requireEdition(Editrix::EDITION_STANDARD);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan('editrix:replace')) {
            return $this->asJson([
                'success' => false,
                'error' => 'Permission denied',
            ]);
        }

        $request = Craft::$app->getRequest();
        $license = Editrix::$plugin->license;

        // Check operation limit for Lite
        if (!$license->canPerformOperation()) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('editrix', 'Monthly operation limit reached. Upgrade to Standard for unlimited operations.'),
                'upgradeRequired' => true,
            ]);
        }

        $searchQuery = $request->getBodyParam('searchQuery', '');
        $replaceWith = $request->getBodyParam('replaceWith', '');
        $selectedResults = $request->getBodyParam('selectedResults', []);
        $siteId = $request->getBodyParam('siteId');
        $useRegex = $this->parseBoolean($request->getBodyParam('useRegex', false));
        $caseInsensitive = $this->parseBoolean($request->getBodyParam('caseInsensitive', false));
        $wholeWords = $this->parseBoolean($request->getBodyParam('wholeWords', false));

        // Parse selected results if JSON string
        if (is_string($selectedResults)) {
            $selectedResults = json_decode($selectedResults, true) ?? [];
        }

        if (empty($selectedResults)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('editrix', 'No results selected'),
            ]);
        }

        try {
            $replacements = Editrix::$plugin->replace->replace(
                $selectedResults,
                $searchQuery,
                $replaceWith,
                $useRegex,
                !$caseInsensitive,
                $wholeWords
            );

            $replacedCount = count($replacements);

            // Create log
            if ($replacedCount > 0) {
                $logSiteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
                
                Editrix::$plugin->log->createLog(
                    (int)$logSiteId,
                    $searchQuery,
                    $replaceWith,
                    $replacements,
                    $useRegex,
                    !$caseInsensitive,
                    $wholeWords
                );
            }

            return $this->asJson([
                'success' => true,
                'replacedCount' => $replacedCount,
                'message' => Craft::t('editrix', 'Successfully replaced {count} occurrence(s).', [
                    'count' => $replacedCount,
                ]),
            ]);

        } catch (\Throwable $e) {
            Craft::error('Editrix replace error: ' . $e->getMessage(), __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('editrix', 'Replace failed: {message}', ['message' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * Preview a single replacement (for diff view)
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan('editrix:search')) {
            return $this->asJson([
                'success' => false,
                'error' => 'Permission denied',
            ]);
        }

        $request = Craft::$app->getRequest();

        $result = $request->getBodyParam('result', []);
        $searchQuery = $request->getBodyParam('searchQuery', '');
        $replaceWith = $request->getBodyParam('replaceWith', '');
        $useRegex = $this->parseBoolean($request->getBodyParam('useRegex', false));
        $caseInsensitive = $this->parseBoolean($request->getBodyParam('caseInsensitive', false));

        if (is_string($result)) {
            $result = json_decode($result, true) ?? [];
        }

        if (empty($result) || empty($result['fieldValue'])) {
            return $this->asJson([
                'success' => false,
                'error' => 'Invalid result data',
            ]);
        }

        $originalValue = $result['fieldValue'];
        $newValue = $this->performPreviewReplacement(
            $originalValue,
            $searchQuery,
            $replaceWith,
            $useRegex,
            !$caseInsensitive
        );

        return $this->asJson([
            'success' => true,
            'original' => $originalValue,
            'proposed' => $newValue,
            'changed' => $originalValue !== $newValue,
        ]);
    }

    /**
     * Preview replacement without saving
     */
    private function performPreviewReplacement(
        string $value,
        string $query,
        string $replaceWith,
        bool $useRegex,
        bool $caseSensitive
    ): string {
        if ($useRegex) {
            $flags = $caseSensitive ? '' : 'i';
            $pattern = "/{$query}/{$flags}";
            return @preg_replace($pattern, $replaceWith, $value) ?? $value;
        }

        if ($caseSensitive) {
            return str_replace($query, $replaceWith, $value);
        }

        $pattern = '/' . preg_quote($query, '/') . '/i';
        return preg_replace($pattern, $replaceWith, $value);
    }

    /**
     * Parse boolean from various input formats
     */
    private function parseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool)$value;
    }
}
