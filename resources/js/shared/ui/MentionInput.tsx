import React, { useState, useRef, memo } from 'react';
import {IconSend, IconAt, IconPaperclip, IconFileText, IconPhoto, IconMovie, IconFile, IconX} from '@tabler/icons-react';

export interface UserOption {
  id: number;
  name: string;
  email: string;
}

export interface MentionInputProps {
  value: string;
  onChange: (value: string) => void;
  onSubmit: () => void;
  users: UserOption[];
  disabled?: boolean;
  placeholder?: string;
  attachments: File[];
  onAttachmentsChange: (files: File[]) => void;
  maxAttachments?: number;
  acceptedFileTypes?: string;
  showFileDetails?: boolean;
}

const MentionInput = memo<MentionInputProps>(({
  value,
  onChange,
  onSubmit,
  users,
  disabled,
  placeholder,
  attachments,
  onAttachmentsChange,
  maxAttachments = 5,
  acceptedFileTypes = 'image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.txt',
  showFileDetails = true,
}) => {
  const [showSuggestions, setShowSuggestions] = useState(false);
  const [suggestionQuery, setSuggestionQuery] = useState('');
  const [cursorPosition, setCursorPosition] = useState(0);
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const filteredUsers = users.filter(
    (user) =>
      user.name.toLowerCase().includes(suggestionQuery.toLowerCase()) ||
      user.email.toLowerCase().includes(suggestionQuery.toLowerCase())
  );

  const handleInputChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
    const newValue = e.target.value;
    const position = e.target.selectionStart || 0;
    onChange(newValue);
    setCursorPosition(position);

    const textBeforeCursor = newValue.slice(0, position);
    const atIndex = textBeforeCursor.lastIndexOf('@');

    if (atIndex !== -1) {
      const textAfterAt = textBeforeCursor.slice(atIndex + 1);
      if (!textAfterAt.includes(' ')) {
        setSuggestionQuery(textAfterAt);
        setShowSuggestions(true);
        return;
      }
    }
    setShowSuggestions(false);
  };

  const handleSelectUser = (user: UserOption) => {
    const textBeforeCursor = value.slice(0, cursorPosition);
    const textAfterCursor = value.slice(cursorPosition);
    const atIndex = textBeforeCursor.lastIndexOf('@');

    const newText =
      textBeforeCursor.slice(0, atIndex) +
      `@${user.name} ` +
      textAfterCursor;

    onChange(newText);
    setShowSuggestions(false);
    inputRef.current?.focus();
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey && !showSuggestions) {
      e.preventDefault();
      onSubmit();
    }
    if (e.key === 'Escape') {
      setShowSuggestions(false);
    }
  };

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(e.target.files || []);
    if (files.length > 0) {
      const newFiles = [...attachments, ...files].slice(0, maxAttachments);
      onAttachmentsChange(newFiles);
    }
    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  };

  const removeAttachment = (index: number) => {
    const newFiles = attachments.filter((_, i) => i !== index);
    onAttachmentsChange(newFiles);
  };

  const getFileIcon = (file: File) => {
    if (file.type.startsWith('image/')) return IconPhoto;
    if (file.type.startsWith('video/')) return IconMovie;
    if (file.type.includes('pdf') || file.type.includes('document')) return IconFileText;
    return IconFile;
  };

  const formatFileSize = (bytes: number) => {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' bytes';
  };

  return (
    <div className="relative">
      {/* Attachments Preview */}
      {attachments.length > 0 && (
        <div className="flex flex-wrap gap-2 mb-2">
          {attachments.map((file, index) => {
            const FileIcon = showFileDetails ? getFileIcon(file) : IconPaperclip;
            return (
              <div
                key={index}
                className="flex items-center gap-1 px-2 py-1 bg-[var(--surface-muted)] rounded-lg text-xs"
              >
                <FileIcon className="h-3 w-3 text-[var(--text-tertiary)]" />
                <span className="max-w-24 truncate">{file.name}</span>
                {showFileDetails && (
                  <span className="text-[var(--text-tertiary)]">{formatFileSize(file.size)}</span>
                )}
                <button
                  type="button"
                  onClick={() => removeAttachment(index)}
                  data-testid="attachment-remove"
                  className="text-[var(--text-tertiary)] hover:text-[var(--status-danger)]"
                >
                  <IconX className="h-3 w-3" />
                </button>
              </div>
            );
          })}
        </div>
      )}

      <div className="relative flex items-start gap-2 bg-[var(--surface-subtle)] rounded-xl p-3 border border-[var(--border-default)] focus-within:border-[var(--border-focus)] focus-within:bg-[var(--surface-base)]">
        {/* Attachment Button */}
        <input
          ref={fileInputRef}
          type="file"
          multiple
          onChange={handleFileSelect}
          className="hidden"
          accept={acceptedFileTypes}
          data-testid="file-input"
        />
        <button
          type="button"
          onClick={() => fileInputRef.current?.click()}
          disabled={disabled || attachments.length >= maxAttachments}
          className="p-1 text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] rounded-lg transition-colors disabled:opacity-50"
          title="إرفاق ملف"
        >
          <IconPaperclip className="h-4 w-4" />
        </button>

        <textarea
          ref={inputRef}
          value={value}
          onChange={handleInputChange}
          onKeyDown={handleKeyDown}
          placeholder={placeholder}
          disabled={disabled}
          rows={5}
          className="flex-1 bg-transparent resize-none outline-none text-sm min-h-[100px] px-2 py-1"
          dir="auto"
          aria-label={placeholder || 'نص التعليق'}
        />

        <button
          type="button"
          onClick={onSubmit}
          disabled={disabled || (!value.trim() && attachments.length === 0)}
          className="p-1 text-[var(--accent-default)] hover:bg-[var(--accent-subtle)] rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          title="إرسال"
        >
          <IconSend className="h-4 w-4" />
        </button>
      </div>

      {/* Mention Suggestions */}
      {showSuggestions && filteredUsers.length > 0 && (
        <div className="absolute bottom-full start-0 mb-1 w-64 bg-[var(--surface-base)] rounded-xl shadow-lg border border-[var(--border-default)] py-1 z-50 max-h-48 overflow-y-auto">
          <div className="px-3 py-1 text-xs text-[var(--text-tertiary)] border-b border-[var(--border-default)]">
            اذكر شخصاً
          </div>
          {filteredUsers.slice(0, 5).map((user) => (
            <button
              key={user.id}
              type="button"
              onClick={() => handleSelectUser(user)}
              className="w-full flex items-center gap-2 px-3 py-2 hover:bg-[var(--surface-muted)] text-start"
            >
              <div className="h-6 w-6 rounded-full bg-[var(--accent-default)] flex items-center justify-center shrink-0">
                <span className="text-[var(--text-inverse)] text-xs font-bold">
                  {user.name.charAt(0)}
                </span>
              </div>
              <div className="flex-1 min-w-0">
                <div className="text-sm font-medium text-[var(--text-primary)] truncate">
                  {user.name}
                </div>
                <div className="text-xs text-[var(--text-tertiary)] truncate">{user.email}</div>
              </div>
              <IconAt className="h-3 w-3 text-[var(--text-tertiary)]" />
            </button>
          ))}
        </div>
      )}
    </div>
  );
});

MentionInput.displayName = 'MentionInput';

export default MentionInput;
