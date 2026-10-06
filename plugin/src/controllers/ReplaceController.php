<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\services\LicenseService;
use brandindustry\editrix\jobs\ReplaceJob;
use brandindustry\editrix\services\ReplaceService;

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
        $preserveCase = $this->parseBoolean(
            $request->getBodyParam("preserveCase", false)
        );
        // Variants of different case can only be found, and so kept, when
        // the search ignores case.
        $caseInsensitive = $caseInsensitive || $preserveCase;

        $licenseError = $this->checkRegexWholeWordsLicense(
            $useRegex,
            $wholeWords,
            $preserveCase
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

        $objections = ReplaceService::objections(
            $searchQuery,
            $replaceWith
        );
        if ($objections !== []) {
            return $this->asJson([
                "success" => false,
                "error" => implode(" ", $objections),
            ]);
        }

        $rowLimit = Editrix::$plugin->replace->currentRowLimit();
        if (count($selectedResults) > $rowLimit) {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t(
                    "editrix",
                    "A replace can change at most {limit} rows; you selected {count}. Select fewer, or narrow the search.",
                    ["limit" => $rowLimit, "count" => count($selectedResults)]
                ),
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
            $userId = (int) Craft::$app->getUser()->getId();
            $logSiteId = (int) ($siteId ?? Craft::$app->getSites()->getCurrentSite()->id);
            $searchId = (string) $request->getBodyParam("searchId", "");

            // The log exists before anything is changed; every value is
            // written to it before its entry is saved.
            $logId = Editrix::$plugin->log->beginReplaceLog(
                $logSiteId,
                $searchQuery,
                $replaceWith,
                $useRegex,
                !$caseInsensitive,
                $wholeWords
            );

            if (!$logId) {
                return $this->asJson([
                    "success" => false,
                    "error" => "Permission denied",
                ]);
            }

            $threshold = Editrix::$plugin->getSettings()->replaceQueueThreshold;

            if (count($selectedResults) > $threshold) {
                // Too many to save inside one request: do it in the queue.
                Editrix::$plugin->log->setTotal($logId, count($selectedResults));

                Craft::$app->getQueue()->push(new ReplaceJob([
                    "logId" => $logId,
                    "rows" => array_values($selectedResults),
                    "query" => $searchQuery,
                    "replaceWith" => $replaceWith,
                    "useRegex" => $useRegex,
                    "caseSensitive" => !$caseInsensitive,
                    "wholeWords" => $wholeWords,
                    "preserveCase" => $preserveCase,
                    "userId" => $userId,
                    "searchId" => $searchId,
                ]));

                return $this->asJson([
                    "success" => true,
                    "queued" => true,
                    "logId" => $logId,
                    "total" => count($selectedResults),
                ]);
            }

            $summary = Editrix::$plugin->replace->run(
                $logId,
                array_values($selectedResults),
                $searchQuery,
                $replaceWith,
                $useRegex,
                !$caseInsensitive,
                $wholeWords,
                $userId,
                $searchId,
                null,
                null,
                false,
                $preserveCase
            );

            return $this->asJson($this->replaceResponse($summary));
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
        $preserveCase = $this->parseBoolean(
            $request->getBodyParam("preserveCase", false)
        );
        // Variants of different case can only be found, and so kept, when
        // the search ignores case.
        $caseInsensitive = $caseInsensitive || $preserveCase;

        $licenseError = $this->checkRegexWholeWordsLicense(
            $useRegex,
            $wholeWords,
            $preserveCase
        );
        if ($licenseError !== null) {
            return $this->asJson($licenseError);
        }

        if (is_string($result)) {
            $result = json_decode($result, true) ?? [];
        }

        if (empty($result) || empty($result["elementId"]) || empty($result["fieldHandle"])) {
            return $this->asJson([
                "success" => false,
                "error" => "Invalid result data",
            ]);
        }

        // A tag row still carries its own (short) value; every other row
        // has only a fingerprint, so the text is read from the element.
        if (isset($result["fieldValue"]) && ($result["readOnlyReason"] ?? null) === "tag") {
            $originalValue = (string) $result["fieldValue"];
            $stale = false;
        } else {
            if (!Editrix::$plugin->replace->isReplaceableField($result)) {
                return $this->asJson([
                    "success" => false,
                    "error" => "Invalid result data",
                ]);
            }

            $originalValue = Editrix::$plugin->replace->currentValueFor($result);
            if ($originalValue === null) {
                return $this->asJson([
                    "success" => false,
                    "error" => Craft::t("editrix", "This content no longer exists."),
                ]);
            }
            $stale = !Editrix::$plugin->replace->matchesSearchSnapshot($result, $originalValue);
        }

        $newValue = Editrix::$plugin->replace->computeNewValue(
            $result,
            $originalValue,
            $searchQuery,
            $replaceWith,
            $useRegex,
            !$caseInsensitive,
            $wholeWords,
            $preserveCase
        );

        return $this->asJson([
            "success" => true,
            "original" => $originalValue,
            "proposed" => $newValue,
            "changed" => $originalValue !== $newValue,
            // The field was edited after the search, so this is not what
            // the user saw in the results.
            "stale" => $stale,
        ]);
    }

    /**
     * Mirrors SearchController::actionSearch()'s regex/whole-words license
     * check - Search already blocks these by edition, so Replace needs the
     * same server-side gate rather than relying on the UI not offering them.
     */
    private function checkRegexWholeWordsLicense(
        bool $useRegex,
        bool $wholeWords,
        bool $preserveCase = false
    ): ?array {
        $license = Editrix::$plugin->license;

        if ($preserveCase && !$license->hasFeature(LicenseService::FEATURE_PRESERVE_CASE)) {
            return [
                "success" => false,
                "error" => Craft::t(
                    "editrix",
                    "Preserving case is only available in Pro edition"
                ),
                "upgradeRequired" => true,
            ];
        }

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

    /** The JSON a finished replace sends back, inline or from the queue. */
    private function replaceResponse(array $summary): array
    {
        return [
            "success" => true,
            "logId" => $summary["logId"] ?? null,
            "cancelled" => $summary["cancelled"] ?? false,
            "replacedCount" => $summary["replacedCount"],
            "replacedMatches" => $summary["replacedMatches"],
            "replacedKeys" => $summary["replacedKeys"],
            "skipped" => $summary["skipped"],
            "failed" => $summary["failed"],
            "message" => Craft::t(
                "editrix",
                "Successfully replaced {count} occurrence(s).",
                ["count" => $summary["replacedMatches"]]
            ),
        ];
    }

    /**
     * Progress of a replace that runs in the queue, and its summary once it
     * has finished.
     */
    public function actionStatus(int $logId): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:replace")) {
            return $this->asJson(["success" => false, "error" => "Permission denied"]);
        }

        $log = Editrix::$plugin->log->getLogById($logId);

        if (!$log || (int) $log["userId"] !== (int) Craft::$app->getUser()->getId()) {
            return $this->asJson(["success" => false, "error" => "Replace not found"]);
        }

        $counts = Editrix::$plugin->log->itemCounts($logId);
        $running = in_array($log["status"], ["running", "cancelling"], true);

        $response = [
            "success" => true,
            "status" => $log["status"],
            "total" => Editrix::$plugin->log->getTotal($logId),
            "done" => array_sum($counts) - ($counts["reverted"] ?? 0),
            "replaced" => (int) ($counts["applied"] ?? 0),
            "idleSeconds" => max(0, time() - \craft\helpers\DateTimeHelper::toDateTime($log["dateUpdated"])->getTimestamp()),
        ];

        if (!$running) {
            $response += $this->replaceResponse(
                ["logId" => $logId, "cancelled" => $log["status"] === "cancelled"] +
                    Editrix::$plugin->replace->summaryFromLog($logId)
            );
        }

        return $this->asJson($response);
    }

    public function actionCancel(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:replace")) {
            return $this->asJson(["success" => false, "error" => "Permission denied"]);
        }

        $logId = (int) Craft::$app->getRequest()->getBodyParam("logId", 0);
        $log = Editrix::$plugin->log->getLogById($logId);

        if ($log && (int) $log["userId"] === (int) Craft::$app->getUser()->getId() && $log["status"] === "running") {
            Editrix::$plugin->log->finishReplaceLog($logId, "cancelling", (int) $log["replacementCount"]);
        }

        return $this->asJson(["success" => true]);
    }
}
