<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\Payum;

use Alma\API\Client;
use Alma\API\Endpoints\Payments;
use Alma\API\Entities\Payment as AlmaPayment;
use Alma\API\RequestError;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface as Details;
use Alma\SyliusPaymentPlugin\DataBuilder\PaymentDataBuilderInterface;
use Alma\SyliusPaymentPlugin\Payum\Action\CaptureAction;
use Alma\SyliusPaymentPlugin\Payum\Action\NotifyAction;
use Alma\SyliusPaymentPlugin\Payum\Action\RedirectToPaymentPageAction;
use Alma\SyliusPaymentPlugin\Payum\Action\StatusAction;
use Alma\SyliusPaymentPlugin\Payum\Action\ValidatePaymentAction;
use Alma\SyliusPaymentPlugin\Payum\Gateway\AlmaGatewayFactory;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfig;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfigInterface as Config;
use Alma\SyliusPaymentPlugin\Payum\Request\RedirectToPaymentPage;
use Alma\SyliusPaymentPlugin\Payum\Request\ValidatePayment;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayInterface;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Request\Notify;
use Payum\Core\Security\TokenInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class RedirectFlowTest extends TestCase
{
    protected function tearDown(): void
    {
        (new ReflectionProperty(AlmaBridge::class, 'almaClient'))->setValue(null, null);
    }

    public function testCaptureAlwaysDelegatesToRedirectForNewAndHistoricalConfigurations(): void
    {
        self::assertSame(Config::PAYMENT_PAGE_MODE_REDIRECT, (new AlmaGatewayFactory())->createConfig()[Config::CONFIG_PAYMENT_PAGE_MODE]);
        foreach ([[], [Config::CONFIG_PAYMENT_PAGE_MODE => Config::PAYMENT_PAGE_MODE_IN_PAGE], [Config::CONFIG_PAYMENT_PAGE_MODE => Config::PAYMENT_PAGE_MODE_REDIRECT]] as $config) {
            self::assertSame(Config::PAYMENT_PAGE_MODE_REDIRECT, (new GatewayConfig(new ArrayObject($config)))->getPaymentPageMode());
            $bridge = $this->bridge();
            $bridge->initialize(new ArrayObject($config));
            $capture = new Capture(new ArrayObject(['payload' => []]));
            $gateway = $this->createMock(GatewayInterface::class);
            $gateway->expects(self::once())->method('execute')->with(self::callback(
                static fn ($request): bool => $request instanceof RedirectToPaymentPage && $request->getModel() === $capture
            ));
            $action = new CaptureAction();
            $action->setApi($bridge);
            $action->setGateway($gateway);
            $action->execute($capture);
        }
    }

    public function testRedirectCreatesPaymentAndPersistsItsIdentityBeforeSendingCustomerAway(): void
    {
        $bridge = $this->bridge();
        $payments = $this->mockClient();
        $payments->expects(self::once())->method('create')->with(['payment' => ['purchase_amount' => 12000]])
            ->willReturn(new AlmaPayment(['id' => 'alma_fixture', 'url' => 'https://example.test/alma/fixture']));
        $payment = $this->payment(['payload' => ['payment' => ['purchase_amount' => 12000]]]);
        $capture = new Capture($payment);
        $capture->setModel(new ArrayObject($payment->getDetails()));
        $action = new RedirectToPaymentPageAction(new NullLogger(), new RequestStack());
        $action->setApi($bridge);

        try {
            $action->execute(new RedirectToPaymentPage($capture));
            self::fail('Expected redirect');
        } catch (HttpRedirect $reply) {
            self::assertSame('https://example.test/alma/fixture', $reply->getUrl());
            self::assertSame(302, $reply->getStatusCode());
            self::assertSame(['Location' => 'https://example.test/alma/fixture'], $reply->getHeaders());
            self::assertStringContainsString('https://example.test/alma/fixture', $reply->getContent());
            self::assertSame('alma_fixture', $payment->getDetails()['payment_id']);
            self::assertSame('alma_fixture', $capture->getModel()['payment_id']);
        }
    }

    public function testCreationFailureResetsPayloadAndRedirectsToAfterUrl(): void
    {
        $bridge = $this->bridge();
        $payments = $this->mockClient();
        $payments->method('create')->willThrowException(new RequestError('fixture failure'));
        $payment = $this->payment(['payload' => ['payment' => []]]);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getAfterUrl')->willReturn('https://example.test/after');
        $capture = new Capture($token);
        $capture->setModel($payment);
        $capture->setModel(new ArrayObject($payment->getDetails()));
        $action = new RedirectToPaymentPageAction(new NullLogger(), new RequestStack());
        $action->setApi($bridge);

        try {
            $action->execute(new RedirectToPaymentPage($capture));
            self::fail('Expected redirect');
        } catch (HttpRedirect $reply) {
            self::assertSame('https://example.test/after', $reply->getUrl());
            self::assertSame(302, $reply->getStatusCode());
            self::assertSame(['Location' => 'https://example.test/after'], $reply->getHeaders());
            self::assertArrayNotHasKey('payload', $payment->getDetails());
        }
    }

    public function testCallbackReturnOrderingAndReplayPreserveValidationResult(): void
    {
        foreach ([['notify', 'status', 'notify', 'status'], ['status', 'notify', 'status', 'notify']] as $sequence) {
            foreach ([true, false] as $valid) {
                $payment = $this->payment(['payload' => [], 'payment_id' => 'alma_fixture']);
                $gateway = $this->gateway($valid, ['pid' => 'alma_fixture']);
                foreach ($sequence as $step) {
                    if ('notify' === $step) {
                        $this->notify($payment, $gateway, $valid);
                    } else {
                        $status = $this->getPaymentStatus($payment, $gateway);
                        self::assertSame($valid, $status->isCaptured());
                        self::assertSame(!$valid, $status->isFailed());
                    }
                }
                self::assertArrayNotHasKey('payload', $payment->getDetails());
            }
        }
    }

    public function testLaterCallbackCanValidatePreviouslyUnpaidPayment(): void
    {
        foreach ([PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING] as $state) {
            foreach (['notify', 'status'] as $firstStep) {
                $payment = $this->payment(['payload' => [], 'payment_id' => 'alma_fixture'], $state);
                $validations = 0;
                $gateway = $this->createMock(GatewayInterface::class);
                $gateway->method('execute')->willReturnCallback(static function ($request) use (&$validations): void {
                    if ($request instanceof GetHttpRequest) {
                        $request->query = ['pid' => 'alma_fixture'];

                        return;
                    }
                    self::assertInstanceOf(ValidatePayment::class, $request);
                    $payment = $request->getModel();
                    $details = $payment->getDetails();
                    $details[Details::DETAILS_KEY_IS_VALID] = ++$validations > 1;
                    $payment->setDetails($details);
                });

                if ('notify' === $firstStep) {
                    $this->notify($payment, $gateway, false);
                } else {
                    self::assertTrue($this->getPaymentStatus($payment, $gateway)->isFailed());
                }
                self::assertFalse($payment->getDetails()['is_valid']);
                $this->notify($payment, $gateway, true);
                self::assertTrue($this->getPaymentStatus($payment, $gateway)->isCaptured());
                $this->notify($payment, $gateway, true);
                self::assertSame(3, $validations);
                self::assertSame('alma_fixture', $payment->getDetails()['payment_id']);
            }
        }
    }

    public function testLaterCallbackCanInvalidatePreviouslyValidNonTerminalPayment(): void
    {
        foreach ([PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING] as $state) {
            $payment = $this->payment(['payment_id' => 'alma_fixture', 'is_valid' => true], $state);
            $this->notify($payment, $this->gateway(false, ['pid' => 'alma_fixture']), false);
            self::assertFalse($payment->getDetails()['is_valid']);
            self::assertSame('alma_fixture', $payment->getDetails()['payment_id']);
        }
    }

    public function testCallbackThenReturnWithoutPidUsesValidatedResult(): void
    {
        $payment = $this->payment(['payload' => [], 'payment_id' => 'alma_fixture']);
        $this->notify($payment, $this->gateway(true, ['pid' => 'alma_fixture']), true);
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::once())->method('execute')->with(self::isInstanceOf(GetHttpRequest::class));
        self::assertTrue($this->getPaymentStatus($payment, $gateway)->isCaptured());
    }

    public function testCancelReturnWithoutPidRemainsPendingAndNewPaymentRemainsNew(): void
    {
        foreach ([[['payload' => []], 'isPending'], [[], 'isNew']] as [$details, $assertion]) {
            $payment = $this->payment($details);
            $gateway = $this->createMock(GatewayInterface::class);
            $gateway->expects(self::once())->method('execute')->with(self::isInstanceOf(GetHttpRequest::class));
            self::assertTrue($this->getPaymentStatus($payment, $gateway)->$assertion());
        }
    }

    public function testMalformedOrDifferentPidCannotReuseOrOverwriteValidatedIdentity(): void
    {
        foreach ([true, false] as $valid) {
            foreach (['other_fixture', '', '   ', 123, ['alma_fixture']] as $pid) {
                $details = ['payment_id' => 'alma_fixture', 'is_valid' => $valid];
                $payment = $this->payment($details);
                $gateway = $this->createMock(GatewayInterface::class);
                $gateway->expects(self::exactly(2))->method('execute')->with(self::isInstanceOf(GetHttpRequest::class))
                    ->willReturnCallback(static function (GetHttpRequest $request) use ($pid): void { $request->query = ['pid' => $pid]; });
                try {
                    $this->getPaymentStatus($payment, $gateway);
                    self::fail('Expected bad request');
                } catch (HttpResponse $reply) {
                    self::assertSame(400, $reply->getStatusCode());
                    self::assertSame('{"error":true}', $reply->getContent());
                    self::assertSame(['content-type' => 'application/json'], $reply->getHeaders());
                }
                $notify = new Notify($payment);
                $notify->setModel(new ArrayObject($details));
                $action = new NotifyAction();
                $action->setGateway($gateway);
                try {
                    $action->execute($notify);
                    self::fail('Expected bad request');
                } catch (HttpResponse $reply) {
                    self::assertSame(400, $reply->getStatusCode());
                    self::assertSame('{"error":true}', $reply->getContent());
                    self::assertSame(['content-type' => 'application/json'], $reply->getHeaders());
                }
                self::assertSame($details, $payment->getDetails());
            }
        }
    }

    public function testLegacyCallbackBindsItsFirstValidatedIdentity(): void
    {
        $payment = $this->payment(['payload' => []]);
        $this->notify($payment, $this->gateway(true, ['pid' => 'alma_fixture']), true);
        self::assertSame('alma_fixture', $payment->getDetails()['payment_id']);
    }

    public function testNotifyWithoutPidDoesNotValidateOrMutatePayment(): void
    {
        $details = ['payload' => []];
        $payment = $this->payment($details);
        $notify = new Notify($payment);
        $notify->setModel(new ArrayObject($details));
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::once())->method('execute')->with(self::isInstanceOf(GetHttpRequest::class));
        $action = new NotifyAction();
        $action->setGateway($gateway);
        $action->execute($notify);
        self::assertSame($details, $payment->getDetails());
    }

    public function testNotificationValidationFailureRespondsWithRetryableError(): void
    {
        $payment = $this->payment(['payload' => []]);
        $notify = new Notify($payment);
        $notify->setModel(new ArrayObject($payment->getDetails()));
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::exactly(2))->method('execute')->willReturnCallback(static function ($request): void {
            if ($request instanceof GetHttpRequest) {
                $request->query = ['pid' => 'alma_fixture'];

                return;
            }
            self::assertInstanceOf(ValidatePayment::class, $request);
            throw new RequestError('fixture failure');
        });
        $action = new NotifyAction();
        $action->setGateway($gateway);
        try {
            $action->execute($notify);
            self::fail('Expected retryable error');
        } catch (HttpResponse $reply) {
            self::assertSame(500, $reply->getStatusCode());
            self::assertSame(['error' => true, 'message' => 'fixture failure'], json_decode($reply->getContent(), true));
            self::assertSame(['content-type' => 'application/json'], $reply->getHeaders());
            self::assertArrayNotHasKey('is_valid', $payment->getDetails());
        }
    }

    public function testValidateActionDelegatesToBridgeAndPersistsResult(): void
    {
        $bridge = $this->bridge();
        $payments = $this->mockClient();
        $data = new AlmaPayment([
            'id' => 'alma_fixture', 'custom_data' => ['payment_id' => 42],
            'purchase_amount' => 12000, 'expired_at' => null, 'state' => 'paid',
            'payment_plan' => [['state' => 'paid']],
        ]);
        $payments->expects(self::once())->method('fetch')->with('alma_fixture')->willReturn($data);
        $payment = $this->payment(['payment_id' => 'alma_fixture']);
        $action = new ValidatePaymentAction();
        $action->setApi($bridge);
        $action->execute(new ValidatePayment($payment));
        self::assertTrue($payment->getDetails()['is_valid']);
        self::assertSame($data, $payment->getDetails()['payment_data']);
    }

    private function bridge(): AlmaBridge
    {
        return new AlmaBridge(new NullLogger(), $this->createMock(PaymentDataBuilderInterface::class));
    }

    private function mockClient(): Payments
    {
        $client = $this->createMock(Client::class);
        $client->payments = $this->createMock(Payments::class);
        (new ReflectionProperty(AlmaBridge::class, 'almaClient'))->setValue(null, $client);

        return $client->payments;
    }

    private function payment(array $details, string $state = PaymentInterface::STATE_NEW): PaymentInterface
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getDetails')->willReturnCallback(static function () use (&$details): array { return $details; });
        $payment->method('setDetails')->willReturnCallback(static function (array $value) use (&$details): void { $details = $value; });
        $payment->method('getState')->willReturn($state);
        $payment->method('getAmount')->willReturn(12000);
        $payment->method('getId')->willReturn(42);

        return $payment;
    }

    private function gateway(bool $valid, array $query): GatewayInterface
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(static function ($request) use ($query, $valid): void {
            if ($request instanceof GetHttpRequest) {
                $request->query = $query;

                return;
            }
            self::assertInstanceOf(ValidatePayment::class, $request);
            $payment = $request->getModel();
            $details = $payment->getDetails();
            $details[Details::DETAILS_KEY_IS_VALID] = $valid;
            $payment->setDetails($details);
        });

        return $gateway;
    }

    private function notify(PaymentInterface $payment, GatewayInterface $gateway, bool $valid): void
    {
        $notify = new Notify($payment);
        $notify->setModel(new ArrayObject($payment->getDetails()));
        $action = new NotifyAction();
        $action->setGateway($gateway);
        try {
            $action->execute($notify);
            self::fail('Expected response');
        } catch (HttpResponse $reply) {
            self::assertSame(200, $reply->getStatusCode());
            self::assertSame(['content-type' => 'application/json'], $reply->getHeaders());
            self::assertSame(['success' => true, 'state' => $valid], json_decode($reply->getContent(), true));
            self::assertSame($payment->getDetails(), $notify->getModel()->getArrayCopy());
        }
    }

    private function getPaymentStatus(PaymentInterface $payment, GatewayInterface $gateway): GetHumanStatus
    {
        $status = new GetHumanStatus($payment);
        $action = new StatusAction();
        $action->setGateway($gateway);
        $action->execute($status);

        return $status;
    }
}
