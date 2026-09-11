import data from '@emoji-mart/data';
import Picker from '@emoji-mart/react';

interface EmojiData {
  native: string;
  id: string;
  name: string;
}

interface EmojiPickerContentProps {
  onEmojiSelect: (emoji: string) => void;
}

export default function EmojiPickerContent({ onEmojiSelect }: EmojiPickerContentProps) {
  const handleSelect = (emoji: EmojiData) => {
    onEmojiSelect(emoji.native);
  };

  return (
    <Picker
      data={data}
      onEmojiSelect={handleSelect}
      theme="light"
      previewPosition="none"
      skinTonePosition="none"
      maxFrequentRows={2}
      perLine={9}
    />
  );
}
