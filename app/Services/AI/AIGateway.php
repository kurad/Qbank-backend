<?php

namespace App\Services\AI;

use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\GroqProvider;
use App\Services\AI\Providers\OpenAIProvider;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AIGateway
{
    public function __construct(
        protected OpenAIProvider $openai,
        protected GeminiProvider $gemini,
        protected GroqProvider $groq
    ) {
    }

    public function chat(array $messages, array $options = []): array
    {
        return $this->execute('chat', $messages, $options);
    }

    public function text(array $messages, array $options = []): string
    {
        return $this->execute('text', $messages, $options);
    }

    /**
     * Capability-aware multimodal request.
     *
     * Current behavior:
     * - OpenAI is tried first when all attachments are supported image types.
     * - Gemini is automatically used when OpenAI cannot handle the attachment
     *   type (for example PDF/plain-text files), or when the OpenAI image call fails.
     * - Groq is intentionally skipped for multimodal unless a future configured
     *   provider implementation explicitly supports it.
     */
    public function multimodalText(
        array $messages,
        array $attachments = [],
        array $options = []
    ): string {
        if (empty($attachments)) {
            return $this->text($messages, $options);
        }

        $configured = $this->providerOrder();
        $candidates = array_values(array_filter(
            $configured,
            fn (string $provider) => in_array($provider, ['openai', 'gemini'], true)
        ));

        // Ensure Gemini is available as a capability fallback even when it was
        // accidentally omitted from AI_FALLBACK_PROVIDERS.
        if (!in_array('gemini', $candidates, true)) {
            $candidates[] = 'gemini';
        }

        $errors = [];
        $attemptedProvider = null;

        foreach ($candidates as $providerKey) {
            try {
                if ($providerKey === 'openai') {
                    if (!$this->openai->supportsAttachments($attachments)) {
                        Log::info('OpenAI multimodal skipped: attachment type is not supported by the configured OpenAI vision path.', [
                            'mime_types' => array_values(array_unique(array_filter(array_map(
                                fn ($attachment) => $attachment['mime_type'] ?? null,
                                $attachments
                            )))),
                        ]);
                        continue;
                    }

                    $callOptions = $attemptedProvider === null
                        ? $options
                        : $this->fallbackUsageOptions($options, $attemptedProvider);

                    $attemptedProvider ??= 'openai';

                    return $this->openai->multimodalText(
                        $messages,
                        $attachments,
                        $callOptions
                    );
                }

                if ($providerKey === 'gemini') {
                    $callOptions = $attemptedProvider === null
                        ? $options
                        : $this->fallbackUsageOptions($options, $attemptedProvider);

                    return $this->gemini->multimodalText(
                        $messages,
                        $attachments,
                        $callOptions
                    );
                }
            } catch (Throwable $e) {
                $errors[$providerKey] = $e->getMessage();
                $attemptedProvider ??= $providerKey;

                Log::warning("AI multimodal provider [{$providerKey}] failed.", [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        Log::error('All multimodal-capable AI providers failed.', [
            'errors' => $errors,
        ]);

        throw new RuntimeException(
            'The Tutor could not read the attached file or image. Please try again or send the question without the attachment.'
        );
    }

    public function json(array $messages, array $schema = [], array $options = []): array
    {
        $providers = $this->providerOrder();
        $primary = $providers[0] ?? null;
        $errors = [];

        foreach ($providers as $index => $providerKey) {
            try {
                $provider = $this->resolveProvider($providerKey);
                $callOptions = $index === 0
                    ? $options
                    : $this->fallbackUsageOptions($options, (string) $primary);

                return $this->callJsonProvider($provider, $messages, $schema, $callOptions);
            } catch (Throwable $e) {
                $errors[$providerKey] = $e->getMessage();
                Log::warning("AI JSON provider [{$providerKey}] failed.", [
                    'message' => $e->getMessage(),
                    'attempt' => $index + 1,
                ]);
            }
        }

        Log::error('All configured AI JSON providers failed.', ['errors' => $errors]);
        throw new RuntimeException('All configured AI providers and fallbacks failed.');
    }

    public function curriculumJson(array $messages, array $schema, array $options = []): array
    {
        $options['_usage_feature'] = $options['_usage_feature'] ?? 'Curriculum Analysis';
        return $this->json($messages, $schema, $options);
    }

    protected function execute(string $method, array $messages, array $options = []): mixed
    {
        $providers = $this->providerOrder();
        $primary = $providers[0] ?? null;
        $errors = [];

        foreach ($providers as $index => $providerKey) {
            try {
                $provider = $this->resolveProvider($providerKey);
                $callOptions = $index === 0
                    ? $options
                    : $this->fallbackUsageOptions($options, (string) $primary);

                if ($index > 0) {
                    Log::info("AI fallback: using {$providerKey} provider.");
                }

                return $provider->{$method}($messages, $callOptions);
            } catch (Throwable $e) {
                $errors[$providerKey] = $e->getMessage();
                Log::warning("AI provider [{$providerKey}] failed.", [
                    'message' => $e->getMessage(),
                    'attempt' => $index + 1,
                ]);
            }
        }

        Log::error('All configured AI providers failed.', ['errors' => $errors]);
        throw new RuntimeException('All configured AI providers and fallbacks failed.');
    }

    protected function providerOrder(): array
    {
        $primary = strtolower(trim((string) config('services.ai.provider', 'openai')));
        $fallbacks = config('services.ai.fallback_providers', ['gemini', 'groq']);

        if (is_string($fallbacks)) {
            $fallbacks = explode(',', $fallbacks);
        }

        $providers = array_merge([$primary], is_array($fallbacks) ? $fallbacks : []);

        return array_values(array_unique(array_filter(array_map(
            fn ($provider) => strtolower(trim((string) $provider)),
            $providers
        ), fn ($provider) => in_array($provider, ['openai', 'gemini', 'groq'], true))));
    }

    protected function resolveProvider(string $providerKey): object
    {
        return match ($providerKey) {
            'openai' => $this->openai,
            'gemini' => $this->gemini,
            'groq' => $this->groq,
            default => throw new RuntimeException("Unsupported AI provider: {$providerKey}"),
        };
    }

    protected function callJsonProvider(
        object $provider,
        array $messages,
        array $schema,
        array $options
    ): array {
        if ($provider instanceof OpenAIProvider) {
            return $provider->json($messages, $schema, $options);
        }

        if ($provider instanceof GeminiProvider) {
            return $provider->json($messages, $schema, $options);
        }

        if ($provider instanceof GroqProvider) {
            return $provider->json($messages, $options);
        }

        throw new RuntimeException('Unsupported AI provider for JSON request.');
    }

    protected function fallbackUsageOptions(array $options, string $primaryProvider): array
    {
        $options['_usage_is_fallback'] = true;
        $options['_usage_primary_provider'] = $primaryProvider;

        return $options;
    }
}
