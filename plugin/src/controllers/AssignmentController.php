<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use brandindustry\editrix\Editrix;
use brandindustry\editrix\services\LicenseService;

/**
 * Category/tag assignment search - "which entries have this category/tag?",
 * not a text search. See AssignmentService.
 */
class AssignmentController extends Controller
{
    public function actionCategories(): Response
    {
        return $this->search("category");
    }

    public function actionTags(): Response
    {
        return $this->search("tag");
    }

    private function search(string $type): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Editrix::$plugin->userCan("editrix:search")) {
            return $this->asJson([
                "success" => false,
                "error" => "Permission denied",
            ]);
        }

        $license = Editrix::$plugin->license;
        if (!$license->hasFeature(LicenseService::FEATURE_ASSIGNMENT_SEARCH)) {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t(
                    "editrix",
                    "Category & tag assignment search requires Pro edition"
                ),
                "upgradeRequired" => true,
            ]);
        }

        $request = Craft::$app->getRequest();
        $query = trim((string) $request->getBodyParam("query", ""));
        $siteId = $request->getBodyParam("siteId");
        $sections = $request->getBodyParam("sections", []);

        if ($query === "") {
            return $this->asJson([
                "success" => false,
                "error" => Craft::t("editrix", "Search query is required"),
            ]);
        }

        $siteId = $siteId !== null ? (int) $siteId : null;
        $assignmentService = Editrix::$plugin->assignment;

        $results =
            $type === "category"
                ? $assignmentService->searchCategoryAssignments(
                    $query,
                    $siteId,
                    $sections
                )
                : $assignmentService->searchTagAssignments(
                    $query,
                    $siteId,
                    $sections
                );

        return $this->asJson([
            "success" => true,
            "results" => $results,
        ]);
    }
}
