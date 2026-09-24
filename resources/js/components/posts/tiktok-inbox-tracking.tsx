import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import TikTokInboxRefreshController from '@/actions/App/Http/Controllers/Posts/TikTokInboxRefreshController';
import { Button } from '@/components/ui/button';
import { ExternalLink, RefreshCw } from '@/components/ui/icons';
import { dayjs } from '@/lib/datetime/dayjs';
import type { TargetView } from '@/types/compose';

export function TikTokInboxTracking({
    postId,
    target,
}: {
    postId: string;
    target: TargetView;
}) {
    const [checking, setChecking] = useState(false);
    const tracking = target.inbox_tracking;

    if (!tracking) {
        return null;
    }

    function checkPublication() {
        setChecking(true);
        router.post(
            TikTokInboxRefreshController.store.url({
                post: postId,
                target: target.id,
            }),
            {},
            {
                preserveScroll: true,
                onError: () =>
                    toast.error(
                        'Could not check TikTok right now. Try again shortly.',
                    ),
                onFinish: () => setChecking(false),
            },
        );
    }

    return (
        <section className="mb-4 space-y-3 rounded-xl border border-border p-4">
            <h3 className="text-sm font-semibold">TikTok publication</h3>
            <p
                role="status"
                className="text-sm leading-6 text-muted-foreground"
            >
                {tracking.message}
            </p>
            {tracking.public_posts.length > 0 && (
                <ul className="space-y-2 text-sm">
                    {tracking.public_posts.map((post, index) => (
                        <li key={post.id}>
                            <a
                                href={post.url}
                                target="_blank"
                                rel="noreferrer noopener"
                                className="inline-flex items-center gap-1 font-medium underline underline-offset-4"
                            >
                                View TikTok post
                                {tracking.public_posts.length > 1 &&
                                    ` ${index + 1}`}
                                <ExternalLink className="size-3" aria-hidden />
                            </a>
                        </li>
                    ))}
                </ul>
            )}
            <div className="flex flex-wrap items-center gap-3">
                {tracking.can_refresh && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={checking}
                        onClick={checkPublication}
                    >
                        <RefreshCw
                            className={
                                checking ? 'size-3 animate-spin' : 'size-3'
                            }
                            aria-hidden
                        />
                        {checking
                            ? 'Checking TikTok…'
                            : 'Check TikTok publication'}
                    </Button>
                )}
                {tracking.checked_at && (
                    <span className="text-xs text-muted-foreground">
                        Last checked {dayjs(tracking.checked_at).fromNow()}
                    </span>
                )}
                {tracking.verified_at && (
                    <span className="text-xs text-muted-foreground">
                        Public post last verified{' '}
                        {dayjs(tracking.verified_at).fromNow()}
                    </span>
                )}
            </div>
        </section>
    );
}
