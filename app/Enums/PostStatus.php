<?php

declare(strict_types=1);

namespace App\Enums;

enum PostStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case AwaitingAction = 'awaiting_action';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Missed = 'missed';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Publishing => 'Publishing',
            self::Published => 'Published',
            self::AwaitingAction => 'Action needed',
            self::Completed => 'Upload complete',
            self::Partial => 'Partially completed',
            self::Failed => 'Failed',
            self::Missed => 'Missed',
            self::Deleted => 'Deleted',
        };
    }

    /**
     * Only drafts (and scheduled posts, in M3) may be edited in the composer.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Scheduled;
    }
}
