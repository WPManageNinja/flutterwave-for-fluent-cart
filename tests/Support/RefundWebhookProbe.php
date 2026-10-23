<?php

namespace Tests\Support;

use FlutterwaveFluentCart\Webhook\FlutterwaveWebhook;

class RefundWebhookProbe extends FlutterwaveWebhook
{
    private $body;
    private $responseCode;

    public function deliver(string $body): int
    {
        $this->body = $body;
        return $this->capture(function () {
            $this->verifyAndProcess();
        });
    }

    public function deliverRefund(array $data): int
    {
        return $this->capture(function () use ($data) {
            $this->handleRefundCompleted($data);
        });
    }

    protected function getWebhookPayload()
    {
        return $this->body;
    }

    protected function sendResponse($statusCode = 200, $message = 'Success')
    {
        $this->responseCode = $statusCode;
        throw new \RuntimeException($message, $statusCode);
    }

    private function capture(callable $callback): int
    {
        $this->responseCode = null;
        try {
            $callback();
        } catch (\RuntimeException $exception) {
            if ($this->responseCode === null) {
                throw $exception;
            }
        }
        return (int) $this->responseCode;
    }
}
