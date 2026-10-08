<?php

namespace HldsRun\ResourcePublishDate\Service;

/**
 * What one backfill batch did, or would have done.
 *
 * A value object rather than an array or a set of counters on the service, so
 * that the CLI command and the Admin CP button are describing the same run in
 * the same words: the command prints per-resource lines from getEntries(), the
 * button puts the totals into a message. Both read one shape.
 *
 * @see \HldsRun\ResourcePublishDate\Service\BackfillService
 */
class BackfillResult
{
    private bool $dryRun;

    /** @var list<array{resource_id: int, title: string, from: int, to: int}> */
    private array $entries;

    private int $skipped;

    public function __construct(bool $dryRun, array $entries, int $skipped)
    {
        $this->dryRun = $dryRun;
        $this->entries = $entries;
        $this->skipped = $skipped;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Resources that were moved (dry run) or would have been moved, in the order
     * the batch processed them: oldest approval first.
     *
     * @return list<array{resource_id: int, title: string, from: int, to: int}>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    public function getMovedCount(): int
    {
        return count($this->entries);
    }

    /**
     * Resources the batch decided not to touch, with no reason attached.
     *
     * A count, not a list, because the reasons are already enumerable - they are
     * the BumpResult statuses, and the CLI prints them per resource. The Admin CP
     * only needs "and this many were left alone", which is enough to notice that
     * a batch did less than expected and go and read the log for the why.
     */
    public function getSkippedCount(): int
    {
        return $this->skipped;
    }

    public function isEmpty(): bool
    {
        return !$this->entries && !$this->skipped;
    }
}
