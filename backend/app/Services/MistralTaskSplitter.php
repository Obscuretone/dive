<?php

namespace App\Services;

use App\Exceptions\AiRateLimitException;
use App\Exceptions\AiTemporaryUnavailableException;
use App\Models\Task;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MistralTaskSplitter
{
    private const COOLDOWN_KEY = 'mistral:next_allowed_at_ms';
    private const DEFAULT_RETRY_AFTER_MS = 60000;
    private const TRANSIENT_RETRY_AFTER_MS = 5000;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model = 'mistral-large-latest',
        private readonly string $endpoint = 'https://api.mistral.ai/v1/chat/completions',
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    /**
     * @return array<int, array{title: string, description: string|null, order: int}>
     */
    public function split(Task $task): array
    {
        if (!$this->apiKey) {
            throw new RuntimeException('Mistral API key is not configured.');
        }

        $this->assertNotCoolingDown();

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->post($this->endpoint, [
                    'model' => $this->model,
                    'messages' => $this->messagesFor($task),
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                ]);
        } catch (ConnectionException $exception) {
            throw new AiTemporaryUnavailableException(
                'Mistral is temporarily unavailable.',
                self::TRANSIENT_RETRY_AFTER_MS,
            );
        }

        if ($response->status() === 429) {
            $retryAfterMs = $this->resolveRetryAfterMs($response);
            $this->storeCooldown($retryAfterMs);

            throw new AiRateLimitException(
                'Mistral rate limit exceeded.',
                $retryAfterMs,
            );
        }

        if ($response->serverError()) {
            throw new AiTemporaryUnavailableException(
                'Mistral is temporarily unavailable.',
                $this->resolveRetryAfterMs($response) ?: self::TRANSIENT_RETRY_AFTER_MS,
            );
        }

        if (!$response->successful()) {
            Log::warning('Mistral task split failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Mistral rejected the task split request.');
        }

        return $this->parseTasks($response);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function messagesFor(Task $task): array
    {
        $existing = $task->children()
            ->orderBy('order')
            ->get(['title', 'description', 'order'])
            ->map(fn (Task $child): array => [
                'title' => $child->title,
                'description' => $child->description,
                'order' => $child->order,
            ])
            ->values()
            ->all();

        $ancestors = [];
        $parent = $task->parent;
        while ($parent) {
            $ancestors[] = [
                'title' => $parent->title,
                'description' => $parent->description,
            ];
            $parent = $parent->parent;
        }

        return [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You split todo items into practical next-action chunks.',
                    'Return only JSON shaped as: {"tasks":[{"title":"...","description":"...","order":1}]}',
                    'Create 3 to 7 tasks unless the task is already atomic.',
                    'Each title must be short, specific, and action-oriented.',
                    'Descriptions should clarify the expected outcome.',
                    'Do not repeat existing subtasks.',
                    'Use integer order values starting at 1.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'task' => [
                        'title' => $task->title,
                        'description' => $task->description,
                    ],
                    'ancestors' => $ancestors,
                    'existing_subtasks' => $existing,
                ], JSON_THROW_ON_ERROR),
            ],
        ];
    }

    /**
     * @return array<int, array{title: string, description: string|null, order: int}>
     */
    private function parseTasks(Response $response): array
    {
        $content = data_get($response->json(), 'choices.0.message.content');
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('Mistral response did not include content.');
        }

        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $tasks = $payload['tasks'] ?? null;
        if (!is_array($tasks)) {
            throw new RuntimeException('Mistral response did not include a tasks array.');
        }

        $cleanTasks = [];
        foreach ($tasks as $index => $task) {
            if (!is_array($task)) {
                continue;
            }

            $title = trim((string) ($task['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $cleanTasks[] = [
                'title' => $title,
                'description' => isset($task['description'])
                    ? trim((string) $task['description'])
                    : null,
                'order' => max((int) ($task['order'] ?? ($index + 1)), 1),
            ];
        }

        usort($cleanTasks, fn (array $left, array $right): int => $left['order'] <=> $right['order']);

        return array_values($cleanTasks);
    }

    private function assertNotCoolingDown(): void
    {
        $nextAllowedAtMs = Cache::get(self::COOLDOWN_KEY);
        if (!is_numeric($nextAllowedAtMs)) {
            return;
        }

        $waitMs = (int) $nextAllowedAtMs - $this->nowMs();
        if ($waitMs > 0) {
            throw new AiRateLimitException(
                'Mistral is cooling down after a rate limit.',
                $waitMs,
            );
        }
    }

    private function storeCooldown(int $retryAfterMs): void
    {
        $ttlSeconds = max((int) ceil($retryAfterMs / 1000), 1);
        Cache::put(self::COOLDOWN_KEY, $this->nowMs() + $retryAfterMs, $ttlSeconds);
    }

    private function resolveRetryAfterMs(Response $response): int
    {
        $retryAfter = $response->header('retry-after');
        if (!is_string($retryAfter) || trim($retryAfter) === '') {
            return self::DEFAULT_RETRY_AFTER_MS;
        }

        return $this->parseRetryAfterMs($retryAfter) ?? self::DEFAULT_RETRY_AFTER_MS;
    }

    private function parseRetryAfterMs(string $retryAfter): ?int
    {
        $value = trim($retryAfter);
        if (is_numeric($value)) {
            return max((int) round(((float) $value) * 1000), 1);
        }

        $retryAt = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231, $value);
        if (!$retryAt) {
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                return null;
            }

            $retryAt = (new DateTimeImmutable())->setTimestamp($timestamp);
        }

        $retryAtMs = ((int) $retryAt->format('U') * 1000) + (int) $retryAt->format('v');
        $delayMs = $retryAtMs - $this->nowMs();
        if ($delayMs <= 0) {
            return null;
        }

        return max((int) round($delayMs), 1);
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
