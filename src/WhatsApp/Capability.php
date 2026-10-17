<?php

declare(strict_types=1);

namespace App\WhatsApp;

enum Capability: string
{
    case Text = 'text';
    case Media = 'media';
    case Templates = 'templates';
    case Interactive = 'interactive';
    case Reactions = 'reactions';
    case Replies = 'replies';
    case MessageEdit = 'message_edit';
    case Groups = 'groups';
    case Typing = 'typing';
    case QrSession = 'qr_session';
    case ReadReceipts = 'read_receipts';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text messages',
            self::Media => 'Images, documents, audio, video',
            self::Templates => 'Approved message templates',
            self::Interactive => 'Interactive buttons / lists',
            self::Reactions => 'Reactions',
            self::Replies => 'Reply to a specific message',
            self::MessageEdit => 'Edit sent messages',
            self::Groups => 'Groups & participants',
            self::Typing => 'Typing indicators',
            self::QrSession => 'QR / pairing-code session',
            self::ReadReceipts => 'Delivery & read receipts',
        };
    }
}
