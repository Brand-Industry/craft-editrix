<?php

namespace brandindustry\editrix;

use yii\base\Event;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\helpers\UrlHelper;

use brandindustry\editrix\models\Settings;
use brandindustry\editrix\services\SearchService;
use brandindustry\editrix\services\ReplaceService;
use brandindustry\editrix\services\LogService;
use brandindustry\editrix\services\LicenseService;

/**
 * Editrix - The safest way to update content at scale in Craft CMS
 *
 * @property SearchService $search
 * @property ReplaceService $replace
 * @property LogService $log
 * @property LicenseService $license
 */

class Editrix extends Plugin
{
    public static Editrix $plugin;

    public string $schemaVersion = "1.0.0";
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public string $edition = self::EDITION_STANDARD;

    public const EDITION_STANDARD = "standard";
    public const EDITION_PRO = "pro";

    public static function editions(): array
    {
        return [self::EDITION_STANDARD, self::EDITION_PRO];
    }

    public static function requireEdition(string $edition): void
    {
        $plugin = self::$plugin ?? null;
        if (!$plugin) {
            return;
        }
        if (!$plugin->isEdition($edition)) {
            throw new \yii\web\ForbiddenHttpException(
                "Requires {$edition} edition."
            );
        }
    }

    public static function getInstance(): ?self
    {
        return self::$plugin ?? null;
    }

    public function isEdition(string $edition): bool
    {
        if (
            !empty($this->edition) &&
            in_array(
                $this->edition,
                [self::EDITION_STANDARD, self::EDITION_PRO],
                true
            )
        ) {
            return $this->edition === $edition;
        }
        return $this->license->isEdition($edition);
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            "search" => SearchService::class,
            "replace" => ReplaceService::class,
            "log" => LogService::class,
            "license" => LicenseService::class,
        ]);

        $this->installCpEventListeners();

        Craft::info(
            Craft::t("editrix", "{name} plugin loaded ({edition} edition)", [
                "name" => $this->name,
                "edition" => $this->license->getEdition(),
            ]),
            __METHOD__
        );
    }

    protected function installCpEventListeners(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                // Craft::debug(
                //     "UrlManager::EVENT_REGISTER_CP_URL_RULES",
                //     __METHOD__
                // );
                $event->rules = array_merge(
                    $event->rules,
                    $this->customAdminCpRoutes()
                );
            }
        );

        if (Craft::$app->getEdition() === Craft::Pro) {
            Event::on(
                UserPermissions::class,
                UserPermissions::EVENT_REGISTER_PERMISSIONS,
                function (RegisterUserPermissionsEvent $event) {
                    $event->permissions[] = [
                        "heading" => Craft::t("editrix", "Editrix"),
                        "permissions" => $this->getPluginPermissions(),
                    ];
                }
            );
        }
    }

    private function getPluginPermissions(): array
    {
        return [
            "editrix:search" => [
                "label" => Craft::t("editrix", "Search content"),
            ],
            "editrix:replace" => [
                "label" => Craft::t("editrix", "Search and replace content"),
                "nested" => [
                    "editrix:search" => [
                        "label" => Craft::t("editrix", "Search content"),
                    ],
                ],
            ],
            "editrix:revert" => [
                "label" => Craft::t("editrix", "Revert changes from logs"),
            ],
            "editrix:deleteLogs" => [
                "label" => Craft::t("editrix", "Delete logs"),
            ],
            "editrix:export" => [
                "label" => Craft::t("editrix", "Export logs"),
            ],
        ];
    }

    public function userCan(string $permission): bool
    {
        if (Craft::$app->getEdition() !== Craft::Pro) {
            return Craft::$app->getUser()->getIsAdmin();
        }

        return Craft::$app->getUser()->checkPermission($permission);
    }

    public function hasFeature(string $feature): bool
    {
        return $this->license->hasFeature($feature);
    }

    public function getEdition(): string
    {
        if (!empty($this->edition)) {
            return $this->edition;
        }
        return $this->license->getEdition();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app
            ->getResponse()
            ->redirect(UrlHelper::cpUrl("editrix/settings"));
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item["label"] = "Editrix";
        $item["subnav"] = [
            "new" => [
                "label" => Craft::t("editrix", "New Operation"),
                "url" => "editrix/search",
            ],
            "logs" => [
                "label" => Craft::t("editrix", "Logs & History"),
                "url" => "editrix/logs",
            ],
            "settings" => [
                "label" => Craft::t("editrix", "Settings"),
                "url" => "editrix/settings",
            ],
        ];

        return $item;
    }

    protected function customAdminCpRoutes(): array
    {
        return [
            "editrix" => "editrix/search/index",
            "editrix/search" => "editrix/search/index",
            "editrix/logs" => "editrix/log/index",
            "editrix/logs/<siteHandle:{handle}>" => "editrix/log/site",
            "editrix/settings" => "editrix/settings/index",

            "editrix/api/search" => "editrix/search/search",
            "editrix/api/replace" => "editrix/replace/execute",
            "editrix/api/preview" => "editrix/replace/preview",
            "editrix/api/logs" => "editrix/log/list",
            "editrix/api/logs/validate-revert/<logId:\d+>" =>
                "editrix/log/validate-revert",
            "editrix/api/logs/revert/<logId:\d+>" => "editrix/log/revert",
            "editrix/api/logs/delete/<logId:\d+>" => "editrix/log/delete",
            "editrix/api/logs/export" => "editrix/log/export",

            "editrix/api/scope/sections" => "editrix/scope/sections",
            "editrix/api/scope/sites" => "editrix/scope/sites",
            "editrix/api/scope/fields" => "editrix/scope/fields",
            "editrix/api/scope/entry-types" => "editrix/scope/entry-types",
        ];
    }
}
