<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use yii\web\Response;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\jobs\RevertJob;
use brandindustry\editrix\assetbundles\EditrixLogsAsset;
use brandindustry\editrix\services\LicenseService;

class LogController extends Controller
{
    public function actionIndex(): Response
    {
        if (!Editrix::$plugin->userCan("editrix:search")) {
            throw new \yii\web\ForbiddenHttpException(
                "You do not have permission to view logs."
            );
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(EditrixLogsAsset::class);

        $config = $this->buildJsConfig();
        $view->registerJs(
            "window.EditrixConfig = " . json_encode($config) . ";",
            View::POS_HEAD
        );

        return $this->renderTemplate("editrix/_logs/index");
    }

    public function actionList(): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $request = Craft::$app->getRequest();

        $filters = [
            "siteId" => $request->getParam("siteId"),
            "userId" => $request->getParam("userId"),
            "type" => $request->getParam("type"),
            "status" => $request->getParam("status"),
            "limit" => (int) $request->getParam("limit", 20),
            "offset" => (int) $request->getParam("offset", 0),
        ];

        $logs = Editrix::$plugin->log->getLogs($filters);
        $total = Editrix::$plugin->log->getLogsCount($filters);

        $formattedLogs = array_map(function ($log) {
            return [
                "id" => $log["id"],
                "date" => $log["dateCreated"],
                "relativeDate" => $this->getRelativeDate($log["dateCreated"]),
                "type" => $log["type"] ?? "replace",
                "userId" => $log["userId"],
                "username" => $log["username"] ?? "Unknown",
                "userFullName" =>
                    trim(
                        ($log["firstName"] ?? "") .
                            " " .
                            ($log["lastName"] ?? "")
                    ) ?:
                    null,
                "siteId" => $log["siteId"],
                "siteName" => $log["siteName"] ?? "Unknown",
                "searchQuery" => $log["searchQuery"],
                "replaceWith" => $log["replaceWith"],
                "useRegex" => (bool) $log["useRegex"],
                "caseSensitive" => (bool) $log["caseSensitive"],
                "wholeWords" => (bool) $log["wholeWords"],
                "scope" => json_decode($log["scope"] ?? "", true),
                "count" => (int) $log["replacementCount"],
                "status" => $log["status"],
                "revertedAt" => $log["revertedAt"],
            ];
        }, $logs);

        return $this->asJson([
            "success" => true,
            "logs" => $formattedLogs,
            "total" => $total,
            "limit" => $filters["limit"],
            "offset" => $filters["offset"],
        ]);
    }

    public function actionDailyCounts(): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $request = Craft::$app->getRequest();
        $days = (int) $request->getParam("days", 14);
        $siteId = $request->getParam("siteId");

        $days = min(max($days, 1), 90);

        $counts = Editrix::$plugin->log->getDailyCounts(
            $days,
            $siteId !== null ? (int) $siteId : null
        );

        return $this->asJson([
            "success" => true,
            "days" => $counts,
        ]);
    }

    public function actionValidateRevert(int $logId): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:revert")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $log = Editrix::$plugin->log->getLogById($logId);

        if (!$log) {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t("editrix", "Log entry not found"),
            ]);
        }

        if (in_array($log["status"], ["running", "reverting"], true)) {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t("editrix", "This operation is still running."),
            ]);
        }

        if ($log["status"] === "reverted") {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t(
                    "editrix",
                    "This operation has already been reverted"
                ),
            ]);
        }

        $validation = Editrix::$plugin->replace->validateRevert(
            Editrix::$plugin->log->replacementsFor($log)
        );

        $count = fn(string $status) => count(
            array_filter($validation, fn($v) => $v["status"] === $status)
        );
        $safeCount = $count("safe");
        $unchangedCount = $count("unchanged");
        $modifiedCount = $count("modified");
        $missingCount = $count("missing");

        return $this->asJson([
            "success" => true,
            "safe" => $modifiedCount === 0 && $missingCount === 0,
            "total" => count($validation),
            "safeCount" => $safeCount,
            "unchangedCount" => $unchangedCount,
            "modifiedCount" => $modifiedCount,
            "missingCount" => $missingCount,
            // Only the ones that need a decision, and not all of a large log
            "details" => array_slice(
                array_values(array_filter($validation, fn($v) => !in_array($v["status"], ["safe", "unchanged"], true))),
                0,
                100
            ),
        ]);
    }

    public function actionRevert(int $logId): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:revert")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $log = Editrix::$plugin->log->getLogById($logId);

        if (!$log) {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t("editrix", "Log entry not found"),
            ]);
        }

        if (in_array($log["status"], ["running", "reverting"], true)) {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t("editrix", "This operation is still running."),
            ]);
        }

        if ($log["status"] === "reverted") {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t(
                    "editrix",
                    "This operation has already been reverted"
                ),
            ]);
        }

        $force = (bool) Craft::$app->getRequest()->getBodyParam("force", false);

        // A large revert runs in the queue, for the same reason a large
        // replace does: restoring a value saves an entry.
        $count = Editrix::$plugin->log->revertableCount($log);
        if ($count > Editrix::$plugin->getSettings()->replaceQueueThreshold) {
            Editrix::$plugin->log->finishReplaceLog($logId, "reverting", (int) $log["replacementCount"]);

            Craft::$app->getQueue()->push(new RevertJob([
                "logId" => $logId,
                "force" => $force,
                "userId" => (int) Craft::$app->getUser()->getId(),
            ]));

            return $this->asJson([
                "success" => true,
                "queued" => true,
                "logId" => $logId,
                "total" => $count,
            ]);
        }

        $result = Editrix::$plugin->replace->revertEntries(
            Editrix::$plugin->log->replacementsFor($log),
            $force,
            userId: (int) Craft::$app->getUser()->getId(),
            logId: $logId
        );

        $left = count($result["conflicts"]) + count($result["missing"]) + count($result["failed"]);

        // Closes the log: reverted, or partial when some values were left
        // alone (it can be reverted again, or forced, for what remains).
        Editrix::$plugin->replace->finishRevert($logId, $result, (int) Craft::$app->getUser()->getId());

        return $this->asJson([
            "success" => true,
            "revertedCount" => $result["reverted"],
            "unchangedCount" => $result["unchanged"],
            "conflictCount" => count($result["conflicts"]),
            "missingCount" => count($result["missing"]),
            "failedCount" => count($result["failed"]),
            "needsForce" => $result["conflicts"] !== [],
            "conflicts" => array_slice($result["conflicts"], 0, 20),
            "failed" => array_slice($result["failed"], 0, 20),
            "message" => $left === 0
                ? Craft::t("editrix", "Successfully reverted {count} change(s).", ["count" => $result["reverted"]])
                : Craft::t(
                    "editrix",
                    "Reverted {count} change(s). {left} were left as they are because they were edited since.",
                    ["count" => $result["reverted"], "left" => $left]
                ),
        ]);
    }

    /**
     * Progress of a revert that runs in the queue.
     */
    public function actionRevertStatus(int $logId): Response
    {
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:revert")) {
            return $this->asJson(["success" => false, "error" => "Permission denied"]);
        }

        $log = Editrix::$plugin->log->getLogById($logId);

        if (!$log) {
            return $this->asJson(["success" => false, "error" => Craft::t("editrix", "Log entry not found")]);
        }

        $counts = Editrix::$plugin->log->itemCounts($logId);
        $reverted = (int) ($counts["reverted"] ?? 0);
        $left = (int) (($counts["applied"] ?? 0) + ($counts["pending"] ?? 0));

        return $this->asJson([
            "success" => true,
            "status" => $log["status"],
            "running" => $log["status"] === "reverting",
            "reverted" => $reverted,
            "left" => $left,
            "total" => $reverted + $left,
        ]);
    }

    public function actionDelete(int $logId): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:deleteLogs")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $deleted = Editrix::$plugin->log->deleteLog($logId);

        return $this->asJson([
            "success" => $deleted,
            "message" => $deleted
                ? Craft::t("editrix", "Log entry deleted")
                : Craft::t("editrix", "Failed to delete log entry"),
        ]);
    }

    public function actionExport(): Response
    {
        if (!Editrix::$plugin->userCan("editrix:export")) {
            throw new \yii\web\ForbiddenHttpException("Permission denied");
        }

        $license = Editrix::$plugin->license;
        $request = Craft::$app->getRequest();

        $format = $request->getParam("format", "csv");
        $filters = [
            "siteId" => $request->getParam("siteId"),
            "type" => $request->getParam("type"),
            "status" => $request->getParam("status"),
        ];

        if (
            $format === "json" &&
            !$license->hasFeature(LicenseService::FEATURE_EXPORT_JSON)
        ) {
            return $this->asJson([
                "success" => false,
                "error" => "JSON export requires Pro edition",
            ]);
        }

        if (
            $format !== "json" &&
            !$license->hasFeature(LicenseService::FEATURE_EXPORT_CSV)
        ) {
            return $this->asJson([
                "success" => false,
                "error" => "CSV export requires Pro edition",
            ]);
        }

        if ($format === "json") {
            $content = Editrix::$plugin->log->exportToJson($filters);
            $filename = "editrix-logs-" . date("Y-m-d") . ".json";
            $mimeType = "application/json";
        } else {
            $content = Editrix::$plugin->log->exportToCsv($filters);
            $filename = "editrix-logs-" . date("Y-m-d") . ".csv";
            $mimeType = "text/csv";
        }

        $response = Craft::$app->getResponse();
        $response->content = $content;
        $response->setDownloadHeaders($filename, $mimeType);

        return $response;
    }

    private function getRelativeDate(string $dateString): string
    {
        $date = new \DateTime($dateString);
        $now = new \DateTime();
        $diff = $now->diff($date);

        if ($diff->days === 0) {
            if ($diff->h === 0) {
                return $diff->i <= 1 ? "Just now" : "{$diff->i} minutes ago";
            }
            return $diff->h === 1 ? "1 hour ago" : "{$diff->h} hours ago";
        }

        if ($diff->days === 1) {
            return "Yesterday";
        }

        if ($diff->days < 7) {
            return "{$diff->days} days ago";
        }

        return $date->format("M j, Y");
    }

    private function buildJsConfig(): array
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $users = $this->getActiveUsers();
        $license = Editrix::$plugin->license;

        return [
            "sites" => array_map(
                fn($site) => [
                    "id" => $site->id,
                    "name" => $site->name,
                    "handle" => $site->handle,
                ],
                $sites
            ),
            "users" => $users,
            "currentSiteId" => Craft::$app->getSites()->getCurrentSite()->id,
            "apiUrl" => \craft\helpers\UrlHelper::cpUrl("editrix/api/logs"),
            "dailyCountsUrl" => \craft\helpers\UrlHelper::actionUrl(
                "editrix/log/daily-counts"
            ),
            "validateRevertUrl" => \craft\helpers\UrlHelper::cpUrl(
                "editrix/api/logs/validate-revert"
            ),
            "revertUrl" => \craft\helpers\UrlHelper::cpUrl(
                "editrix/api/logs/revert"
            ),
            "revertStatusUrl" => \craft\helpers\UrlHelper::cpUrl(
                "editrix/api/logs/revert-status"
            ),
            "deleteUrl" => \craft\helpers\UrlHelper::cpUrl(
                "editrix/api/logs/delete"
            ),
            "exportUrl" => \craft\helpers\UrlHelper::cpUrl(
                "editrix/api/logs/export"
            ),
            "searchPageUrl" => \craft\helpers\UrlHelper::cpUrl(
                "editrix/search"
            ),
            "canRevert" => Editrix::$plugin->userCan("editrix:revert"),
            "canDelete" => Editrix::$plugin->userCan("editrix:deleteLogs"),
            "canExport" => Editrix::$plugin->userCan("editrix:export"),
            "license" => $license->getFeaturesConfig(),
            "view" => "logs",
        ];
    }

    private function getActiveUsers(): array
    {
        $users = (new \craft\db\Query())
            ->select(["u.id", "u.username", "u.firstName", "u.lastName"])
            ->from(["u" => "{{%users}}"])
            ->innerJoin(["l" => "{{%editrix_logs}}"], "[[u.id]] = [[l.userId]]")
            ->groupBy(["u.id", "u.username", "u.firstName", "u.lastName"])
            ->all();

        return array_map(
            fn($u) => [
                "id" => $u["id"],
                "username" => $u["username"],
                "name" =>
                    trim($u["firstName"] . " " . $u["lastName"]) ?:
                    $u["username"],
            ],
            $users
        );
    }
}
