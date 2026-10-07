<?php

namespace HldsRun\ResourcePublishDate\XF\Service\Thread;

use HldsRun\ResourcePublishDate\Service\PublishDateManager;

/**
 * Class extension of XF\Service\Thread\ApproverService.
 *
 * Note the class name. In XF 2.3 the approval services were renamed:
 * `XF\Service\Thread\Approver` became `ApproverService` (and likewise
 * `XF\Service\Post\Approver`). An add-on targeting XF 2.2 that extends the old
 * name installs without error and silently never runs. This add-on therefore
 * requires XF 2.3+ in addon.json instead of pretending to be version agnostic.
 *
 * @see \XF\Service\Thread\ApproverService::approve()
 * @see docs/BEHAVIOR.md
 */
class ApproverService extends XFCP_ApproverService
{
    protected function onApprove()
    {
        parent::onApprove();

        /** @var PublishDateManager $manager */
        $manager = $this->service(PublishDateManager::class);
        $manager->bumpThread($this->thread);
    }
}
