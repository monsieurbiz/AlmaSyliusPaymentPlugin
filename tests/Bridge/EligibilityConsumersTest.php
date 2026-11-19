<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\Bridge;

use Alma\API\Client;
use Alma\API\Endpoints\Payments;
use Alma\API\Endpoints\Results\Eligibility;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\DataBuilder\EligibilityDataBuilder;
use Alma\SyliusPaymentPlugin\DataBuilder\PaymentDataBuilder;
use Alma\SyliusPaymentPlugin\Helper\EligibilityHelper;
use Alma\SyliusPaymentPlugin\Helper\FeePlansHelper;
use Alma\SyliusPaymentPlugin\Payum\Gateway\AlmaGatewayFactory;
use Alma\SyliusPaymentPlugin\Resolver\AlmaPaymentMethodsResolver;
use Alma\SyliusPaymentPlugin\Twig\AlmaExtension;
use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Payment\Model\GatewayConfig;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class EligibilityConsumersTest extends TestCase
{
    protected function tearDown(): void
    {
        (new ReflectionProperty(AlmaBridge::class, 'almaClient'))->setValue(null, null);
    }

    public function testResolverRetainsEligibleMethodsFromBothMerchantGroupsInTheirOriginalOrder(): void
    {
        $methods = [
            $this->method(1, 'merchant-a', 3),
            $this->method(2, 'merchant-b', 12),
            $this->method(3, 'merchant-a', 4),
            $this->method(4, 'merchant-a', 3),
        ];
        $channel = $this->createMock(ChannelInterface::class);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getChannel')->willReturn($channel);
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getCurrencyCode')->willReturn('EUR');
        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $repository->expects(self::once())->method('findEnabledForChannel')->with($channel)->willReturn($methods);
        $calls = [];
        $bridge = $this->bridgeWithResponses(static function (array $config, array $data, bool $raiseOnError) use (&$calls): array {
            $calls[] = [$config['merchant_id'], $data['payment']['installments_count'], $raiseOnError];

            return 'merchant-a' === $config['merchant_id']
                ? [3 => new Eligibility(['eligible' => true, 'installments_count' => 3]), 4 => new Eligibility(['eligible' => false, 'installments_count' => 4])]
                : ['general_12_0_0' => new Eligibility(['eligible' => true, 'installments_count' => 12])];
        });

        self::assertSame([$methods[0], $methods[1], $methods[3]], array_values((new AlmaPaymentMethodsResolver($repository, $bridge))->getSupportedMethods($payment)));
        self::assertSame([['merchant-a', [3, 4], true], ['merchant-b', [12], true]], $calls);
    }

    public function testResolverSeparatesTestAndLiveEligibilityForTheSameMerchant(): void
    {
        $methods = [
            $this->method(1, 'merchant-a', 3, 'test'),
            $this->method(2, 'merchant-a', 3, 'live'),
            $this->method(3, 'merchant-a', 4, 'test'),
            $this->method(4, 'merchant-a', 4, 'live'),
        ];
        $channel = $this->createMock(ChannelInterface::class);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getChannel')->willReturn($channel);
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getCurrencyCode')->willReturn('EUR');
        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $repository->expects(self::once())->method('findEnabledForChannel')->with($channel)->willReturn($methods);
        $calls = [];
        $bridge = $this->bridgeWithResponses(static function (array $config, array $data, bool $raiseOnError) use (&$calls): array {
            $calls[] = [$config['merchant_id'], $config['api_mode'], $data['payment']['installments_count'], $raiseOnError];

            return [
                3 => new Eligibility(['eligible' => 'live' === $config['api_mode'], 'installments_count' => 3]),
                4 => new Eligibility(['eligible' => 'test' === $config['api_mode'], 'installments_count' => 4]),
            ];
        });

        self::assertSame([$methods[1], $methods[2]], array_values((new AlmaPaymentMethodsResolver($repository, $bridge))->getSupportedMethods($payment)));
        self::assertSame([
            ['merchant-a', 'test', [3, 4], true],
            ['merchant-a', 'live', [3, 4], true],
        ], $calls);
    }

    public function testSingletonIsAvailableToPlanDataAndIneligibleOrEmptyResultsDoNotRenderAPlan(): void
    {
        $method = $this->method(1, 'merchant-a', 3);
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getAmount')->willReturn(12000);
        $eligible = new Eligibility([
            'eligible' => true,
            'installments_count' => 3,
            'customer_total_cost_amount' => 150,
            'annual_interest_rate' => 1250,
        ]);
        $ineligible = new Eligibility(['eligible' => false, 'installments_count' => 3]);
        $responses = [$eligible, $ineligible, []];
        $bridge = $this->bridgeWithResponses(static function (array $config, array $data, bool $raiseOnError) use (&$responses) {
            self::assertSame('merchant-a', $config['merchant_id']);
            self::assertSame(3, $data['queries'][0]['installments_count']);
            self::assertTrue($raiseOnError);

            return array_shift($responses);
        });
        $locale = $this->createMock(LocaleContextInterface::class);
        $locale->method('getLocaleCode')->willReturn('fr_FR');
        $extension = new AlmaExtension(
            new EligibilityHelper($bridge, new EligibilityDataBuilder()),
            new FeePlansHelper(
                $bridge,
                $this->createMock(PaymentMethodRepositoryInterface::class),
                $this->createMock(ChannelContextInterface::class),
                $this->createMock(CacheInterface::class),
            ),
            $locale,
        );

        self::assertSame([
            'plans' => $eligible,
            'installmentsCount' => 3,
            'creditInfo' => ['totalCart' => 12000, 'costCredit' => 150, 'totalCredit' => 12150, 'taeg' => 1250],
        ], $extension->getPlanData($payment, $method));
        self::assertNull($extension->getPlanData($payment, $method));
        self::assertNull($extension->getPlanData($payment, $method));
        self::assertSame([], $responses);
    }

    private function method(int $id, string $merchant, int $count, string $mode = 'test'): PaymentMethodInterface
    {
        $gateway = new GatewayConfig();
        $gateway->setConfig([
            'payum.factory_name' => AlmaGatewayFactory::FACTORY_NAME,
            'merchant_id' => $merchant,
            'api_mode' => $mode,
            'installments_count' => $count,
        ]);
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getId')->willReturn($id);
        $method->method('getGatewayConfig')->willReturn($gateway);

        return $method;
    }

    private function bridgeWithResponses(callable $response): AlmaBridgeInterface
    {
        $registry = $this->createMock(ServiceRegistryInterface::class);
        $registry->method('all')->willReturn([]);
        $realBridge = new AlmaBridge(new NullLogger(), new PaymentDataBuilder($registry));
        $bridge = $this->createMock(AlmaBridgeInterface::class);
        // Install an offline SDK double after each real initialization resets the static client.
        $bridge->method('initialize')->willReturnCallback(function (ArrayObject $config) use ($realBridge, $response): void {
            $realBridge->initialize($config);
            $payments = $this->createMock(Payments::class);
            $payments->expects(self::once())->method('eligibility')->willReturnCallback(static function (array $data, bool $raiseOnError) use ($config, $response) {
                return $response($config->getArrayCopy(), $data, $raiseOnError);
            });
            $client = $this->createMock(Client::class);
            $client->payments = $payments;
            (new ReflectionProperty(AlmaBridge::class, 'almaClient'))->setValue(null, $client);
        });
        $bridge->method('getEligibilities')->willReturnCallback([$realBridge, 'getEligibilities']);
        $bridge->method('retrieveEligibilities')->willReturnCallback([$realBridge, 'retrieveEligibilities']);

        return $bridge;
    }
}
