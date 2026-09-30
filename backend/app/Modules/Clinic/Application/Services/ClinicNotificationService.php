<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Domain\Contracts\NotificationProviderInterface;
use App\Modules\Clinic\Domain\Contracts\ParentProviderInterface;
use App\Modules\Clinic\Domain\Enums\NotificationEvent;
use App\Modules\Clinic\Models\ClinicDischarge;
use App\Modules\Clinic\Models\ClinicEmergency;
use App\Modules\Clinic\Models\ClinicHealthCertificate;
use App\Modules\Clinic\Models\ClinicInjury;
use App\Modules\Clinic\Models\ClinicPatient;
use App\Modules\Clinic\Models\ClinicReferral;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Support\Facades\DB;

/**
 * Pemberitahuan klinis ber-kebijakan privasi.
 *
 * Prinsip yang dijaga: **orang tua berhak tahu anaknya dilayani, bukan
 * menerima salinan rekam medis.** Karena itu setiap pesan disusun dari
 * "template aman" yang hanya memuat informasi yang diizinkan kebijakan:
 *
 * - tanggal layanan, jenis layanan umum, dan ajakan membuka portal;
 * - detail klinis (catatan, hasil asesmen, diagnosis rinci) **tidak** dikirim
 *   ke kanal apa pun kecuali administrator mengizinkannya.
 *
 * Peristiwa yang menyangkut keselamatan (gawat darurat, dipulangkan, dirujuk)
 * selalu dikirim dan tidak dapat dimatikan dari UI — lihat
 * `clinic.privacy.mandatory_events`.
 */
class ClinicNotificationService
{
    public function __construct(
        private readonly NotificationProviderInterface $notifications,
        private readonly ParentProviderInterface $parents,
    ) {
    }

    /** Apakah peristiwa ini boleh diberitakan menurut kebijakan sekolah. */
    public function isAllowed(NotificationEvent $event): bool
    {
        $mandatory = (array) config('clinic.privacy.mandatory_events', []);

        if (in_array($event->value, $mandatory, true)) {
            return true;
        }

        return match ($event) {
            NotificationEvent::VISIT_CREATED => (bool) config('clinic.privacy.notify_on_visit', true),
            NotificationEvent::MEDICATION => (bool) config('clinic.privacy.parent_visible_medication', true),
            default => (bool) config('clinic.privacy.notify_on_visit', true),
        };
    }

    /** Kunjungan dicatat: kabar ringkas tanpa isi klinis. */
    public function notifyVisitCreated(ClinicVisit $visit, User $actor): array
    {
        return $this->toParents($visit, NotificationEvent::VISIT_CREATED, 'clinic.visit.'.$visit->id, [
            'title' => 'Kunjungan UKS/Klinik dicatat',
            'body' => sprintf(
                'Anak Anda mendapat layanan kesehatan pada %s. Silakan lihat detail layanan melalui Portal Orang Tua.',
                $visit->visit_at?->translatedFormat('d F Y H:i') ?? now()->translatedFormat('d F Y'),
            ),
        ], $actor);
    }

    public function notifyEmergency(ClinicVisit $visit, ClinicEmergency $emergency, User $actor): array
    {
        $body = sprintf(
            'Anak Anda memerlukan penanganan segera pada %s di %s. Petugas klinik akan menghubungi Anda. Mohon segera menghubungi sekolah.',
            $emergency->incident_at->translatedFormat('d F Y H:i'),
            $emergency->location ?? 'lingkungan sekolah',
        );

        return $this->toParents($visit, NotificationEvent::EMERGENCY, 'clinic.emergency.'.$emergency->id, [
            'title' => 'PENTING: Penanganan gawat darurat',
            'body' => $body,
        ], $actor, ['database', 'mail']);
    }

    public function notifyInjury(ClinicPatient $patient, ClinicInjury $injury, User $actor): array
    {
        $body = sprintf(
            'Anak Anda mengalami cedera pada %s di %s dan telah mendapat penanganan awal di klinik sekolah.%s',
            $injury->incident_at->translatedFormat('d F Y H:i'),
            $injury->location ?? 'lingkungan sekolah',
            $injury->referral_required ? ' Anak Anda dirujuk untuk pemeriksaan lanjutan.' : '',
        );

        return $this->toPatientParents($patient, NotificationEvent::INJURY, 'clinic.injury.'.$injury->id, [
            'title' => 'Kabar cedera anak',
            'body' => $body,
        ], $actor);
    }

    public function notifyReferral(ClinicVisit $visit, ClinicReferral $referral, User $actor): array
    {
        $body = sprintf(
            'Anak Anda dirujuk ke %s pada %s. Silakan lihat Portal Orang Tua untuk informasi tindak lanjut.',
            $referral->facility,
            $referral->referral_date->translatedFormat('d F Y H:i'),
        );

        return $this->toParents($visit, NotificationEvent::REFERRAL, 'clinic.referral.'.$referral->id, [
            'title' => 'Rujukan ke fasilitas kesehatan',
            'body' => $body,
        ], $actor, ['database', 'mail']);
    }

    public function notifyDischarge(ClinicVisit $visit, ClinicDischarge $discharge, User $actor): array
    {
        $body = sprintf(
            'Anak Anda telah selesai mendapat layanan klinik pada %s dengan status: %s.%s',
            $discharge->discharge_time->translatedFormat('d F Y H:i'),
            $discharge->statusEnum()->label(),
            $discharge->follow_up_required && $discharge->follow_up_date !== null
                ? ' Dijadwalkan kontrol pada '.$discharge->follow_up_date->translatedFormat('d F Y').'.'
                : '',
        );

        return $this->toParents($visit, NotificationEvent::SENT_HOME, 'clinic.discharge.'.$discharge->id, [
            'title' => 'Kabar layanan klinik selesai',
            'body' => $body,
        ], $actor);
    }

    /** Pemberitahuan obat: hanya instruksi pemberian, bukan diagnosis. */
    public function notifyMedication(ClinicVisit $visit, string $medicationName, ?string $instruction, User $actor): array
    {
        if (! (bool) config('clinic.privacy.parent_visible_medication', true)) {
            return $this->suppressed(NotificationEvent::MEDICATION, $visit, 'Kebijakan privasi menyembunyikan detail obat dari orang tua.');
        }

        $body = sprintf(
            'Anak Anda diberikan obat oleh klinik sekolah: %s.%s Mohon periksa Portal Orang Tua untuk instruksi lengkap.',
            $medicationName,
            $instruction === null ? '' : ' Catatan pemberian: '.$instruction.'.',
        );

        return $this->toParents($visit, NotificationEvent::MEDICATION, 'clinic.medication.'.$visit->id.'.'.$medicationName, [
            'title' => 'Informasi pemberian obat',
            'body' => $body,
        ], $actor);
    }

    public function notifyFollowUp(ClinicPatient $patient, string $dueDate, string $type, User $actor): array
    {
        return $this->toPatientParents($patient, NotificationEvent::FOLLOW_UP, 'clinic.followup.'.$patient->id.'.'.$dueDate, [
            'title' => 'Pengingat tindak lanjut kesehatan',
            'body' => "Anak Anda dijadwalkan {$type} pada {$dueDate}. Mohon diingatkan dan hubungi klinik bila perlu penyesuaian jadwal.",
        ], $actor);
    }

    public function notifyCertificateReady(ClinicHealthCertificate $certificate, User $actor): array
    {
        $patient = $certificate->patient;

        return $this->toPatientParents($patient, NotificationEvent::CERTIFICATE_READY, 'clinic.certificate.'.$certificate->id, [
            'title' => 'Surat keterangan kesehatan siap',
            'body' => sprintf(
                'Surat keterangan %s untuk anak Anda sudah diterbitkan pada %s dan dapat diunduh melalui Portal Orang Tua.',
                $certificate->typeEnum()->label(),
                $certificate->issued_at?->translatedFormat('d F Y') ?? now()->translatedFormat('d F Y'),
            ),
        ], $actor);
    }

    public function notifyScreening(ClinicPatient $patient, string $result, ?string $recommendation, User $actor): array
    {
        // Hasil skrining disampaikan sebagai ajakan, bukan label medis.
        $body = $result === 'NORMAL'
            ? 'Hasil pemeriksaan kesehatan berkala anak Anda dalam batas normal.'
            : 'Hasil pemeriksaan kesehatan berkala anak Anda memerlukan perhatian lanjutan. Silakan lihat Portal Orang Tua untuk rekomendasi.';

        return $this->toPatientParents($patient, NotificationEvent::SCREENING, 'clinic.screening.'.$patient->id.'.'.now()->toDateString(), [
            'title' => 'Hasil pemeriksaan kesehatan berkala',
            'body' => $body.($recommendation === null ? '' : ' '.$recommendation),
        ], $actor);
    }

    public function notifyObservation(ClinicVisit $visit, string $message, User $actor): array
    {
        return $this->toParents($visit, NotificationEvent::OBSERVATION, 'clinic.observation.'.$visit->id, [
            'title' => 'Anak Anda dalam pemantauan klinik',
            'body' => $message,
        ], $actor);
    }

    /**
     * Kirim ke wali siswa pemilik kunjungan.
     *
     * @param array{title: string, body: string} $message
     * @param list<string>|null $channels
     * @return array<string, mixed>
     */
    private function toParents(ClinicVisit $visit, NotificationEvent $event, string $dedupeKey, array $message, User $actor, ?array $channels = null): array
    {
        $patient = $visit->patient;

        if ($patient === null || $patient->student_id === null) {
            return $this->suppressed($event, $visit, 'Pasien bukan siswa sehingga tidak ada wali penerima.');
        }

        return $this->dispatch($event, $patient, $visit->id, $message, $dedupeKey, $channels);
    }

    /**
     * Kirim ke wali pasien (tanpa kunjungan tertentu).
     *
     * @param array{title: string, body: string} $message
     * @return array<string, mixed>
     */
    private function toPatientParents(ClinicPatient $patient, NotificationEvent $event, string $dedupeKey, array $message, User $actor): array
    {
        return $this->dispatch($event, $patient, null, $message, $dedupeKey, null);
    }

    /**
     * @param array{title: string, body: string} $message
     * @param list<string>|null $channels
     * @return array<string, mixed>
     */
    private function dispatch(NotificationEvent $event, ClinicPatient $patient, ?int $visitId, array $message, string $dedupeKey, ?array $channels): array
    {
        if (! $this->isAllowed($event)) {
            return $this->suppressed($event, null, 'Peristiwa ini dimatikan oleh kebijakan sekolah.', $patient->id, $visitId);
        }

        if ($patient->student_id === null) {
            return $this->suppressed($event, null, 'Pasien tidak terhubung ke siswa.', $patient->id, $visitId);
        }

        $recipients = $this->parents->guardianUserIdsForStudent((int) $patient->student_id);

        if ($recipients === []) {
            // Tetap tercatat: petugas perlu tahu harus menelepon manual.
            return $this->suppressed($event, null, 'Wali belum memiliki akun, hubungi manual.', $patient->id, $visitId);
        }

        return $this->notifications->send($recipients, [
            'event' => $event->value,
            'title' => $message['title'],
            'body' => $message['body'],
            'url' => '/clinic/portal',
            'audience' => 'PARENT',
            'patient_id' => $patient->id,
            'visit_id' => $visitId,
            'dedupe_key' => $dedupeKey,
        ]);
    }

    /** @return array<string, mixed> */
    private function suppressed(NotificationEvent $event, ?ClinicVisit $visit, string $reason, ?int $patientId = null, ?int $visitId = null): array
    {
        DB::table('clinic_notification_logs')->insert([
            'event' => $event->value,
            'patient_id' => $patientId ?? $visit?->patient_id,
            'visit_id' => $visitId ?? $visit?->id,
            'recipient_id' => null,
            'channel' => 'DATABASE',
            'audience' => 'PARENT',
            'title' => $event->label(),
            'body' => null,
            'status' => 'SUPPRESSED',
            'suppressed_reason' => $reason,
            'sent_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['sent' => 0, 'suppressed' => 1, 'channels' => [], 'reason' => $reason];
    }
}
