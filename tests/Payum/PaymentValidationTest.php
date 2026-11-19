<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\Payum;

use Alma\API\Client;
use Alma\API\Endpoints\Payments;
use Alma\API\Entities\Instalment;
use Alma\API\Entities\Payment as AlmaPayment;
use Alma\API\RequestError;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\PaymentIdMismatchException;
use Alma\SyliusPaymentPlugin\DataBuilder\PaymentDataBuilderInterface;
use Alma\SyliusPaymentPlugin\Payum\Action\ValidatePaymentAction;
use Alma\SyliusPaymentPlugin\Payum\Request\ValidatePayment;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Sylius\Component\Core\Model\PaymentInterface;

final class PaymentValidationTest extends TestCase
{
    private ?Client $previousClient;

    protected function setUp(): void
    {
        $this->previousClient = (new ReflectionProperty(AlmaBridge::class, 'almaClient'))->getValue();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(AlmaBridge::class, 'almaClient'))->setValue(null, $this->previousClient);
    }

    public function testMismatchedRemoteIdentityThrowsAndDoesNotPersistValidation(): void
    {
        foreach ([
            ['custom_data' => ['payment_id' => 43]],
            ['custom_data' => ['payment_id' => '42']],
            ['custom_data' => null],
            ['custom_data' => []],
            ['id' => 'alma_other'],
        ] as $overrides) {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('error')->with('[Alma] Attempt to validate payment 42 with Alma payment alma_fixture');
            $sdkData = $this->fixture($overrides);
            $payments = $this->mockClient();
            $payments->expects(self::once())->method('fetch')->with('alma_fixture')->willReturn($sdkData);
            $payment = $this->payment(false);
            $before = $payment->getDetails();

            try {
                $this->action($logger)->execute(new ValidatePayment($payment));
                self::fail('Expected mismatched internal payment ID to be rejected.');
            } catch (PaymentIdMismatchException $exception) {
                self::assertSame('Attempt to validate payment 42 with Alma payment alma_fixture', $exception->getMessage());
                self::assertSame($before, $payment->getDetails());
            }
        }
    }

    public function testDifferentPurchaseAmountIsInvalid(): void
    {
        foreach ([11999, 12001] as $amount) {
            $this->assertValidation($this->fixture(['purchase_amount' => $amount]), false);
        }
    }

    public function testCandidateCannotReplaceAnAlreadyLinkedPid(): void
    {
        $this->mockClient()->expects(self::never())->method('fetch');
        $this->expectException(PaymentIdMismatchException::class);
        $this->action($this->createMock(LoggerInterface::class))
            ->execute(new ValidatePayment($this->payment(false), 'alma_other'));
    }

    public function testAnyRecordedExpirationIsInvalid(): void
    {
        foreach ([1, 1893456000] as $expiration) {
            $this->assertValidation($this->fixture(['expired_at' => $expiration]), false);
        }
    }

    public function testStatesOtherThanInProgressAndPaidAreInvalidEvenWithPaidFirstInstallment(): void
    {
        foreach (['not_started', 'pending', 'canceled', 'expired', 'failed', 'refunded', 'unknown'] as $state) {
            $this->assertValidation($this->fixture(['state' => $state]), false);
        }
    }

    public function testUnpaidFirstInstallmentIsInvalidEvenWhenLaterInstallmentIsPaid(): void
    {
        foreach ([AlmaPayment::STATE_IN_PROGRESS, AlmaPayment::STATE_PAID] as $state) {
            foreach ([Instalment::STATE_PENDING, Instalment::STATE_INCIDENT, Instalment::STATE_CLAIMED, Instalment::STATE_COVERED] as $firstState) {
                $this->assertValidation($this->fixture([
                    'state' => $state,
                    'payment_plan' => [['state' => $firstState], ['state' => Instalment::STATE_PAID]],
                ]), false);
            }
        }
    }

    public function testInProgressAndPaidPaymentsWithPaidFirstInstallmentAreValid(): void
    {
        foreach ([AlmaPayment::STATE_IN_PROGRESS, AlmaPayment::STATE_PAID] as $state) {
            $this->assertValidation($this->fixture([
                'state' => $state,
                'payment_plan' => [
                    ['state' => Instalment::STATE_PAID],
                    ['state' => AlmaPayment::STATE_PAID === $state ? Instalment::STATE_PAID : Instalment::STATE_PENDING],
                ],
            ]), true);
        }
    }

    public function testFetchRequestErrorIsLoggedAndSameExceptionPropagatesWithoutChangingDetails(): void
    {
        $exception = new RequestError('fixture fetch failure');
        $payments = $this->mockClient();
        $payments->expects(self::once())->method('fetch')->with('alma_fixture')->willThrowException($exception);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('[Alma] Could not fetch payment alma_fixture to validate payment 42');
        $payment = $this->payment(false);
        $before = $payment->getDetails();

        try {
            $this->action($logger)->execute(new ValidatePayment($payment));
            self::fail('Expected fetch error to propagate.');
        } catch (RequestError $caught) {
            self::assertSame($exception, $caught);
            self::assertSame($before, $payment->getDetails());
        }
    }

    private function assertValidation(AlmaPayment $sdkData, bool $expected): void
    {
        $payments = $this->mockClient();
        $payments->expects(self::once())->method('fetch')->with('alma_fixture')->willReturn($sdkData);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $payment = $this->payment(true);
        $before = $payment->getDetails();

        $this->action($logger)->execute(new ValidatePayment($payment));

        self::assertSame($before + ['is_valid' => $expected, 'payment_data' => $sdkData], $payment->getDetails());
    }

    private function fixture(array $overrides = []): AlmaPayment
    {
        return new AlmaPayment(array_replace([
            'id' => 'alma_fixture',
            'custom_data' => ['payment_id' => 42],
            'purchase_amount' => 12000,
            'expired_at' => null,
            'state' => AlmaPayment::STATE_IN_PROGRESS,
            'payment_plan' => [['state' => Instalment::STATE_PAID], ['state' => Instalment::STATE_PENDING]],
        ], $overrides));
    }

    private function mockClient(): Payments
    {
        $client = $this->createMock(Client::class);
        $client->payments = $this->createMock(Payments::class);
        (new ReflectionProperty(AlmaBridge::class, 'almaClient'))->setValue(null, $client);

        return $client->payments;
    }

    private function action(LoggerInterface $logger): ValidatePaymentAction
    {
        $action = new ValidatePaymentAction();
        $action->setApi(new AlmaBridge($logger, $this->createMock(PaymentDataBuilderInterface::class)));

        return $action;
    }

    private function payment(bool $expectUpdate): PaymentInterface
    {
        $details = ['payment_id' => 'alma_fixture', 'payload' => ['fixture' => 'preserved']];
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);
        $payment->method('getAmount')->willReturn(12000);
        $payment->method('getDetails')->willReturnCallback(static function () use (&$details): array { return $details; });
        $payment->expects($expectUpdate ? self::once() : self::never())->method('setDetails')
            ->willReturnCallback(static function (array $value) use (&$details): void { $details = $value; });

        return $payment;
    }
}
