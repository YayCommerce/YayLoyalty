import { Trash } from '@phosphor-icons/react';

import { Button } from '../button';

function ImagePicker({
  coverSrc,
  onSelectCover,
  onRemoveCover,
}: {
  coverSrc: string;
  onSelectCover: (src: string) => void;
  onRemoveCover: (src: string) => void;
}) {
  const imgFallback = '';

  const handleImageUpload = () => {
    // Create the media frame.
    const file_frame = (window.wp.media.frames.downloadable_file = window.wp.media({
      title: 'Choose an image',
      multiple: false,
    }));
    // When an image is selected, run a callback.
    file_frame.on('select', function () {
      const attachment = file_frame.state().get('selection').first().toJSON();
      const attachment_thumbnail = attachment.sizes.full || attachment.sizes.thumbnail;

      onSelectCover(attachment_thumbnail.url);
    });
    // Finally, open the modal.
    file_frame.open();
  };

  const handleRemoveCover = () => {
    onRemoveCover(imgFallback);
  };

  return (
    <div className="flex items-center gap-2">
      <Button variant="outline" onClick={handleImageUpload}>
        <span>{coverSrc ? 'Replace Image' : 'Upload Image'}</span>
      </Button>

      {coverSrc && (
        <Button variant="outline" onClick={handleRemoveCover}>
          <Trash className="text-red-600" />
        </Button>
      )}
    </div>
  );
}

export { ImagePicker };
