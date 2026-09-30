<?php

namespace App\Modules\UKS\Application\Services;

use App\Models\Guardian;
use App\Models\User;
use App\Modules\UKS\Models\UksConsent;
use App\Modules\UKS\Models\UksVisit;
use App\Modules\UKS\Notifications\UksNotification;
use Illuminate\Support\Facades\DB;

/**
 * Pemberitahuan UKS ke orang tua, petugas, dan pelapor.
 *
 * Orang tua dijangkau lewat akun yang tertaut ke wali murid
 * (`users.userable` = Guardian). Bila wali belum punya akun, peristiwa tetap
 * tercatat di jejak audit supaya petugas tahu harus menghubungi manual.
 */
class UksNotifier
{
    /** Kunjungan baru: beri tahu petugas UKS yang bertugas. */
    public function visitRecorded(UksVisit $visit, User $actor): void
    {
        $visit->loadMissing('student:id,full_name');

        $this->notifyOfficers(new UksNotification([
            'type' => 'uks.visit.created',
            'title' => 'Kunjungan UKS baru: '.$visit->code,
            'body' => ($visit->student->full_name ?? 'Siswa').' — '.$visit->complaint.' ('.$visit->severity->label().').',
            'url' => '/uks/visits/'.$visit->id,
            'visit_id' => $visit->id,
        ]), $actor->id);

        activity('uks')->causedBy($actor)->performedOn($visit)
            ->withProperties(['severity' => $visit->severity->value])
            ->log('Kunjungan UKS dicatat');
    }

    /** Kabar untuk orang tua/wali siswa. */
    public function parentAlert(UksVisit $visit, string $message): void
    {
        $visit->loadMissing('student:id,full_name');
        $guardians = $this->guardianUsers((int) $visit->student_id);

        $notification = new UksNotification([
            'type' => 'uks.visit.parent',
            'title' => 'Kabar UKS: '.($visit->student->full_name ?? 'anak Anda'),
            'body' => $message,
            'url' => '/uks/visits/'.$visit->id,
            'visit_id' => $visit->id,
        ]);

        foreach ($guardians as $user) {
            $user->notify($notification);
        }

        if ($guardians !== []) {
            $visit->parent_notified = true;
            $visit->parent_notified_at = now();
            $visit->save();
        }
    }

    public function consentRecorded(UksVisit $visit, UksConsent $consent, User $actor): void
    {
        activity('uks')->causedBy($actor)->performedOn($visit)
            ->withProperties(['decision' => $consent->decision->value, 'channel' => $consent->channel])
            ->log('Persetujuan orang tua dicatat: '.$consent->decision->label());

        // Petugas perlu tahu bila orang tua menolak tindakan.
        if ($consent->decision->value === 'DITOLAK') {
            $this->notifyOfficers(new UksNotification([
                'type' => 'uks.consent.rejected',
                'title' => 'Persetujuan ditolak: '.$visit->code,
                'body' => 'Orang tua menolak tindakan untuk kunjungan '.$visit->code.'.',
                'url' => '/uks/visits/'.$visit->id,
                'visit_id' => $visit->id,
            ]), $actor->id);
        }
    }

    /** @return list<User> */
    private function guardianUsers(int $studentId): array
    {
        $guardianIds = DB::table('student_guardians')
            ->where('student_id', $studentId)
            ->pluck('guardian_id');

        if ($guardianIds->isEmpty()) {
            return [];
        }

        return User::query()
            ->where('userable_type', (new Guardian)->getMorphClass())
            ->whereIn('userable_id', $guardianIds)
            ->where('is_active', true)
            ->get()
            ->all();
    }

    private function notifyOfficers(UksNotification $notification, ?int $exceptUserId = null): void
    {
        $employeeIds = DB::table('uks_officers')->where('is_active', true)->pluck('employee_id');

        if ($employeeIds->isEmpty()) {
            return;
        }

        $users = User::query()
            ->where('userable_type', (new \App\Models\Employee)->getMorphClass())
            ->whereIn('userable_id', $employeeIds)
            ->where('is_active', true)
            ->when($exceptUserId !== null, fn ($q) => $q->where('id', '!=', $exceptUserId))
            ->get();

        foreach ($users as $user) {
            $user->notify($notification);
        }
    }
}
