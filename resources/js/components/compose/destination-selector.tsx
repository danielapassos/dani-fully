import { router } from '@inertiajs/react';
import type React from 'react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    AlertTriangle,
    Check,
    ChevronDown,
    Layers,
} from '@/components/ui/icons';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { index as accountsRoute } from '@/routes/accounts';
import type { Account, AccountSet, Destination } from '@/types/compose';

/** Per-platform brand accent for the glyph badge (mirrors the accounts page). */
const PLATFORM_BRAND: Record<string, { tile: string; glyph: string }> = {
    x: { tile: 'bg-white', glyph: 'text-black!' },
    linkedin: { tile: 'bg-blue-600', glyph: 'text-white!' },
    bluesky: { tile: 'bg-sky-500', glyph: 'text-white!' },
    facebook: { tile: 'bg-[#1877F2]', glyph: 'text-white!' },
    instagram: { tile: 'bg-[#E4405F]', glyph: 'text-white!' },
    tiktok: { tile: 'bg-black', glyph: 'text-white!' },
    youtube: { tile: 'bg-[#FF0033]', glyph: 'text-white!' },
    threads: { tile: 'bg-black', glyph: 'text-white!' },
    discord: { tile: 'bg-[#5865F2]', glyph: 'text-white!' },
};

const PLATFORM_FALLBACK = { tile: 'bg-muted', glyph: 'text-muted-foreground' };

/**
 * Avatar with the platform logo tucked into the bottom-right corner. The badge
 * stays inside the avatar bounds so it never gets clipped when this visual is
 * mirrored into the (overflow-hidden) trigger.
 */
function AccountVisual({ account }: { account: Account }) {
    const brand = PLATFORM_BRAND[account.platform] ?? PLATFORM_FALLBACK;

    return (
        <span className="relative inline-grid shrink-0">
            <Avatar className="size-5">
                <AvatarImage
                    src={account.avatar_url ?? undefined}
                    alt={account.handle}
                />
                <AvatarFallback className="text-[9px] font-medium">
                    {account.handle.replace(/^@/, '').slice(0, 1).toUpperCase()}
                </AvatarFallback>
            </Avatar>
            <span
                className={cn(
                    'absolute right-0 bottom-0 grid size-2.5 place-items-center rounded-full ring-2 ring-popover',
                    brand.tile,
                    brand.glyph,
                )}
            >
                {/* size-* class is required: shared item CSS force-sizes any
                    class-less svg to size-4 (16px). */}
                <PlatformGlyph
                    platform={account.platform}
                    className={cn('size-1.5', brand.glyph)}
                />
            </span>
        </span>
    );
}

/** Leading icon for an account set. */
function SetVisual() {
    return (
        <span className="grid size-5 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground">
            <Layers className="size-3" />
        </span>
    );
}

type DestinationSelectorProps = {
    accounts: Account[];
    sets: AccountSet[];
    destination: Destination;
    onChange: (destination: Destination) => void;
    /** Lock the selector (read-only post). */
    disabled?: boolean;
};

export function destinationAccountIds(
    destination: Destination,
    accounts: Account[],
    sets: AccountSet[],
): string[] {
    const available = new Set(accounts.map((account) => account.id));
    if (destination.kind === 'account') {
        return available.has(destination.id) ? [destination.id] : [];
    }
    if (destination.kind === 'accounts') {
        return destination.ids.filter((id) => available.has(id));
    }
    if (destination.kind === 'set') {
        const setIds =
            sets.find((set) => set.id === destination.id)
                ?.connected_account_ids ?? [];

        return setIds.filter((id) => available.has(id));
    }
    if (destination.kind === 'none') {
        return [];
    }

    return accounts.map((account) => account.id);
}

export function publishingAccountIds(
    destination: Destination,
    accounts: Account[],
    sets: AccountSet[],
): string[] {
    const publishableIds = new Set(
        accounts
            .filter((account) => account.publishing_ready !== false)
            .map((account) => account.id),
    );

    return destinationAccountIds(destination, accounts, sets).filter((id) =>
        publishableIds.has(id),
    );
}

/** Persisted drafts retain every stored target; only new composers filter defaults. */
export function composerAccountIds(
    hasPersistedPost: boolean,
    destination: Destination,
    accounts: Account[],
    sets: AccountSet[],
): string[] {
    return hasPersistedPost
        ? destinationAccountIds(destination, accounts, sets)
        : publishingAccountIds(destination, accounts, sets);
}

function explicitDestinationFor(ids: string[]): Destination {
    if (ids.length === 0) {
        return { kind: 'none' };
    }
    if (ids.length === 1) {
        return { kind: 'account', id: ids[0] };
    }

    return { kind: 'accounts', ids };
}

/**
 * A saved "all" or set destination may outlive a provider's publishing grant.
 * Convert only stale destinations to explicit ready account ids so an autosave
 * cannot silently add an unavailable account back server-side.
 */
export function normalizePublishingDestination(
    destination: Destination,
    accounts: Account[],
    sets: AccountSet[],
): Destination {
    const readyIds = publishingAccountIds(destination, accounts, sets);

    if (destination.kind === 'none') {
        return destination;
    }
    if (destination.kind === 'all' && readyIds.length === accounts.length) {
        return destination;
    }
    if (destination.kind === 'set') {
        const set = sets.find((item) => item.id === destination.id);
        if (
            set &&
            readyIds.length === set.connected_account_ids.length &&
            set.connected_account_ids.every((id) => readyIds.includes(id))
        ) {
            return destination;
        }
    }
    if (
        destination.kind === 'account' &&
        readyIds.length === 1 &&
        readyIds[0] === destination.id
    ) {
        return destination;
    }
    if (
        destination.kind === 'accounts' &&
        readyIds.length === destination.ids.length &&
        readyIds.every((id) => destination.ids.includes(id))
    ) {
        return destination;
    }

    return explicitDestinationFor(readyIds);
}

function sameIds(left: string[], right: string[]): boolean {
    if (left.length !== right.length) {
        return false;
    }
    const selected = new Set(left);

    return right.every((id) => selected.has(id));
}

function destinationFromIds(
    ids: string[],
    accounts: Account[],
    preferredSet: AccountSet | null = null,
): Destination {
    if (ids.length === 0) {
        return { kind: 'none' };
    }
    if (ids.length === accounts.length) {
        return { kind: 'all' };
    }
    if (
        preferredSet &&
        preferredSet.connected_account_ids.length === ids.length &&
        sameIds(ids, preferredSet.connected_account_ids)
    ) {
        return { kind: 'set', id: preferredSet.id };
    }
    if (ids.length === 1) {
        return { kind: 'account', id: ids[0] };
    }

    return { kind: 'accounts', ids };
}

function triggerLabel(
    destination: Destination,
    selectedIds: string[],
    accounts: Account[],
    sets: AccountSet[],
): string {
    const publishableCount = accounts.filter(
        (account) => account.publishing_ready !== false,
    ).length;
    if (selectedIds.length === 0) {
        return 'No accounts';
    }
    if (selectedIds.length === publishableCount) {
        return 'All accounts';
    }
    if (destination.kind === 'set') {
        return sets.find((s) => s.id === destination.id)?.name ?? 'Set';
    }
    if (selectedIds.length === 1) {
        return (
            accounts.find((a) => a.id === selectedIds[0])?.handle ?? '1 account'
        );
    }

    return `${selectedIds.length} accounts`;
}

export default function DestinationSelector({
    accounts,
    sets,
    destination,
    onChange,
    disabled = false,
}: DestinationSelectorProps) {
    const publishableAccounts = accounts.filter(
        (account) => account.publishing_ready !== false,
    );
    const publishableIds = new Set(
        publishableAccounts.map((account) => account.id),
    );
    const selectedIds = publishingAccountIds(destination, accounts, sets);
    const selected = new Set(selectedIds);
    const label = triggerLabel(destination, selectedIds, accounts, sets);
    const allSelected =
        publishableAccounts.length > 0 &&
        selectedIds.length === publishableAccounts.length;

    // "All accounts" toggles the whole roster: on when nothing (or a subset) is
    // selected, off when everything is. Clearing to none leaves publishing
    // disabled until the user picks the specific accounts they want.
    function toggleAll() {
        onChange(
            allSelected
                ? { kind: 'none' }
                : destinationFromIds(
                      publishableAccounts.map((account) => account.id),
                      accounts,
                  ),
        );
    }

    function toggleAccount(accountId: string) {
        const next = selected.has(accountId)
            ? selectedIds.filter((id) => id !== accountId)
            : [...selectedIds, accountId];

        onChange(destinationFromIds(next, accounts));
    }

    function toggleSet(set: AccountSet) {
        const eligibleIds = set.connected_account_ids.filter((id) =>
            publishableIds.has(id),
        );
        if (eligibleIds.length === 0) {
            return;
        }
        const allSetAccountsSelected = eligibleIds.every((id) =>
            selected.has(id),
        );
        const setIds = new Set(eligibleIds);
        const next = allSetAccountsSelected
            ? selectedIds.filter((id) => !setIds.has(id))
            : [...new Set([...selectedIds, ...eligibleIds])];

        onChange(destinationFromIds(next, accounts, set));
    }

    return (
        <Popover>
            <PopoverTrigger
                disabled={disabled}
                render={
                    <button
                        type="button"
                        aria-label="Post destination"
                        className="inline-flex h-7 max-w-[116px] items-center gap-1 rounded-md border border-transparent bg-transparent px-2 text-[12px] text-muted-foreground hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-50 sm:max-w-[150px]"
                    />
                }
            >
                <span className="truncate">{label}</span>
                <ChevronDown className="size-3 shrink-0 opacity-70" />
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className="w-[288px] gap-1 rounded-3xl p-2 text-sm"
            >
                <div className="px-2 py-1.5 text-xs font-medium text-muted-foreground">
                    Sets
                </div>
                <OptionButton selected={allSelected} onClick={toggleAll}>
                    <SetVisual />
                    All accounts
                </OptionButton>
                {sets.map((set) => {
                    const eligibleIds = set.connected_account_ids.filter((id) =>
                        publishableIds.has(id),
                    );

                    return (
                        <OptionButton
                            key={set.id}
                            selected={
                                eligibleIds.length > 0 &&
                                sameIds(selectedIds, eligibleIds)
                            }
                            disabled={eligibleIds.length === 0}
                            onClick={() => toggleSet(set)}
                        >
                            <SetVisual />
                            {set.name}
                        </OptionButton>
                    );
                })}
                {accounts.length > 0 && (
                    <>
                        <div className="px-2 pt-3 pb-1.5 text-xs font-medium text-muted-foreground">
                            Accounts
                        </div>
                        {accounts.map((account) =>
                            account.publishing_ready === false ? (
                                <PublishingUnavailableRow
                                    key={account.id}
                                    account={account}
                                />
                            ) : (
                                <OptionButton
                                    key={account.id}
                                    selected={selected.has(account.id)}
                                    onClick={() => toggleAccount(account.id)}
                                >
                                    <AccountVisual account={account} />
                                    <span className="min-w-0 flex-1 truncate">
                                        {account.handle}
                                    </span>
                                    {account.status === 'needs_attention' && (
                                        <NeedsAttentionLabel
                                            handle={account.handle}
                                        />
                                    )}
                                </OptionButton>
                            ),
                        )}
                    </>
                )}
            </PopoverContent>
        </Popover>
    );
}

function PublishingUnavailableRow({ account }: { account: Account }) {
    const reason =
        account.publishing_unavailable_reason ??
        'Reconnect this account before publishing.';

    function openAccounts() {
        router.visit(accountsRoute().url);
    }

    return (
        <div
            className="flex min-h-10 w-full items-center gap-2 rounded-xl px-2 py-1.5 text-left text-sm"
            aria-disabled="true"
        >
            <AccountVisual account={account} />
            <span className="min-w-0 flex-1">
                <span className="block truncate text-muted-foreground">
                    {account.handle}
                </span>
                <span className="block truncate text-[11px] text-destructive">
                    {reason}
                </span>
            </span>
            <Tooltip>
                <TooltipTrigger
                    render={
                        <button
                            type="button"
                            className="inline-grid size-6 shrink-0 place-items-center rounded-md text-destructive outline-hidden hover:bg-destructive/10 focus-visible:ring-2 focus-visible:ring-destructive/40"
                            aria-label={`Manage ${account.handle} on Accounts`}
                            onClick={openAccounts}
                        />
                    }
                >
                    <AlertTriangle className="size-3.5" aria-hidden />
                </TooltipTrigger>
                <TooltipContent side="top">
                    {reason} Open Accounts to fix it.
                </TooltipContent>
            </Tooltip>
        </div>
    );
}

function NeedsAttentionLabel({ handle }: { handle?: string }) {
    function openAccounts(event: React.MouseEvent | React.KeyboardEvent) {
        event.preventDefault();
        event.stopPropagation();
        router.visit(accountsRoute().url);
    }

    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <span
                        role="link"
                        tabIndex={0}
                        className="ml-auto inline-grid size-4 shrink-0 cursor-pointer place-items-center rounded-sm text-destructive outline-hidden hover:bg-destructive/10 focus-visible:ring-2 focus-visible:ring-destructive/40"
                        aria-label={
                            handle
                                ? `${handle} needs attention`
                                : 'Account needs attention'
                        }
                        onClick={openAccounts}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' || event.key === ' ') {
                                openAccounts(event);
                            }
                        }}
                    />
                }
            >
                <AlertTriangle className="size-3.5" aria-hidden />
            </TooltipTrigger>
            <TooltipContent side="top">
                {handle
                    ? `Reconnect ${handle} before posting.`
                    : 'Reconnect the account before posting.'}
            </TooltipContent>
        </Tooltip>
    );
}

function OptionButton({
    selected,
    children,
    onClick,
    disabled = false,
}: {
    selected: boolean;
    children: React.ReactNode;
    onClick: () => void;
    disabled?: boolean;
}) {
    return (
        <button
            type="button"
            aria-pressed={selected}
            onClick={onClick}
            disabled={disabled}
            className="flex min-h-8 w-full items-center gap-2 rounded-xl px-2 py-1.5 text-left text-sm outline-hidden select-none hover:bg-muted focus-visible:bg-muted disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent"
        >
            <span className="flex min-w-0 flex-1 items-center gap-2">
                {children}
            </span>
            <Check
                className={cn(
                    'ml-auto size-4 shrink-0 text-foreground',
                    selected ? 'opacity-100' : 'opacity-0',
                )}
            />
        </button>
    );
}
