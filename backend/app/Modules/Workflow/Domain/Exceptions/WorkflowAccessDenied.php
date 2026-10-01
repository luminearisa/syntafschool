<?php

namespace App\Modules\Workflow\Domain\Exceptions;

/**
 * Aktor tidak berhak atas tindakan ini (HTTP 403).
 *
 * Dipakai untuk kasus yang bukan sekadar "kurang permission": approver di luar
 * scope, requester yang mencoba menyetujui request sendiri (§36), atau SoD yang
 * melarang orang yang sama tampil dua kali (§37).
 */
class WorkflowAccessDenied extends WorkflowException
{
    public function statusCode(): int
    {
        return 403;
    }

    /** @param array<string, mixed> $context */
    public static function make(string $message, array $context = []): self
    {
        return new self($message, $context);
    }

    public static function outOfScope(): self
    {
        return new self('Penugasan ini bukan untuk Anda — approver di luar cakupan tidak dapat memutuskan.');
    }

    public static function selfApproval(): self
    {
        return new self(
            'Requester tidak boleh menyetujui request sendiri. Kebijakan self-approval harus diaktifkan eksplisit pada definisi workflow.',
        );
    }
}
