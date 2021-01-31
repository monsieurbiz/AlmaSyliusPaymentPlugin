<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Bridge;


use Alma\API\Client;
use Alma\API\Entities\Instalment;
use Alma\API\Entities\Merchant;
use Alma\API\Entities\Payment as AlmaPayment;
use Alma\API\RequestError;
use Alma\SyliusPaymentPlugin\AlmaSyliusPaymentPlugin;
use Alma\SyliusPaymentPlugin\DataBuilder\PaymentDataBuilderInterface;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfig;
use Exception;
use Payum\Core\Bridge\Spl\ArrayObject;
use Psr\Log\LoggerInterface;
use Sylius\Bundle\CoreBundle\Application\Kernel as Sylius;
use Sylius\Component\Core\Model\PaymentInterface;

final class AlmaBridge implements AlmaBridgeInterface
{
    /**
     * @var GatewayConfig
     */
    private $gatewayConfig = null;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Client|null
     */
    static private $almaClient;
    /**
     * @var PaymentDataBuilderInterface
     */
    private $paymentDataBuilder;

    public function __construct(LoggerInterface $logger, PaymentDataBuilderInterface $paymentDataBuilder)
    {
        $this->logger = $logger;
        $this->paymentDataBuilder = $paymentDataBuilder;
    }

    public function initialize(ArrayObject $config): void
    {
        self::$almaClient = null;
        $this->gatewayConfig = new GatewayConfig($config);
    }

    public function getDefaultClient(?string $mode = null): Client
    {
        if ($mode === null) {
            $mode = $this->gatewayConfig->getApiMode();
        }

        if (!self::$almaClient) {
            self::$almaClient = self::createClientInstance(
                $this->gatewayConfig->getActiveApiKey(),
                $mode,
                $this->logger
            );
        }

        return self::$almaClient;
    }

    public static function createClientInstance(string $apiKey, string $mode, LoggerInterface $logger): ?Client
    {
        /** @var Client|null $alma */
        $alma = null;

        try {
            $alma = new Client($apiKey, [
                'mode' => $mode,
                'logger' => $logger
            ]);

            $alma->addUserAgentComponent('Sylius', Sylius::VERSION);
            $alma->addUserAgentComponent('Alma for Sylius', AlmaSyliusPaymentPlugin::VERSION);
        } catch (Exception $e) {
            $logger->error('[Alma] Error creating Alma API client: ' . $e->getMessage());
        }

        return $alma;
    }

    function getMerchantInfo(): ?Merchant
    {
        $client = $this->getDefaultClient();
        if (!$client) {
            return null;
        }

        /** @var Merchant|null $merchant */
        $merchant = null;

        try {
            $merchant = $client->merchants->me();
        } catch (RequestError $e) {
            $this->logger->error('[Alma] Error fetching merchant info: ' . $e->getMessage());
        }

        return $merchant;
    }

    /**
     * @return GatewayConfig
     */
    public function getGatewayConfig(): GatewayConfig
    {
        return $this->gatewayConfig;
    }

    /**
     * @inheritDoc
     */
    function getEligibilities(PaymentInterface $payment, array $installmentsCounts): array
    {
        $builder = $this->paymentDataBuilder;
        $builder->addBuilder(function (array $data, PaymentInterface $payment) use ($installmentsCounts): array {
            $data['payment'] = array_merge($data['payment'], [
                "installments_count" => $installmentsCounts
            ]);

            return $data;
        });

        $alma = $this->getDefaultClient();
        try {
            return $alma->payments->eligibility($builder([], $payment));
        } catch (RequestError $e) {
            $this->logger->error("[Alma] Eligibility call failed with error: " . $e->getMessage());
        }

        return [];
    }

    /**
     * @param PaymentInterface $payment
     * @param string $almaPaymentId
     * @return bool
     * @throws RequestError
     */
    public function validatePayment(PaymentInterface $payment, string $almaPaymentId): bool
    {
        /** @var int $pid */
        $pid = $payment->getId();

        try {
            $almaPayment = $this->getDefaultClient()->payments->fetch($almaPaymentId);
        } catch (RequestError $e) {
            $this->logger->error("[Alma] Could not fetch payment $almaPaymentId to validate payment $pid");
            throw $e;
        }

        if ($pid !== $almaPayment->custom_data['payment_id']) {
            $error = "Attempt to validate payment $pid with Alma payment $almaPaymentId";
            $this->logger->error("[Alma] $error");

            throw new PaymentIdMismatchException($error);
        }

        return
            // Check that paid amount matches due amount
            $almaPayment->purchase_amount === $payment->getAmount()
            // Check that payment is either correctly initiated, or fully paid (p1x fallback)
            && in_array($almaPayment->state, [AlmaPayment::STATE_IN_PROGRESS, AlmaPayment::STATE_PAID], true)
            // Extra-check that first installment has indeed been paid
            && $almaPayment->payment_plan[0]->state === Instalment::STATE_PAID;
    }
}
