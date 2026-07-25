<?php

namespace App\Http\Controllers\Mailbox;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->events()->orderBy('starts_at');

        if ($from = $request->date('from')) {
            $query->where('starts_at', '>=', $from->startOfDay());
        }
        if ($to = $request->date('to')) {
            $query->where('starts_at', '<=', $to->endOfDay());
        }

        return response()->json(['data' => $query->get()->map(fn (Event $e) => $this->present($e))]);
    }

    public function store(Request $request): JsonResponse
    {
        $event = $request->user()->events()->create($this->validated($request));

        return response()->json(['event' => $this->present($event)], 201);
    }

    public function update(Request $request, int $event): JsonResponse
    {
        $model = $request->user()->events()->findOrFail($event);
        $model->update($this->validated($request));

        return response()->json(['event' => $this->present($model->fresh())]);
    }

    public function destroy(Request $request, int $event): JsonResponse
    {
        $request->user()->events()->findOrFail($event)->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:32'],
        ]);
    }

    private function present(Event $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'location' => $e->location,
            'starts_at' => $e->starts_at?->toIso8601String(),
            'ends_at' => $e->ends_at?->toIso8601String(),
            'all_day' => $e->all_day,
            'notes' => $e->notes,
            'color' => $e->color,
        ];
    }
}
