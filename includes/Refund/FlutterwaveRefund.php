<?php

namespace FlutterwaveFluentCart\Refund;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;
use FlutterwaveFluentCart\API\FlutterwaveAPI;
use FlutterwaveFluentCart\FlutterwaveHelper;

class FlutterwaveRefund
{
    public static function processRemoteRefund($transaction, $amount, $args)
    {
        $flutterwaveTransactionId = $transaction->vendor_charge_id;

        if (!$flutterwaveTransactionId) {
            return new \WP_Error(
                'flutterwave_refund_error',
                __('Payment ID not found for refund', 'flutterwave-for-fluent-cart')
            );
        }

        // Flutterwave expects amount in main currency unit
        $refundAmount = FlutterwaveHelper::formatAmountForFlutterwave($amount, $transaction->currency);

        $refund = FlutterwaveAPI::createRefund($flutterwaveTransactionId, $refundAmount);

        if (is_wp_error($refund)) {
            return $refund;
        }

        if (Arr::get($refund, 'status') !== 'success') {
            return new \WP_Error(
                'refund_failed',
                Arr::get($refund, 'message', __('Refund could not be processed in Flutterwave. Please check your Flutterwave account', 'flutterwave-for-fluent-cart'))
            );
        }

        $refundData = Arr::get($refund, 'data', []);
        $refundId = Arr::get($refundData, 'id');
        $refundStatus = Arr::get($refundData, 'status');

        $acceptedStatuses = ['pending', 'pending-void', 'completed'];

        if (!in_array($refundStatus, $acceptedStatuses)) {
            return new \WP_Error(
                'refund_failed',
                sprintf(__('Refund status: %s. Please check your Flutterwave account', 'flutterwave-for-fluent-cart'), $refundStatus)
            );
        }

        return $refundId;
    }

    public static function createOrUpdateIpnRefund($refundData, $parentTransaction)
    {
        $allRefunds = OrderTransaction::query()
            ->where('order_id', $refundData['order_id'])
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->orderBy('id', 'DESC')
            ->get();

        if ($allRefunds->isEmpty()) {
            $refundData['uuid'] = md5(time() . wp_generate_uuid4());
            $createdRefund = OrderTransaction::query()->create($refundData);
            PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $createdRefund->total);
            return $createdRefund;
        }

        $currentRefundId = Arr::get($refundData, 'vendor_charge_id', '');

        foreach ($allRefunds as $refund) {
            if ($refund->vendor_charge_id == $currentRefundId) {
                if ($refund->total != $refundData['total']) {
                    $refund->fill($refundData);
                    $refund->save();
                }
                return $refund;
            }

            if (!$refund->vendor_charge_id && $refund->total == $refundData['total']) {
                $refund->fill($refundData);
                $refund->save();
                return $refund;
            }
        }

        $refundData['uuid'] = md5(time() . wp_generate_uuid4());
        $createdRefund = OrderTransaction::query()->create($refundData);
        PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $createdRefund->total);

        return $createdRefund;
    }
}
