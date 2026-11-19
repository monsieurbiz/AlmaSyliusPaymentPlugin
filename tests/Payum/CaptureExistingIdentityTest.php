<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\Payum;

use Alma\API\Client;
use Alma\API\Endpoints\Payments;
use Alma\API\Entities\Payment as AlmaPayment;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\DataBuilder\PaymentDataBuilder;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Reply\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CaptureExistingIdentityTest extends TestCase
{
    use CompiledCaptureGateway;

    public function testUnlinkedCaptureStillConvertsAndCreatesThroughSylius(): void
    {
        $property = new ReflectionProperty(AlmaBridge::class, 'almaClient');
        $previousClient = $property->getValue();
        try {
            $registry = $this->createMock(ServiceRegistryInterface::class);
            $registry->expects(self::once())->method('all')->willReturn([]);
            $gateway = $this->captureGateway('/capture', new PaymentDataBuilder($registry), new NullLogger(), $this->createMock(TranslatorInterface::class));
            $client = $this->createMock(Client::class);
            $client->payments = $this->createMock(Payments::class);
            $client->payments->expects(self::never())->method('fetch');
            $client->payments->expects(self::once())->method('create')->willReturn(new AlmaPayment([
                'id' => 'alma_created', 'url' => 'https://example.test/alma_created',
            ]));
            $property->setValue(null, $client);
            $payment = $this->existingPayment(PaymentInterface::STATE_NEW);
            $payment->setDetails([]);

            $reply = $gateway->execute($this->captureRequest($payment), true);

            self::assertInstanceOf(HttpRedirect::class, $reply);
            self::assertSame('https://example.test/alma_created', $reply->getUrl());
            self::assertSame('alma_created', $payment->getDetails()['payment_id']);
        } finally {
            $property->setValue(null, $previousClient);
        }
    }

    public static function captures(): iterable
    {
        foreach ([PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING] as $state) {
            foreach (['/capture', '/capture?pid=alma_existing'] as $uri) {
                yield [$state, $uri, []];
                yield [$state, $uri, [
                    'payload' => ['payment' => ['purchase_amount' => 12000, 'installments_count' => 3]],
                    'is_valid' => false,
                ]];
            }
        }
    }

    #[DataProvider('captures')]
    public function testCaptureRefusesMissingPayloadOrFailedValidation(string $state, string $uri, array $extra): void
    {
        $property = new ReflectionProperty(AlmaBridge::class, 'almaClient');
        $previousClient = $property->getValue();
        try {
            $translator = $this->createMock(TranslatorInterface::class);
            $translator->expects(self::once())->method('trans')
                ->with('alma_sylius_payment_plugin.payment.reopening_failed', [], 'flashes')->willReturn('Cannot reopen');
            $registry = $this->createMock(ServiceRegistryInterface::class);
            $registry->expects(self::never())->method('all');
            $gateway = $this->captureGateway($uri, new PaymentDataBuilder($registry), new NullLogger(), $translator);
            $client = $this->createMock(Client::class);
            $client->payments = $this->createMock(Payments::class);
            foreach (['create', 'fetch', 'fullRefund'] as $method) {
                $client->payments->expects(self::never())->method($method);
            }
            $property->setValue(null, $client);
            $payment = $this->existingPayment($state, $extra);
            $details = $payment->getDetails();
            $capture = $this->captureRequest($payment);

            $reply = $gateway->execute($capture, true);

            self::assertInstanceOf(HttpResponse::class, $reply);
            self::assertSame(409, $reply->getStatusCode());
            self::assertSame('Cannot reopen', $reply->getContent());
            self::assertSame($payment, $capture->getModel(), 'Sylius capture wrapper must not handle a linked payment.');
            self::assertSame($details, $payment->getDetails());
            self::assertSame($state, $payment->getState());
        } finally {
            $property->setValue(null, $previousClient);
        }
    }
}
