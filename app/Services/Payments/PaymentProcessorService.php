<?php

namespace App\Services\Payments;

use App\Models\PaymentProcessor;
use App\Models\User;
use InvalidArgumentException;

/**
 * Connects, tests and disconnects a processor row. Every "connected" status
 * this writes followed a real, successful test call against that processor's
 * real API — never assumed from the credentials merely being present.
 */
class PaymentProcessorService
{
    /** @var array<string, class-string<ProcessorConnector>> */
    private const CONNECTORS = [
        PaymentProcessor::STRIPE => StripeConnector::class,
        PaymentProcessor::PAYPAL => PayPalConnector::class,
        PaymentProcessor::SQUARE => SquareConnector::class,
    ];

    public function connectorFor(string $key): ProcessorConnector
    {
        if (! isset(self::CONNECTORS[$key])) {
            throw new InvalidArgumentException("Unknown payment processor \"{$key}\".");
        }

        return app(self::CONNECTORS[$key]);
    }

    /** @param  array<string, string>  $credentials */
    public function connect(PaymentProcessor $processor, array $credentials, User $user): ProcessorTestResult
    {
        $result = $this->connectorFor($processor->key)->test($credentials);

        if ($result->success) {
            $processor->update([
                'credentials' => $credentials,
                'status' => PaymentProcessor::STATUS_ACTIVE,
                'connected_at' => now(),
                'last_tested_at' => now(),
                'last_error' => null,
                'connected_by' => $user->id,
            ]);
        } else {
            $processor->update([
                'status' => PaymentProcessor::STATUS_ERROR,
                'last_tested_at' => now(),
                'last_error' => $result->message,
            ]);
        }

        return $result;
    }

    public function test(PaymentProcessor $processor): ProcessorTestResult
    {
        if (blank($processor->credentials)) {
            return ProcessorTestResult::fail('No credentials are saved for this processor yet.');
        }

        $result = $this->connectorFor($processor->key)->test($processor->credentials);

        $processor->update([
            'status' => $result->success ? PaymentProcessor::STATUS_ACTIVE : PaymentProcessor::STATUS_ERROR,
            'last_tested_at' => now(),
            'last_error' => $result->success ? null : $result->message,
        ]);

        return $result;
    }

    public function disconnect(PaymentProcessor $processor): void
    {
        $processor->update([
            'credentials' => null,
            'status' => PaymentProcessor::STATUS_NOT_CONNECTED,
            'connected_at' => null,
            'last_error' => null,
            'connected_by' => null,
        ]);
    }
}
