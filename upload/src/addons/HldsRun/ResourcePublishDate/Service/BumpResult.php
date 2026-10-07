<?php

namespace HldsRun\ResourcePublishDate\Service;

/**
 * Outcome of a publish date decision.
 *
 * Every entry point of the add-on returns one of these instead of a boolean so
 * that the moderator log, the CLI commands and the troubleshooting guide can all
 * explain *why* nothing happened. See docs/TROUBLESHOOTING.md.
 */
final class BumpResult
{
    /** Dates were shifted. */
    public const BUMPED = 'bumped';

    /**
     * Date shifting for this content type is switched off in the options.
     *
     * There is deliberately no "the add-on is switched off" status. It used to
     * exist, alongside an hldsRunRpdEnabled option that duplicated the disable
     * button on Admin CP -> Add-ons: a disabled add-on has its class extensions
     * unloaded, so onApprove() is never called and there is no decision left to
     * record. One switch, and it is the one XenForo already owns.
     */
    public const SKIPPED_DISABLED_FOR_TYPE = 'disabled_for_type';

    /** The resource's category is on the exclusion list. */
    public const SKIPPED_CATEGORY_EXCLUDED = 'category_excluded';

    /** The resource did not sit in the queue for long enough. */
    public const SKIPPED_BELOW_MINIMUM_AGE = 'below_minimum_age';

    /** Only the first approval shifts dates, and this is not the first one. */
    public const SKIPPED_NOT_FIRST_APPROVAL = 'not_first_approval';

    /** The resource has no visible update to anchor the new date to. */
    public const SKIPPED_NO_VISIBLE_UPDATE = 'no_visible_update';

    /** The thread is not a resource discussion thread. */
    public const SKIPPED_THREAD_NOT_A_RESOURCE = 'thread_not_a_resource';

    /**
     * The thread already has replies.
     *
     * Moving the first post's date past later posts would break the position
     * ordering inside the thread, so this case is deliberately skipped.
     */
    public const SKIPPED_THREAD_HAS_REPLIES = 'thread_has_replies';

    /** The thread has no first post, so there is no date to move. */
    public const SKIPPED_NO_FIRST_POST = 'no_first_post';

    /** The stored date is already at or after the requested timestamp. */
    public const SKIPPED_ALREADY_CURRENT = 'already_current';

    /**
     * @var string
     */
    private $status;

    /**
     * @var int
     */
    private $previousTimestamp;

    /**
     * @var int
     */
    private $newTimestamp;

    public function __construct(string $status, int $previousTimestamp = 0, int $newTimestamp = 0)
    {
        $this->status = $status;
        $this->previousTimestamp = $previousTimestamp;
        $this->newTimestamp = $newTimestamp;
    }

    public static function bumped(int $previousTimestamp, int $newTimestamp): self
    {
        return new self(self::BUMPED, $previousTimestamp, $newTimestamp);
    }

    public static function skipped(string $status): self
    {
        return new self($status);
    }

    public function isBumped(): bool
    {
        return $this->status === self::BUMPED;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPreviousTimestamp(): int
    {
        return $this->previousTimestamp;
    }

    public function getNewTimestamp(): int
    {
        return $this->newTimestamp;
    }

    /**
     * Phrase title explaining the outcome, for CLI output and mod log entries.
     */
    public function getPhraseTitle(): string
    {
        return 'hlds_run_rpd_reason_' . $this->status;
    }
}
