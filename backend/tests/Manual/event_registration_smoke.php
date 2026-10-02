<?php

/** Uji pendaftaran: kapasitas, daftar tunggu, prioritas, idempotensi. */
require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\SchoolOperations\Events\Application\Services\EventRegistrationService;
use App\Modules\SchoolOperations\Events\Application\Services\EventService;
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $note = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   $label".($note ? " — $note" : '')."\n"; }
    else { $fail++; echo "  GAGAL $label".($note ? " — $note" : '')."\n"; }
}

$suffix = substr((string) uniqid(), -6);
$typeId = DB::table('school_event_types')->insertGetId([
    'code' => 'REG-'.$suffix, 'name' => 'Uji Registrasi', 'slug' => 'reg-'.$suffix,
    'created_at' => now(), 'updated_at' => now(),
]);

$events = app(EventService::class);
$regs = app(EventRegistrationService::class);

$makeEvent = function (array $overrides = []) use ($events, $typeId): App\Modules\SchoolOperations\Events\Models\Event {
    return $events->create(array_merge([
        'name' => 'Event Registrasi', 'school_event_type_id' => $typeId,
        'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
    ], $overrides));
};

// Pendaftaran harus ditolak selama event belum membuka pendaftaran.
$closed = $makeEvent(['name' => 'Belum Dibuka']);
$blocked = false;
try { $regs->register($closed, ['registrant_type' => 'STUDENT', 'student_id' => 1]); }
catch (DomainException $ex) { $blocked = true; }
check('pendaftaran ditolak saat belum dibuka', $blocked);

// Buka pendaftaran lewat siklus hidup, bukan dengan menulis status langsung.
$open = $makeEvent(['name' => 'Sudah Dibuka', 'max_participants' => 2, 'waitlist_enabled' => true]);
foreach (['PROPOSED', 'UNDER_REVIEW', 'APPROVED', 'PLANNING', 'REGISTRATION_OPEN'] as $step) {
    $open = $events->transition($open, $step);
}
check('pendaftaran terbuka setelah siklus hidup dijalani', $open->status === 'REGISTRATION_OPEN', (string) $open->status);

$r1 = $regs->register($open, ['registrant_type' => 'STUDENT', 'student_id' => 11]);
check('pendaftar pertama berstatus menunggu tinjauan', $r1->status === 'SUBMITTED', (string) $r1->status);
check('kode pendaftaran dibuat', !empty($r1->registration_code), (string) $r1->registration_code);

// Idempotensi: kunci yang sama tidak boleh melahirkan pendaftaran kedua.
$again = $regs->register($open, ['registrant_type' => 'STUDENT', 'student_id' => 11]);
check('kunci idempotensi mencegah pendaftaran ganda', $again->id === $r1->id, "id={$again->id} vs {$r1->id}");

$r1 = $regs->approve($r1);
check('pendaftaran dapat disetujui', $r1->status === 'APPROVED', (string) $r1->status);
check('kapasitas terpakai 1 dari 2', $regs->availableCapacity($open) === 1, (string) $regs->availableCapacity($open));

$r2 = $regs->approve($regs->register($open, ['registrant_type' => 'STUDENT', 'student_id' => 12]));
check('slot terakhir terisi', $regs->availableCapacity($open) === 0);

// Kuota penuh: pendaftar berikutnya masuk daftar tunggu, bukan ditolak.
$r3 = $regs->register($open, ['registrant_type' => 'STUDENT', 'student_id' => 13]);
check('pendaftar saat penuh masuk daftar tunggu', $r3->status === 'WAITLIST', (string) $r3->status);
check('posisi daftar tunggu terisi', (int) $r3->waitlist_position === 1, (string) $r3->waitlist_position);

// Mengubah kuota agar ada slot, lalu naikkan dari daftar tunggu.
$open->max_participants = 3;
$open->save();
$promoted = $regs->promoteFromWaitlist($open->refresh(), 1);
check('peserta daftar tunggu dapat dinaikkan', count($promoted) === 1 && $promoted[0]->id === $r3->id);
check('posisi daftar tunggu dibersihkan setelah naik', $promoted !== [] && $promoted[0]->waitlist_position === null);

// Menarik diri harus mengembalikan slot dan tidak boleh mengubah baris audit lama.
$auditBefore = DB::table('event_audit_logs')->where('event_id', $open->id)->count();
$r2 = $regs->withdraw($r2, 'Sakit');
check('penarikan mengembalikan slot', $regs->availableCapacity($open->refresh()) === 1);
$auditAfter = DB::table('event_audit_logs')->where('event_id', $open->id)->count();
check('penarikan menambah audit, bukan menimpa', $auditAfter > $auditBefore, "$auditBefore -> $auditAfter");

$rejected = false;
try { $regs->approve($r2); } catch (DomainException $ex) { $rejected = true; }
check('pendaftaran yang ditarik tidak dapat disetujui', $rejected);

// Event tanpa daftar tunggu harus menolak saat penuh.
$strict = $makeEvent(['name' => 'Tanpa Daftar Tunggu', 'max_participants' => 1, 'waitlist_enabled' => false]);
foreach (['PROPOSED', 'UNDER_REVIEW', 'APPROVED', 'PLANNING', 'REGISTRATION_OPEN'] as $step) { $strict = $events->transition($strict, $step); }
$strict = $regs->approve($regs->register($strict, ['registrant_type' => 'STUDENT', 'student_id' => 21]));
$denied = false;
try { $regs->register($strict->event, ['registrant_type' => 'STUDENT', 'student_id' => 22]); }
catch (DomainException $ex) { $denied = true; }
check('penuh tanpa daftar tunggu menolak dengan jelas', $denied);

check('kegagalan integrasi tidak menjatuhkan alur',
    app(App\Modules\SchoolOperations\Events\Domain\Support\EventContext::class)->failures() === []);

echo "\n$pass lulus, $fail gagal\n";
exit($fail > 0 ? 1 : 0);
