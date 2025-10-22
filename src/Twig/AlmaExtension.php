<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Twig;

use Alma\SyliusPaymentPlugin\Helper\EligibilityHelper;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Alma\API\Endpoints\Results\Eligibility;
use Alma\SyliusPaymentPlugin\Helper\FeePlansHelper;

class AlmaExtension extends AbstractExtension
{
    public function __construct(
        private EligibilityHelper $eligibilityHelper,
        private FeePlansHelper $feePlansHelper,
        private LocaleContextInterface $localeContext,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('alma_price_from_cent', [$this, 'formatPriceFromCent']),
            new TwigFilter('alma_format_percent_number', [$this, 'formatPercentNumber']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('alma_get_plan_data', [$this, 'getPlanData']),
            new TwigFunction('alma_get_merchant_id', [$this, 'getMerchantId']),
            new TwigFunction('alma_get_fee_plans', [$this, 'getFeePlans']),
        ];
    }

    public function formatPriceFromCent(int $number): float
    {
        return $number / 100;
    }

    public function formatPercentNumber(int $number): string
    {
        return \sprintf('%s %%', number_format($number / 100, 2, ',', ' '));
    }

    public function getPlanData(PaymentInterface $payment, PaymentMethodInterface $paymentMethod): ?array
    {
        $paymentGatewayConfig = $paymentMethod->getGatewayConfig();
        if (null === $paymentGatewayConfig) {
            return null;
        }

        $config = $paymentGatewayConfig->getConfig();
        if (!isset($config['installments_count'])) {
            return null;
        }

        $installmentsCount = $paymentGatewayConfig->getConfig()['installments_count'] ?? 0;
        $totalCart = $payment->getAmount();

        $this->eligibilityHelper->initializeConfig($paymentMethod);
        $eligibilities = $this->eligibilityHelper->getEligibilities(
            $totalCart,
            $installmentsCount,
            $payment->getOrder()?->getBillingAddress()?->getCountryCode() ?? '',
            $payment->getOrder()?->getShippingAddress()?->getCountryCode() ?? '',
            substr($this->localeContext->getLocaleCode(), 0, 2)
        );

        /** @var ?Eligibility $eligibility */
        $eligibility = $eligibilities['general_' . $installmentsCount . '_0_0'] ?? null;
        if (null === $eligibility || !$eligibility->isEligible()) {
            return null;
        }

        return [
            'plans' => $eligibility,
            'installmentsCount' => $installmentsCount,
            'creditInfo' => [
                'totalCart' => $totalCart,
                'costCredit' => $eligibility->customerTotalCostAmount,
                'totalCredit' => $eligibility->customerTotalCostAmount + $totalCart,
                'taeg' => $eligibility->annualInterestRate,
            ]
        ];
    }

    public function getMerchantId(): string
    {
        return $this->feePlansHelper->getMerchantId();
    }

    public function getFeePlans(bool $forBadges): array
    {
        return $forBadges ? $this->feePlansHelper->getFeePlansForBadge() : $this->feePlansHelper->getFeePlans();
    }
}
