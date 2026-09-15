import YouTubeCoverController from '@/actions/App/Http/Controllers/Posts/YouTubeCoverController';
import { youtubeThumbnailFileError } from '@/lib/compose/youtube';
import type { Account, YouTubePostOptions } from '@/types/compose';

import {
    WorkspaceCoverPicker,
    type WorkspaceCoverPickerConfig,
} from './workspace-cover-picker';

type Props = {
    account: Account;
    postId: string | null;
    thumbnailMediaId: string | null | undefined;
    formatIntent: YouTubePostOptions['format_intent'];
    canChoose: boolean;
    onChange: (mediaId: string | null) => void;
    onUploadingChange: (uploading: boolean) => void;
};

const config: WorkspaceCoverPickerConfig = {
    platform: 'YouTube',
    title: 'YouTube cover',
    selectedCoverAlt: 'Selected YouTube cover',
    uploadInputLabel: 'Upload YouTube cover image',
    invalidSelectionMessage:
        'This cover requires exactly one video. Add a video or remove the cover before publishing.',
    uploadBecameInvalidMessage:
        'Cover uploaded. Choose a single video before selecting it.',
    galleryHint:
        'Workspace JPEG and PNG images up to 8 MB. Choose a cover to use it for this channel.',
    notice: 'YouTube checks whether your channel and video can use a custom cover. With a cover selected, Shoutrrr uploads the video privately first. If applying the cover fails, the video stays private, even if you chose Public or Unlisted.',
    galleryUrl: (postId, query) =>
        YouTubeCoverController.index(postId, { query }).url,
    validateFile: youtubeThumbnailFileError,
};

const videoConfig: WorkspaceCoverPickerConfig = {
    ...config,
    imageAspectRatio: '16/9',
};
const shortConfig: WorkspaceCoverPickerConfig = {
    ...config,
    imageAspectRatio: '9/16',
};

export function YouTubeCoverPicker({
    thumbnailMediaId,
    formatIntent,
    ...props
}: Props) {
    return (
        <WorkspaceCoverPicker
            {...props}
            config={formatIntent === 'video' ? videoConfig : shortConfig}
            selectedId={thumbnailMediaId ?? null}
        />
    );
}
