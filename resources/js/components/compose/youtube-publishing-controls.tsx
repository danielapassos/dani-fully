import {
    normalizeYouTubePostOptions,
    YOUTUBE_DEFAULT_OPTIONS,
    youtubeCopyErrors,
} from '@/lib/compose/youtube';
import type { Account, YouTubePostOptions } from '@/types/compose';

type Props = {
    account: Account;
    options: YouTubePostOptions | undefined;
    onChange: (options: YouTubePostOptions) => void;
};

export function YouTubePublishingControls({
    account,
    options,
    onChange,
}: Props) {
    const normalizedOptions = options
        ? normalizeYouTubePostOptions(options)
        : undefined;
    const current = {
        ...normalizedOptions,
        category_id:
            options?.category_id ?? YOUTUBE_DEFAULT_OPTIONS.category_id,
        notify_subscribers:
            options?.notify_subscribers ??
            YOUTUBE_DEFAULT_OPTIONS.notify_subscribers,
    };
    function change(patch: YouTubePostOptions) {
        onChange({ ...current, ...patch });
    }
    function inherit(key: 'title' | 'description') {
        const next = { ...current };
        delete next[key];
        onChange(next);
    }
    const copyErrors = youtubeCopyErrors(current);
    const selectClass = 'h-9 rounded-md border border-input bg-background px-3';
    return (
        <section
            className="space-y-4 border-t border-border px-4 py-4 sm:px-[26px]"
            aria-label={`YouTube publishing settings for ${account.handle}`}
        >
            <div>
                <h3 className="text-sm font-medium">Post to YouTube</h3>
                <p className="text-sm text-muted-foreground">
                    {account.handle}
                </p>
            </div>
            <label className="grid gap-1.5 text-sm">
                Visibility
                <select
                    className={selectClass}
                    value={current.privacy_status ?? ''}
                    onChange={(event) =>
                        change({
                            privacy_status:
                                (event.target
                                    .value as YouTubePostOptions['privacy_status']) ||
                                undefined,
                        })
                    }
                >
                    <option value="">Choose visibility</option>
                    <option value="public">Public</option>
                    <option value="unlisted">Unlisted</option>
                    <option value="private">Private</option>
                </select>
            </label>
            {current.privacy_status === 'public' && (
                <p className="text-xs text-muted-foreground">
                    Publishes to your channel when YouTube confirms it is
                    public. YouTube may restrict uploads from an unverified app
                    to private.
                </p>
            )}
            {current.privacy_status && current.privacy_status !== 'public' && (
                <p className="text-xs text-muted-foreground">
                    This upload will not be a public channel post.
                </p>
            )}
            <label className="grid gap-1.5 text-sm">
                Video format
                <select
                    className={selectClass}
                    value={current.format_intent ?? ''}
                    onChange={(event) =>
                        change({
                            format_intent:
                                (event.target
                                    .value as YouTubePostOptions['format_intent']) ||
                                undefined,
                        })
                    }
                >
                    <option value="">Choose format</option>
                    <option value="short">Short</option>
                    <option value="video">Video</option>
                </select>
            </label>
            {current.format_intent === 'short' && (
                <p className="text-xs text-muted-foreground">
                    YouTube determines Shorts eligibility from the uploaded
                    video. Selecting Short does not crop or re-edit your file.
                </p>
            )}
            {(
                [
                    ['made_for_kids', 'Is this video made for kids?'],
                    [
                        'contains_synthetic_media',
                        'Does it contain realistic altered or synthetic content?',
                    ],
                    [
                        'has_paid_product_placement',
                        'Does it include a paid promotion?',
                    ],
                ] as const
            ).map(([key, label]) => (
                <label className="grid gap-1.5 text-sm" key={key}>
                    {label}
                    <select
                        className={selectClass}
                        value={
                            current[key] === undefined
                                ? ''
                                : String(current[key])
                        }
                        onChange={(event) =>
                            change({
                                [key]:
                                    event.target.value === ''
                                        ? undefined
                                        : event.target.value === 'true',
                            })
                        }
                    >
                        <option value="">Choose an answer</option>
                        <option value="false">No</option>
                        <option value="true">Yes</option>
                    </select>
                </label>
            ))}
            <label className="grid gap-1.5 text-sm">
                Category
                <select
                    className={selectClass}
                    value={current.category_id ?? '22'}
                    onChange={(event) =>
                        change({ category_id: event.target.value })
                    }
                >
                    <option value="22">People &amp; Blogs</option>
                    <option value="23">Comedy</option>
                    <option value="24">Entertainment</option>
                    <option value="19">Travel &amp; Events</option>
                    <option value="26">Howto &amp; Style</option>
                    <option value="17">Sports</option>
                    <option value="10">Music</option>
                    <option value="27">Education</option>
                    {current.category_id &&
                        ![
                            '22',
                            '23',
                            '24',
                            '19',
                            '26',
                            '17',
                            '10',
                            '27',
                        ].includes(current.category_id) && (
                            <option value={current.category_id}>
                                Category {current.category_id}
                            </option>
                        )}
                </select>
            </label>
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    className="size-4 accent-primary"
                    checked={current.notify_subscribers ?? false}
                    onChange={(event) =>
                        change({ notify_subscribers: event.target.checked })
                    }
                />
                Notify subscribers
            </label>
            <div className="space-y-3 border-t border-border pt-4">
                <label className="grid gap-1.5 text-sm">
                    YouTube title (optional)
                    <input
                        type="text"
                        className={selectClass}
                        value={current.title ?? ''}
                        placeholder="Use the post caption"
                        aria-invalid={!!copyErrors.title}
                        onChange={(event) =>
                            event.target.value === ''
                                ? inherit('title')
                                : change({ title: event.target.value })
                        }
                    />
                </label>
                <p className="text-xs text-muted-foreground">
                    Leave blank to use the post caption.{' '}
                    {Array.from(current.title ?? '').length}/100 characters.
                </p>
                {copyErrors.title && (
                    <p role="alert" className="text-sm text-destructive">
                        {copyErrors.title}
                    </p>
                )}
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="size-4 accent-primary"
                        checked={current.description === undefined}
                        onChange={(event) =>
                            event.target.checked
                                ? inherit('description')
                                : change({ description: '' })
                        }
                    />
                    Use post caption as description
                </label>
                <label className="grid gap-1.5 text-sm">
                    YouTube description (optional)
                    <textarea
                        className="min-h-24 rounded-md border border-input bg-background px-3 py-2 disabled:opacity-50"
                        value={current.description ?? ''}
                        disabled={current.description === undefined}
                        aria-invalid={!!copyErrors.description}
                        placeholder={
                            current.description === undefined
                                ? 'Using the post caption'
                                : 'Leave empty for no description'
                        }
                        onChange={(event) =>
                            change({ description: event.target.value })
                        }
                    />
                </label>
                <p className="text-xs text-muted-foreground">
                    Uncheck to write a separate description or leave it empty.
                    YouTube allows up to 5,000 bytes.
                </p>
                {copyErrors.description && (
                    <p role="alert" className="text-sm text-destructive">
                        {copyErrors.description}
                    </p>
                )}
            </div>
        </section>
    );
}
