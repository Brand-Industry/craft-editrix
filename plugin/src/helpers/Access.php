<?php

namespace brandindustry\editrix\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\base\BlockElementInterface;
use craft\elements\User;

/**
 * Holds a search or a replace to Craft's own permissions.
 *
 * The Editrix permissions say who may use the tool; they say nothing about
 * which entries. Without this, someone allowed to search and replace could
 * read and change a section Craft would not let them open.
 *
 * A user id of null means nothing is checked: the caller is not acting for
 * a user (tests, an internal call). The command line passes the user it logs
 * under, so a named user is held to their permissions there too.
 */
class Access
{
    /** @var array<int, User|false> */
    private static array $users = [];

    public static function canView(ElementInterface $element, ?int $userId): bool
    {
        return self::allowed($element, $userId, false);
    }

    public static function canSave(ElementInterface $element, ?int $userId): bool
    {
        return self::allowed($element, $userId, true);
    }

    /** For tests: forget the users read so far. */
    public static function flush(): void
    {
        self::$users = [];
    }

    private static function allowed(ElementInterface $element, ?int $userId, bool $save): bool
    {
        if (!$userId) {
            return true;
        }

        $user = self::$users[$userId] ??= Craft::$app->getUsers()->getUserById($userId) ?? false;

        // A user who no longer exists is not allowed to do anything.
        if (!$user) {
            return false;
        }

        if ($user->admin) {
            return true;
        }

        $root = self::root($element);

        return $save ? $root->canSave($user) : $root->canView($user);
    }

    /**
     * What Craft's permissions are about: a Matrix or Neo block is as
     * editable as the entry that owns it.
     */
    private static function root(ElementInterface $element): ElementInterface
    {
        for ($depth = 0; $depth < 10; $depth++) {
            $owner = null;

            if ($element instanceof BlockElementInterface) {
                $owner = $element->getOwner();
            } elseif (method_exists($element, "getOwner")) {
                $owner = $element->getOwner();
            }

            if (!$owner instanceof ElementInterface) {
                break;
            }

            $element = $owner;
        }

        return $element;
    }
}
