import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import PostMediaController from '@/actions/App/Http/Controllers/Posts/PostMediaController';
import { Button } from '@/components/ui/button';
import type { Account, MediaView } from '@/types/compose';

export type WorkspaceCoverPickerConfig = {
    platform: string;
    title: string;
    selectedCoverAlt: string;
    uploadInputLabel: string;
    invalidSelectionMessage: string;
    uploadBecameInvalidMessage: string;
    galleryHint: string;
    notice?: string;
    imageAspectRatio?: '16/9' | '9/16';
    galleryUrl: (postId: string, query: Record<string, string>) => string;
    validateFile: (file: File) => string | null;
};

type Props = {
    account: Account;
    postId: string | null;
    selectedId: string | null;
    config: WorkspaceCoverPickerConfig;
    canChoose: boolean;
    onChange: (mediaId: string | null) => void;
    onUploadingChange: (uploading: boolean) => void;
};

type CoverPage = { media: MediaView[]; next_cursor: string | null };

export function WorkspaceCoverPicker(props: Props) {
    // Inertia can reuse the composer across drafts. Keep requests and gallery
    // pagination scoped to the post and target that opened them.
    return (
        <CoverPicker
            key={`${props.config.platform}:${props.postId ?? 'new'}:${props.account.id}`}
            {...props}
        />
    );
}

function CoverPicker({
    account,
    postId,
    selectedId,
    config,
    canChoose,
    onChange,
    onUploadingChange,
}: Props) {
    const galleryHttp = useHttp<Record<string, never>, CoverPage>({});
    const uploadHttp = useHttp<{ file?: File }, { media: MediaView }>({});
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState<MediaView[]>([]);
    const [nextCursor, setNextCursor] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [revision, setRevision] = useState(0);
    const fileInput = useRef<HTMLInputElement>(null);
    const requestSequence = useRef(0);
    const mounted = useRef(true);
    const uploadStatus = useRef(onUploadingChange);
    const selectionAllowed = useRef(canChoose);
    uploadStatus.current = onUploadingChange;
    selectionAllowed.current = canChoose;
    const selected = items.find((item) => item.id === selectedId);

    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            requestSequence.current += 1;
        };
    }, []);

    useEffect(() => {
        uploadStatus.current(uploading);
        return () => uploadStatus.current(false);
    }, [uploading]);

    async function loadPage(cursor: string | null = null) {
        if (!postId) return;
        const sequence = ++requestSequence.current;
        setLoading(true);
        setError(null);
        try {
            const data = await galleryHttp.get(
                config.galleryUrl(postId, {
                    ...(selectedId ? { selected: selectedId } : {}),
                    ...(cursor ? { cursor } : {}),
                }),
                {
                    onHttpException: () => undefined,
                    onNetworkError: () => undefined,
                },
            );
            if (!mounted.current || sequence !== requestSequence.current)
                return;
            setItems((previous) =>
                Array.from(
                    new Map(
                        [...(cursor ? previous : []), ...data.media].map(
                            (item) => [item.id, item],
                        ),
                    ).values(),
                ),
            );
            setNextCursor(data.next_cursor);
        } catch {
            if (!mounted.current || sequence !== requestSequence.current)
                return;
            setError('Could not load cover images. Try again.');
        } finally {
            if (mounted.current && sequence === requestSequence.current) {
                setLoading(false);
            }
        }
    }

    useEffect(() => {
        if (!postId || (!open && !selectedId)) {
            setLoading(false);
            return;
        }
        void loadPage();
        return () => {
            requestSequence.current += 1;
        };
        // oxlint-disable-next-line react-hooks/exhaustive-deps -- Load for the current selection or an explicit gallery refresh, not callback/useHttp identity changes.
    }, [postId, selectedId, open, revision, config]);

    async function uploadCover(file: File) {
        if (!postId || !canChoose || uploading) return;
        const fileError = config.validateFile(file);
        if (fileError) {
            setError(fileError);
            return;
        }
        setUploading(true);
        setError(null);
        let validationMessage: string | undefined;
        uploadHttp.transform(() => ({ file }));
        try {
            const { media } = await uploadHttp.post(
                PostMediaController.store(postId).url,
                {
                    onError: (errors) => {
                        const first = errors.file ?? Object.values(errors)[0];
                        validationMessage =
                            typeof first === 'string' ? first : undefined;
                    },
                    onHttpException: () => undefined,
                    onNetworkError: () => undefined,
                },
            );
            if (!mounted.current) return;
            setItems((previous) => [
                media,
                ...previous.filter((item) => item.id !== media.id),
            ]);
            if (selectionAllowed.current) {
                // Cover uploads stay outside composer media and placements.
                onChange(media.id);
            } else {
                setError(config.uploadBecameInvalidMessage);
            }
        } catch {
            if (mounted.current) {
                setError(
                    validationMessage ??
                        'Could not upload the cover. Try again.',
                );
            }
        } finally {
            if (mounted.current) setUploading(false);
        }
    }

    return (
        <section
            className="space-y-3 border-t border-border px-4 py-4 sm:px-[26px]"
            aria-label={`${config.platform} cover for ${account.handle}`}
        >
            <div>
                <h3 className="text-sm font-medium">{config.title}</h3>
                <p className="text-sm text-muted-foreground">
                    {account.handle} · Optional cover image, separate from the
                    video.
                </p>
            </div>
            {config.notice && (
                <p className="text-sm text-muted-foreground">{config.notice}</p>
            )}
            {selectedId && (
                <div className="flex items-center gap-3">
                    {selected ? (
                        <img
                            src={selected.url}
                            alt={selected.alt_text || config.selectedCoverAlt}
                            className={`${config.imageAspectRatio === '16/9' ? 'h-20 w-36' : config.imageAspectRatio === '9/16' ? 'h-32 w-18' : 'h-28 w-20'} rounded-md border bg-muted object-contain`}
                        />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {loading
                                ? 'Loading selected cover…'
                                : 'Selected cover preview unavailable.'}
                        </p>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={uploading}
                        onClick={() => onChange(null)}
                    >
                        Remove cover
                    </Button>
                </div>
            )}
            {!canChoose && selectedId && (
                <p role="alert" className="text-sm text-destructive">
                    {config.invalidSelectionMessage}
                </p>
            )}
            {!postId ? (
                <p className="text-sm text-muted-foreground">
                    Save this draft to choose an existing cover.
                </p>
            ) : (
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!canChoose || uploading}
                        aria-expanded={open}
                        onClick={() => setOpen((value) => !value)}
                    >
                        {open ? 'Hide cover library' : 'Choose existing cover'}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!canChoose || uploading}
                        onClick={() => fileInput.current?.click()}
                    >
                        {uploading ? 'Uploading cover…' : 'Upload cover'}
                    </Button>
                    <input
                        ref={fileInput}
                        type="file"
                        accept="image/jpeg,image/png"
                        aria-label={config.uploadInputLabel}
                        className="hidden"
                        disabled={!canChoose || uploading}
                        onChange={(event) => {
                            const file = event.target.files?.[0];
                            event.target.value = '';
                            if (file) void uploadCover(file);
                        }}
                    />
                </div>
            )}
            {uploading && (
                <p role="status" className="text-sm text-muted-foreground">
                    Uploading cover…
                </p>
            )}
            {error && (
                <div className="flex items-center gap-2">
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                    {!uploading && (open || selectedId) && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setRevision((value) => value + 1)}
                        >
                            Refresh covers
                        </Button>
                    )}
                </div>
            )}
            {open && (
                <div className="space-y-3">
                    <p className="text-xs text-muted-foreground">
                        {config.galleryHint}
                    </p>
                    <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                        {items.map((item, index) => (
                            <button
                                key={item.id}
                                type="button"
                                className="overflow-hidden rounded-md border border-input bg-muted p-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-50 aria-pressed:border-primary aria-pressed:ring-2 aria-pressed:ring-primary"
                                aria-label={`Use ${item.alt_text || `cover image ${index + 1}`}`}
                                aria-pressed={item.id === selectedId}
                                disabled={!canChoose || uploading}
                                onClick={() => onChange(item.id)}
                            >
                                <img
                                    src={item.url}
                                    alt={item.alt_text || ''}
                                    className={`${config.imageAspectRatio === '16/9' ? 'aspect-video' : config.imageAspectRatio === '9/16' ? 'aspect-[9/16]' : 'aspect-[3/4]'} w-full object-contain`}
                                    loading="lazy"
                                />
                            </button>
                        ))}
                    </div>
                    {loading ? (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Loading cover images…
                        </p>
                    ) : items.length === 0 && !error ? (
                        <p className="text-sm text-muted-foreground">
                            No cover images yet. Upload a JPEG or PNG to get
                            started.
                        </p>
                    ) : null}
                    {nextCursor && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={loading || uploading}
                            onClick={() => void loadPage(nextCursor)}
                        >
                            Load more covers
                        </Button>
                    )}
                </div>
            )}
        </section>
    );
}
