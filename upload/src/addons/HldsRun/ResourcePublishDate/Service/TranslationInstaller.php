<?php

namespace HldsRun\ResourcePublishDate\Service;

use HldsRun\ResourcePublishDate\PublishDateAddOn;
use XF\Entity\Language;
use XF\Service\AbstractService;
use XF\Service\Phrase\ImportService;

use function count, is_dir, strtolower, substr;

/**
 * Installs the translations shipped in `_translations/`.
 *
 * XenForo has no mechanism for this. It imports `_data/*.xml` as master phrases
 * with language_id 0 and stops there; `_translations/` is never read by anything.
 * Every add-on with translations therefore needs its own importer, and until one
 * runs the add-on is half-English on a Russian forum.
 *
 * Both entry points share this service so there is one implementation of "which
 * translation belongs to which language":
 *
 *  * Setup::postInstall() - automatic, at install time only.
 *  * hlds-run-rpd:import-translation - deliberate, any time.
 *
 * @package HldsRun\ResourcePublishDate
 */
class TranslationInstaller extends AbstractService
{
    /**
     * Translations imported at install time, as a report.
     *
     * Deliberately install-only and never on upgrade. An upgrade re-import would
     * overwrite phrases an administrator has since customised in Admin CP -> Phrases,
     * silently, with no way to recover the old wording.
     *
     * @return array<int, array{code: string, language: string, count: int}>
     */
    public function installForExistingLanguages(): array
    {
        $report = [];

        foreach ($this->getAvailableCodes() as $code) {
            // Everything a translation does happens inside this try, including the
            // lookup. Wrapping only install() looked sufficient and was not: the
            // lookup queries the database, so it can fail exactly like the import
            // can, and an uncaught throw here aborts the install batch.
            try {
                $language = $this->findLanguageForCode($code);

                // The board has no language this translation is for. That is the
                // common case on an English-only board, and it is not an error.
                if (!$language) {
                    continue;
                }

                $report[] = [
                    'code' => $code,
                    'language' => $language->title,
                    'count' => $this->install($code, $language),
                ];
            } catch (\Throwable $e) {
                // A translation must never be able to fail an install. Log it and
                // carry on with the next language; the CLI remains available.
                \XF::logError('[ResourcePublishDate] Could not import the ' . $code
                    . ' translation: ' . $e->getMessage());
            }
        }

        return $report;
    }

    /**
     * Translation codes present in `_translations/`, without the extension.
     *
     * @return string[]
     */
    public function getAvailableCodes(): array
    {
        $directory = PublishDateAddOn::getAddOnDirectory();
        if ($directory === null) {
            return [];
        }

        $path = $directory . \XF::$DS . PublishDateAddOn::TRANSLATION_DIRECTORY;
        if (!is_dir($path)) {
            return [];
        }

        $codes = [];
        foreach ((array) glob($path . \XF::$DS . '*.xml') as $file) {
            $codes[] = basename($file, '.xml');
        }

        return $codes;
    }

    /**
     * The board language a translation code refers to, or null.
     *
     * Matching is on the code and its region suffix, because XenForo's own packs
     * use the suffixed form: the Russian language pack ships language_code="ru-RU",
     * not "ru". An equality test against the file name would therefore match
     * nothing at all, and the import would report success while importing nothing.
     *
     * @return Language|null
     */
    public function findLanguageForCode(string $code)
    {
        // The entity manager has no findAll(). XF\Finder is the way to get a
        // collection of every row, and this is what the CLI listing already used.
        $languages = \XF::finder(Language::class)->order('title')->fetch();
        $wanted = strtolower($code);
        $regional = null;

        foreach ($languages as $language) {
            $languageCode = strtolower((string) $language->language_code);

            // Exact match wins outright, so a board carrying both "ru" and
            // "ru-RU" gets a deterministic answer rather than whichever row the
            // finder happened to return first.
            if ($languageCode === $wanted) {
                return $language;
            }

            // strlen(), not count(): $code is a string, and count() on one is a
            // TypeError on PHP 8. That threw out of the regional-match check on
            // every non-exact code, so a board whose language is ru-RU against a
            // ru.xml matched nothing, and the install reported success having
            // imported nothing.
            if ($regional === null
                && substr($languageCode, 0, strlen($code) + 1) === $wanted . '-'
            ) {
                $regional = $language;
            }
        }

        return $regional;
    }

    /**
     * Imports one translation into one language. Returns the phrase count.
     *
     * @return int
     */
    public function install(string $code, Language $language): int
    {
        $path = PublishDateAddOn::getTranslationPath($code);
        if ($path === null || !is_file($path)) {
            throw new \RuntimeException('Translation file ' . $code . '.xml is missing');
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new \RuntimeException('Translation file ' . $code . '.xml is not readable XML');
        }

        $count = count($xml->phrase);
        if ($count === 0) {
            return 0;
        }

        /** @var ImportService $importService */
        $importService = \XF::service(ImportService::class, $language);
        $importService->importFromXml($xml, PublishDateAddOn::ADDON_ID);

        return $count;
    }
}