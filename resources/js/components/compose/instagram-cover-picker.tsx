import InstagramCoverController from '@/actions/App/Http/Controllers/Posts/InstagramCoverController';
import { instagramCoverFileError } from '@/lib/compose/instagram-cover';
import type { Account, InstagramPostOptions } from '@/types/compose';

import {
    WorkspaceCoverPicker,
    type WorkspaceCoverPickerConfig,
} from './workspace-cover-picker';

type Props = {
    account: Account;
    postId: string | null;
    options: InstagramPostOptions | undefined;
    canChoose: boolean;
    onChange: (options: InstagramPostOptions) => void;
    onUploadingChange: (uploading: boolean) => void;
};

const config: WorkspaceCoverPickerConfig = {
    platform: 'Instagram',
    title: 'Instagram Reel cover',
    selectedCoverAlt: 'Selected Instagram Reel cover',
    uploadInputLabel: 'Upload Instagram cover image',
    invalidSelectionMessage:
        'This cover requires exactly one video in a Reel or feed post. Change the format or media, or remove the cover before publishing.',
    uploadBecameInvalidMessage:
        'Cover uploaded. Choose a single-video Reel before selecting it.',
    galleryHint:
        'Workspace JPEG and PNG images up to 8 MB. Choose a cover to use it for this account.',
    galleryUrl: (postId, query) =>
        InstagramCoverController.index(postId, { query }).url,
    validateFile: instagramCoverFileError,
};

export function InstagramCoverPicker({ options, onChange, ...props }: Props) {
    return (
        <WorkspaceCoverPicker
            {...props}
            config={config}
            selectedId={options?.cover_media_id ?? null}
            onChange={(mediaId) =>
                onChange({ ...options, cover_media_id: mediaId })
            }
        />
    );
}
