<?php

namespace App\Services\Payments;

/**
 * One implementation per processor, each making a real, authenticated call
 * against that processor's real API to prove the submitted credentials
 * actually work — never a simulated success.
 */
interface ProcessorConnector
{
    /** Field name => human label, for the credential form. */
    public function requiredCredentialFields(): array;

    /** @param  array<string, string>  $credentials */
    public function test(array $credentials): ProcessorTestResult;
}
