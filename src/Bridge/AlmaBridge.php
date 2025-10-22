<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Bridge;

use Alma\API\Client;
use Alma\API\Entities\Instalment;
use Alma\API\Entities\Merchant;
use Alma\API\Entities\Payment;
use Alma\API\Entities\Payment as AlmaPayment;
use Alma\API\RequestError;
use Alma\SyliusPaymentPlugin\AlmaSyliusPaymentPlugin;
use Alma\SyliusPaymentPlugin\DataBuilder\PaymentDataBuilderInterface;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfig;
use Exception;
use Payum\Core\Bridge\Spl\ArrayObject;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\CoreBundle\SyliusCoreBundle;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;

final class AlmaBridge implements AlmaBridgeInterface
{
    private ?GatewayConfig $gatewayConfig = null;

    private static ?Client $almaClient = null;

    public function __construct(
        private LoggerInterface $logger,
        private PaymentDataBuilderInterface $paymentDataBuilder,
    ) {
    }

    public function initialize(ArrayObject $config): void
    {
        self::$almaClient = null;
        $this->gatewayConfig = new GatewayConfig($config);
    }

    public function getDefaultClient(?string $mode = null): Client
    {
        if (null !== self::$almaClient) {
            return self::$almaClient;
        }

        if (null === $this->gatewayConfig) {
            throw new Exception('AlmaBridge not initialized with gateway config');
        }

        if (null === $mode) {
            $mode = $this->gatewayConfig->getApiMode();
        }

        self::$almaClient = self::createClientInstance(
            $this->gatewayConfig->getActiveApiKey(),
            $mode,
            $this->logger
        );

        return self::$almaClient;
    }

    public static function createClientInstance(string $apiKey, string $mode, LoggerInterface $logger): Client
    {
        $alma = null;

        $alma = new Client($apiKey, [
            'mode' => $mode,
            'logger' => $logger,
        ]);

        $alma->addUserAgentComponent('Sylius', SyliusCoreBundle::VERSION);
        $alma->addUserAgentComponent('Alma for Sylius', AlmaSyliusPaymentPlugin::VERSION);

        return $alma;
    }

    public function getMerchantInfo(): ?Merchant
    {
        $client = $this->getDefaultClient();

        /** @var ?Merchant $merchant */
        $merchant = null;

        try {
            $merchant = $client->merchants->me();
        } catch (RequestError $e) {
            $this->logger->error('[Alma] Error fetching merchant info: ' . $e->getMessage());
        }

        return $merchant;
    }

    public function getGatewayConfig(): GatewayConfig
    {
        if (null === $this->gatewayConfig) {
            throw new Exception('AlmaBridge not initialized with gateway config');
        }

        return $this->gatewayConfig;
    }

    public function getEligibilities(PaymentInterface $payment, array $installmentsCounts): array
    {
        $builder = $this->paymentDataBuilder;
        $builder->addBuilder(function (array $data, PaymentInterface $payment) use ($installmentsCounts): array {
            $data['payment'] = array_merge($data['payment'], [
                'installments_count' => $installmentsCounts,
            ]);

            return $data;
        });

        $alma = $this->getDefaultClient();
        try {
            return $alma->payments->eligibility($builder([], $payment), true);
        } catch (RequestError $e) {
            $this->logger->error('[Alma] Eligibility call failed with error: ' . $e->getMessage());
        }

        return [];
    }

    public function retrieveEligibilities(array $data): array
    {
        $alma = $this->getDefaultClient();
        try {
            return $alma->payments->eligibility($data, true);
        } catch (RequestError $e) {
            $this->logger->error('[Alma] Eligibility call failed with error: ' . $e->getMessage());
        }

        return [];
    }

    public function validatePayment(
        PaymentInterface $payment,
        string $almaPaymentId,
        ?Payment &$paymentData = null,
    ): bool {
        /** @var int $pid */
        $pid = $payment->getId();

        try {
            $paymentData = $this->getDefaultClient()->payments->fetch($almaPaymentId);
        } catch (RequestError $e) {
            $this->logger->error("[Alma] Could not fetch payment $almaPaymentId to validate payment $pid");
            throw $e;
        }

        if ($pid !== $paymentData->custom_data['payment_id']) {
            $error = "Attempt to validate payment $pid with Alma payment $almaPaymentId";
            $this->logger->error("[Alma] $error");

            throw new PaymentIdMismatchException($error);
        }

        return
            // Check that paid amount matches due amount
            $paymentData->purchase_amount === $payment->getAmount()
            // Check that payment is not expired
            && null === $paymentData->expired_at
            // Check that payment is either correctly initiated, or fully paid (p1x fallback)
            && \in_array($paymentData->state, [AlmaPayment::STATE_IN_PROGRESS, AlmaPayment::STATE_PAID], true)
            // Extra-check that first installment has indeed been paid
            && Instalment::STATE_PAID === $paymentData->payment_plan[0]->state;
    }

    public function getFeePlans(PaymentMethodInterface $paymentMethod): array
    {
        $alma = $this->getDefaultClient();

        try {
            $almaFeePlans = $alma->merchants->feePlans();
        } catch (\Exception $e) {
            $this->logger->error('Get Alma Fee plans :', [$e->getMessage()]);
        }

        return $almaFeePlans;
    }
}
