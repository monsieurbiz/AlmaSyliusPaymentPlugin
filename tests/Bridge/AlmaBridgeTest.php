<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\Bridge;

use Alma\API\Client;
use Alma\API\Endpoints\Payments;
use Alma\API\Endpoints\Results\Eligibility;
use Alma\API\RequestError;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\DataBuilder\EligibilityDataBuilder;
use Alma\SyliusPaymentPlugin\DataBuilder\PaymentDataBuilder;
use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;
use TypeError;

final class AlmaBridgeTest extends TestCase
{
    protected function tearDown(): void
    {
        (new ReflectionProperty(AlmaBridge::class, 'almaClient'))->setValue(null, null);
    }

    public function testBothMethodsNormalizeSingletonsAndPreserveEveryArrayKey(): void
    {
        $eligible = new Eligibility(['eligible' => true, 'installments_count' => 3]);
        $ineligible = new Eligibility(['eligible' => false, 'installments_count' => 4]);
        $cases = [
            [$eligible, ['general_3_0_0' => $eligible]],
            [$ineligible, ['general_4_0_0' => $ineligible]],
            [[3 => $eligible, 4 => $ineligible], [3 => $eligible, 4 => $ineligible]],
            [['general_3_0_0' => $eligible, 'general_4_0_0' => $ineligible], ['general_3_0_0' => $eligible, 'general_4_0_0' => $ineligible]],
            [[], []],
        ];

        foreach (['getEligibilities', 'retrieveEligibilities'] as $method) {
            foreach ($cases as [$response, $expected]) {
                $builder = $this->builder();
                $bridge = new AlmaBridge(new NullLogger(), $builder);
                $payment = $this->createMock(PaymentInterface::class);
                $payment->method('getAmount')->willReturn(12000);
                $payment->method('getId')->willReturn(12);
                $payload = 'getEligibilities' === $method
                    ? ['payment' => ['purchase_amount' => 12000, 'custom_data' => ['payment_id' => 12], 'installments_count' => [3, 4]]]
                    : (new EligibilityDataBuilder())(12000, 3, 'FR', 'FR', 'fr');
                $payments = $this->createMock(Payments::class);
                $payments->expects(self::once())->method('eligibility')->with($payload, true)->willReturn($response);
                $this->injectPayments($payments);

                $actual = 'getEligibilities' === $method
                    ? $bridge->getEligibilities($payment, [3, 4])
                    : $bridge->retrieveEligibilities($payload);

                self::assertSame($expected, $actual);
            }
        }
    }

    public function testBothMethodsKeepRequestErrorLoggingAndEmptyArrayFallback(): void
    {
        foreach (['getEligibilities', 'retrieveEligibilities'] as $method) {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('error')->with('[Alma] Eligibility call failed with error: unavailable');
            $bridge = new AlmaBridge($logger, $this->builder());
            $payments = $this->createMock(Payments::class);
            $payments->expects(self::once())->method('eligibility')->willThrowException(new RequestError('unavailable'));
            $this->injectPayments($payments);

            self::assertSame([], 'getEligibilities' === $method
                ? $bridge->getEligibilities($this->createMock(PaymentInterface::class), [3])
                : $bridge->retrieveEligibilities([]));
        }
    }

    public function testEligibilityArgumentsRemainNonNullable(): void
    {
        $bridge = new AlmaBridge(new NullLogger(), $this->builder());
        $payment = $this->createMock(PaymentInterface::class);
        $calls = [
            static function () use ($bridge): void { $bridge->getEligibilities(null, [3]); },
            static function () use ($bridge, $payment): void { $bridge->getEligibilities($payment, null); },
            static function () use ($bridge): void { $bridge->retrieveEligibilities(null); },
        ];

        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Null must be rejected at the bridge boundary.');
            } catch (TypeError $error) {
                self::assertStringContainsString('must be of type', $error->getMessage());
            }
        }
    }

    public function testRepeatedMerchantEligibilityOverridesPreviousCountsWithRealBuilder(): void
    {
        $bridge = new AlmaBridge(new NullLogger(), $this->builder());
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getAmount')->willReturn(12000);

        foreach (['merchant-a' => [3], 'merchant-b' => [4]] as $merchant => $counts) {
            $bridge->initialize(new ArrayObject(['merchant_id' => $merchant, 'api_mode' => 'test']));
            $payments = $this->createMock(Payments::class);
            $payments->expects(self::once())->method('eligibility')->with(self::callback(static function (array $data) use ($counts): bool {
                return $data['payment']['installments_count'] === $counts;
            }), true)->willReturn([]);
            $this->injectPayments($payments);

            self::assertSame($merchant, $bridge->getGatewayConfig()->getMerchantId());
            self::assertSame([], $bridge->getEligibilities($payment, $counts));
        }
    }

    private function builder(): PaymentDataBuilder
    {
        $registry = $this->createMock(ServiceRegistryInterface::class);
        $registry->method('all')->willReturn([]);

        return new PaymentDataBuilder($registry);
    }

    private function injectPayments(Payments $payments): void
    {
        $client = $this->createMock(Client::class);
        $client->payments = $payments;
        (new ReflectionProperty(AlmaBridge::class, 'almaClient'))->setValue(null, $client);
    }
}
