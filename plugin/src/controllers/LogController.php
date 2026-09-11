<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use yii\web\Response;
use brandindustry\editrix\Editrix;
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

        if ($log["status"] === "reverted") {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t(
                    "editrix",
                    "This operation has already been reverted"
                ),
            ]);
        }

        $replacements = json_decode($log["replacements"], true) ?? [];
        $validation = Editrix::$plugin->replace->validateRevert($replacements);

        $safeCount = count(
            array_filter($validation, fn($v) => $v["status"] === "safe")
        );
        $modifiedCount = count(
            array_filter($validation, fn($v) => $v["status"] === "modified")
        );
        $missingCount = count(
            array_filter($validation, fn($v) => $v["status"] === "missing")
        );

        return $this->asJson([
            "success" => true,
            "safe" => $modifiedCount === 0 && $missingCount === 0,
            "total" => count($validation),
            "safeCount" => $safeCount,
            "modifiedCount" => $modifiedCount,
            "missingCount" => $missingCount,
            "details" => $validation,
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

        if ($log["status"] === "reverted") {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t(
                    "editrix",
                    "This operation has already been reverted"
                ),
            ]);
        }

        $replacements = json_decode($log["replacements"], true) ?? [];
        $force = (bool) Craft::$app->getRequest()->getBodyParam("force", false);

        if (!$force) {
            $validation = Editrix::$plugin->replace->validateRevert(
                $replacements
            );
            $modifiedCount = count(
                array_filter($validation, fn($v) => $v["status"] === "modified")
            );
            $missingCount = count(
                array_filter($validation, fn($v) => $v["status"] === "missing")
            );

            if ($modifiedCount > 0 || $missingCount > 0) {
                return $this->asJson([
                    "success" => false,
                    "needsConfirmation" => true,
                    "modifiedCount" => $modifiedCount,
                    "missingCount" => $missingCount,
                    "error" => Craft::t(
                        "editrix",
                        "{count} element(s) have been modified since the replacement. Use force revert to proceed.",
                        [
                            "count" => $modifiedCount + $missingCount,
                        ]
                    ),
                ]);
            }
        }

        $revertedCount = 0;

        foreach ($replacements as $replacement) {
            if (Editrix::$plugin->replace->revert($replacement)) {
                $revertedCount++;
            }
        }

        if ($revertedCount > 0) {
            Editrix::$plugin->log->markAsReverted($logId);
        }

        return $this->asJson([
            "success" => true,
            "revertedCount" => $revertedCount,
            "message" => Craft::t(
                "editrix",
                "Successfully reverted {count} change(s).",
                [
                    "count" => $revertedCount,
                ]
            ),
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
