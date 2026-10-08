<?php

namespace HldsRun\ResourcePublishDate\Service;

use XF\App;
use XF\Entity\Thread;
use XF\Mvc\Entity\Entity;
use XF\Repository\ModeratorLogRepository;
use XF\Service\AbstractService;
use XFRM\Entity\ResourceItem;
use XFRM\Entity\ResourceUpdate;

/**
 * All decision-making and all writes live here.
 *
 * The two class extensions (see _data/class_extensions.xml) are deliberately
 * thin: they call onApprove() as usual and hand over to this service. That
 * keeps the XenForo-facing surface at exactly two method overrides and makes
 * the behaviour testable and readable in one file.
 *
 * @see docs/BEHAVIOR.md for why `last_update` is the field to move and why
 *      `resource_date` must be left alone.
 */
class PublishDateManager extends AbstractService
{
    /** Shift dates only when the resource is published for the first time. */
    public const SCOPE_FIRST_APPROVAL = 'first';

    /** Shift dates on every approval, including re-approvals. */
    public const SCOPE_EVERY_APPROVAL = 'every';

    public const CONTENT_TYPE_RESOURCE = 'resource';
    public const CONTENT_TYPE_THREAD = 'thread';

    public const MOD_LOG_ACTION_BUMP = 'publish_date_bump';

    /**
     * `discussion_type` value XFRM assigns to automatically created resource
     * discussion threads (see XFRM\Service\ResourceItem\Create::setupResourceThreadCreation()).
     */
    public const THREAD_TYPE_RESOURCE = 'resource';

    /**
     * How many `approve` rows we are willing to count.
     *
     * Two is enough: one for the approval currently being written, one more to
     * prove the resource was already approved before.
     */
    private const APPROVAL_PROBE_LIMIT = 2;

    private ?bool $loggingOverride = null;

    public function __construct(App $app)
    {
        parent::__construct($app);
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    public function getScope(): string
    {
        $scope = (string) $this->app->options()->hldsRunRpdScope;

        return $scope === self::SCOPE_EVERY_APPROVAL
            ? self::SCOPE_EVERY_APPROVAL
            : self::SCOPE_FIRST_APPROVAL;
    }

    public function shouldBumpResources(): bool
    {
        return (bool) $this->app->options()->hldsRunRpdBumpResource;
    }

    public function shouldBumpThreads(): bool
    {
        return (bool) $this->app->options()->hldsRunRpdBumpThread;
    }

    public function isLoggingEnabled(): bool
    {
        if ($this->loggingOverride !== null) {
            return $this->loggingOverride;
        }

        return (bool) $this->app->options()->hldsRunRpdLogToModeratorLog;
    }

    /**
     * Forces the moderator log on or off for the current request.
     *
     * The backfill command disables it because
     * XF\ModeratorLog\AbstractHandler::setupLogEntityActor() calls
     * Ip::stringToBinary() on the request IP, which throws when there is no HTTP
     * request (CLI). Rather than guessing whether we are in CLI, the caller
     * states its intent.
     */
    public function setLoggingEnabled(bool $enabled): void
    {
        $this->loggingOverride = $enabled;
    }

    /**
     * @return list<int>
     */
    public function getExcludedCategoryIds(): array
    {
        $ids = $this->app->options()->hldsRunRpdExcludedCategories;

        if (!is_array($ids)) {
            return [];
        }

        $ids = array_map('intval', array_values($ids));

        return array_values(array_filter($ids, static function ($id) {
            return $id > 0;
        }));
    }

    public function isCategoryExcluded(int $categoryId): bool
    {
        return in_array($categoryId, $this->getExcludedCategoryIds(), true);
    }

    public function getMinimumModerationSeconds(): int
    {
        $minutes = (int) $this->app->options()->hldsRunRpdMinimumModerationMinutes;

        return max(0, $minutes) * 60;
    }

    // ------------------------------------------------------------------
    // Moderator log inspection
    // ------------------------------------------------------------------

    /**
     * Counts moderator log `approve` entries for a piece of content.
     *
     * XFRM writes the `approve` action from
     * XFRM\ModeratorLog\ResourceItem::getLogActionForChange() when
     * `resource_state` moves moderated -> visible, and XF core does the same for
     * `discussion_state`. Both entity structures hard-code
     * `options['log_moderator'] = true`, so this data is always available.
     *
     * Retention is the one caveat: `moderatorLogLength` (ACP -> Options ->
     * Logging) prunes old rows. With the default of 0 days nothing is pruned.
     * See docs/TROUBLESHOOTING.md.
     */
    public function countApprovals(string $contentType, int $contentId): int
    {
        if ($contentId <= 0) {
            return 0;
        }

        return \XF::repository(ModeratorLogRepository::class)
            ->findLogsForList()
            ->where('content_type', $contentType)
            ->where('content_id', $contentId)
            ->where('action', 'approve')
            ->limit(self::APPROVAL_PROBE_LIMIT)
            ->fetch()
            ->count();
    }

    /**
     * True while the *first* approval of this content is being processed.
     *
     * Called from onApprove(), i.e. after the entity has already been saved, so
     * the moderator log row for the current approval exists by now. A count of
     * exactly one therefore means "this is the first approval".
     */
    public function isFirstApprovalInProgress(string $contentType, int $contentId): bool
    {
        return $this->countApprovals($contentType, $contentId) <= 1;
    }

    /**
     * True if the content has an approval recorded at all.
     *
     * Called from outside an approval (the backfill command), where any `approve`
     * row means the content has been published before.
     */
    public function hasBeenApproved(string $contentType, int $contentId): bool
    {
        return $this->countApprovals($contentType, $contentId) >= 1;
    }

    /**
     * Timestamp of the earliest approval, used by the backfill command.
     */
    public function findFirstApprovalTimestamp(string $contentType, int $contentId): ?int
    {
        if ($contentId <= 0) {
            return null;
        }

        $logDate = \XF::db()->fetchOne(
            'SELECT MIN(log_date)
             FROM xf_moderator_log
             WHERE content_type = ?
               AND content_id = ?
               AND action = ?',
            [$contentType, $contentId, 'approve']
        );

        return $logDate === null || $logDate === false ? null : (int) $logDate;
    }

    // ------------------------------------------------------------------
    // Resource
    // ------------------------------------------------------------------

    /**
     * The visible update that `last_update` is derived from.
     *
     * Mirrors XFRM\Entity\ResourceItem::rebuildLastUpdateInfo() exactly so that
     * our result and a later counter rebuild cannot disagree.
     */
    public function findNewestVisibleUpdate(ResourceItem $resource): ?ResourceUpdate
    {
        $updateId = \XF::db()->fetchOne(
            "SELECT resource_update_id
             FROM xf_rm_resource_update
             WHERE resource_id = ?
               AND message_state = 'visible'
             ORDER BY post_date DESC
             LIMIT 1",
            [(int) $resource->resource_id]
        );

        if (!$updateId) {
            return null;
        }

        return $this->em()->find('XFRM:ResourceUpdate', (int) $updateId);
    }

    /**
     * Runs every configured check for a resource without writing anything.
     */
    public function evaluateResource(ResourceItem $resource, ?int $now = null): BumpResult
    {
        if (!$this->shouldBumpResources()) {
            return BumpResult::skipped(BumpResult::SKIPPED_DISABLED_FOR_TYPE);
        }

        if ($this->isCategoryExcluded((int) $resource->resource_category_id)) {
            return BumpResult::skipped(BumpResult::SKIPPED_CATEGORY_EXCLUDED);
        }

        $now = $now ?? time();
        $minimum = $this->getMinimumModerationSeconds();
        if ($minimum > 0 && ($now - (int) $resource->resource_date) < $minimum) {
            return BumpResult::skipped(BumpResult::SKIPPED_BELOW_MINIMUM_AGE);
        }

        if (
            $this->getScope() === self::SCOPE_FIRST_APPROVAL
            && !$this->isFirstApprovalInProgress(self::CONTENT_TYPE_RESOURCE, (int) $resource->resource_id)
        ) {
            return BumpResult::skipped(BumpResult::SKIPPED_NOT_FIRST_APPROVAL);
        }

        // Structural checks are delegated so that this method and
        // applyResourceDate() can never disagree about what is allowed.
        $structural = $this->evaluateResourceStructure($resource, $now);
        if ($structural !== null) {
            return $structural;
        }

        return BumpResult::bumped((int) $resource->last_update, $now);
    }

    /**
     * Moves a resource's publish date to $timestamp (default: now).
     */
    public function bumpResource(ResourceItem $resource, ?int $timestamp = null): BumpResult
    {
        $evaluation = $this->evaluateResource($resource, $timestamp);
        if (!$evaluation->isBumped()) {
            return $evaluation;
        }

        return $this->applyResourceDate($resource, $timestamp ?? time());
    }

    /**
     * Writes the new dates for a resource without consulting the approval state.
     *
     * Only the structural guards apply: there must be a visible update to derive
     * the date from, and the stored date must actually be older than the target.
     *
     * The backfill command uses this directly, because it re-dates content whose
     * approval already happened and therefore must not run the "is this the first
     * approval" check again.
     */
    public function applyResourceDate(ResourceItem $resource, int $timestamp): BumpResult
    {
        $structural = $this->evaluateResourceStructure($resource, $timestamp);
        if ($structural !== null) {
            return $structural;
        }

        $newest = $this->findNewestVisibleUpdate($resource);
        $previousTimestamp = (int) $resource->last_update;

        // fastUpdate writes straight to the database without firing _postSave();
        // that is what we want for a date-only change, and it guarantees the
        // re-read below sees the new value.
        $newest->fastUpdate('post_date', $timestamp);

        if (!$resource->rebuildLastUpdateInfo()) {
            $resource->last_update = $timestamp;
        }

        $resource->save();

        $result = BumpResult::bumped($previousTimestamp, (int) $resource->last_update);

        $this->logBump(self::CONTENT_TYPE_RESOURCE, $resource, $result);

        return $result;
    }

    /**
     * Structural checks for a resource: null when the write may proceed.
     *
     * "Structural" means the properties of the data itself, independent of why
     * we are re-dating it. Used by both applyResourceDate() and the backfill
     * command's dry run, so the two can never disagree.
     */
    public function evaluateResourceStructure(ResourceItem $resource, int $timestamp): ?BumpResult
    {
        $newest = $this->findNewestVisibleUpdate($resource);

        if (!$newest) {
            return BumpResult::skipped(BumpResult::SKIPPED_NO_VISIBLE_UPDATE);
        }

        if ((int) $newest->post_date >= $timestamp) {
            return BumpResult::skipped(BumpResult::SKIPPED_ALREADY_CURRENT);
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Discussion thread
    // ------------------------------------------------------------------

    /**
     * Runs every configured check for a discussion thread without writing.
     */
    public function evaluateThread(Thread $thread, ?int $now = null): BumpResult
    {
        if (!$this->shouldBumpThreads()) {
            return BumpResult::skipped(BumpResult::SKIPPED_DISABLED_FOR_TYPE);
        }

        if ($thread->discussion_type !== self::THREAD_TYPE_RESOURCE) {
            return BumpResult::skipped(BumpResult::SKIPPED_THREAD_NOT_A_RESOURCE);
        }

        $now = $now ?? time();
        $minimum = $this->getMinimumModerationSeconds();
        if ($minimum > 0 && ($now - (int) $thread->post_date) < $minimum) {
            return BumpResult::skipped(BumpResult::SKIPPED_BELOW_MINIMUM_AGE);
        }

        if (
            $this->getScope() === self::SCOPE_FIRST_APPROVAL
            && !$this->isFirstApprovalInProgress(self::CONTENT_TYPE_THREAD, (int) $thread->thread_id)
        ) {
            return BumpResult::skipped(BumpResult::SKIPPED_NOT_FIRST_APPROVAL);
        }

        // Structural checks are delegated, for the same reason as in
        // evaluateResource().
        $structural = $this->evaluateThreadStructure($thread, $now);
        if ($structural !== null) {
            return $structural;
        }

        return BumpResult::bumped((int) $thread->post_date, $now);
    }

    /**
     * Moves a resource discussion thread's dates to $timestamp (default: now).
     */
    public function bumpThread(Thread $thread, ?int $timestamp = null): BumpResult
    {
        $evaluation = $this->evaluateThread($thread, $timestamp);
        if (!$evaluation->isBumped()) {
            return $evaluation;
        }

        return $this->applyThreadDate($thread, $timestamp ?? time());
    }

    /**
     * Writes the new dates for a thread without consulting the approval state.
     *
     * See applyResourceDate() for why the backfill command needs this split.
     * The single-post guard is structural, not approval-related, so it still
     * applies here.
     */
    public function applyThreadDate(Thread $thread, int $timestamp): BumpResult
    {
        $structural = $this->evaluateThreadStructure($thread, $timestamp);
        if ($structural !== null) {
            return $structural;
        }

        $firstPost = $thread->FirstPost;
        $previousTimestamp = (int) $thread->post_date;

        $firstPost->post_date = $timestamp;
        $firstPost->save();

        $thread->post_date = $timestamp;
        $thread->last_post_date = max($timestamp, (int) $thread->last_post_date);
        $thread->save();

        $result = BumpResult::bumped($previousTimestamp, $timestamp);

        $this->logBump(self::CONTENT_TYPE_THREAD, $thread, $result);

        return $result;
    }

    /**
     * Structural checks for a thread: null when the write may proceed.
     *
     * The single-post guard belongs here rather than in the approval flow: it is
     * a property of the data, and it is the reason a resource thread that was
     * approved long ago and has since been replied to is left alone.
     */
    public function evaluateThreadStructure(Thread $thread, int $timestamp): ?BumpResult
    {
        if (!$thread->FirstPost) {
            return BumpResult::skipped(BumpResult::SKIPPED_NO_FIRST_POST);
        }

        if (!$this->threadIsStillSinglePost($thread)) {
            return BumpResult::skipped(BumpResult::SKIPPED_THREAD_HAS_REPLIES);
        }

        if ((int) $thread->FirstPost->post_date >= $timestamp) {
            return BumpResult::skipped(BumpResult::SKIPPED_ALREADY_CURRENT);
        }

        return null;
    }

    /**
     * A thread is "still single post" when its reply count and first/last post
     * ids all agree. Cheap, index-friendly and does not need a COUNT query.
     */
    public function threadIsStillSinglePost(Thread $thread): bool
    {
        return (int) $thread->reply_count === 0
            && (int) $thread->first_post_id === (int) $thread->last_post_id;
    }

    // ------------------------------------------------------------------
    // Moderator log
    // ------------------------------------------------------------------

    /**
     * Records the date shift in XenForo's own moderator log so that it shows up
     * in the staff UI next to the approval entry.
     *
     * Wrapped in a try/catch on purpose: the core handler converts the request
     * IP with Ip::stringToBinary(), which throws when there is no HTTP request.
     */
    private function logBump(string $contentType, Entity $content, BumpResult $result): void
    {
        if (!$this->isLoggingEnabled()) {
            return;
        }

        $language = \XF::app()->language();
        $format = static function ($timestamp) use ($language) {
            return $language->date((int) $timestamp, 'absolute');
        };

        try {
            \XF::app()->logger()->logModeratorAction(
                $contentType,
                $content,
                self::MOD_LOG_ACTION_BUMP,
                [
                    'from' => $format($result->getPreviousTimestamp()),
                    'to' => $format($result->getNewTimestamp()),
                ],
                false
            );
        } catch (\Throwable $e) {
            \XF::logError(
                '[ResourcePublishDate] Could not write the moderator log entry: ' . $e->getMessage()
            );
        }
    }
}
