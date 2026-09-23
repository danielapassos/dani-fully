import { Link, useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import TikTokCreatorInfoController from '@/actions/App/Http/Controllers/ConnectedAccounts/TikTokCreatorInfoController';
import { Button } from '@/components/ui/button';
import {
    TIKTOK_DEFAULT_OPTIONS,
    TIKTOK_ISSUE_MESSAGES,
    TIKTOK_PRIVACY_LABELS,
    tiktokIssues,
} from '@/lib/compose/tiktok';
import { index as accountsRoute } from '@/routes/accounts';
import type {
    Account,
    TikTokCreatorInfo,
    TikTokPostOptions,
    TikTokPrivacy,
} from '@/types/compose';

type Props = {
    account: Account;
    options: TikTokPostOptions | undefined;
    durationSeconds: number | null | undefined;
    onChange: (options: TikTokPostOptions) => void;
    onCreatorInfo: (creator: TikTokCreatorInfo | null) => void;
};

export function TikTokPublishingControls({
    account,
    options,
    durationSeconds,
    onChange,
    onCreatorInfo,
}: Props) {
    const http = useHttp<Record<string, never>, { creator: TikTokCreatorInfo }>(
        {},
    );
    const [creator, setCreator] = useState<TikTokCreatorInfo | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [revision, setRevision] = useState(0);
    const [loading, setLoading] = useState(true);
    const current = options ?? TIKTOK_DEFAULT_OPTIONS;
    const publishingBlocked = account.publishing_ready === false;
    const unavailableReason =
        account.publishing_unavailable_reason ??
        'This TikTok account is not ready to publish. Check its publishing setup in Accounts.';

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        setError(null);
        setCreator(null);
        onCreatorInfo(null);
        if (publishingBlocked) {
            setLoading(false);
            return () => {
                cancelled = true;
            };
        }
        const fail = () => {
            if (cancelled) return;
            setError(
                'TikTok could not load current publishing settings. Refresh settings or check this account’s publishing connection.',
            );
            setLoading(false);
        };
        void http.get(TikTokCreatorInfoController(account.id).url, {
            onSuccess: (data) => {
                if (cancelled) return;
                setCreator(data.creator);
                onCreatorInfo(data.creator);
                const next = { ...current };
                if (data.creator.comment_disabled) next.disable_comment = true;
                if (data.creator.duet_disabled) next.disable_duet = true;
                if (data.creator.stitch_disabled) next.disable_stitch = true;
                if (
                    next.privacy_level &&
                    !data.creator.privacy_level_options.includes(
                        next.privacy_level,
                    )
                )
                    delete next.privacy_level;
                if (options && JSON.stringify(next) !== JSON.stringify(current))
                    onChange(next);
                setLoading(false);
            },
            onHttpException: fail,
            onNetworkError: fail,
            onError: (errors) => {
                if (cancelled) return;
                const message = Object.values(errors)[0];
                setError(
                    typeof message === 'string'
                        ? message
                        : 'TikTok could not load current publishing settings. Refresh, or reconnect the account with direct publishing permission.',
                );
                setLoading(false);
            },
        });
        return () => {
            cancelled = true;
        };
        // oxlint-disable-next-line react-hooks/exhaustive-deps -- Reload on account/readiness changes or explicit refresh, not options autosaves or callback identity changes.
    }, [account.id, publishingBlocked, revision]);

    function change(patch: Partial<TikTokPostOptions>) {
        onChange({ ...current, ...patch });
    }

    const issues = tiktokIssues(options, creator, durationSeconds).filter(
        (issue) =>
            issue !== 'tiktok_options_required' &&
            issue !== 'tiktok_creator_unavailable',
    );

    return (
        <section
            className="space-y-4 border-t border-border px-4 py-4 sm:px-[26px]"
            aria-label={`TikTok publishing settings for ${account.handle}`}
        >
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h3 className="text-sm font-medium">Post to TikTok</h3>
                    <p className="text-sm text-muted-foreground">
                        {creator && !publishingBlocked
                            ? `${creator.creator_nickname} · @${creator.creator_username}`
                            : account.handle}
                    </p>
                </div>
                {!publishingBlocked && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={loading}
                        onClick={() => setRevision((value) => value + 1)}
                    >
                        Refresh settings
                    </Button>
                )}
            </div>
            {loading && !publishingBlocked && (
                <p role="status" className="text-sm text-muted-foreground">
                    Loading current TikTok settings…
                </p>
            )}
            {(publishingBlocked || error) && (
                <p role="alert" className="text-sm text-destructive">
                    {publishingBlocked ? unavailableReason : error}
                </p>
            )}
            {publishingBlocked && (
                <Link
                    href={accountsRoute().url}
                    className="text-sm underline underline-offset-2"
                >
                    View account setup
                </Link>
            )}
            {creator && !publishingBlocked && (
                <>
                    <label className="grid gap-1.5 text-sm">
                        Who can watch this video?
                        <select
                            className="h-9 rounded-md border border-input bg-background px-3"
                            value={current.privacy_level ?? ''}
                            onChange={(event) =>
                                change({
                                    privacy_level: event.target.value
                                        ? (event.target.value as TikTokPrivacy)
                                        : undefined,
                                })
                            }
                        >
                            <option value="">Choose privacy</option>
                            {creator.privacy_level_options.map((privacy) => (
                                <option
                                    key={privacy}
                                    value={privacy}
                                    disabled={
                                        privacy === 'SELF_ONLY' &&
                                        current.brand_content_toggle
                                    }
                                >
                                    {TIKTOK_PRIVACY_LABELS[privacy]}
                                </option>
                            ))}
                        </select>
                    </label>
                    <fieldset className="space-y-2">
                        <legend className="mb-2 text-sm font-medium">
                            Allow interactions
                        </legend>
                        {(['comment', 'duet', 'stitch'] as const).map(
                            (interaction) => {
                                const disabled =
                                    creator[`${interaction}_disabled`];
                                const key = `disable_${interaction}` as const;
                                return (
                                    <label
                                        key={interaction}
                                        className={`flex items-center gap-2 text-sm ${disabled ? 'text-muted-foreground' : ''}`}
                                    >
                                        <input
                                            type="checkbox"
                                            className="size-4 accent-primary disabled:opacity-50"
                                            checked={!current[key] && !disabled}
                                            disabled={disabled}
                                            onChange={(event) =>
                                                change({
                                                    [key]: !event.target
                                                        .checked,
                                                })
                                            }
                                        />
                                        {interaction === 'comment'
                                            ? 'Comments'
                                            : interaction === 'duet'
                                              ? 'Duet'
                                              : 'Stitch'}
                                        {disabled && (
                                            <span className="text-xs">
                                                Disabled in TikTok
                                            </span>
                                        )}
                                    </label>
                                );
                            },
                        )}
                    </fieldset>
                    <p className="text-xs text-muted-foreground">
                        This account allows videos up to{' '}
                        {creator.max_video_post_duration_sec} seconds.
                    </p>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="size-4 accent-primary"
                            checked={current.commercial_content}
                            onChange={(event) =>
                                change({
                                    commercial_content: event.target.checked,
                                    ...(!event.target.checked
                                        ? {
                                              brand_organic_toggle: false,
                                              brand_content_toggle: false,
                                              branded_content_policy_confirmed: false,
                                          }
                                        : {}),
                                })
                            }
                        />
                        Disclose commercial content
                    </label>
                    <p className="-mt-2 text-xs text-muted-foreground">
                        Turn on if this video promotes you, a brand, product, or
                        service.
                    </p>
                    {current.commercial_content && (
                        <div className="space-y-2 border-l-2 border-border pl-3">
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="size-4 accent-primary"
                                    checked={current.brand_organic_toggle}
                                    onChange={(event) =>
                                        change({
                                            brand_organic_toggle:
                                                event.target.checked,
                                        })
                                    }
                                />
                                Your brand
                            </label>
                            <label
                                className={`flex items-center gap-2 text-sm ${current.privacy_level === 'SELF_ONLY' ? 'text-muted-foreground' : ''}`}
                            >
                                <input
                                    type="checkbox"
                                    className="size-4 accent-primary"
                                    checked={current.brand_content_toggle}
                                    disabled={
                                        current.privacy_level === 'SELF_ONLY'
                                    }
                                    onChange={(event) =>
                                        change({
                                            brand_content_toggle:
                                                event.target.checked,
                                            branded_content_policy_confirmed: false,
                                        })
                                    }
                                />
                                Branded content
                            </label>
                            {current.privacy_level === 'SELF_ONLY' && (
                                <p className="text-xs text-muted-foreground">
                                    Branded content visibility cannot be set to
                                    private.
                                </p>
                            )}
                            {(current.brand_organic_toggle ||
                                current.brand_content_toggle) && (
                                <p className="text-xs text-muted-foreground">
                                    Your video will be labeled as “
                                    {current.brand_content_toggle
                                        ? 'Paid partnership'
                                        : 'Promotional content'}
                                    ”.
                                </p>
                            )}
                        </div>
                    )}
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="size-4 accent-primary"
                            checked={current.is_aigc}
                            onChange={(event) =>
                                change({ is_aigc: event.target.checked })
                            }
                        />
                        AI-generated content
                    </label>
                    <label className="grid gap-1.5 text-sm">
                        Cover frame time (milliseconds, optional)
                        <input
                            type="number"
                            className="h-9 rounded-md border border-input bg-background px-3"
                            min={0}
                            step={1}
                            max={
                                durationSeconds
                                    ? durationSeconds * 1000 - 1
                                    : undefined
                            }
                            value={current.video_cover_timestamp_ms ?? ''}
                            onChange={(event) => {
                                if (event.target.value === '') {
                                    const next = { ...current };
                                    delete next.video_cover_timestamp_ms;
                                    onChange(next);
                                } else
                                    change({
                                        video_cover_timestamp_ms: Number(
                                            event.target.value,
                                        ),
                                    });
                            }}
                        />
                    </label>
                    <label className="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="mt-0.5 size-4 shrink-0 accent-primary"
                            checked={
                                current.music_usage_confirmed &&
                                (!current.brand_content_toggle ||
                                    current.branded_content_policy_confirmed)
                            }
                            onChange={(event) =>
                                change({
                                    music_usage_confirmed: event.target.checked,
                                    branded_content_policy_confirmed:
                                        current.brand_content_toggle &&
                                        event.target.checked,
                                })
                            }
                        />
                        <span>
                            By posting, you agree to TikTok’s{' '}
                            {current.brand_content_toggle && (
                                <>
                                    <a
                                        href="https://www.tiktok.com/legal/page/global/bc-policy/en"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="underline"
                                    >
                                        Branded Content Policy
                                    </a>{' '}
                                    and{' '}
                                </>
                            )}
                            <a
                                href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline"
                            >
                                Music Usage Confirmation
                            </a>
                            .
                        </span>
                    </label>
                    {issues.length > 0 && (
                        <ul className="space-y-1 text-xs text-amber-700 dark:text-amber-500">
                            {issues.map((issue) => (
                                <li key={issue}>
                                    {TIKTOK_ISSUE_MESSAGES[issue]}
                                </li>
                            ))}
                        </ul>
                    )}
                    <p className="text-xs text-muted-foreground">
                        Review your caption and video preview before publishing.
                        TikTok may take a few minutes to process the post before
                        it appears on your profile.
                    </p>
                </>
            )}
        </section>
    );
}
