import type { PhotoMusicOptions } from '@/types/compose';

/** Canonical persistence/comparison; do not discard a required, unchosen song. */
export function normalizeMusicPlan(
    plan: PhotoMusicOptions | null | undefined,
): PhotoMusicOptions | null {
    if (!plan) return null;
    const entries = Object.entries(plan)
        .map(
            ([key, value]) =>
                [
                    key,
                    typeof value === 'string' ? value.trim() : value,
                ] as const,
        )
        .filter(
            ([, value]) =>
                value !== undefined && value !== null && value !== '',
        )
        .sort(([a], [b]) => a.localeCompare(b));
    if (!entries.some(([, value]) => value !== false)) return null;
    return { track: '', ...Object.fromEntries(entries) } as PhotoMusicOptions;
}

export function musicPlanSummary(plan: PhotoMusicOptions): string {
    return [
        `Track: ${plan.track || 'Choose in the native app'}`,
        plan.artist && `Artist: ${plan.artist}`,
        plan.version && `Version/remix: ${plan.version}`,
        plan.native_audio_id && `Native audio ID: ${plan.native_audio_id}`,
        plan.source_url && `Source: ${plan.source_url}`,
        plan.start_seconds != null && `Start: ${plan.start_seconds}s`,
        plan.duration_seconds != null && `Excerpt: ${plan.duration_seconds}s`,
        plan.music_volume != null && `Music volume: ${plan.music_volume}%`,
        plan.original_volume != null &&
            `Original sound: ${plan.original_volume}%`,
        plan.sync_notes && `Sync/mix notes: ${plan.sync_notes}`,
        plan.availability_checked_at &&
            `Manual availability check: ${plan.availability_checked_at}`,
        plan.availability_note &&
            `Availability notes: ${plan.availability_note}`,
        'Music is not attached. Confirm availability and preview the final mix in the destination app before posting.',
    ]
        .filter((line) => typeof line === 'string' && line.length > 0)
        .join('\n');
}

export function requiresNativeMusic(
    plan: PhotoMusicOptions | null | undefined,
): boolean {
    const normalized = normalizeMusicPlan(plan);
    return (
        normalized !== null &&
        (!['none', 'existing_audio'].includes(normalized.mode ?? '') ||
            normalized.required === true ||
            Boolean(normalized.track))
    );
}
