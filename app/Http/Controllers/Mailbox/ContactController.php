<?php

namespace App\Http\Controllers\Mailbox;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->contacts()->orderByDesc('favorite')->orderBy('name');

        if ($q = $request->string('q')->toString()) {
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('company', 'like', "%{$q}%");
            });
        }

        return response()->json(['data' => $query->get()->map(fn (Contact $c) => $this->present($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        $contact = $request->user()->contacts()->create($this->validated($request));

        return response()->json(['contact' => $this->present($contact)], 201);
    }

    public function update(Request $request, int $contact): JsonResponse
    {
        $model = $request->user()->contacts()->findOrFail($contact);
        $model->update($this->validated($request));

        return response()->json(['contact' => $this->present($model->fresh())]);
    }

    public function destroy(Request $request, int $contact): JsonResponse
    {
        $request->user()->contacts()->findOrFail($contact)->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'company' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'favorite' => ['nullable', 'boolean'],
        ]);
    }

    private function present(Contact $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'email' => $c->email,
            'phone' => $c->phone,
            'company' => $c->company,
            'title' => $c->title,
            'notes' => $c->notes,
            'favorite' => $c->favorite,
        ];
    }
}
