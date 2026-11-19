<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\Payum;

use Alma\API\Client;
use Alma\API\Endpoints\Payments;
use Alma\API\Entities\Payment as AlmaPayment;
use Alma\API\RequestError;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\DataBuilder\PaymentDataBuilder;
use Payum\Core\Reply\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use TypeError;

final class ReopeningInvalidResponseTest extends TestCase
{
    use CompiledCaptureGateway;

    public static function responses(): iterable
    {
        foreach (['/capture', '/capture?pid=alma_existing'] as $uri) {
            yield [$uri, false, 409, 'reopening_failed', 'invalid_fetch_response', TypeError::class, 0];
            yield [$uri, true, 503, 'reopening_unavailable', 'fetch_failed', RequestError::class, 0];
        }
    }

    /**
     * @dataProvider responses
     */
    #[DataProvider('responses')]
    public function testReopeningRejectsInvalidSdkResponseButKeepsRequestErrorsRetryable(
        string $uri,
        bool $requestError,
        int $status,
        string $key,
        string $cause,
        string $exception,
        int $code,
    ): void {
        $property = new ReflectionProperty(AlmaBridge::class, 'almaClient');
        $previousClient = $property->getValue();
        try {
            $translator = $this->createMock(TranslatorInterface::class);
            $translator->expects(self::once())->method('trans')
                ->with('alma_sylius_payment_plugin.payment.' . $key, [], 'flashes')->willReturn('Translated refusal');
            $registry = $this->createMock(ServiceRegistryInterface::class);
            $registry->expects(self::never())->method('all');
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('error')->with('[Alma] Payment reopening rejected', [
                'payment_id' => 42, 'cause' => $cause, 'exception_class' => $exception, 'exception_code' => $code,
            ]);
            $gateway = $this->captureGateway($uri, new PaymentDataBuilder($registry), $logger, $translator);
            $client = $this->createMock(Client::class);
            $client->payments = $this->createMock(Payments::class);
            $client->payments->expects(self::never())->method('create');
            $client->payments->expects(self::never())->method('fullRefund');
            $client->payments->expects(self::once())->method('fetch')->with('alma_existing')
                ->willReturnCallback(static function () use ($requestError): AlmaPayment {
                    if ($requestError) {
                        throw new RequestError('Fixture lookup unavailable');
                    }

                    return new AlmaPayment(json_decode('null', true, 512, JSON_THROW_ON_ERROR));
                });
            $property->setValue(null, $client);
            $payment = $this->existingPayment(PaymentInterface::STATE_PROCESSING, [
                'payload' => ['payment' => ['purchase_amount' => 12000, 'installments_count' => 3]],
            ]);
            $details = $payment->getDetails();
            $capture = $this->captureRequest($payment);

            $reply = $gateway->execute($capture, true);

            self::assertInstanceOf(HttpResponse::class, $reply);
            self::assertSame($status, $reply->getStatusCode());
            self::assertSame('Translated refusal', $reply->getContent());
            self::assertSame(['content-type' => 'text/plain; charset=UTF-8'], $reply->getHeaders());
            self::assertSame($payment, $capture->getModel());
            self::assertSame($details, $payment->getDetails());
            self::assertSame(PaymentInterface::STATE_PROCESSING, $payment->getState());
        } finally {
            $property->setValue(null, $previousClient);
        }
    }
}
