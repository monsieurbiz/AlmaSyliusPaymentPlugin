<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Helper;

use Alma\API\Entities\FeePlan;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Alma\SyliusPaymentPlugin\Payum\Gateway\AlmaGatewayFactory;
use Payum\Core\Bridge\Spl\ArrayObject;
use Symfony\Contracts\Cache\CacheInterface;

final class FeePlansHelper
{
    public const CACHE_KEY_PREFIX_PLANS = 'alma_fee_plans_';
    public const CACHE_KEY_PREFIX_MERCHANT_ID = 'alma_merchant_id_';

    private ?PaymentMethodInterface $almaPaymentMethod = null;

    public function __construct(
        private AlmaBridgeInterface $almaBridge,
        private PaymentMethodRepositoryInterface $paymentMethodRepository,
        private ChannelContextInterface $channelContext,
        private CacheInterface $cache,
    ) {
    }

    public function getFeePlansForBadge(): array
    {
        $feePlans = $this->getFeePlans();
        $plansArray = [];
        /** @var FeePlan $plan */
        foreach ($feePlans as $plan) {
            $plansArray[] = $this->formatFeePlanForBadge($plan);
        }

        return $plansArray;
    }

    public function formatFeePlanForBadge(FeePlan $plan): array
    {
        return [
            'installmentsCount'=> $plan->installments_count,
            'deferredDays'=> $plan->deferred_days,
            'deferredMonths'=> $plan->deferred_months,
            'minAmount'=> $plan->min_purchase_amount,
            'maxAmount'=> $plan->max_purchase_amount,
        ];
    }

    public function getFeePlans(): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX_PLANS . $this->channelContext->getChannel()->getId();
        $plans = $this->cache->get($cacheKey, function () {
            return $this->fetchFeePlans();
        });

        return $plans;
    }

    public function getMerchantId(): string
    {
        $cacheKey = self::CACHE_KEY_PREFIX_MERCHANT_ID . $this->channelContext->getChannel()->getId();
        $merchantId = $this->cache->get($cacheKey, function () {
            return $this->fetchMerchantId();
        });
        return $merchantId;
    }

    private function fetchFeePlans(): array
    {
        $paymentMethod = $this->getAlmaPaymentMethod();
        if (null === $paymentMethod) {
            return [];
        }

        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = $paymentMethod->getGatewayConfig();

        $config = ArrayObject::ensureArrayObject($gatewayConfig->getConfig());
        $this->almaBridge->initialize($config);

        $plans = $this->almaBridge->getFeePlans($paymentMethod);

        return $plans;
    }

    private function fetchMerchantId(): string
    {
        $paymentMethod = $this->getAlmaPaymentMethod();
        if (null === $paymentMethod) {
            return '';
        }

        /** @var GatewayConfigInterface $gatewayConfig */
        $gatewayConfig = $paymentMethod->getGatewayConfig();

        return (string) ($gatewayConfig->getConfig()['merchant_id'] ?? '');
    }

    /**
     * We need a method to retrieve API configured payment method
     * So we take the first Alma payment method enabled for current channel
     */
    private function getAlmaPaymentMethod(): ?PaymentMethodInterface
    {
        if (null !== $this->almaPaymentMethod) {
            return $this->almaPaymentMethod;
        }

        $paymentMethods = $this->paymentMethodRepository->findEnabledForChannel(
            $this->channelContext->getChannel()
        );

        /** @var PaymentMethodInterface $method */
        foreach ($paymentMethods as $method) {
            if (AlmaGatewayFactory::FACTORY_NAME === $method->getGatewayConfig()?->getFactoryName()) {
                $this->almaPaymentMethod = $method;
                return $method;
            }
        }

        return null;
    }
}
