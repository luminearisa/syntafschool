<?php

namespace App\Modules\SchoolOperations\Religious\Http\Controllers;

use App\Modules\SchoolOperations\Religious\Application\Services\{EidService, RamadanService};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Ramadan, tarawih, tahajjud, tadarus, iftar, suhoor, i'tikaf, hari raya, dan qurban (§44-§52). */
class RamadanController extends Controller
{
    public function __construct(
        private readonly RamadanService $ramadan,
        private readonly EidService $eid,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('religious.ramadan.view') || abort(403);

        return response()->json(['data' => [
            'programs' => \App\Modules\SchoolOperations\Religious\Models\RamadanProgram::query()->orderByDesc('id')->get(),
            'dashboard' => $this->ramadan->dashboard((string) $request->input('date', now()->toDateString())),
        ]]);
    }

    public function storeProgram(Request $request): JsonResponse
    {
        $request->user()->can('religious.ramadan.manage') || abort(403);

        return response()->json(['data' => $this->ramadan->createProgram($request->validate([
            'name' => ['nullable', 'string'], 'hijri_year' => ['nullable', 'string'],
            'gregorian_start' => ['nullable', 'date'], 'gregorian_end' => ['nullable', 'date'],
            'campus_id' => ['nullable', 'integer'], 'mosque_id' => ['nullable', 'integer'],
            'organization_id' => ['nullable', 'integer'], 'program_types' => ['nullable', 'array'],
            'target_population' => ['nullable', 'string'], 'expected_participants' => ['nullable', 'integer'],
            'budget' => ['nullable', 'numeric'], 'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    /** Bangun kalender harian Ramadan (§45). */
    public function generateDays(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.ramadan.manage') || abort(403);

        return response()->json(['data' => $this->ramadan->generateDays($id, (int) $request->input('total_days', 30))]);
    }

    public function storeActivity(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.ramadan.manage') || abort(403);

        return response()->json(['data' => $this->ramadan->addActivity($id, $request->all())], 201);
    }

    public function storeTarawih(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.ramadan.manage') || abort(403);

        return response()->json(['data' => $this->ramadan->scheduleTarawih($id, $request->all())], 201);
    }

    public function storeTahajjud(Request $request): JsonResponse
    {
        $request->user()->can('religious.ramadan.manage') || abort(403);

        return response()->json(['data' => $this->ramadan->scheduleTahajjud($request->all())], 201);
    }

    /** Iftar: kebutuhan makan diteruskan ke Catering (§47, Aturan 11). */
    public function storeIftar(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.ramadan.manage') || abort(403);

        return response()->json(['data' => $this->ramadan->scheduleIftar($id, $request->all())], 201);
    }

    public function storeSuhoor(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.ramadan.manage') || abort(403);

        return response()->json(['data' => $this->ramadan->scheduleSuhoor($id, $request->all())], 201);
    }

    public function storeItikaf(Request $request): JsonResponse
    {
        $request->user()->can('religious.ramadan.manage') || abort(403);

        return response()->json(['data' => $this->ramadan->createItikaf($request->all())], 201);
    }

    public function registerItikaf(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.ramadan.manage') || abort(403);

        return response()->json(['data' => $this->ramadan->registerItikaf($id, $request->all())], 201);
    }

    /** Dashboard Ramadan (§93). */
    public function dashboard(Request $request): JsonResponse
    {
        $request->user()->can('religious.ramadan.view') || abort(403);

        return response()->json(['data' => $this->ramadan->dashboard(
            (string) $request->input('date', now()->toDateString())
        )]);
    }

    public function report(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.report.view') || abort(403);

        return response()->json(['data' => $this->ramadan->report($id)]);
    }

    // ── Hari raya & qurban (§51, §52) ───────────────────────────────

    public function storeEid(Request $request): JsonResponse
    {
        $request->user()->can('religious.eid.manage') || abort(403);

        return response()->json(['data' => $this->eid->createEvent($request->validate([
            'eid_type' => ['required', 'string', 'in:EID_FITR,EID_ADHA'],
            'event_date' => ['required', 'date'], 'hijri_year' => ['nullable', 'string'],
            'campus_id' => ['nullable', 'integer'], 'mosque_id' => ['nullable', 'integer'],
            'prayer_time' => ['nullable'], 'khatib_id' => ['nullable', 'integer'],
            'imam_id' => ['nullable', 'integer'], 'muadzin_id' => ['nullable', 'integer'],
            'venue' => ['nullable', 'string'], 'expected_attendance' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ]))], 201);
    }

    public function recordEidAttendance(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.eid.manage') || abort(403);
        $validated = $request->validate(['actual_attendance' => ['required', 'integer', 'min:0']]);

        return response()->json(['data' => $this->eid->recordAttendance($id, (int) $validated['actual_attendance'])]);
    }

    public function storeQurbanProgram(Request $request): JsonResponse
    {
        $request->user()->can('religious.eid.manage') || abort(403);

        return response()->json(['data' => $this->eid->createQurbanProgram($request->all())], 201);
    }

    public function storeQurbanAnimal(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.eid.manage') || abort(403);

        return response()->json(['data' => $this->eid->registerAnimal($id, $request->all())], 201);
    }

    public function sacrificeAnimal(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.eid.manage') || abort(403);

        return response()->json(['data' => $this->eid->sacrifice($id, $request->all())]);
    }

    public function distributeQurban(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.eid.manage') || abort(403);

        return response()->json(['data' => $this->eid->distribute($id, $request->all())], 201);
    }

    public function qurbanReport(Request $request, int $id): JsonResponse
    {
        $request->user()->can('religious.report.view') || abort(403);

        return response()->json(['data' => $this->eid->qurbanReport($id)]);
    }
}
