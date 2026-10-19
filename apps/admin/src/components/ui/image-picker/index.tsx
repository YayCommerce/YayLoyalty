import { Trash2Icon } from 'lucide-react';

import { Button } from '../button';

/** The chosen attachment; callers persist the id. */
export type ImagePickerSelection = { id: number; url: string };

function ImagePicker({
  coverSrc,
  onSelectCover,
  onRemoveCover,
}: {
  coverSrc: string;
  onSelectCover: (selection: ImagePickerSelection) => void;
  onRemoveCover: (src: string) => void;
}) {
  const imgFallback = '';

  const handleImageUpload = () => {
    const file_frame = (window.wp.media.frames.downloadable_file = window.wp.media({
      title: 'Choose an image',
      multiple: false,
    }));
    file_frame.on('select', function () {
      const attachment = file_frame.state().get('selection').first().toJSON();
      const attachment_thumbnail = attachment.sizes.full || attachment.sizes.thumbnail;

      onSelectCover({ id: attachment.id, url: attachment_thumbnail.url });
    });
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
          <Trash2Icon className="text-red-600" />
        </Button>
      )}
    </div>
  );
}

export { ImagePicker };
