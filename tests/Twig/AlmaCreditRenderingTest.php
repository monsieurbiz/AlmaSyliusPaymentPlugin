<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\Twig;

use Alma\API\Client;
use Alma\API\Endpoints\Results\Eligibility;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\DataBuilder\EligibilityDataBuilder;
use Alma\SyliusPaymentPlugin\Helper\EligibilityHelper;
use Alma\SyliusPaymentPlugin\Helper\FeePlansHelper;
use Alma\SyliusPaymentPlugin\Twig\AlmaExtension;
use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Payment\Model\GatewayConfigInterface;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Cache\CacheInterface;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;

final class AlmaCreditRenderingTest extends TestCase
{
    private const INSTALLMENT_TEMPLATE = '@AlmaSyliusPaymentPlugin/shop/form/select_payment/payment/choice/details/installment_plan.html.twig';

    public function testTwentyFourInstallmentsRenderRoundedLastPaymentFeesAndApr(): void
    {
        foreach ([0, 1290] as $apr) {
            foreach ([10001, 10500] as $lastAmount) {
                $eligibility = $this->eligibility(24, $lastAmount, $apr);
                [$twig, $payment, $method] = $this->environment($eligibility);
                $planData = $twig->getFunction('alma_get_plan_data')->getCallable()($payment, $method);

                self::assertSame($eligibility, $planData['plans']);
                self::assertSame(24, $planData['installmentsCount']);
                self::assertSame([
                    'totalCart' => $payment->getAmount(),
                    'costCredit' => 12000,
                    'totalCredit' => 11000 + 22 * 10500 + $lastAmount,
                    'taeg' => $apr,
                ], $planData['creditInfo']);

                $html = $twig->render(self::INSTALLMENT_TEMPLATE, $this->installmentContext($planData));
                $document = new \DOMDocument();
                $document->loadHTML('<meta charset="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
                $xpath = new \DOMXPath($document);
                $summary = $this->normalize($xpath->query('//h6')->item(0)->textContent);

                if (10001 === $lastAmount) {
                    self::assertSame("110,00 € aujourd'hui puis 22 x 105,00 € et une dernière mensualité de 100,01 €", $summary);
                    self::assertStringNotContainsString('23 x', $summary);
                } else {
                    self::assertSame("110,00 € aujourd'hui puis 23 x 105,00 €", $summary);
                    self::assertStringNotContainsString('dernière', $summary);
                }

                $rows = [];
                foreach ($xpath->query('//li') as $row) {
                    $spans = $xpath->query('./span', $row);
                    $rows[$this->normalize($spans->item(0)->textContent)] = $this->normalize($spans->item(1)->textContent);
                }
                self::assertSame('120,00 €', $rows['Coût du crédit']);
                self::assertSame(0 === $apr ? '0,00 %' : '12,90 %', $rows['TAEG fixe']);
                self::assertSame(10001 === $lastAmount ? '2 400,01 €' : '2 405,00 €', $rows['Votre panier']);
                self::assertSame(10001 === $lastAmount ? '2 520,01 €' : '2 525,00 €', $rows['Total']);
            }
        }
    }

    public function testExistingShortPlansRenderTheirActualScheduleAndCreditPlansKeepCreditInformation(): void
    {
        foreach ([2, 3, 4, 10, 12] as $count) {
            [$twig, $payment, $method] = $this->environment($this->eligibility($count, 10001, 1290));
            $planData = $twig->getFunction('alma_get_plan_data')->getCallable()($payment, $method);
            $text = $this->normalize(strip_tags($twig->render(self::INSTALLMENT_TEMPLATE, $this->installmentContext($planData))));

            if ($count <= 4) {
                self::assertStringContainsString("Aujourd'hui", $text);
                self::assertStringContainsString('100,01 €', $text);
                self::assertStringContainsString('Dont frais inclus: 5,00 €', $text);
                self::assertStringNotContainsString('Votre crédit', $text);
            } else {
                self::assertStringContainsString('Votre crédit', $text);
                self::assertStringContainsString('TAEG fixe', $text);
                self::assertStringContainsString('12,90 %', $text);
                self::assertStringContainsString('dernière mensualité de 100,01 €', $text);
            }
        }
    }

    public function testUnselectedPaymentMethodDoesNotRenderSchedule(): void
    {
        [$twig, $payment, $method] = $this->environment($this->eligibility(24, 10001, 1290));
        $planData = $twig->getFunction('alma_get_plan_data')->getCallable()($payment, $method);
        $context = $this->installmentContext($planData);
        $context['hookable_metadata']['context']['form']['parent']['vars']['value'] = 'another_method';

        self::assertSame('', trim($twig->render(self::INSTALLMENT_TEMPLATE, $context)));
    }

    public function testIneligibleOrMissingPlanDoesNotRenderPaymentDetails(): void
    {
        foreach ([false, null] as $eligible) {
            $eligibility = $this->eligibility(24, 10001, 1290);
            $eligibility->setIsEligible(false);
            [$twig, $payment, $method] = $this->environment(null === $eligible ? null : $eligibility);

            $html = $twig->render('@AlmaSyliusPaymentPlugin/shop/form/select_payment/payment/choice/details.html.twig', [
                'hookable_metadata' => ['context' => [
                    'method' => $method,
                    'order' => $payment->getOrder(),
                    'form' => ['parent' => ['parent' => ['vars' => ['data' => $payment]]]],
                ]],
            ]);

            self::assertSame('', trim($html));
        }
    }

    private function eligibility(int $count, int $lastAmount, int $apr): Eligibility
    {
        $plan = [];
        for ($index = 0; $index < $count; ++$index) {
            $plan[] = [
                'total_amount' => 0 === $index ? 11000 : ($count - 1 === $index ? $lastAmount : 10500),
                'customer_fee' => 500,
                'due_date' => 1893456000 + $index * 2592000,
            ];
        }

        return new Eligibility([
            'eligible' => true,
            'installments_count' => $count,
            'deferred_days' => 0,
            'deferred_months' => 0,
            'payment_plan' => $plan,
            'customer_total_cost_amount' => $count * 500,
            'annual_interest_rate' => $apr,
        ]);
    }

    private function environment(?Eligibility $eligibility): array
    {
        $count = null === $eligibility ? 24 : $eligibility->installmentsCount;
        $amount = null === $eligibility ? 240001 : array_sum(array_column($eligibility->paymentPlan, 'total_amount')) - $eligibility->customerTotalCostAmount;
        $bridge = $this->createMock(AlmaBridgeInterface::class);
        $bridge->expects(self::once())->method('initialize')->with(self::callback(
            static fn (ArrayObject $config): bool => $count === $config['installments_count'] && Client::TEST_MODE === $config['api_mode']
        ));
        $bridge->expects(self::once())->method('retrieveEligibilities')->with([
            'purchase_amount' => $amount,
            'queries' => [['installments_count' => $count, 'deferred_days' => '0', 'deferred_months' => '0']],
            'billing_address' => ['country' => 'FR'],
            'shipping_address' => ['country' => 'BE'],
            'locale' => 'fr',
        ])->willReturn(null === $eligibility ? [] : ['general_' . $count . '_0_0' => $eligibility]);
        $gateway = $this->createMock(GatewayConfigInterface::class);
        $gateway->method('getConfig')->willReturn(['installments_count' => $count, 'api_mode' => Client::TEST_MODE]);
        $method = new PaymentMethod();
        $method->setGatewayConfig($gateway);
        $billing = new Address();
        $billing->setCountryCode('FR');
        $shipping = new Address();
        $shipping->setCountryCode('BE');
        $order = new Order();
        $order->setBillingAddress($billing);
        $order->setShippingAddress($shipping);
        $payment = new Payment();
        $payment->setAmount($amount);
        $payment->setOrder($order);
        $localeContext = $this->createMock(LocaleContextInterface::class);
        $localeContext->method('getLocaleCode')->willReturn('fr_FR');
        $extension = new AlmaExtension(
            new EligibilityHelper($bridge, new EligibilityDataBuilder()),
            new FeePlansHelper(
                $bridge,
                $this->createMock(PaymentMethodRepositoryInterface::class),
                $this->createMock(ChannelContextInterface::class),
                $this->createMock(CacheInterface::class)
            ),
            $localeContext
        );
        $root = dirname(__DIR__, 2);
        $loader = new FilesystemLoader();
        $loader->addPath($root . '/templates', 'AlmaSyliusPaymentPlugin');
        $translator = new Translator('fr');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $root . '/translations/messages.fr.yml', 'fr');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension($extension);
        $twig->addExtension(new TranslationExtension($translator));
        $twig->addExtension(new IntlExtension(null, new \NumberFormatter('fr_FR', \NumberFormatter::CURRENCY)));

        return [$twig, $payment, $method];
    }

    private function installmentContext(array $planData): array
    {
        return ['hookable_metadata' => ['context' => [
            'plan_data' => $planData,
            'form' => ['vars' => ['value' => 'alma_24'], 'parent' => ['vars' => ['value' => 'alma_24']]],
        ]]];
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(["\u{00A0}", "\u{202F}"], ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }
}
