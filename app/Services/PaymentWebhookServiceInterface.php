<?php

namespace App\Services;

interface PaymentWebhookServiceInterface
{
    /**
     * Handle payment webhook (idempotent and out-of-order safe).
     *
     * @param array $payload
     * @return array
     */
    public function handlePaymentWebhook(array $payload): array;
}

