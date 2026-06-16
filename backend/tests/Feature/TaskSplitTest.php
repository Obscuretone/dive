<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaskSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_creates_ai_subtasks(): void
    {
        config([
            'services.mistral.api_key' => 'test-key',
            'services.mistral.endpoint' => 'https://api.mistral.ai/v1/chat/completions',
        ]);

        Http::fake([
            'api.mistral.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'tasks' => [
                                    [
                                        'title' => 'Draft outline',
                                        'description' => 'Write the major sections.',
                                        'order' => 1,
                                    ],
                                    [
                                        'title' => 'Review scope',
                                        'description' => 'Trim anything that does not fit.',
                                        'order' => 2,
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $task = Task::create([
            'title' => 'Write proposal',
            'description' => 'Prepare a short project proposal.',
        ]);

        $response = $this->postJson("/api/tasks/{$task->id}/split");

        $response
            ->assertCreated()
            ->assertJsonPath('subtasks.0.title', 'Draft outline')
            ->assertJsonPath('subtasks.1.title', 'Review scope');

        $this->assertDatabaseHas('tasks', [
            'title' => 'Draft outline',
            'parent_id' => $task->id,
        ]);
    }

    public function test_split_returns_retry_after_on_mistral_rate_limit(): void
    {
        config([
            'services.mistral.api_key' => 'test-key',
            'services.mistral.endpoint' => 'https://api.mistral.ai/v1/chat/completions',
        ]);
        Cache::clear();

        Http::fake([
            'api.mistral.ai/*' => Http::response(
                ['message' => 'rate limited'],
                429,
                ['retry-after' => '12'],
            ),
        ]);

        $task = Task::create(['title' => 'Plan the launch']);

        $response = $this->postJson("/api/tasks/{$task->id}/split");

        $response
            ->assertStatus(429)
            ->assertHeader('Retry-After', '12')
            ->assertJsonPath('retry_after_ms', 12000);
    }
}
