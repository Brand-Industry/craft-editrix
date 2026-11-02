<?php

namespace brandindustry\editrix\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;
use brandindustry\editrix\Editrix;

class SettingsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireAdmin();

        return $this->renderTemplate("editrix/_settings/index", [
            "settings" => Editrix::$plugin->getSettings(),
            "edition" => Editrix::$plugin->getEdition(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $settings = Editrix::$plugin->getSettings();

        $settings->searchEntries = (bool) $request->getBodyParam(
            "searchEntries"
        );
        $settings->searchGlobals = (bool) $request->getBodyParam(
            "searchGlobals"
        );
        $settings->searchMatrix = (bool) $request->getBodyParam("searchMatrix");
        $settings->searchCategories = (bool) $request->getBodyParam(
            "searchCategories"
        );
        $previousRetention = $settings->logRetentionDays;
        $settings->logRetentionDays =
            (int) ($request->getBodyParam("logRetentionDays") ?: 30);
        $settings->bulkConfirmationThreshold =
            (int) ($request->getBodyParam("bulkConfirmationThreshold") ?: 100);
        $settings->showEnvironmentIndicator = (bool) $request->getBodyParam(
            "showEnvironmentIndicator"
        );
        $settings->productionSafeMode = (bool) $request->getBodyParam(
            "productionSafeMode"
        );

        $valid = $settings->validate();

        // A number above the edition cap is refused only when it is a change:
        // a site that moved to a smaller edition can save its other settings
        // and keep the logs it already has.
        $retentionCap = Editrix::$plugin->license->getLimit("logRetentionDays");
        if (
            $settings->logRetentionDays > $retentionCap &&
            $settings->logRetentionDays !== $previousRetention
        ) {
            $settings->addError(
                "logRetentionDays",
                Craft::t(
                    "editrix",
                    "Your edition keeps logs for up to {days} days.",
                    ["days" => $retentionCap]
                )
            );
            $valid = false;
        }

        if (!$valid) {
            Craft::$app
                ->getSession()
                ->setError(Craft::t("editrix", 'Couldn\'t save settings.'));
            return $this->renderTemplate("editrix/_settings/index", [
                "settings" => $settings,
                "edition" => Editrix::$plugin->getEdition(),
            ]);
        }

        if (
            !Craft::$app
                ->getPlugins()
                ->savePluginSettings(Editrix::$plugin, $settings->toArray())
        ) {
            Craft::$app
                ->getSession()
                ->setError(Craft::t("editrix", 'Couldn\'t save settings.'));
            return $this->renderTemplate("editrix/_settings/index", [
                "settings" => $settings,
                "edition" => Editrix::$plugin->getEdition(),
            ]);
        }

        Craft::$app
            ->getSession()
            ->setNotice(Craft::t("editrix", "Settings saved."));

        return $this->redirectToPostedUrl();
    }
}
