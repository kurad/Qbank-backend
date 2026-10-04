<?php

namespace App\Observers;

use App\Models\TutorMessage;
use App\Services\TutorMediaService;

class TutorMessageObserver
{
    public function __construct(
        protected TutorMediaService $media
    ) {}

    public function creating(TutorMessage $message): void
    {
        if ($message->role !== 'assistant') {
            return;
        }

        // Formal checkpoint payloads have their own dedicated UI.
        if ($message->message_type === 'checkpoint') {
            return;
        }

        if (!$message->tutor_session_id || trim((string) $message->content) === '') {
            return;
        }

        $session = $message->session()->first();

        if (!$session) {
            return;
        }

        $prepared = $this->media->prepare(
            $session,
            (string) $message->content
        );

        $message->content = $prepared['content'];

        $existingMetadata = is_array($message->metadata)
            ? $message->metadata
            : [];

        $mediaMetadata = is_array($prepared['metadata'] ?? null)
            ? $prepared['metadata']
            : [];

        $merged = array_replace_recursive(
            $existingMetadata,
            $mediaMetadata
        );

        $message->metadata = $merged === []
            ? null
            : $merged;
    }
}
