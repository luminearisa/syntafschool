<?php

namespace App\Modules\SchoolOperations\Religious\Http\Controllers;

use App\Modules\SchoolOperations\Religious\Application\Services\PrayerScheduleService;
use App\Modules\SchoolOperations\Religious\Application\Services\ReligiousScopeService;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\AssetGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\AttendanceGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\BoardingGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\CateringGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\CertificateGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\DisciplineGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\DocumentGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\EmployeeGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\EventGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\FacilityGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\FinanceGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\InventoryGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\NotificationGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\ParentGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\StudentActivityGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\StudentGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\TahfidzGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\TeacherGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\TransportationGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Contracts\WorkflowGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Meta modul, ketersediaan integrasi, dan status penyedia (§76).
 *
 * Endpoint ini penting untuk operasional: administrator dapat melihat titik
 * integrasi mana yang sudah terpasang dan mana yang belum, sehingga modul tidak
 * diam-diam kehilangan fungsi.
 */
class MetaController extends Controller
{
    public function __construct(
        private readonly PrayerScheduleService $prayers,
        private readonly ReligiousScopeService $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->user()->can('religious.view') || abort(403);

        return response()->json(['data' => [
            'module' => config('religious.module'),
            'domains' => [
                'Mosque', 'Prayer', 'Congregation', 'Imam', 'Muadzin', 'Khatib', 'ReligiousActivity',
                'Kajian', 'Friday', 'Ramadan', 'Attendance', 'DutyRoster', 'ReligiousEvent',
                'ReligiousLearning', 'Achievement',
            ],
            'prayer_types' => $this->prayers->prayerTypes(),
            'activity_types' => array_keys((array) config('religious.activity.types', [])),
            'registration_modes' => (array) config('religious.activity.registration_modes', []),
            'attendance' => [
                'statuses' => (array) config('religious.attendance.statuses', []),
                'methods' => (array) config('religious.attendance.methods', []),
            ],
            'scope' => $this->scope->scope($request->user()),
            'requesting_user' => $request->user()?->id,
        ]]);
    }

    /** Ketersediaan seluruh titik integrasi lintas modul (§77, §100). */
    public function integrations(Request $request): JsonResponse
    {
        $request->user()->can('religious.view') || abort(403);

        $contracts = [
            'student' => StudentGatewayInterface::class,
            'parent' => ParentGatewayInterface::class,
            'employee' => EmployeeGatewayInterface::class,
            'teacher' => TeacherGatewayInterface::class,
            'boarding' => BoardingGatewayInterface::class,
            'attendance' => AttendanceGatewayInterface::class,
            'tahfidz' => TahfidzGatewayInterface::class,
            'student_activity' => StudentActivityGatewayInterface::class,
            'catering' => CateringGatewayInterface::class,
            'transportation' => TransportationGatewayInterface::class,
            'facility' => FacilityGatewayInterface::class,
            'asset' => AssetGatewayInterface::class,
            'inventory' => InventoryGatewayInterface::class,
            'finance' => FinanceGatewayInterface::class,
            'notification' => NotificationGatewayInterface::class,
            'workflow' => WorkflowGatewayInterface::class,
            'document' => DocumentGatewayInterface::class,
            'certificate' => CertificateGatewayInterface::class,
            'event' => EventGatewayInterface::class,
            'discipline' => DisciplineGatewayInterface::class,
        ];

        $status = [];
        foreach ($contracts as $key => $contract) {
            $status[$key] = $this->describe($contract);
        }

        return response()->json(['data' => [
            'prayer_time' => $this->prayers->providerCapabilities(),
            'integrations' => $status,
            'provided_contracts' => [
                'religious_life',
                'religious_personnel',
                'mosque_resource',
            ],
            'note' => 'Modul tetap berjalan mandiri untuk integrasi yang belum terpasang; '
                .'keterbatasan dinyatakan eksplisit, bukan dikarang.',
        ]]);
    }

    /** Ringkas ketersediaan satu gateway. */
    protected function describe(string $contract): array
    {
        try {
            if (! interface_exists($contract) || ! app()->bound($contract)) {
                return ['available' => false, 'reason' => 'Kontrak belum terdaftar pada container.'];
            }

            $gateway = app($contract);
            $result = ['available' => method_exists($gateway, 'available') ? (bool) $gateway->available() : true];

            if (method_exists($gateway, 'unavailableReason')) {
                $result['reason'] = $gateway->unavailableReason();
            }

            return $result;
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => $e->getMessage()];
        }
    }
}
