<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Application\Support\ClinicNumber;
use App\Modules\Clinic\Domain\Contracts\InventoryProviderInterface;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Domain\Enums\InventoryStockResult;
use App\Modules\Clinic\Domain\Enums\PrescriptionStatus;
use App\Modules\Clinic\Models\ClinicDispensing;
use App\Modules\Clinic\Models\ClinicDispensingItem;
use App\Modules\Clinic\Models\ClinicMedicationBatch;
use App\Modules\Clinic\Models\ClinicPrescription;
use App\Modules\Clinic\Models\ClinicPrescriptionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penyerahan obat (dispensing).
 *
 * Inilah titik pertemuan klinik dan gudang. Empat jaminan yang dijaga:
 *
 * 1. **Alergi diperiksa lebih dahulu** (Rule 3) — resep bertanda override
 *    tetap dicatat, tetapi peringatannya tersimpan pada resep.
 * 2. **Obat kedaluwarsa tidak boleh diberikan** (Rule 4) — batch kedaluwarsa
 *    ditolak sebelum menyentuh stok.
 * 3. **Stok tidak pernah dikurangi dua kali** (Rule 6) — kunci idempotensi
 *    membuat percobaan ulang mengembalikan hasil yang sama.
 * 4. **Klinik tidak pernah menulis saldo sendiri** (Rule 15) — pengurangan
 *    hanya lewat `InventoryProviderInterface`, di dalam transaksi yang sama.
 */
class DispensingService
{
    public function __construct(
        private readonly InventoryProviderInterface $inventory,
        private readonly PrescriptionService $prescriptions,
        private readonly ClinicAuditService $audit,
    ) {
    }

    /**
     * Serahkan obat untuk sebuah resep.
     *
     * @param array{items?: list<array{prescription_item_id: int, quantity: float, batch_id?: ?int, notes?: ?string}>, notes?: ?string, idempotency_key?: ?string} $data
     * @return array{dispensing: ClinicDispensing, results: list<array<string, mixed>>, prescription_status: string}
     */
    public function dispense(ClinicPrescription $prescription, array $data, User $actor): array
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = ClinicDispensing::query()
                ->with('items')
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return [
                    'dispensing' => $existing,
                    'results' => $existing->items->map(fn (ClinicDispensingItem $item): array => $this->describeItem($item))->all(),
                    'prescription_status' => $existing->prescription?->status ?? $prescription->status,
                ];
            }
        }

        if (! $prescription->isDispensable()) {
            throw ValidationException::withMessages([
                'status' => "Resep berstatus {$prescription->statusEnum()->label()} dan tidak dapat diserahkan.",
            ]);
        }

        $requested = $this->normaliseItems($prescription, $data);

        return DB::transaction(function () use ($prescription, $requested, $data, $actor, $idempotencyKey): array {
            $sequence = $this->nextSequence();

            $dispensing = ClinicDispensing::query()->create([
                'dispensing_number' => ClinicNumber::dispensing($sequence),
                'prescription_id' => $prescription->id,
                'patient_id' => $prescription->patient_id,
                'visit_id' => $prescription->visit_id,
                'dispensed_by' => $actor->id,
                'dispensed_at' => now(),
                'status' => 'COMPLETED',
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $results = [];

            foreach ($requested as $entry) {
                /** @var ClinicPrescriptionItem $item */
                $item = $entry['item'];
                $quantity = $entry['quantity'];
                $medication = $item->medication;
                $batch = $entry['batch'];

                // -- Aturan 4: obat kedaluwarsa tidak boleh diberikan.
                // Dua jalur: (a) batch terpilih ternyata kedaluwarsa, atau
                // (b) obat punya catatan batch tetapi tidak satu pun dapat dipakai.
                $expiryBlocker = $this->expiryBlocker($item, $batch);

                if ($expiryBlocker !== null) {
                    $results[] = $this->persist(
                        $dispensing,
                        $item,
                        $batch,
                        $quantity,
                        InventoryStockResult::EXPIRED,
                        null,
                        $expiryBlocker,
                    );

                    continue;
                }

                // -- Aturan 15: stok hanya berubah lewat Inventory.
                $outcome = $this->inventory->consume(
                    $medication?->inventory_item_id,
                    $quantity,
                    'Pemakaian klinis '.$dispensing->dispensing_number.' untuk resep '.$prescription->prescription_number,
                    $dispensing->dispensing_number,
                    $actor->id,
                );

                $row = $this->persist(
                    $dispensing,
                    $item,
                    $batch,
                    $quantity,
                    $outcome['result'],
                    $outcome['movement_id'],
                    $outcome['message'],
                    $outcome['reference'],
                );

                // Jumlah terserahkan hanya bertambah bila stok benar-benar keluar.
                if ($outcome['result'] === InventoryStockResult::SUCCESS) {
                    $item->dispensed_quantity = round((float) $item->dispensed_quantity + $quantity, 2);
                    $item->save();
                }

                $results[] = $row;

                $this->audit->record(
                    AuditAction::DISPENSE,
                    $row,
                    (int) $prescription->patient_id,
                    $actor,
                    [
                        'dispensing_number' => $dispensing->dispensing_number,
                        'medication' => $item->medication_name,
                        'quantity' => $quantity,
                        'inventory_result' => $outcome['result']->value,
                    ],
                    [],
                    'Obat diserahkan',
                );
            }

            $successCount = collect($results)->where('inventory_result', InventoryStockResult::SUCCESS->value)->count();

            $dispensing->status = match (true) {
                $successCount === count($results) && $results !== [] => 'COMPLETED',
                $successCount === 0 => 'REJECTED',
                default => 'PARTIAL',
            };
            $dispensing->save();

            $prescription = $this->prescriptions->refreshStatus($prescription);

            return [
                'dispensing' => $dispensing->fresh('items'),
                // Bentuk hasil diseragamkan lewat describeItem() agar pengiriman
                // ulang (idempotent replay) mengembalikan struktur yang sama
                // dengan pemanggilan pertama — klien tidak perlu dua bentuk.
                'results' => $dispensing->items->map(fn (ClinicDispensingItem $item): array => $this->describeItem($item))->all(),
                'prescription_status' => $prescription->status,
            ];
        });
    }

    /**
     * Validasi & normalkan permintaan penyerahan.
     *
     * Bila `items` tidak dikirim, seluruh sisa resep diserahkan sekaligus —
     * perilaku yang paling sering dipakai di UKS (satu kali serah obat).
     *
     * @param array<string, mixed> $data
     * @return list<array{item: ClinicPrescriptionItem, quantity: float, batch: ?ClinicMedicationBatch}>
     */
    private function normaliseItems(ClinicPrescription $prescription, array $data): array
    {
        $prescription->loadMissing('items.medication');
        $requested = array_values((array) ($data['items'] ?? []));
        $normalised = [];

        if ($requested === []) {
            foreach ($prescription->items as $item) {
                $remaining = $item->remainingQuantity();

                if ($remaining <= 0) {
                    continue;
                }

                $normalised[] = [
                    'item' => $item,
                    'quantity' => $remaining,
                    'batch' => $this->pickBatch($item),
                ];
            }

            if ($normalised === []) {
                throw ValidationException::withMessages([
                    'items' => 'Tidak ada sisa obat yang dapat diserahkan pada resep ini.',
                ]);
            }

            return $normalised;
        }

        foreach ($requested as $index => $row) {
            $itemId = $row['prescription_item_id'] ?? null;

            if ($itemId === null) {
                throw ValidationException::withMessages(["items.{$index}.prescription_item_id" => 'Baris resep wajib diisi.']);
            }

            $item = $prescription->items->firstWhere('id', (int) $itemId);

            if ($item === null) {
                throw ValidationException::withMessages(["items.{$index}.prescription_item_id" => 'Baris resep tidak termasuk resep ini.']);
            }

            $quantity = (float) ($row['quantity'] ?? 0);

            if ($quantity <= 0) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'Jumlah harus lebih dari nol.']);
            }

            if ($quantity > $item->remainingQuantity() + 0.0001) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => "Jumlah melebihi sisa resep ({$item->remainingQuantity()}).",
                ]);
            }

            $batch = isset($row['batch_id'])
                ? ClinicMedicationBatch::query()->find((int) $row['batch_id'])
                : $this->pickBatch($item);

            $normalised[] = ['item' => $item, 'quantity' => $quantity, 'batch' => $batch];
        }

        return $normalised;
    }

    /**
     * Pilih batch menurut FEFO: kedaluwarsa terdekat lebih dahulu.
     * Mengembalikan null bila obat tidak memiliki catatan batch.
     */
    private function pickBatch(ClinicPrescriptionItem $item): ?ClinicMedicationBatch
    {
        if ($item->medication_id === null) {
            return null;
        }

        return ClinicMedicationBatch::query()
            ->where('medication_id', $item->medication_id)
            ->usable()
            ->first();
    }

    /**
     * Menentukan apakah penyerahan harus ditolak karena kedaluwarsa.
     *
     * FEFO hanya memilih batch yang masih layak, sehingga batch kedaluwarsa
     * tidak pernah terpilih. Karena itu pemeriksaan ini menutup celah yang
     * berbahaya: bila obat **punya** catatan batch tetapi tidak satu pun dapat
     * dipakai (semua kedaluwarsa atau dikarantina), obat tidak boleh keluar —
     * meski saldo item di Inventory masih positif.
     */
    private function expiryBlocker(ClinicPrescriptionItem $item, ?ClinicMedicationBatch $batch): ?string
    {
        if (! (bool) config('clinic.dispensing.block_expired', true)) {
            return null;
        }

        if ($batch !== null && $batch->isExpired()) {
            return "Batch {$batch->batch_number} sudah kedaluwarsa per {$batch->expiry_date?->toDateString()}.";
        }

        if ($batch !== null || $item->medication_id === null) {
            return null;
        }

        $batches = ClinicMedicationBatch::query()->where('medication_id', $item->medication_id)->get();

        if ($batches->isEmpty()) {
            return null;
        }

        $latest = $batches->sortByDesc(fn (ClinicMedicationBatch $row) => $row->expiry_date?->timestamp ?? 0)->first();

        return sprintf(
            'Seluruh batch %s tidak dapat dipakai (kedaluwarsa/karantina); batch terakhir berakhir %s. Lakukan pemusnahan dan pengadaan ulang.',
            $item->medication_name,
            $latest?->expiry_date?->toDateString() ?? 'tanpa tanggal',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function persist(
        ClinicDispensing $dispensing,
        ClinicPrescriptionItem $item,
        ?ClinicMedicationBatch $batch,
        float $quantity,
        InventoryStockResult $result,
        ?int $movementId,
        string $message,
        ?string $reference = null,
    ): ClinicDispensingItem {
        return ClinicDispensingItem::query()->create([
            'dispensing_id' => $dispensing->id,
            'prescription_item_id' => $item->id,
            'medication_id' => $item->medication_id,
            'batch_id' => $batch?->id,
            'batch_number' => $batch?->batch_number,
            'expiry_date' => $batch?->expiry_date,
            'quantity' => $quantity,
            'unit' => $item->unit,
            'inventory_result' => $result->value,
            'inventory_reference' => $reference,
            'inventory_movement_id' => $movementId,
            'notes' => $message,
        ]);
    }

    /** @return array<string, mixed> */
    private function describeItem(ClinicDispensingItem $item): array
    {
        return [
            'id' => $item->id,
            'prescription_item_id' => $item->prescription_item_id,
            'medication_id' => $item->medication_id,
            'medication_name' => $item->prescriptionItem?->medication_name,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'batch_number' => $item->batch_number,
            'expiry_date' => $item->expiry_date?->toDateString(),
            'inventory_result' => $item->inventory_result,
            'inventory_movement_id' => $item->inventory_movement_id,
            'notes' => $item->notes,
        ];
    }

    private function nextSequence(): int
    {
        $sequence = ClinicNumber::nextSequence('clinic_dispensings', 'dispensed_at', now()->toDateString());

        while (ClinicDispensing::query()->where('dispensing_number', ClinicNumber::dispensing($sequence))->exists()) {
            $sequence++;
        }

        return $sequence;
    }
}
