import { useId, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';
import { musicPlanSummary, normalizeMusicPlan } from '@/lib/compose/music';
import { raw } from '@/routes/media';
import type { Account, MediaView, PhotoMusicOptions } from '@/types/compose';

const textFields = [
    ['artist', 'Artist', 200],
    ['version', 'Version or remix', 200],
    ['native_audio_id', 'Native audio ID (optional)', 200],
    ['source_url', 'Audio page URL (https)', 2048],
] as const;
const numberFields = [
    ['start_seconds', 'Excerpt start (seconds)', 0, 86400, 0.1],
    ['duration_seconds', 'Excerpt duration (seconds)', 0.1, 86400, 0.1],
    ['music_volume', 'Music volume (%)', 0, 100, 1],
    ['original_volume', 'Original sound volume (%)', 0, 100, 1],
] as const;

export function PhotoMusicControls({
    account,
    options,
    media = [],
    caption = '',
    coverId,
    onChange,
}: {
    account: Account;
    options?: PhotoMusicOptions | null;
    media?: MediaView[];
    caption?: string;
    coverId?: string | null;
    onChange: (options: PhotoMusicOptions | null) => void;
}) {
    const id = useId();
    const platform = account.platform === 'instagram' ? 'Instagram' : 'TikTok';
    const plan = normalizeMusicPlan(options);
    const [, copy] = useClipboard();
    const [copyStatus, setCopyStatus] = useState('');
    const update = (patch: Partial<PhotoMusicOptions>) =>
        onChange({
            track: '',
            ...options,
            mode: 'native',
            ...patch,
        });
    async function copyReview() {
        if (!plan) return;
        const ok = await copy(
            `${account.handle} · ${platform}\n\n${caption}\n\n${musicPlanSummary(plan)}`,
        );
        setCopyStatus(
            ok
                ? 'Caption and music plan copied.'
                : 'Could not copy. Select the review text below and copy it manually.',
        );
    }

    return (
        <section
            className="space-y-3 border-t border-border px-6 py-4"
            aria-label={`Music for ${account.handle} on ${platform}`}
        >
            <h3 className="text-sm font-medium">
                Music · {platform} · {account.handle}
            </h3>
            <p className="text-xs leading-5 text-muted-foreground">
                Choose music in the native app. Shoutrrr saves your plan; it
                cannot browse or attach that app’s catalog with this workflow.
                Music already mixed into your video stays in the file.
            </p>
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        onChange({
                            mode: 'existing_audio',
                            track: '',
                            required: false,
                        })
                    }
                >
                    Use file’s existing audio
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        onChange({ mode: 'none', track: '', required: false })
                    }
                >
                    No added music
                </Button>
            </div>
            {plan?.mode === 'existing_audio' && (
                <p role="status" className="text-xs">
                    Saved decision: use the approved file’s existing audio. This
                    does not add, remove or license any sound.
                </p>
            )}
            {plan?.mode === 'none' && (
                <p role="status" className="text-xs">
                    Saved decision: no added music. Existing sound in the file
                    is unchanged.
                </p>
            )}
            {!plan || plan.mode === 'none' || plan.mode === 'existing_audio' ? (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() =>
                        onChange({ mode: 'native', track: '', required: true })
                    }
                >
                    Plan music in {platform}
                </Button>
            ) : (
                <>
                    <p role="note" className="text-sm leading-6">
                        {account.platform === 'instagram'
                            ? 'This post stays a draft in Shoutrrr. Download the media below, start a post in Instagram, and choose music there before publishing.'
                            : account.tiktok_inbox_enabled &&
                                !account.tiktok_direct_post_enabled
                              ? 'Upload once to the TikTok inbox, then choose music and finish there. Inbox delivery is not a live post.'
                              : 'Direct posting is held. This music plan needs TikTok inbox delivery or manual preparation in TikTok.'}
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1 sm:col-span-2">
                            <label
                                htmlFor={`${id}-track`}
                                className="text-xs font-medium"
                            >
                                Song title
                            </label>
                            <Input
                                id={`${id}-track`}
                                value={options?.track ?? ''}
                                maxLength={200}
                                placeholder="Choose a song now or in the native app"
                                onChange={(event) =>
                                    update({
                                        track: event.target.value,
                                        availability_checked_at: null,
                                    })
                                }
                            />
                        </div>
                        {textFields.map(([key, label, maxLength]) => (
                            <div key={key} className="space-y-1">
                                <label
                                    htmlFor={`${id}-${key}`}
                                    className="text-xs font-medium"
                                >
                                    {label}
                                </label>
                                <Input
                                    id={`${id}-${key}`}
                                    type={key === 'source_url' ? 'url' : 'text'}
                                    maxLength={maxLength}
                                    value={options?.[key] ?? ''}
                                    onChange={(event) =>
                                        update({
                                            [key]: event.target.value,
                                            availability_checked_at: null,
                                        })
                                    }
                                />
                            </div>
                        ))}
                        {numberFields.map(([key, label, min, max, step]) => (
                            <div key={key} className="space-y-1">
                                <label
                                    htmlFor={`${id}-${key}`}
                                    className="text-xs font-medium"
                                >
                                    {label}
                                </label>
                                <Input
                                    id={`${id}-${key}`}
                                    type="number"
                                    min={min}
                                    max={max}
                                    step={step}
                                    value={options?.[key] ?? ''}
                                    onChange={(event) =>
                                        update({
                                            [key]:
                                                event.target.value === ''
                                                    ? null
                                                    : Number(
                                                          event.target.value,
                                                      ),
                                        })
                                    }
                                />
                            </div>
                        ))}
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={options?.required !== false}
                            onChange={(event) =>
                                update({ required: event.target.checked })
                            }
                        />
                        Keep unpublished if this music is unavailable
                    </label>
                    <p className="text-xs text-muted-foreground">
                        All native music plans hold direct publishing until you
                        explicitly remove the plan. Timing and volume are
                        instructions to apply in the native editor; preview the
                        mix there and keep speech clear.
                    </p>
                    <div className="space-y-1">
                        <label
                            htmlFor={`${id}-sync`}
                            className="text-xs font-medium"
                        >
                            Sync and mix notes
                        </label>
                        <Textarea
                            id={`${id}-sync`}
                            maxLength={2000}
                            value={options?.sync_notes ?? ''}
                            placeholder="Beat/drop at 8s; keep narration clear; lower music during speech"
                            onChange={(event) =>
                                update({ sync_notes: event.target.value })
                            }
                        />
                    </div>
                    <div className="space-y-1">
                        <label
                            htmlFor={`${id}-availability`}
                            className="text-xs font-medium"
                        >
                            Availability notes for this account and post
                        </label>
                        <Textarea
                            id={`${id}-availability`}
                            maxLength={1000}
                            value={options?.availability_note ?? ''}
                            placeholder="Account, region, post format, and any usage restrictions seen in the app"
                            onChange={(event) =>
                                update({
                                    availability_note: event.target.value,
                                })
                            }
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                update({
                                    availability_checked_at:
                                        new Date().toISOString(),
                                })
                            }
                        >
                            I checked availability in {platform}
                        </Button>
                        <p className="text-xs text-muted-foreground">
                            {options?.availability_checked_at
                                ? `Your manual check: ${options.availability_checked_at}. Recheck if the account, format or intended use changes.`
                                : 'Availability has not been checked. A saved song name or link is not proof of availability or a license.'}
                        </p>
                    </div>
                    <details
                        className="rounded-lg border border-border p-3"
                        open
                    >
                        <summary className="cursor-pointer text-sm font-medium">
                            Review and finish in {platform}
                        </summary>
                        <div className="mt-3 space-y-3">
                            <Textarea
                                aria-label="Caption and music plan"
                                readOnly
                                value={`${caption}\n\n${musicPlanSummary(plan)}`}
                                className="max-h-64"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => void copyReview()}
                            >
                                Copy caption and music plan
                            </Button>
                            <p role="status" className="text-xs">
                                {copyStatus}
                            </p>
                            <ul className="space-y-2 text-sm">
                                {coverId &&
                                    account.platform === 'instagram' && (
                                        <li>
                                            <a
                                                className="underline"
                                                href={raw.url(coverId)}
                                                download
                                            >
                                                Download selected Instagram
                                                cover
                                            </a>
                                        </li>
                                    )}
                                {media.map((item, index) => (
                                    <li key={item.id}>
                                        <a
                                            className="underline"
                                            href={item.edit_url}
                                            download={`shoutrrr-${item.id}.${item.mime === 'image/jpeg' ? 'jpg' : item.mime.split('/')[1]}`}
                                        >
                                            Download {item.kind} {index + 1}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                            <p className="text-xs text-muted-foreground">
                                Music is not attached. Downloaded media contains
                                only its existing sound. Select the exact
                                version, apply the excerpt and levels, review
                                the caption, cover and audience, then post in{' '}
                                {platform}. This does not mark the post live in
                                Shoutrrr.
                            </p>
                        </div>
                    </details>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            onChange({
                                mode: 'none',
                                track: '',
                                required: false,
                            })
                        }
                    >
                        Use no added music instead
                    </Button>
                    <p className="text-xs text-muted-foreground">
                        Only remove the plan after deciding to use the file’s
                        existing audio or intentionally publish without added
                        music.
                    </p>
                </>
            )}
        </section>
    );
}
