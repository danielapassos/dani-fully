import { useEffect, useRef, useState } from 'react';

import { InstagramTrialSummary } from '@/components/compose/instagram-trial-controls';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    Bookmark,
    ChevronLeft,
    ChevronRight,
    Heart,
    Image,
    MessageCircle,
    MoreHorizontal,
    Send,
} from '@/components/ui/icons';
import type { PlatformPreview } from '@/lib/compose/platform-preview';
import { LinkedText } from '@/lib/linked-text';
import { cn } from '@/lib/utils';
import type { MediaView } from '@/types/compose';

import {
    clampIndex,
    handleName,
    PREVIEW_ENTITY_LINK,
    previewInitials,
    previewMedia,
} from './helpers';
import { PreviewVideo } from './preview-video';
import { ReelsFrame } from './reels-frame';
import { StoryFrame } from './story-frame';

function VerifiedBadge() {
    return (
        <svg
            viewBox="0 0 24 24"
            className="size-3 shrink-0"
            aria-label="Verified"
        >
            <path
                fill="#0095F6"
                d="M12 1l2.4 2.2 3.2-.4 1.3 3 3 1.3-.4 3.2L23 12l-2.2 2.4.4 3.2-3 1.3-1.3 3-3.2-.4L12 23l-2.4-2.2-3.2.4-1.3-3-3-1.3.4-3.2L1 12l2.2-2.4-.4-3.2 3-1.3 1.3-3 3.2.4z"
            />
            <path
                fill="#fff"
                d="M10.6 14.6l-2.3-2.3 1.1-1.1 1.2 1.2 3.9-3.9 1.1 1.1z"
            />
        </svg>
    );
}

/** Horizontal drag past this many pixels flips to the next/previous slide. */
const SWIPE_THRESHOLD = 40;

function sourceAspectRatio(
    width: number | null | undefined,
    height: number | null | undefined,
): number | null {
    return typeof width === 'number' &&
        typeof height === 'number' &&
        Number.isFinite(width) &&
        Number.isFinite(height) &&
        width > 0 &&
        height > 0
        ? width / height
        : null;
}

/** The first source sets a stable carousel frame; every slide remains uncropped. */
function InstagramCarousel({ media }: { media: MediaView[] }) {
    const [index, setIndex] = useState(0);
    const [loadedRatio, setLoadedRatio] = useState<{
        url: string;
        ratio: number;
    } | null>(null);
    const dragStartX = useRef<number | null>(null);
    const safeIndex = clampIndex(index, media.length);
    const current = media[safeIndex];
    const first = media[0];
    const firstUrl = first?.url;
    const firstKind = first?.kind;
    const storedRatio = sourceAspectRatio(first?.width, first?.height);
    const aspectRatio =
        storedRatio ??
        (loadedRatio?.url === firstUrl ? loadedRatio?.ratio : null) ??
        1;
    const many = media.length > 1;

    useEffect(() => {
        if (!firstUrl || firstKind !== 'image' || storedRatio !== null) {
            return;
        }

        // Loading the first image independently keeps the frame correct even
        // when someone moves to the next slide before that image has loaded.
        const image = new window.Image();
        let active = true;
        const readDimensions = () => {
            const ratio = sourceAspectRatio(
                image.naturalWidth,
                image.naturalHeight,
            );
            if (active && ratio !== null) {
                setLoadedRatio({ url: firstUrl, ratio });
            }
        };
        image.onload = readDimensions;
        image.src = firstUrl;
        if (image.complete) {
            readDimensions();
        }

        return () => {
            active = false;
            image.onload = null;
        };
    }, [firstUrl, firstKind, storedRatio]);

    const go = (delta: number) => {
        setIndex((previous) => clampIndex(previous + delta, media.length));
    };

    if (!current) {
        return null;
    }

    return (
        <div
            role="group"
            aria-label="Instagram media preview"
            className="group relative w-full touch-pan-y overflow-hidden bg-neutral-950 select-none"
            style={{ aspectRatio }}
            onPointerDown={
                many
                    ? (event) => {
                          dragStartX.current = event.clientX;
                      }
                    : undefined
            }
            onPointerUp={
                many
                    ? (event) => {
                          if (dragStartX.current === null) {
                              return;
                          }
                          const dx = event.clientX - dragStartX.current;
                          dragStartX.current = null;
                          if (dx <= -SWIPE_THRESHOLD) {
                              go(1);
                          } else if (dx >= SWIPE_THRESHOLD) {
                              go(-1);
                          }
                      }
                    : undefined
            }
            onPointerCancel={() => {
                dragStartX.current = null;
            }}
        >
            {current.kind === 'video' ? (
                <PreviewVideo
                    key={current.id}
                    src={current.url}
                    className="absolute inset-0 size-full"
                    videoClassName="object-contain"
                />
            ) : (
                <img
                    key={current.id}
                    src={current.url}
                    alt={current.alt_text ?? ''}
                    draggable={false}
                    className="absolute inset-0 size-full object-contain"
                />
            )}

            {many && (
                <>
                    <span className="absolute top-2.5 right-2.5 rounded-full bg-black/60 px-2 py-0.5 text-[11px] font-semibold text-white tabular-nums">
                        {safeIndex + 1}/{media.length}
                    </span>

                    {safeIndex > 0 && (
                        <button
                            type="button"
                            aria-label="Previous photo"
                            onClick={() => go(-1)}
                            className="absolute top-1/2 left-2 grid size-6 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-neutral-800 opacity-0 shadow-sm transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                        >
                            <ChevronLeft className="size-4" aria-hidden />
                        </button>
                    )}
                    {safeIndex < media.length - 1 && (
                        <button
                            type="button"
                            aria-label="Next photo"
                            onClick={() => go(1)}
                            className="absolute top-1/2 right-2 grid size-6 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-neutral-800 opacity-0 shadow-sm transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                        >
                            <ChevronRight className="size-4" aria-hidden />
                        </button>
                    )}

                    <div className="absolute inset-x-0 bottom-1.5 flex items-center justify-center">
                        {media.map((item, dot) => (
                            <button
                                key={item.id}
                                type="button"
                                aria-label={`Go to photo ${dot + 1}`}
                                aria-current={dot === safeIndex}
                                onClick={() => setIndex(dot)}
                                className="flex items-center justify-center px-1 py-1.5"
                            >
                                <span
                                    className={cn(
                                        'block size-1.5 rounded-full transition-colors',
                                        dot === safeIndex
                                            ? 'bg-[#0095F6]'
                                            : 'bg-white/60',
                                    )}
                                />
                            </button>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

function InstagramFeedPost({ preview }: { preview: PlatformPreview }) {
    const media = previewMedia(preview);
    const item = preview.items[0];
    const caption = item?.text ?? '';

    return (
        <article className="mx-auto w-full max-w-[380px] overflow-hidden rounded-xl border border-border bg-background">
            <header className="flex items-center gap-2.5 px-3 py-2.5">
                <span className="rounded-full bg-gradient-to-tr from-[#FEDA75] via-[#D62976] to-[#4F5BD5] p-[2px]">
                    <Avatar className="size-8 bg-background ring-2 ring-background">
                        <AvatarImage src={preview.avatarUrl ?? undefined} />
                        <AvatarFallback className="text-[10px] font-semibold">
                            {previewInitials(preview.accountName)}
                        </AvatarFallback>
                    </Avatar>
                </span>
                <div className="flex min-w-0 items-center gap-1">
                    <span className="truncate text-[13px] font-semibold text-foreground">
                        {handleName(preview)}
                    </span>
                    <VerifiedBadge />
                </div>
                <MoreHorizontal
                    className="ml-auto size-4 text-foreground"
                    aria-hidden
                />
            </header>

            {media.length > 0 ? (
                <>
                    <InstagramCarousel
                        key={`${media[0].id}:${media[0].url}`}
                        media={media}
                    />
                    <p className="px-3 pt-2 text-[11px] text-muted-foreground">
                        Source preview. Instagram may adjust framing.
                    </p>
                </>
            ) : (
                <div className="grid aspect-square w-full place-items-center bg-muted/60 text-center">
                    <div className="space-y-1.5 px-6 text-muted-foreground">
                        <Image className="mx-auto size-6" aria-hidden />
                        <p className="text-[12px] leading-4">
                            Add a photo or video — Instagram posts always
                            include media.
                        </p>
                    </div>
                </div>
            )}

            <div className="flex items-center gap-4 px-3 pt-2.5 text-foreground">
                <Heart className="size-6" aria-hidden />
                <MessageCircle className="size-6" aria-hidden />
                <Send className="size-6" aria-hidden />
                <Bookmark className="ml-auto size-6" aria-hidden />
                <span className="sr-only">Like · Comment · Share · Save</span>
            </div>

            <div className="space-y-1 px-3 pt-2 pb-3">
                <p className="text-[13px] leading-5 wrap-anywhere whitespace-pre-wrap text-foreground">
                    <span className="font-semibold">{handleName(preview)}</span>{' '}
                    <LinkedText
                        text={caption}
                        platform="instagram"
                        linkExclusions={item?.linkExclusions ?? []}
                        linkClassName={PREVIEW_ENTITY_LINK}
                        emptyFallback={
                            <span className="text-muted-foreground">
                                Write a caption to see it here.
                            </span>
                        }
                    />
                </p>
                <p className="text-[12px] text-muted-foreground">
                    View all comments
                </p>
                <p className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                    Just now
                </p>
            </div>
        </article>
    );
}

/**
 * Renders the active account's Instagram surface. The format comes from the
 * composer (per account): a feed post, a Reel, or a Story. Feed media shows the
 * full source image; Instagram controls the final published framing.
 */
export function InstagramPreview({ preview }: { preview: PlatformPreview }) {
    return (
        <div className="p-4">
            <InstagramTrialSummary options={preview.instagramOptions} />
            {preview.format === 'story' ? (
                <StoryFrame preview={preview} platform="instagram" />
            ) : preview.format === 'reels' ? (
                <ReelsFrame preview={preview} platform="instagram" />
            ) : (
                <InstagramFeedPost preview={preview} />
            )}
        </div>
    );
}
