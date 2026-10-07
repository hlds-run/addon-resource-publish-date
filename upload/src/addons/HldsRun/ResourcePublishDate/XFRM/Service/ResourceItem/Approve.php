<?php

namespace HldsRun\ResourcePublishDate\XFRM\Service\ResourceItem;

use HldsRun\ResourcePublishDate\Service\BumpResult;
use HldsRun\ResourcePublishDate\Service\PublishDateManager;

/**
 * Class extension of XFRM\Service\ResourceItem\Approve.
 *
 * XFRM creates the resource, saves it with resource_state = 'visible' and only
 * then calls onApprove(). By the time we get here the resource is public, so
 * everything we do is a follow-up write. That costs one extra UPDATE per
 * approved resource, which is irrelevant next to the notification emails XFRM
 * already queues, and it buys us a hook whose contract cannot drift: onApprove()
 * is only reached when an approval actually happened.
 *
 * Overriding approve() instead would let us fold the date change into XFRM's own
 * single write, but it would mean copying XFRM's internal
 * `if ($this->resource->resource_state == 'moderated')` guard into our add-on,
 * which would silently diverge the day XFRM changes it.
 *
 * @see \XFRM\Service\ResourceItem\Approve::approve()
 * @see docs/BEHAVIOR.md
 */
class Approve extends XFCP_Approve
{
    protected function onApprove()
    {
        parent::onApprove();

        /** @var PublishDateManager $manager */
        $manager = $this->service(PublishDateManager::class);
        $result = $manager->bumpResource($this->resource);

        if (!$result->isBumped() && $result->getStatus() === BumpResult::SKIPPED_NO_VISIBLE_UPDATE) {
            // Unexpected for a freshly approved resource: the description is
            // always created visible by XFRM\Service\ResourceItem\Create.
            // Worth an error_log line because it means RM's invariant is broken.
            \XF::logError(sprintf(
                '[ResourcePublishDate] Resource %d was approved but has no visible update to date.',
                (int) $this->resource->resource_id
            ));
        }
    }
}
