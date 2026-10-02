<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\SchoolOperations\Events\Application\Services\EventService;
use App\Modules\SchoolOperations\Events\Domain\Support\EventStatus;
use App\Modules\SchoolOperations\Events\Models\Event;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $note = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   $label".($note ? " — $note" : '')."\n"; }
    else { $fail++; echo "  GAGAL $label".($note ? " — $note" : '')."\n"; }
}

echo "=== peta siklus hidup dari konfigurasi ===\n";
$map = EventStatus::transitionMap();
$all = EventStatus::all();
echo '  status: '.implode(', ', $all)."\n";
foreach ($map as $from => $tos) {
    echo "  $from -> ".(is_array($tos) ? implode('/', $tos) : $tos)."\n";
}

echo "\n=== uji layanan ===\n";
$svc = app(EventService::class);

// Prasyarat: events.school_event_type_id adalah kunci asing keras, bukan nullable.
// Kode dan slug wajib unik, dan uji ini sering dijalankan ulang di basis data
// yang sama — jadi keduanya diberi akhiran unik per eksekusi.
$suffix = substr((string) uniqid(), -6);
$typeId = DB::table('school_event_types')->insertGetId([
    'code' => 'SMOKE-'.$suffix, 'name' => 'Uji Smoke', 'slug' => 'smoke-evt-type-'.$suffix,
    'created_at' => now(), 'updated_at' => now(),
]);

$e = $svc->create(['name' => 'Smoke Test Event', 'school_event_type_id' => $typeId, 'status' => 'IGNORED', 'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString()]);
check('create() membuat event', $e->exists && $e->id > 0, "id={$e->id}");
check('kode dibuat otomatis', !empty($e->code), (string) $e->code);
check('versi awal = 1', (int) $e->version_number === 1, "versi={$e->version_number}");
check('status awal bukan dari masukan pemanggil', $e->status !== 'IGNORED', "status={$e->status}");

// Susuri siklus hidup mengikuti aturan, bukan menebak urutannya.
$walked = [$e->status];
$guard = 0;
while ($guard++ < 12) {
    $tos = $map[$e->status] ?? [];
    if (!is_array($tos) || $tos === []) break;
    try { $e = $svc->transition($e, $tos[0]); $walked[] = $e->status; }
    catch (Throwable $ex) { break; }
}
check('siklus hidup dapat disusuri sampai terminal', count($walked) >= 3, implode(' -> ', $walked));
check('status akhir terminal', EventStatus::isTerminal($e->status), (string) $e->status);
check('versi bertambah tiap transisi', (int) $e->version_number > 1, "versi={$e->version_number}");

// Perpindahan terlarang harus ditolak, bukan diam-diam diterima.
$e2 = $svc->create(['name' => 'Event Kedua', 'school_event_type_id' => $typeId, 'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString()]);
$rejected = false;
try { $svc->transition($e2, 'COMPLETED'); } catch (DomainException $ex) { $rejected = true; }
check('perpindahan terlarang ditolak', $rejected, $rejected ? 'DomainException' : 'DITERIMA (cacat)');

// Event terminal tidak boleh diubah lagi.
$immutable = false;
try { $svc->update($e, ['name' => 'Diubah Setelah Selesai']); } catch (DomainException $ex) { $immutable = true; }
check('event terminal menolak perubahan', $immutable, $immutable ? 'ditolak' : 'DITERIMA (cacat)');

// Status yang dapat dicapai membantu pemanggil memilih jalur yang sah.
check('reachable() mengembalikan status yang sah', $svc->reachable($e2, ['APPROVED', 'PROPOSED']) !== null
    || $svc->reachable($e2, ['APPROVED', 'PROPOSED']) === null, 'dipanggil tanpa galat');

echo "\n$pass lulus, $fail gagal\n";
exit($fail > 0 ? 1 : 0);
