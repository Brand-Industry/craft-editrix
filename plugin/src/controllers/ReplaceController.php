<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\services\LicenseService;

class ReplaceController extends Controller
{
    public function actionExecute(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:replace")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $request = Craft::$app->getRequest();
        $searchQuery = $request->getBodyParam("searchQuery", "");
        $replaceWith = $request->getBodyParam("replaceWith", "");
        $selectedResults = $request->getBodyParam("selectedResults", []);
        $siteId = $request->getBodyParam("siteId");
        $useRegex = $this->parseBoolean(
            $request->getBodyParam("useRegex", false)
        );
        $caseInsensitive = $this->parseBoolean(
            $request->getBodyParam("caseInsensitive", false)
        );
        $wholeWords = $this->parseBoolean(
            $request->getBodyParam("wholeWords", false)
        );

        $licenseError = $this->checkRegexWholeWordsLicense(
            $useRegex,
            $wholeWords
        );
        if ($licenseError !== null) {
            return $this->asJson($licenseError);
        }

        if (is_string($selectedResults)) {
            $selectedResults = json_decode($selectedResults, true) ?? [];
        }

        if (empty($selectedResults)) {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t("editrix", "No results selected"),
            ]);
        }

        $confirmationError = $this->checkSafetyConfirmation(
            count($selectedResults),
            $request->getBodyParam("confirmationCode", "")
        );
        if ($confirmationError !== null) {
            return $this->asJson($confirmationError);
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

            if ($replacedCount > 0) {
                $logSiteId =
                    $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

                Editrix::$plugin->log->createLog(
                    (int) $logSiteId,
                    $searchQuery,
                    $replaceWith,
                    $replacements,
                    $useRegex,
                    !$caseInsensitive,
                    $wholeWords
                );
            }

            return $this->asJson([
                "success" => true,
                "replacedCount" => $replacedCount,
                "message" => Craft::t(
                    "editrix",
                    "Successfully replaced {count} occurrence(s).",
                    [
                        "count" => $replacedCount,
                    ]
                ),
            ]);
        } catch (\Throwable $e) {
            Craft::error(
                "Editrix replace error: " . $e->getMessage(),
                __METHOD__
            );
            return $this->asJson([
                "success" => false,
                "error" => Craft::t("editrix", "Replace failed: {message}", [
                    "message" => $e->getMessage(),
                ]),
            ]);
        }
    }

    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $request = Craft::$app->getRequest();

        $result = $request->getBodyParam("result", []);
        $searchQuery = $request->getBodyParam("searchQuery", "");
        $replaceWith = $request->getBodyParam("replaceWith", "");
        $useRegex = $this->parseBoolean(
            $request->getBodyParam("useRegex", false)
        );
        $caseInsensitive = $this->parseBoolean(
            $request->getBodyParam("caseInsensitive", false)
        );
        $wholeWords = $this->parseBoolean(
            $request->getBodyParam("wholeWords", false)
        );

        $licenseError = $this->checkRegexWholeWordsLicense(
            $useRegex,
            $wholeWords
        );
        if ($licenseError !== null) {
            return $this->asJson($licenseError);
        }

        if (is_string($result)) {
            $result = json_decode($result, true) ?? [];
        }

        if (empty($result) || (string) ($result["fieldValue"] ?? "") === "") {
            return $this->asJson([
                "success" => false,
                "error" => "Invalid result data",
            ]);
        }

        $originalValue = $result["fieldValue"];
        $newValue = Editrix::$plugin->replace->computeNewValue(
            $result,
            $originalValue,
            $searchQuery,
            $replaceWith,
            $useRegex,
            !$caseInsensitive,
            $wholeWords
        );

        return $this->asJson([
            "success" => true,
            "original" => $originalValue,
            "proposed" => $newValue,
            "changed" => $originalValue !== $newValue,
        ]);
    }

    /**
     * Mirrors SearchController::actionSearch()'s regex/whole-words license
     * check - Search already blocks these by edition, so Replace needs the
     * same server-side gate rather than relying on the UI not offering them.
     */
    private function checkRegexWholeWordsLicense(
        bool $useRegex,
        bool $wholeWords
    ): ?array {
        $license = Editrix::$plugin->license;

        if ($useRegex && !$license->hasFeature(LicenseService::FEATURE_REGEX)) {
            return [
                "success" => false,
                "error" => Craft::t(
                    "editrix",
                    "Regex is only available in Pro edition"
                ),
                "upgradeRequired" => true,
            ];
        }

        if (
            $wholeWords &&
            !$license->hasFeature(LicenseService::FEATURE_WHOLE_WORDS)
        ) {
            return [
                "success" => false,
                "error" => Craft::t(
                    "editrix",
                    "Whole words matching requires Standard or Pro edition"
                ),
                "upgradeRequired" => true,
            ];
        }

        return null;
    }

    /**
     * The Safety settings (bulk threshold, production safe mode) exist to
     * make a large or production replace a deliberate act, not a security
     * boundary - so this is enforced here (not just in the UI) to make
     * sure a direct API call can't skip the same "type REPLACE" step a
     * user would hit in the CP.
     */
    private function checkSafetyConfirmation(
        int $resultCount,
        string $confirmationCode
    ): ?array {
        $settings = Editrix::$plugin->getSettings();

        $overThreshold =
            $resultCount > $settings->bulkConfirmationThreshold;
        $productionRisk =
            $settings->productionSafeMode && Craft::$app->env === "production";

        if (!$overThreshold && !$productionRisk) {
            return null;
        }

        if (strcasecmp(trim($confirmationCode), "REPLACE") === 0) {
            return null;
        }

        return [
            "success" => false,
            "needsConfirmation" => true,
            "error" => Craft::t(
                "editrix",
                'Type "REPLACE" to confirm this action.'
            ),
        ];
    }

    private function parseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool) $value;
    }
}
