<?php

namespace App\Modules\Hrd\Domain\Exceptions;

/**
 * Aturan validasi HRD dilanggar (§57).
 *
 * Membawa kesalahan per kolom sekaligus kode aturan, sehingga respons API dapat
 * memberi pesan yang dapat ditindaklanjuti ("Kontrak berjangka wajib punya
 * tanggal berakhir") tanpa controller perlu mengetahui aturannya.
 */
class HrdValidationException extends HrdException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        protected array $errors = [],
        string $ruleCode = 'hr.validation',
    ) {
        parent::__construct($message);
        $this->ruleCode = $ruleCode;
    }

    public function ruleCode(): string
    {
        return $this->ruleCode;
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function forField(string $field, string $message, string $ruleCode = 'hr.validation'): self
    {
        return new self($message, [$field => [$message]], $ruleCode);
    }

    /** @param array<string, list<string>> $errors */
    public static function fromErrors(array $errors, string $message = 'Data yang dikirim tidak memenuhi aturan SDM.', string $ruleCode = 'hr.validation'): self
    {
        return new self($message, $errors, $ruleCode);
    }
}
