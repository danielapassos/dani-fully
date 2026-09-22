import { Switch } from '@/components/ui/switch';
import type {
    Account,
    InstagramPostOptions,
    InstagramTrialParams,
} from '@/types/compose';

type Props = {
    account: Account;
    options: InstagramPostOptions | undefined;
    canChoose: boolean;
    onChange: (
        strategy: InstagramTrialParams['graduation_strategy'] | null,
    ) => void;
};

export function InstagramTrialControls({
    account,
    options,
    canChoose,
    onChange,
}: Props) {
    const strategy = options?.trial_params?.graduation_strategy;
    if (account.platform !== 'instagram' || (!canChoose && !strategy)) {
        return null;
    }
    const id = `instagram-trial-${account.id}`;

    return (
        <section
            className="space-y-3 border-t border-border px-4 py-4 sm:px-[26px]"
            aria-label={`Instagram Trial Reel settings for ${account.handle}`}
        >
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <label htmlFor={id} className="text-sm font-medium">
                        Trial Reel
                    </label>
                    <p
                        id={`${id}-description`}
                        className="text-xs text-muted-foreground"
                    >
                        {account.handle} · Show this Reel to non-followers
                        first.
                    </p>
                </div>
                <Switch
                    id={id}
                    checked={strategy !== undefined}
                    aria-describedby={`${id}-description`}
                    onCheckedChange={(checked) =>
                        onChange(checked ? 'MANUAL' : null)
                    }
                />
            </div>
            {strategy && (
                <fieldset className="grid gap-2 text-sm" disabled={!canChoose}>
                    <legend className="sr-only">Sharing with followers</legend>
                    <label className="flex items-start gap-2">
                        <input
                            type="radio"
                            name={`${id}-strategy`}
                            value="MANUAL"
                            checked={strategy === 'MANUAL'}
                            onChange={() => onChange('MANUAL')}
                            className="mt-1 accent-primary"
                        />
                        Share with followers manually
                    </label>
                    <label className="flex items-start gap-2">
                        <input
                            type="radio"
                            name={`${id}-strategy`}
                            value="SS_PERFORMANCE"
                            checked={strategy === 'SS_PERFORMANCE'}
                            onChange={() => onChange('SS_PERFORMANCE')}
                            className="mt-1 accent-primary"
                        />
                        Automatically share with followers if it performs well
                    </label>
                </fieldset>
            )}
            {strategy && !canChoose && (
                <p role="alert" className="text-xs text-destructive">
                    Trial Reels require exactly one video in a Reel or feed
                    post. Change the media or format, or turn off Trial Reel
                    before publishing.
                </p>
            )}
            {strategy === 'MANUAL' && (
                <p className="text-xs text-muted-foreground">
                    To share with everyone later, open this Reel in Instagram.
                </p>
            )}
        </section>
    );
}

export function InstagramTrialSummary({
    options,
}: {
    options?: InstagramPostOptions;
}) {
    const strategy = options?.trial_params?.graduation_strategy;
    if (!strategy) {
        return null;
    }

    return (
        <div
            className="mb-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-xs"
            aria-label="Configured Instagram Trial Reel"
        >
            <p className="font-medium">
                Trial Reel configured · Non-followers first
            </p>
            <p className="mt-1 text-muted-foreground">
                {strategy === 'MANUAL'
                    ? 'Share with followers manually in Instagram.'
                    : 'Automatically share with followers if it performs well.'}
            </p>
        </div>
    );
}
