<?php

namespace HldsRun\ResourcePublishDate;

/**
 * Add-on wide constants and path helpers.
 *
 * Deliberately tiny and dependency free: this is the only place in the add-on
 * that needs to know where the add-on lives on disk, and the CLI commands plus
 * the option callback all use it.
 */
final class PublishDateAddOn
{
    /** Add-on id. Must match the path under src/addons/ and every class path. */
    public const ADDON_ID = 'HldsRun/ResourcePublishDate';

    /** Translation imported when --file is not given. */
    public const DEFAULT_TRANSLATION = 'ru';

    /** Directory, relative to the add-on root, holding the translation files. */
    public const TRANSLATION_DIRECTORY = '_translations';

    /**
     * Absolute path of the add-on root, or null when it cannot be resolved.
     *
     * \XF::getAddOnDirectory() returns the `src/addons` root without arguments,
     * so the add-on folder is appended here.
     */
    public static function getAddOnDirectory(): ?string
    {
        $addOnsDirectory = \XF::getAddOnDirectory();

        if (!$addOnsDirectory) {
            return null;
        }

        $ds = \XF::$DS;

        return $addOnsDirectory . $ds . 'HldsRun' . $ds . 'ResourcePublishDate';
    }

    /**
     * Absolute path of a translation file, or null when it cannot be resolved.
     *
     * The file name is validated rather than sanitised: a caller-supplied name is
     * never allowed to walk out of the translations directory.
     */
    public static function getTranslationPath(string $fileName): ?string
    {
        $addOnDirectory = self::getAddOnDirectory();

        if ($addOnDirectory === null) {
            return null;
        }

        if (!preg_match('/^[a-z0-9_-]+$/i', $fileName)) {
            return null;
        }

        $ds = \XF::$DS;

        return $addOnDirectory . $ds . self::TRANSLATION_DIRECTORY . $ds . $fileName . '.xml';
    }
}
