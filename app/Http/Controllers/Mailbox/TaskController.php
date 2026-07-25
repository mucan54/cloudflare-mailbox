<?php

namespace App\Http\Controllers\Mailbox;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tasks = $request->user()->tasks()
            ->orderBy('done')->orderBy('position')->orderByDesc('id')
            ->get()->map(fn (Task $t) => $this->present($t));

        return response()->json(['data' => $tasks]);
    }

    public function store(Request $request): JsonResponse
    {
        $task = $request->user()->tasks()->create($this->validated($request));

        return response()->json(['task' => $this->present($task)], 201);
    }

    public function update(Request $request, int $task): JsonResponse
    {
        $model = $request->user()->tasks()->findOrFail($task);
        $model->update($this->validated($request, true));

        return response()->json(['task' => $this->present($model->fresh())]);
    }

    public function destroy(Request $request, int $task): JsonResponse
    {
        $request->user()->tasks()->findOrFail($task)->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'done' => ['nullable', 'boolean'],
            'due_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'position' => ['nullable', 'integer'],
        ]);
    }

    private function present(Task $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'done' => $t->done,
            'due_on' => $t->due_on?->toDateString(),
            'notes' => $t->notes,
            'position' => $t->position,
        ];
    }
}
