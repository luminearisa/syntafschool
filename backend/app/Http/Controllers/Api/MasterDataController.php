<?php

namespace App\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Controller CRUD generik untuk seluruh master data.
 * Konfigurasi resource didefinisikan di config/master_data.php.
 */
class MasterDataController extends BaseApiController
{
    /**
     * Ambil konfigurasi resource atau gagal 404.
     *
     * @return array<string, mixed>
     */
    protected function config(string $resource): array
    {
        $config = config("master_data.{$resource}");

        if (! $config || ! isset($config['model'])) {
            abort(404, "Resource master data '{$resource}' tidak ditemukan.");
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function newQuery(array $config)
    {
        /** @var Model $model */
        $model = new $config['model'];
        $query = $model->newQuery();

        if (! empty($config['relations'])) {
            $query->with($config['relations']);
        }

        return $query;
    }

    public function index(Request $request, string $resource): JsonResponse
    {
        $config = $this->config($resource);
        $query = $this->newQuery($config);

        // Search
        if ($search = $request->query('search')) {
            $columns = $config['search'] ?? [];
            $query->where(function ($q) use ($columns, $search) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', "%{$search}%");
                }
            });
        }

        // Filter (filter[column]=value) — hanya kolom yang diizinkan konfigurasi
        $allowedFilters = $config['filter'] ?? [];
        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '' || ! in_array($column, $allowedFilters, true)) {
                continue;
            }
            $query->where($column, $value);
        }

        // Sort
        $sortBy = $request->query('sort_by');
        $sortDir = $request->query('sort_dir', 'asc') === 'desc' ? 'desc' : 'asc';
        if ($sortBy && in_array($sortBy, $config['sort'] ?? [], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->latest('id');
        }

        $perPage = (int) $request->query('per_page', 15);

        if ($request->boolean('all')) {
            return $this->successResponse($query->get(), 'Data berhasil diambil');
        }

        $paginator = $query->paginate($perPage);

        return $this->successResponse($paginator, 'Data berhasil diambil');
    }

    public function show(string $resource, string $id): JsonResponse
    {
        $config = $this->config($resource);
        $record = $this->newQuery($config)->findOrFail($id);

        return $this->successResponse($record, 'Data berhasil diambil');
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $config = $this->config($resource);

        if (! empty($config['read_only'])) {
            return $this->errorResponse('Resource ini bersifat read-only.', 403);
        }

        $data = $this->validateData((array) ($config['rules'] ?? []), $config, $request);

        /** @var Model $model */
        $model = new $config['model'];
        $record = $model->create($data);

        return $this->createdResponse($record, 'Data berhasil dibuat');
    }

    public function update(Request $request, string $resource, string $id): JsonResponse
    {
        $config = $this->config($resource);

        if (! empty($config['read_only'])) {
            return $this->errorResponse('Resource ini bersifat read-only.', 403);
        }

        $record = $this->newQuery($config)->findOrFail($id);

        $rules = $config['rules'] ?? [];
        // Sesuaikan aturan unique agar mengabaikan id saat update.
        $rules = $this->adjustUniqueRules($rules, $record);

        $data = $this->validateData($rules, $config, $request, $record);

        $record->update($data);

        return $this->successResponse($record->refresh(), 'Data berhasil diperbarui');
    }

    public function destroy(string $resource, string $id): JsonResponse
    {
        $config = $this->config($resource);

        if (! empty($config['read_only'])) {
            return $this->errorResponse('Resource ini bersifat read-only.', 403);
        }

        $record = $this->newQuery($config)->findOrFail($id);
        $record->delete();

        return $this->successResponse(null, 'Data berhasil dihapus');
    }

    /**
     * Validasi request berdasarkan rules.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function validateData(array $rules, array $config, Request $request, ?Model $record = null): array
    {
        $validator = Validator::make($request->all(), $rules, [], $this->attributeNames($rules));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Ubah rule unique agar mengabaikan primary key saat update.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function adjustUniqueRules(array $rules, Model $record): array
    {
        foreach ($rules as $field => $fieldRules) {
            $rules[$field] = array_map(function ($rule) use ($record) {
                if (is_string($rule) && str_starts_with($rule, 'unique:')) {
                    return $rule.','.$record->getKey().','.$record->getKeyName();
                }

                return $rule;
            }, $fieldRules);
        }

        return $rules;
    }

    /**
     * Nama atribut yang ramah untuk pesan error.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, string>
     */
    protected function attributeNames(array $rules): array
    {
        $names = [];
        foreach (array_keys($rules) as $field) {
            $names[$field] = str_replace(['.', '_'], [' ', ' '], $field);
        }

        return $names;
    }
}