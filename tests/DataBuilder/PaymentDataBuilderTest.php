<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\DataBuilder;

use Alma\API\Client;
use Alma\API\Endpoints\Payments;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\DataBuilder\OrderDataBuilder;
use Alma\SyliusPaymentPlugin\DataBuilder\PaymentDataBuilder;
use Alma\SyliusPaymentPlugin\Payum\Action\ConvertPaymentAction;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Model\Token;
use Payum\Core\Request\Convert;
use Payum\Core\Security\GenericTokenFactoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;

final class PaymentDataBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        (new ReflectionProperty(AlmaBridge::class, 'almaClient'))->setValue(null, null);
    }

    public function testConversionAfterTwoMerchantEligibilityCallsUsesIntegerCountAndFreshUrls(): void
    {
        $registry = $this->createMock(ServiceRegistryInterface::class);
        $registry->method('all')->willReturn([new OrderDataBuilder()]);
        $builder = new PaymentDataBuilder($registry);
        $bridge = new AlmaBridge(new NullLogger(), $builder);
        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('ORDER-12');
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAmount')->willReturn(12000);
        $payment->method('getId')->willReturn(12);

        foreach (['merchant-a' => [3], 'merchant-b' => [4, 12]] as $merchant => $counts) {
            $bridge->initialize(new ArrayObject(['merchant_id' => $merchant, 'api_mode' => 'test']));
            $payments = $this->createMock(Payments::class);
            $payments->expects(self::once())->method('eligibility')->with(self::callback(static function (array $data) use ($counts): bool {
                return $data['payment']['installments_count'] === $counts;
            }), true)->willReturn([]);
            $client = $this->createMock(Client::class);
            $client->payments = $payments;
            (new ReflectionProperty(AlmaBridge::class, 'almaClient'))->setValue(null, $client);
            $bridge->getEligibilities($payment, $counts);
        }

        $action = new ConvertPaymentAction($builder);
        $action->setApi($bridge);
        $factory = $this->createMock(GenericTokenFactoryInterface::class);
        $factory->expects(self::exactly(2))->method('createNotifyToken')->willReturnCallback(static function (string $gateway): Token {
            $token = new Token();
            $token->setTargetUrl('https://shop.example/notify/' . $gateway);

            return $token;
        });
        $action->setGenericTokenFactory($factory);

        foreach (['first' => '4', 'second' => '12'] as $attempt => $count) {
            $bridge->initialize(new ArrayObject(['installments_count' => $count]));
            $token = new Token();
            $token->setGatewayName($attempt);
            $token->setAfterUrl('https://shop.example/return/' . $attempt);
            $request = new Convert($payment, 'array', $token);
            $action->execute($request);
            $data = $request->getResult()['payload'];

            self::assertSame((int) $count, $data['payment']['installments_count']);
            self::assertSame(12000, $data['payment']['purchase_amount']);
            self::assertSame(['payment_id' => 12], $data['payment']['custom_data']);
            self::assertSame('https://shop.example/return/' . $attempt, $data['payment']['return_url']);
            self::assertSame('https://shop.example/return/' . $attempt, $data['payment']['customer_cancel_url']);
            self::assertSame('https://shop.example/notify/' . $attempt, $data['payment']['ipn_callback_url']);
            self::assertSame(['merchant_reference' => 'ORDER-12'], $data['order']);
        }
    }
}
