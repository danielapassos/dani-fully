import { useId, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';
import type { TargetView } from '@/types/compose';

export function TikTokInboxCompletion({
    handoff,
}: {
    handoff: NonNullable<TargetView['manual_completion']>;
}) {
    const captionId = useId();
    const captionRef = useRef<HTMLTextAreaElement>(null);
    const [copiedText, copy] = useClipboard();
    const [copyFailed, setCopyFailed] = useState(false);
    const includesPhotoCaption =
        handoff.media_type === 'photo' && handoff.caption_included === true;

    async function copyCaption() {
        const copied = await copy(handoff.caption);
        setCopyFailed(!copied);
        if (!copied) {
            captionRef.current?.focus();
            captionRef.current?.select();
        }
    }

    return (
        <section className="mb-4 space-y-3 rounded-xl border border-border bg-muted/30 p-4">
            <h3 className="text-sm font-semibold">Finish in TikTok</h3>
            <p className="text-sm leading-6 text-muted-foreground">
                {handoff.instructions}
            </p>
            {handoff.media_type === 'photo' && handoff.music_track && (
                <p className="text-sm leading-6">
                    <span className="font-medium">
                        Music to add in TikTok:{' '}
                    </span>
                    {handoff.music_track}
                    <span className="block text-xs text-muted-foreground">
                        Choose this song in TikTok before posting, if available
                        for your account. It has not been selected
                        automatically.
                    </span>
                </p>
            )}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <label htmlFor={captionId} className="text-sm font-medium">
                    {includesPhotoCaption
                        ? 'Photo caption to review in TikTok'
                        : 'Caption to paste in TikTok'}
                </label>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={handoff.caption.length === 0}
                    onClick={() => void copyCaption()}
                >
                    Copy caption
                </Button>
            </div>
            <Textarea
                id={captionId}
                ref={captionRef}
                readOnly
                value={handoff.caption}
                className="max-h-64 border-border bg-background"
            />
            <p role="status" className="text-xs text-muted-foreground">
                {copyFailed
                    ? 'Could not copy automatically. The caption is selected so you can copy it manually.'
                    : copiedText === handoff.caption &&
                        handoff.caption.length > 0
                      ? includesPhotoCaption
                          ? 'Caption copied. Use it if you need to restore the approved caption in TikTok.'
                          : 'Caption copied. Paste it over any text TikTok prefilled, then confirm the mentions.'
                      : includesPhotoCaption
                        ? 'The photo upload includes this caption. Review it and choose music in TikTok before posting.'
                        : 'The caption is saved in Shoutrrr. TikTok does not receive it with the video.'}
            </p>
        </section>
    );
}
