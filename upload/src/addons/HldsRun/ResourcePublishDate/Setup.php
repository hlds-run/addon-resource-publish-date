<?php

/**
 * Setup class.
 *
 * This add-on does not create, alter or drop any database table, so there is no
 * schema step to run. The class still has to exist, and it still has to be a
 * concrete class: XF\AddOn\AbstractSetup declares install(), upgrade() and
 * uninstall() as abstract, so a Setup that does not provide all three is a fatal
 * error the moment the data-rebuild job loads it - not at install time, and not
 * from any check.
 *
 * The three methods come from XenForo's step-runner traits. They are required even
 * with nothing to run; the traits return immediately when no steps are declared.
 * This is the same skeleton `xf-addon:create` writes.
 *
 * postInstall() is also overridden, and that one does have work to do - see below.
 *
 * Do not add schema steps here without bumping version_id in addon.json and
 * updating CHANGELOG.md and docs/UPGRADE.md.
 */

namespace HldsRun\ResourcePublishDate;

use HldsRun\ResourcePublishDate\Service\TranslationInstaller;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUninstallTrait;
    use StepRunnerUpgradeTrait;

    /**
     * Import the shipped translations into every language the board already has.
     *
     * XenForo installs master phrases only, so without this the add-on is
     * half-English on a Russian forum until somebody reads the manual and runs the
     * importer by hand. That is exactly what happened.
     *
     * Two deliberate limits:
     *
     *  - install only, never postUpgrade. An upgrade re-import would overwrite
     *    phrases an administrator has customised in Admin CP -> Phrases, with no
     *    way to get the old wording back. The CLI is there for deliberate updates.
     *  - only languages that already exist. A translation cannot create a language,
     *    and matching ru.xml to a board that has no Russian row must do nothing
     *    rather than invent one.
     *
     * Errors are logged and swallowed: a translation is never worth failing an
     * install over.
     */
    public function postInstall(array &$stateChanges)
    {
        // \XF::service() rather than $this->service(): AbstractSetup inherits
        // only InstallHelperTrait, which has no service() helper. The class_check
        // pass in CI is what caught that.
        $report = \XF::service(TranslationInstaller::class)->installForExistingLanguages();

        // \XF::logError() is the only logging entry point XenForo exposes - there
        // is no logInfo, and the message lands in Admin CP -> Logs -> Error log.
        // Writing an informational line there is the lesser evil versus leaving the
        // administrator with no record of what happened to their translations.
        //
        // That is the best case, not the guaranteed one. logError() writes only
        // when there is a web request: XF\Error::logException() builds the request
        // state before it inserts, that throws under cmd.php, and the catch around
        // it is empty. Verified on XF 2.3.7 - installing from the Admin CP logs
        // this, installing from the CLI does not. So nothing downstream may treat
        // these lines as the record of what happened; the phrases are.
        foreach ($report as $entry) {
            \XF::logError(sprintf(
                '[ResourcePublishDate] Imported the %s translation (%d phrases) into "%s".',
                $entry['code'],
                $entry['count'],
                $entry['language']
            ));
        }

        if (!$report) {
            \XF::logError('[ResourcePublishDate] No shipped translation matched a language on this board.');
        }
    }
}