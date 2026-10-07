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
}
