<?php

namespace App\Api\Controllers;

use App\Exceptions\AiRateLimitException;
use App\Exceptions\AiTemporaryUnavailableException;
use App\Models\Task;
use App\Services\MistralTaskSplitter;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(): JsonResponse
    {
        $tasks = Task::roots()
            ->withCount('children')
            ->orderBy('completed')
            ->orderBy('order')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['tasks' => $tasks]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'estimated_time' => ['nullable', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date'],
            'parent_id' => ['nullable', 'uuid', 'exists:tasks,id'],
        ]);

        $task = Task::create([
            ...$validated,
            'status' => 'pending',
            'completed' => false,
            'order' => $this->nextOrder($validated['parent_id'] ?? null),
        ]);

        return response()->json(['task' => $task->loadCount('children')], 201);
    }

    public function show(string $taskId): JsonResponse
    {
        $task = Task::with(['parent', 'children'])
            ->withCount('children')
            ->findOrFail($taskId);

        return response()->json([
            'task' => $task,
            'subtasks' => $task->children->loadCount('children')->values(),
        ]);
    }

    public function update(Request $request, string $taskId): JsonResponse
    {
        $task = Task::findOrFail($taskId);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'estimated_time' => ['nullable', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date'],
            'completed' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['pending', 'in_progress', 'completed'])],
            'order' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        if (array_key_exists('completed', $validated)) {
            $validated['status'] = $validated['completed'] ? 'completed' : 'pending';
        } elseif (($validated['status'] ?? null) === 'completed') {
            $validated['completed'] = true;
        }

        $task->fill($validated)->save();

        return response()->json(['task' => $task->fresh()->loadCount('children')]);
    }

    public function destroy(string $taskId): JsonResponse
    {
        Task::findOrFail($taskId)->delete();

        return response()->json(null, 204);
    }

    public function split(string $taskId, MistralTaskSplitter $splitter): JsonResponse
    {
        $task = Task::with(['parent', 'children'])->findOrFail($taskId);

        try {
            $suggestions = $splitter->split($task);
        } catch (AiRateLimitException $exception) {
            return response()
                ->json([
                    'message' => $exception->getMessage(),
                    'retry_after_ms' => $exception->retryAfterMs,
                ], 429)
                ->header('Retry-After', (string) $exception->retryAfterSeconds());
        } catch (AiTemporaryUnavailableException $exception) {
            return response()
                ->json([
                    'message' => $exception->getMessage(),
                    'retry_after_ms' => $exception->retryAfterMs,
                ], 503)
                ->header('Retry-After', (string) $exception->retryAfterSeconds());
        }

        $created = collect($suggestions)->map(function (array $suggestion) use ($task): Task {
            return Task::create([
                'title' => $suggestion['title'],
                'description' => $suggestion['description'],
                'order' => $suggestion['order'],
                'completed' => false,
                'status' => 'pending',
                'parent_id' => $task->id,
            ]);
        });

        return response()->json([
            'task' => $task->fresh()->loadCount('children'),
            'subtasks' => $created->map->loadCount('children')->values(),
        ], 201);
    }

    private function nextOrder(?string $parentId): int
    {
        return ((int) Task::where('parent_id', $parentId)->max('order')) + 1;
    }
}
