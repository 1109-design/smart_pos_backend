<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Category;
use App\Models\SyncRecord;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoriesController extends BackOfficeController
{
    public function store(Request $request, SyncProcessor $processor): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $this->applyCategory($processor, (string) Str::uuid(), [
            'name' => $validated['name'],
            'color' => $validated['color'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('success', 'Category created. Devices will receive it on their next sync.');
    }

    public function update(Request $request, string $category, SyncProcessor $processor): RedirectResponse
    {
        $existing = Category::where('business_id', $this->tenantId())->findOrFail($category);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $this->applyCategory($processor, $existing->id, [
            'parent_id' => $existing->parent_id,
            'name' => $validated['name'],
            'color' => $validated['color'] ?? $existing->color,
            'icon' => $existing->icon,
            'sort_order' => $existing->sort_order,
            'is_active' => $existing->is_active,
        ]);

        return back()->with('success', 'Category updated.');
    }

    public function toggleActive(string $category, SyncProcessor $processor): RedirectResponse
    {
        $existing = Category::where('business_id', $this->tenantId())->findOrFail($category);

        $this->applyCategory($processor, $existing->id, [
            'parent_id' => $existing->parent_id,
            'name' => $existing->name,
            'color' => $existing->color,
            'icon' => $existing->icon,
            'sort_order' => $existing->sort_order,
            'is_active' => ! $existing->is_active,
        ]);

        return back()->with('success', $existing->is_active ? 'Category archived.' : 'Category restored.');
    }

    /**
     * Apply through the same SyncProcessor a device push uses, then publish to
     * the sync stream so every device (including newly paired) receives it.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyCategory(SyncProcessor $processor, string $uuid, array $data): void
    {
        $data['business_id'] = $this->tenantId();

        $processor->process('categories', $uuid, 'upsert', $data);

        SyncRecord::create([
            'business_id' => $data['business_id'],
            'table_name' => 'categories',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $data,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
