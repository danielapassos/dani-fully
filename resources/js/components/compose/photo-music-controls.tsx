import { useId } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Account } from '@/types/compose';

export function PhotoMusicControls({
    account,
    track,
    onChange,
}: {
    account: Account;
    track: string;
    onChange: (track: string) => void;
}) {
    const id = useId();
    const platform = account.platform === 'instagram' ? 'Instagram' : 'TikTok';

    return (
        <section className="space-y-2 border-t border-border px-6 py-4">
            <label htmlFor={id} className="text-sm font-medium">
                Music to add in {platform} (optional)
            </label>
            <p className="text-xs text-muted-foreground">{account.handle}</p>
            <div className="flex items-center gap-2">
                <Input
                    id={id}
                    value={track}
                    maxLength={200}
                    placeholder="Song title and artist"
                    aria-describedby={`${id}-help`}
                    onChange={(event) => onChange(event.target.value)}
                />
                {track && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onChange('')}
                    >
                        Clear music note
                    </Button>
                )}
            </div>
            <p
                id={`${id}-help`}
                className="text-xs leading-5 text-muted-foreground"
            >
                {account.platform === 'instagram'
                    ? 'This saves your song choice as a note. Instagram music must be added in Instagram, so a photo post with this note stays a draft for native completion. Clear the note to publish without music from Shoutrrr.'
                    : 'This saves a song note with your photo inbox handoff. After upload, choose the song in TikTok before posting. Shoutrrr does not select licensed music automatically.'}
            </p>
        </section>
    );
}
