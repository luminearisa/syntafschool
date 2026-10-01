<?php

namespace App\Modules\Asset\Http\Controllers;

use App\Modules\Asset\Application\Services\AssetAccessService;
use App\Modules\Asset\Application\Services\LocationService;
use App\Modules\Asset\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LocationController extends AssetControllerBase
{
    public function __construct(
        private readonly LocationService $locations,
        private readonly AssetAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Location::query()->with(['campus:id,name', 'building:id,name', 'floor:id,name']);

        $this->access->scopeLocations($query, $this->actor($request));

        foreach (['campus_id', 'building_id', 'building_floor_id', 'parent_location_id', 'location_type', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('q')) {
            $keyword = (string) $request->input('q');
            $query->where(fn ($inner) => $inner->where('name', 'like', "%$keyword%")->orWhere('code', 'like', "%$keyword%")->orWhere('path', 'like', "%$keyword%"));
        }

        return $this->ok($query->orderBy('path')->get(), 'Daftar lokasi.');
    }

    public function tree(Request $request): JsonResponse
    {
        $locations = Location::query()
            ->when($request->filled('campus_id'), fn ($query) => $query->where('campus_id', $request->input('campus_id')))
            ->orderBy('path')
            ->get(['id', 'parent_location_id', 'code', 'name', 'location_type', 'path', 'capacity', 'status']);

        return $this->ok($locations, 'Pohon lokasi (urut menurut path).');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'location_type' => ['required', 'string', 'max:32'],
            'code' => ['nullable', 'string', 'max:60', 'unique:locations,code'],
            'parent_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
            'building_id' => ['nullable', 'integer', 'exists:buildings,id'],
            'building_floor_id' => ['nullable', 'integer', 'exists:building_floors,id'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'area_m2' => ['nullable', 'numeric', 'min:0'],
            'floor_label' => ['nullable', 'string', 'max:40'],
            'function' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'string', 'max:24'],
            'responsible_unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'contact' => ['nullable', 'string', 'max:80'],
            'is_bookable' => ['nullable', 'boolean'],
            'academic_room_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string'],
        ]);

        return $this->created($this->locations->create($this->actor($request), $data), 'Lokasi dibuat.');
    }

    public function show(Request $request, Location $location): JsonResponse
    {
        Gate::authorize('view', $location);

        return $this->ok($location->load(['campus', 'building', 'floor', 'parent:id,name,code']), 'Rincian lokasi.');
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        Gate::authorize('update', $location);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'location_type' => ['sometimes', 'string', 'max:32'],
            'parent_location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'area_m2' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'function' => ['sometimes', 'nullable', 'string', 'max:80'],
            'status' => ['sometimes', 'string', 'max:24'],
            'responsible_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:units,id'],
            'manager_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'contact' => ['sometimes', 'nullable', 'string', 'max:80'],
            'is_bookable' => ['sometimes', 'boolean'],
            'academic_room_id' => ['sometimes', 'nullable', 'integer'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        return $this->ok($this->locations->update($this->actor($request), $location, $data), 'Lokasi diperbarui.');
    }

    public function archive(Request $request, Location $location): JsonResponse
    {
        Gate::authorize('update', $location);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        return $this->ok($this->locations->archive($this->actor($request), $location, $data['reason'] ?? null), 'Lokasi dinonaktifkan.');
    }
}
