<?php

/**
 * Uji jejak audit dan bus domain event modul Event.
 *
 * Dijalankan terhadap basis data bermigrasi — bukan dengan mengandalkan
 * pembacaan kode. Kegagalan penulisan audit sengaja ditelan oleh
 * EventContext::safe(), sehingga tanpa uji seperti ini audit yang tidak pernah
 * menulis akan tampak "berhasil" dari luar.
 */
require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\SchoolOperations\Events\Application\Services\EventService;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $note = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   $label".($note ? " — $note" : '')."\n"; }
    else { $fail++; echo "  GAGAL $label".($note ? " — $note" : '')."\n"; }
}

$suffix = substr((string) uniqid(), -6);
$typeId = DB::table('school_event_types')->insertGetId([
    'code' => 'AUD-'.$suffix, 'name' => 'Uji Audit', 'slug' => 'aud-'.$suffix,
    'created_at' => now(), 'updated_at' => now(),
]);

$svc = app(EventService::class);
$before = DB::table('event_audit_logs')->count();
$beforeBus = DB::table('event_domain_events')->count();

$e = $svc->create([
    'name' => 'Event Uji Audit',
    'school_event_type_id' => $typeId,
    'start_date' => now()->toDateString(),
    'end_date' => now()->addDay()->toDateString(),
]);

$created = DB::table('event_audit_logs')->where('event_id', $e->id)->where('action', 'event.created')->first();
check('create() menulis baris audit', $created !== null);
check('audit merekam entitas yang benar', $created && str_ends_with((string) $created->entity_type, 'Event'), (string) ($created->entity_type ?? '-'));
check('audit merekam status awal', $created && str_contains((string) $created->new_value, 'DRAFT'), (string) ($created->new_value ?? '-'));

$e = $svc->transition($e, 'PROPOSED');
$e = $svc->transition($e, 'CANCELLED', 'Uji pembatalan');

$changed = DB::table('event_audit_logs')->where('event_id', $e->id)->where('action', 'event.status.changed')->get();
check('setiap perpindahan status tercatat', $changed->count() === 2, "baris={$changed->count()}");

$row = $changed->last();
check('audit merekam nilai lama dan baru',
    $row && str_contains((string) $row->old_value, 'PROPOSED') && str_contains((string) $row->new_value, 'CANCELLED'));
check('audit merekam alasan', $row && $row->reason === 'Uji pembatalan', (string) ($row->reason ?? '-'));
check('audit mencatat versi sebelum dan sesudah',
    $row && str_contains((string) $row->old_value, 'version_number') && str_contains((string) $row->new_value, 'version_number'));

$additions = DB::table('event_audit_logs')->where('event_id', $e->id)->count();
check('jumlah baris audit = 1 buat + 2 transisi', $additions === 3, "baris={$additions}");

$busRows = DB::table('event_domain_events')->where('event_id', $e->id)->get();
check('peristiwa domain tercatat di bus', $busRows->count() === 3, "baris={$busRows->count()}");
check('peristiwa domain disiarkan', $busRows->where('published', 1)->count() === 3);
$names = $busRows->pluck('event_name')->unique()->sort()->values()->all();
check('nama peristiwa bermakna', in_array('event.created', $names, true) && in_array('event.status.changed', $names, true), implode(', ', $names));

// Jejak audit harus tetap utuh: tidak ada jalur yang menyunting atau menghapus.
$methods = get_class_methods(App\Modules\SchoolOperations\Events\Application\Services\EventAuditService::class);
$mutators = array_filter($methods, fn (string $m) => (bool) preg_match('/^(update|delete|remove|edit|purge)/i', $m));
check('layanan audit tidak menyediakan jalur menyunting', $mutators === [], $mutators ? implode(', ', $mutators) : 'hanya menambah');

// Kegagalan integrasi tidak boleh menjatuhkan alur bisnis.
check('tidak ada kegagalan integrasi tercatat', app(App\Modules\SchoolOperations\Events\Domain\Support\EventContext::class)->failures() === []);

echo "\n$pass lulus, $fail gagal\n";
exit($fail > 0 ? 1 : 0);
