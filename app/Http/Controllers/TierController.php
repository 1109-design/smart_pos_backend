<?php

namespace App\Http\Controllers;

use App\Models\Tier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TierController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tiers/Index', [
            'tiers' => Tier::orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key'        => 'required|string|max:50|unique:tiers,key|regex:/^[a-z0-9_]+$/',
            'label'      => 'required|string|max:100',
            'price'      => 'required|string|max:100',
            'features'   => 'required|array|min:1',
            'features.*' => 'required|string|max:255',
            'sort_order' => 'required|integer|min:0',
        ]);

        Tier::create($data);

        return redirect()->route('tiers.index')->with('success', 'Tier created.');
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $tier = Tier::findOrFail($key);

        $data = $request->validate([
            'label'      => 'required|string|max:100',
            'price'      => 'required|string|max:100',
            'features'   => 'required|array|min:1',
            'features.*' => 'required|string|max:255',
            'sort_order' => 'required|integer|min:0',
        ]);

        $tier->update($data);

        return redirect()->route('tiers.index')->with('success', 'Tier updated.');
    }

    public function destroy(string $key): RedirectResponse
    {
        Tier::findOrFail($key)->delete();

        return redirect()->route('tiers.index')->with('success', 'Tier deleted.');
    }
}
