<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Mobile\ContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin menautkan satu akun ke beberapa identitas (pegawai, wali, siswa),
 * sehingga pegawai yang juga wali murid dapat berganti peran di aplikasi mobile.
 *
 * Penautan SELALU eksplisit. `suggestions` hanya memberi petunjuk (telepon /
 * email yang sama) untuk dikonfirmasi admin; tidak pernah menautkan sendiri.
 */
class UserIdentityController extends BaseApiController
{
    public function __construct(private readonly ContextService $contexts) {}

    public function index(User $user): JsonResponse
    {
        $rows = UserIdentity::query()->where('user_id', $user->id)->orderBy('id')->get()
            ->map(fn (UserIdentity $i) => $this->present($i))
            ->all();

        return $this->successResponse([
            'primary' => $this->primary($user),
            'identities' => $rows,
            'contexts' => $this->contexts->contexts($user),
        ], 'Identitas akun berhasil diambil');
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'identity_type' => ['required', 'string', 'in:'.implode(',', ContextService::TYPES)],
            'identity_id' => ['required', 'integer'],
        ]);

        $type = $data['identity_type'];
        $id = (int) $data['identity_id'];

        if ($this->entity($type, $id) === null) {
            return $this->errorResponse('Data identitas tidak ditemukan.', 422);
        }

        $owner = $this->ownerOf($type, $id);
        if ($owner !== null && $owner !== $user->id) {
            return $this->errorResponse('Identitas ini sudah tertaut ke akun lain.', 422);
        }

        if ($owner === $user->id) {
            return $this->errorResponse('Identitas ini sudah tertaut ke akun tersebut.', 422);
        }

        $identity = UserIdentity::create([
            'user_id' => $user->id,
            'identity_type' => $type,
            'identity_id' => $id,
            'linked_by' => $request->user()->id,
        ]);

        return $this->successResponse($this->present($identity), 'Identitas berhasil ditautkan', 201);
    }

    public function destroy(User $user, UserIdentity $identity): JsonResponse
    {
        abort_unless($identity->user_id === $user->id, 404);

        $identity->delete();

        return $this->successResponse(null, 'Tautan identitas dihapus');
    }

    /**
     * Petunjuk: identitas lain dengan telepon/email sama dengan identitas
     * utama akun. Bukan tautan — admin yang memutuskan.
     */
    public function suggestions(User $user): JsonResponse
    {
        $user->loadMissing('userable');
        $base = $user->userable;
        $phones = array_filter([$this->phone($base?->phone ?? null), $this->phone($user->phone)]);
        $emails = array_filter([strtolower((string) ($base?->email ?? '')), strtolower((string) $user->email)]);

        $out = [];

        $candidates = [
            ContextService::GUARDIAN => Guardian::query(),
            ContextService::EMPLOYEE => Employee::query(),
        ];

        foreach ($candidates as $type => $query) {
            if ($phones === [] && $emails === []) {
                break;
            }

            $rows = $query->where(function ($q) use ($phones, $emails) {
                if ($emails !== []) {
                    $q->orWhereIn(\Illuminate\Support\Facades\DB::raw('lower(email)'), array_values($emails));
                }
                foreach ($phones as $p) {
                    $q->orWhereRaw("replace(replace(replace(replace(phone, '-', ''), ' ', ''), '+', ''), '.', '') like ?", ['%'.substr($p, -9)]);
                }
            })->limit(10)->get();

            foreach ($rows as $row) {
                if ($this->contexts->identityIds($user, $type) !== [] && in_array((int) $row->id, $this->contexts->identityIds($user, $type), true)) {
                    continue;
                }
                if ($this->ownerOf($type, (int) $row->id) !== null) {
                    continue;
                }
                $out[] = [
                    'identity_type' => $type,
                    'identity_id' => (int) $row->id,
                    'name' => $row->full_name,
                    'phone' => $row->phone,
                    'email' => $row->email,
                ];
            }
        }

        return $this->successResponse($out, 'Petunjuk identitas berhasil diambil');
    }

    private function phone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return strlen((string) $digits) >= 8 ? $digits : null;
    }

    /** Pemilik tautan: akun lain yang memakai identitas ini (userable / identities / akun PPDB). */
    private function ownerOf(string $type, int $id): ?int
    {
        $class = $this->class($type);

        $viaUserable = User::query()
            ->where('userable_type', (new $class)->getMorphClass())
            ->where('userable_id', $id)
            ->value('id');

        if ($viaUserable) {
            return (int) $viaUserable;
        }

        $viaIdentity = UserIdentity::query()->where('identity_type', $type)->where('identity_id', $id)->value('user_id');

        return $viaIdentity ? (int) $viaIdentity : null;
    }

    /** @return array<string, mixed>|null */
    private function primary(User $user): ?array
    {
        $user->loadMissing('userable');
        $e = $user->userable;

        if ($e === null) {
            return null;
        }

        return [
            'identity_type' => $e instanceof Employee ? 'employee' : ($e instanceof Guardian ? 'guardian' : 'student'),
            'identity_id' => (int) $e->id,
            'name' => $e->full_name,
        ];
    }

    /** @return array<string, mixed> */
    private function present(UserIdentity $i): array
    {
        return [
            'id' => $i->id,
            'identity_type' => $i->identity_type,
            'identity_id' => (int) $i->identity_id,
            'name' => $this->entity($i->identity_type, (int) $i->identity_id)?->full_name,
            'linked_by' => $i->linked_by,
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }

    private function entity(string $type, int $id): ?object
    {
        return $this->class($type)::query()->find($id);
    }

    /** @return class-string */
    private function class(string $type): string
    {
        return match ($type) {
            ContextService::EMPLOYEE => Employee::class,
            ContextService::GUARDIAN => Guardian::class,
            default => Student::class,
        };
    }
}
