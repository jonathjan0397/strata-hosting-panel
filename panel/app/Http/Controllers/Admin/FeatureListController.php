<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeatureList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FeatureListController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/FeatureLists/Index', [
            'featureLists' => FeatureList::query()
                ->withCount('packages')
                ->orderBy('name')
                ->get()
                ->map(fn (FeatureList $featureList) => [
                    'id' => $featureList->id,
                    'name' => $featureList->name,
                    'slug' => $featureList->slug,
                    'description' => $featureList->description,
                    'features' => $featureList->features ?? [],
                    'packages_count' => $featureList->packages_count,
                ]),
            'featureCatalog' => FeatureList::catalog(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/FeatureLists/Create', [
            'featureCatalog' => FeatureList::catalog(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        FeatureList::create($this->validated($request));

        return redirect()->route('admin.feature-lists.index')
            ->with('success', 'Feature list created.');
    }

    public function edit(FeatureList $featureList): Response
    {
        return Inertia::render('Admin/FeatureLists/Edit', [
            'featureList' => $featureList->only('id', 'name', 'slug', 'description', 'features'),
            'featureCatalog' => FeatureList::catalog(),
        ]);
    }

    public function update(Request $request, FeatureList $featureList): RedirectResponse
    {
        $featureList->update($this->validated($request, $featureList));

        return redirect()->route('admin.feature-lists.index')
            ->with('success', 'Feature list updated.');
    }

    public function destroy(FeatureList $featureList): RedirectResponse
    {
        $featureList->delete();

        return redirect()->route('admin.feature-lists.index')
            ->with('success', 'Feature list deleted.');
    }

    private function validated(Request $request, ?FeatureList $featureList = null): array
    {
        $slugRule = Rule::unique('feature_lists', 'slug');
        if ($featureList) {
            $slugRule = $slugRule->ignore($featureList->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('feature_lists', 'name')->ignore($featureList?->id)],
            'slug' => ['nullable', 'string', 'max:100', $slugRule],
            'description' => ['nullable', 'string', 'max:1000'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', Rule::in(array_keys(FeatureList::catalog()))],
        ]);
    }
}
