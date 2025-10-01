<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Bridge;

use Alma\API\Client;
use Alma\API\Endpoints\Results\Eligibility;
use Alma\API\Entities\Merchant;
use Alma\API\Entities\Payment;
use Alma\API\RequestException;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfig;
use Payum\Core\Bridge\Spl\ArrayObject;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;

interface AlmaBridgeInterface
{
    public const QUERY_PARAM_PID = 'pid';

    public const DETAILS_KEY_PAYLOAD = 'payload';
    public const DETAILS_KEY_PAYMENT_ID = 'payment_id';
    public const DETAILS_KEY_PAYMENT_DATA = 'payment_data';
    public const DETAILS_KEY_IS_VALID = 'is_valid';
    public const DETAILS_KEY_ERROR_TRIGGERED_REFUND = 'error_triggered_refund';

    public function initialize(ArrayObject $config): void;

    public function getGatewayConfig(): GatewayConfig;

    public function getDefaultClient(?string $mode = null): ?Client;

    public static function createClientInstance(string $apiKey, string $mode, LoggerInterface $logger): ?Client;

    public function getMerchantInfo(): ?Merchant;

    /**
     * @param PaymentInterface $payment
     * @param int[] $installmentsCounts
     *
     * @return Eligibility[]
     */
    public function getEligibilities(PaymentInterface $payment, array $installmentsCounts): array;

    /**
     * @param ?Payment $paymentData Optional ref to a variable that will receive the payment's data from the API
     *
     * @throws RequestException
     */
    public function validatePayment(PaymentInterface $payment, string $almaPaymentId, ?Payment &$paymentData = null): bool;

    /**
     * @return Eligibility|Eligibility[]|array
     */
    public function retrieveEligibilities(array $data): array;
}
