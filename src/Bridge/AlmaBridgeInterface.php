<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Bridge;

use Alma\API\Client;
use Alma\API\Endpoints\Results\Eligibility;
use Alma\API\Entities\Merchant;
use Alma\API\Entities\Payment;
use Alma\API\RequestError;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfig;
use Payum\Core\Bridge\Spl\ArrayObject;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;

interface AlmaBridgeInterface
{
    const QUERY_PARAM_PID = 'pid';

    const DETAILS_KEY_PAYLOAD = 'payload';
    const DETAILS_KEY_PAYMENT_ID = 'payment_id';
    const DETAILS_KEY_PAYMENT_DATA = 'payment_data';
    const DETAILS_KEY_IS_VALID = 'is_valid';
    const DETAILS_KEY_ERROR_TRIGGERED_REFUND = 'error_triggered_refund';

    function initialize(ArrayObject $config): void;

    function getGatewayConfig(): GatewayConfig;

    function getDefaultClient(?string $mode = null): ?Client;
    static function createClientInstance(string $apiKey, string $mode, LoggerInterface $logger): ?Client;

    function getMerchantInfo(): ?Merchant;

    /**
     * @param PaymentInterface $payment
     * @param int[] $installmentsCounts
     * @return Eligibility[]
     */
    function getEligibilities(PaymentInterface $payment, array $installmentsCounts): array;

    /**
     * @param PaymentInterface $payment
     * @param string $almaPaymentId
     * @param Payment|null $paymentData Optional ref to a variable that will receive the payment's data from the API
     * @return bool
     * @throws RequestError
     */
    function validatePayment(PaymentInterface $payment, string $almaPaymentId, Payment &$paymentData = null): bool;
}
