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
use Alma\SyliusPaymentPlugin\Payum\Extension\RefundFailedPaymentExtension;
use Alma\SyliusPaymentPlugin\Payum\Gateway\AlmaGatewayFactory;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfig;
use Alma\SyliusPaymentPlugin\Payum\Gateway\GatewayConfigInterface as Config;
use Alma\SyliusPaymentPlugin\Payum\Request\RedirectToPaymentPage;
use Alma\SyliusPaymentPlugin\Payum\Request\ValidatePayment;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Gateway;
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
use Sylius\Bundle\PayumBundle\Action\CapturePaymentAction as SyliusCapturePaymentAction;
use Sylius\Bundle\PayumBundle\Action\ExecuteSameRequestWithPaymentDetailsAction;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig as SyliusGatewayConfig;
use Sylius\Bundle\PayumBundle\Provider\PaymentDescriptionProviderInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        $bridge->initialize(new ArrayObject($this->redirectConfig()));
        $payments = $this->mockClient();
        $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
        $payments->expects(self::once())->method('create')->with($payment->getDetails()['payload'])
            ->willReturn(new AlmaPayment(['id' => 'alma_fixture', 'url' => 'https://example.test/alma/fixture']));
        $capture = new Capture($payment);
        $capture->setModel(new ArrayObject($payment->getDetails()));
        $action = new RedirectToPaymentPageAction(new NullLogger(), new RequestStack(), $this->translator());
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
        $bridge->initialize(new ArrayObject($this->redirectConfig()));
        $payments = $this->mockClient();
        $payments->method('create')->willThrowException(new RequestError('fixture failure'));
        $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getAfterUrl')->willReturn('https://example.test/after');
        $capture = new Capture($token);
        $capture->setModel($payment);
        $capture->setModel(new ArrayObject($payment->getDetails()));
        $action = new RedirectToPaymentPageAction(new NullLogger(), new RequestStack(), $this->translator());
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

    public function testFirstTabNotificationRemainsValidAfterReopeningNewPayment(): void
    {
        $this->assertFirstTabSurvivesReopening(PaymentInterface::STATE_NEW, true);
    }

    public function testFirstTabNotificationRemainsValidAfterReopeningProcessingPayment(): void
    {
        $this->assertFirstTabSurvivesReopening(PaymentInterface::STATE_PROCESSING, true);
    }

    public function testFirstTabReturnRemainsValidAfterReopeningNewPayment(): void
    {
        $this->assertFirstTabSurvivesReopening(PaymentInterface::STATE_NEW, false);
    }

    public function testFirstTabReturnRemainsValidAfterReopeningProcessingPayment(): void
    {
        $this->assertFirstTabSurvivesReopening(PaymentInterface::STATE_PROCESSING, false);
    }

    public function testIndependentOrdersCreateAndValidateTheirOwnAlmaPayments(): void
    {
        foreach ([PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING] as $state) {
            $calls = ['create' => [], 'fetch' => [], 'paid' => false];
            [$gateway, $http] = $this->redirectGateway($calls);
            $first = $this->redirectPayment(42, $state);
            $second = $this->redirectPayment(43, $state);
            $this->captureRedirect($gateway, $first);
            $this->captureRedirect($gateway, $second);
            self::assertSame([42, 43], array_values($calls['create']));
            self::assertSame('alma_1', $first->getDetails()['payment_id']);
            self::assertSame('alma_2', $second->getDetails()['payment_id']);

            $calls['paid'] = true;
            foreach ([[$first, 'alma_1'], [$second, 'alma_2']] as [$payment, $pid]) {
                $http->query = ['pid' => $pid];
                $reply = $gateway->execute(new Notify($payment), true);
                self::assertInstanceOf(HttpResponse::class, $reply);
                self::assertSame(200, $reply->getStatusCode());
                self::assertSame(['success' => true, 'state' => true], json_decode($reply->getContent(), true));
                self::assertTrue($payment->getDetails()['is_valid']);
            }
            self::assertSame(['alma_1', 'alma_2'], $calls['fetch']);
        }
    }

    public function testUnavailableLookupDoesNotCreateResetOrValidatePayment(): void
    {
        foreach ([PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING] as $state) {
            $calls = ['create' => [], 'fetch' => [], 'paid' => false];
            [$gateway] = $this->redirectGateway($calls);
            $payment = $this->redirectPayment(42, $state);
            $this->captureRedirect($gateway, $payment);
            $calls['fetch_error'] = true;

            $this->assertReopeningRejected($gateway, $payment, $calls, 503, 1);
        }
    }

    public function testLegacyIdentityCannotBeReopenedButItsCallbackStillValidates(): void
    {
        $calls = ['create' => [], 'fetch' => [], 'paid' => false];
        [$gateway, $http] = $this->redirectGateway($calls);
        $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
        $this->captureRedirect($gateway, $payment);
        $details = $payment->getDetails();
        unset($details[Details::DETAILS_KEY_CREATION_CONTEXT]);
        $payment->setDetails($details);
        $this->assertReopeningRejected($gateway, $payment, $calls, 409, 0);

        $calls['paid'] = true;
        $http->query = ['pid' => 'alma_1'];
        $reply = $gateway->execute(new Notify($payment), true);
        self::assertInstanceOf(HttpResponse::class, $reply);
        self::assertSame(200, $reply->getStatusCode());
        self::assertTrue($payment->getDetails()['is_valid']);
        self::assertSame(['alma_1'], $calls['fetch']);
    }

    public function testChangedCurrentCreationContextCannotReusePayment(): void
    {
        foreach (['payment_method_id', 'gateway_config_id', 'merchant_id', 'api_mode', 'currency'] as $field) {
            $calls = ['create' => [], 'fetch' => [], 'paid' => false];
            [$gateway, , $config] = $this->redirectGateway($calls);
            $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
            $this->captureRedirect($gateway, $payment);
            if ('payment_method_id' === $field) {
                (new ReflectionProperty($payment->getMethod(), 'id'))->setValue($payment->getMethod(), 6);
            } elseif ('gateway_config_id' === $field) {
                $gatewayConfig = $payment->getMethod()->getGatewayConfig();
                (new ReflectionProperty($gatewayConfig, 'id'))->setValue($gatewayConfig, 8);
            } elseif ('currency' === $field) {
                $payment->setCurrencyCode('USD');
            } else {
                $config[$field] = 'merchant_id' === $field ? 'other_merchant' : Client::LIVE_MODE;
            }
            $this->assertReopeningRejected($gateway, $payment, $calls, 409, 0);
        }
    }

    public function testIncompleteMerchantContextDoesNotBlockFirstCreationButPreventsReopening(): void
    {
        foreach ([[], ['merchant_id' => ''], ['merchant_id' => '   '], ['merchant_id' => 123], ['merchant_id' => null], ['merchant_id' => []]] as $merchant) {
            $calls = ['create' => [], 'fetch' => [], 'paid' => false];
            [$gateway, , $config] = $this->redirectGateway($calls);
            unset($config[Config::CONFIG_MERCHANT_ID]);
            if (array_key_exists(Config::CONFIG_MERCHANT_ID, $merchant)) {
                $config[Config::CONFIG_MERCHANT_ID] = $merchant[Config::CONFIG_MERCHANT_ID];
            }
            $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
            $payment->getMethod()->getGatewayConfig()->setConfig($config->getArrayCopy());
            $this->captureRedirect($gateway, $payment);
            self::assertSame([42], array_values($calls['create']));
            self::assertSame('alma_1', $payment->getDetails()['payment_id']);
            self::assertArrayHasKey(Details::DETAILS_KEY_CREATION_CONTEXT, $payment->getDetails());
            $this->assertReopeningRejected($gateway, $payment, $calls, 409, 0);
        }
    }

    public function testMissingCurrentApiModeRejectsReopeningWithoutServerError(): void
    {
        $this->assertInvalidCurrentApiModeRejected([]);
    }

    public function testNullCurrentApiModeRejectsReopeningWithoutServerError(): void
    {
        $this->assertInvalidCurrentApiModeRejected([Config::CONFIG_API_MODE => null]);
    }

    public function testArrayCurrentApiModeRejectsReopeningWithoutServerError(): void
    {
        $this->assertInvalidCurrentApiModeRejected([Config::CONFIG_API_MODE => []]);
    }

    public function testIntegerCurrentApiModeRejectsReopeningWithoutServerError(): void
    {
        $this->assertInvalidCurrentApiModeRejected([Config::CONFIG_API_MODE => 123]);
    }

    public function testUnknownCurrentApiModeRejectsReopeningWithoutServerError(): void
    {
        $this->assertInvalidCurrentApiModeRejected([Config::CONFIG_API_MODE => 'unknown']);
    }

    public function testUnknownApiModeCannotBeReusedEvenWhenItsSnapshotMatches(): void
    {
        $calls = ['create' => [], 'fetch' => [], 'paid' => false];
        [$gateway, , $config] = $this->redirectGateway($calls);
        $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
        $this->captureRedirect($gateway, $payment);
        $config[Config::CONFIG_API_MODE] = 'unknown';
        $payment->getMethod()->getGatewayConfig()->setConfig($config->getArrayCopy());
        $details = $payment->getDetails();
        $details[Details::DETAILS_KEY_CREATION_CONTEXT]['api_mode'] = 'unknown';
        $payment->setDetails($details);

        $this->assertReopeningRejected($gateway, $payment, $calls, 409, 0);
    }

    public function testInvalidApiModeIsRejectedBeforeInitializingSdkClient(): void
    {
        $calls = ['create' => [], 'fetch' => [], 'paid' => false];
        [$gateway, , $config] = $this->redirectGateway($calls);
        $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
        $this->captureRedirect($gateway, $payment);
        $config[Config::CONFIG_API_MODE] = [];
        $bridge = $this->createMock(Details::class);
        $bridge->method('getGatewayConfig')->willReturn(new GatewayConfig($config));
        $bridge->expects(self::never())->method('getDefaultClient');
        $action = new RedirectToPaymentPageAction(new NullLogger(), new RequestStack(), $this->translator());
        // The concrete bridge is final; inject its SPI here to observe client initialization independently of SDK stubs.
        (new ReflectionProperty($action, 'api'))->setValue($action, $bridge);
        $before = $payment->getDetails();
        $capture = new Capture($payment);
        $capture->setModel(new ArrayObject($before));

        try {
            $action->execute(new RedirectToPaymentPage($capture));
            self::fail('Expected invalid context to be rejected before client initialization.');
        } catch (HttpResponse $reply) {
            self::assertSame(409, $reply->getStatusCode());
            self::assertSame($before, $payment->getDetails());
            self::assertSame([42], array_values($calls['create']));
            self::assertSame([], $calls['fetch']);
        }
    }

    private function assertInvalidCurrentApiModeRejected(array $mode): void
    {
        $calls = ['create' => [], 'fetch' => [], 'paid' => false];
        [$gateway, , $config] = $this->redirectGateway($calls);
        $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
        $this->captureRedirect($gateway, $payment);
        self::assertSame(Client::TEST_MODE, $payment->getDetails()[Details::DETAILS_KEY_CREATION_CONTEXT]['api_mode']);
        unset($config[Config::CONFIG_API_MODE]);
        if (array_key_exists(Config::CONFIG_API_MODE, $mode)) {
            $config[Config::CONFIG_API_MODE] = $mode[Config::CONFIG_API_MODE];
        }
        $payment->getMethod()->getGatewayConfig()->setConfig($config->getArrayCopy());

        $this->assertReopeningRejected($gateway, $payment, $calls, 409, 0);
    }

    public function testRealCallbacksRejectForeignOrMalformedPidBeforeFetchingOrRefunding(): void
    {
        foreach ([PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING] as $state) {
            foreach (['foreign_alma', 123, null, ['alma_1']] as $pid) {
                foreach ([true, false] as $notify) {
                    $calls = ['create' => [], 'fetch' => [], 'paid' => false];
                    [$gateway, $http] = $this->redirectGateway($calls);
                    $payment = $this->redirectPayment(42, $state);
                    $this->captureRedirect($gateway, $payment);
                    $before = $payment->getDetails();
                    $http->query = ['pid' => $pid];
                    $request = $notify ? new Notify($payment) : new GetHumanStatus($payment);
                    $reply = $gateway->execute($request, true);
                    self::assertInstanceOf(HttpResponse::class, $reply);
                    self::assertSame(400, $reply->getStatusCode());
                    self::assertSame(['content-type' => 'application/json'], $reply->getHeaders());
                    self::assertSame('{"error":true}', $reply->getContent());
                    self::assertSame($before, $payment->getDetails());
                    self::assertSame([42], array_values($calls['create']));
                    self::assertSame([], $calls['fetch']);
                    self::assertSame([], $calls['refund']);
                }
            }
        }
    }

    public function testRemoteIdentityAmountInstallmentsExpirationAndUrlMustRemainSafe(): void
    {
        foreach ([
            ['id' => 'other_alma_payment'],
            ['custom_data' => ['payment_id' => 43]],
            ['custom_data' => ['payment_id' => '42']],
            ['purchase_amount' => 12001],
            ['purchase_amount' => '12000'],
            ['installments_count' => 4],
            ['installments_count' => '3'],
            ['expired_at' => 1],
            ['expired_at' => 1893456000],
            ['url' => null],
            ['url' => ''],
            ['url' => 'http://example.test/alma_1'],
            ['url' => '/alma_1'],
            ['url' => 'https://user:password@example.test/alma_1'],
        ] as $remote) {
            $calls = ['create' => [], 'fetch' => [], 'paid' => false];
            [$gateway] = $this->redirectGateway($calls);
            $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
            $this->captureRedirect($gateway, $payment);
            $calls['remote'] = $remote;
            $this->assertReopeningRejected($gateway, $payment, $calls, 409, 1);
        }
    }

    public function testOriginalAndCurrentAmountAndInstallmentsMustAllMatch(): void
    {
        foreach (['original_amount', 'current_amount', 'remote_and_current_amount', 'original_installments', 'current_installments', 'remote_and_current_installments'] as $change) {
            $calls = ['create' => [], 'fetch' => [], 'paid' => false];
            [$gateway, , $config] = $this->redirectGateway($calls);
            $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
            $this->captureRedirect($gateway, $payment);
            $details = $payment->getDetails();
            if ('original_amount' === $change) {
                $details['payload']['payment']['purchase_amount'] = 12001;
            } elseif ('original_installments' === $change) {
                $details['payload']['payment']['installments_count'] = 4;
            } elseif (str_contains($change, 'amount')) {
                $payment->setAmount(12001);
                if ('remote_and_current_amount' === $change) {
                    $calls['remote']['purchase_amount'] = 12001;
                }
            } else {
                $config[Config::CONFIG_INSTALLMENTS_COUNT] = 4;
                if ('remote_and_current_installments' === $change) {
                    $calls['remote']['installments_count'] = 4;
                }
            }
            $payment->setDetails($details);
            $this->assertReopeningRejected($gateway, $payment, $calls, 409, 1);
        }
    }

    public function testMalformedPresentIdentityNeverFallsBackToCreation(): void
    {
        foreach (['', '   ', 123, null, ['alma_1']] as $pid) {
            $calls = ['create' => [], 'fetch' => [], 'paid' => false];
            [$gateway] = $this->redirectGateway($calls);
            $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
            $details = $payment->getDetails();
            $details['payment_id'] = $pid;
            $payment->setDetails($details);
            $this->assertReopeningRejected($gateway, $payment, $calls, 409, 0, 0);
        }
    }

    public function testMalformedPayloadOrCreationContextFailsWithoutLosingDetails(): void
    {
        foreach ([
            ['payload', null], ['payload', 'invalid'], ['payload', []],
            ['payload', ['payment' => 'invalid']], ['payload', ['payment' => []]],
            [Details::DETAILS_KEY_CREATION_CONTEXT, null],
            [Details::DETAILS_KEY_CREATION_CONTEXT, 'invalid'],
            [Details::DETAILS_KEY_CREATION_CONTEXT, []],
            [Details::DETAILS_KEY_CREATION_CONTEXT, [
                'payment_method_id' => '5', 'gateway_config_id' => 7,
                'merchant_id' => 'merchant_fixture', 'api_mode' => Client::TEST_MODE, 'currency' => 'EUR',
            ]],
        ] as [$key, $value]) {
            $calls = ['create' => [], 'fetch' => [], 'paid' => false];
            [$gateway] = $this->redirectGateway($calls);
            $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
            $this->captureRedirect($gateway, $payment);
            $details = $payment->getDetails();
            $details[$key] = $value;
            $payment->setDetails($details);
            // Direct entry avoids an unrelated pre-capture conversion of malformed/absent payloads.
            $this->assertReopeningRejected($gateway, $payment, $calls, 409, null, 1, true);
        }
    }

    public function testTerminalPaymentsCannotCreateOrReopenAnAttempt(): void
    {
        foreach ([PaymentInterface::STATE_COMPLETED, PaymentInterface::STATE_FAILED, PaymentInterface::STATE_CANCELLED, PaymentInterface::STATE_REFUNDED] as $state) {
            foreach ([false, true] as $existing) {
                $calls = ['create' => [], 'fetch' => [], 'paid' => false];
                [$gateway] = $this->redirectGateway($calls);
                $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
                if ($existing) {
                    $this->captureRedirect($gateway, $payment);
                }
                $payment->setState($state);
                $this->assertReopeningRejected($gateway, $payment, $calls, 409, 0, $existing ? 1 : 0);
            }
        }
    }

    public function testAlreadyPaidAttemptReopensItsRecapWithoutValidationOrRefund(): void
    {
        foreach ([PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING] as $state) {
            $calls = ['create' => [], 'fetch' => [], 'paid' => false];
            [$gateway] = $this->redirectGateway($calls);
            $payment = $this->redirectPayment(42, $state);
            $first = $this->captureRedirect($gateway, $payment);
            $before = $payment->getDetails();
            $calls['paid'] = true;
            $second = $this->captureRedirect($gateway, $payment);
            self::assertSame($first->getUrl(), $second->getUrl());
            self::assertSame($before, $payment->getDetails());
            self::assertSame($state, $payment->getState());
            self::assertSame([42], array_values($calls['create']));
            self::assertSame(['alma_1'], $calls['fetch']);
            self::assertSame([], $calls['refund']);
        }
    }

    public function testPreExistingInvalidStatusStillTriggersItsExistingRefundBehavior(): void
    {
        $calls = ['create' => [], 'fetch' => [], 'paid' => false];
        [$gateway] = $this->redirectGateway($calls);
        $payment = $this->redirectPayment(42, PaymentInterface::STATE_NEW);
        $this->captureRedirect($gateway, $payment);
        $details = $payment->getDetails();
        $details['is_valid'] = false;
        $payment->setDetails($details);
        $status = new GetHumanStatus($payment);
        $gateway->execute($status);
        self::assertTrue($status->isFailed());
        self::assertSame(['alma_1'], $calls['refund']);
        self::assertTrue($payment->getDetails()[Details::DETAILS_KEY_ERROR_TRIGGERED_REFUND]);
        self::assertFalse($payment->getDetails()['is_valid']);
        self::assertSame([], $calls['fetch']);
    }

    private function assertReopeningRejected(
        Gateway $gateway,
        Payment $payment,
        array &$calls,
        int $status,
        ?int $fetches,
        int $creations = 1,
        bool $direct = false,
    ): void {
        $before = $payment->getDetails();
        $state = $payment->getState();
        $capture = new Capture($payment);
        if ($direct) {
            $capture->setModel(new ArrayObject($before));
        }
        $reply = $gateway->execute($direct ? new RedirectToPaymentPage($capture) : $capture, true);
        self::assertInstanceOf(HttpResponse::class, $reply);
        self::assertSame($status, $reply->getStatusCode());
        self::assertSame(['content-type' => 'text/plain; charset=UTF-8'], $reply->getHeaders());
        $key = 503 === $status ? 'reopening_unavailable' : 'reopening_failed';
        self::assertStringContainsString('[flashes] alma_sylius_payment_plugin.payment.' . $key, $reply->getContent());
        self::assertSame($before, $payment->getDetails(), 'Refusal must preserve details at redirect entry.');
        self::assertSame($state, $payment->getState());
        self::assertCount($creations, $calls['create'], 'Refusal must never create a fallback Alma payment.');
        if (null !== $fetches) {
            self::assertCount($fetches, $calls['fetch']);
        }
        self::assertSame([], $calls['refund'], 'Lookup must not turn an unknown validation result into a refund.');
        self::assertArrayNotHasKey('is_valid', $payment->getDetails());
    }

    private function assertFirstTabSurvivesReopening(string $state, bool $notify): void
    {
        $calls = ['create' => [], 'fetch' => [], 'paid' => false];
        [$gateway, $http] = $this->redirectGateway($calls);
        $payment = $this->redirectPayment(42, $state);
        $first = $this->captureRedirect($gateway, $payment);
        $afterFirst = $payment->getDetails();
        $second = $this->captureRedirect($gateway, $payment);
        $afterReopening = $payment->getDetails();
        self::assertArrayNotHasKey('is_valid', $afterReopening);
        self::assertSame($state, $payment->getState());

        // Only the first tab is paid, after both sequential capture requests have completed.
        $calls['paid'] = true;
        $http->query = ['pid' => 'alma_1'];
        $request = $notify ? new Notify($payment) : new GetHumanStatus($payment);
        $reply = $gateway->execute($request, true);
        if ($notify) {
            self::assertInstanceOf(HttpResponse::class, $reply);
            self::assertSame(200, $reply->getStatusCode(), 'Reopening must not reject the first paid tab with HTTP 400.');
            self::assertSame(['content-type' => 'application/json'], $reply->getHeaders());
            self::assertSame(['success' => true, 'state' => true], json_decode($reply->getContent(), true));
        } else {
            self::assertNull($reply, 'The first-tab return must not produce an HTTP error reply.');
            self::assertTrue($request->isCaptured());
        }
        self::assertSame('https://example.test/alma_1', $first->getUrl());
        self::assertSame($first->getUrl(), $second->getUrl());
        self::assertSame($afterFirst, $afterReopening);
        self::assertSame([42], array_values($calls['create']), 'Reopening must reuse, not create a second Alma payment.');
        self::assertSame(['alma_1', 'alma_1'], $calls['fetch'], 'Reuse fetches the original payment; the callback validates it again.');
        self::assertSame('alma_1', $afterReopening['payment_id']);
        self::assertSame('alma_1', $payment->getDetails()['payment_id']);
        self::assertTrue($payment->getDetails()['is_valid']);
        self::assertSame('paid', $payment->getDetails()['payment_data']->state);
        self::assertSame([], $calls['refund']);
    }

    private function redirectGateway(array &$calls): array
    {
        $calls += ['refund' => [], 'remote' => [], 'fetch_error' => false];
        $bridge = $this->bridge();
        $config = new ArrayObject($this->redirectConfig());
        $bridge->initialize($config);
        $payments = $this->mockClient();
        $payments->method('create')->willReturnCallback(static function (array $payload) use (&$calls): AlmaPayment {
            $pid = 'alma_' . (count($calls['create']) + 1);
            $calls['create'][$pid] = $payload['payment']['custom_data']['payment_id'];

            return new AlmaPayment(['id' => $pid, 'url' => 'https://example.test/' . $pid]);
        });
        $payments->method('fetch')->willReturnCallback(static function (string $pid) use (&$calls): AlmaPayment {
            $calls['fetch'][] = $pid;
            if ($calls['fetch_error']) {
                throw new RequestError('Fixture lookup unavailable');
            }

            return new AlmaPayment(array_replace([
                'id' => $pid, 'url' => 'https://example.test/' . $pid,
                'custom_data' => ['payment_id' => $calls['create'][$pid]],
                'purchase_amount' => 12000, 'installments_count' => 3, 'expired_at' => null,
                'state' => $calls['paid'] ? 'paid' : 'not_started',
                'payment_plan' => array_fill(0, 3, ['state' => $calls['paid'] ? 'paid' : 'pending']),
            ], $calls['remote']));
        });
        $payments->method('fullRefund')->willReturnCallback(static function (string $pid) use (&$calls): array {
            $calls['refund'][] = $pid;

            return [];
        });
        $http = new class() implements ActionInterface {
            public array $query = [];

            public function execute($request): void
            {
                $request->query = $this->query;
            }

            public function supports($request): bool
            {
                return $request instanceof GetHttpRequest;
            }
        };
        $gateway = new Gateway();
        $gateway->addApi($bridge);
        foreach ([
            $http, new StatusAction(),
            new SyliusCapturePaymentAction($this->createMock(PaymentDescriptionProviderInterface::class)),
            new CaptureAction(), new RedirectToPaymentPageAction(new NullLogger(), new RequestStack(), $this->translator()),
            new ValidatePaymentAction(), new NotifyAction(), new ExecuteSameRequestWithPaymentDetailsAction(),
        ] as $action) {
            $gateway->addAction($action);
        }
        $gateway->addExtension(new RefundFailedPaymentExtension($bridge, new NullLogger(), new RequestStack()));

        return [$gateway, $http, $config];
    }

    private function redirectConfig(): array
    {
        return [
            Config::CONFIG_MERCHANT_ID => 'merchant_fixture',
            Config::CONFIG_API_MODE => Client::TEST_MODE,
            Config::CONFIG_INSTALLMENTS_COUNT => 3,
            Config::CONFIG_PAYMENT_PAGE_MODE => Config::PAYMENT_PAGE_MODE_REDIRECT,
        ];
    }

    private function redirectPayment(int $id, string $state): Payment
    {
        $gatewayConfig = new SyliusGatewayConfig();
        (new ReflectionProperty($gatewayConfig, 'id'))->setValue($gatewayConfig, 7);
        $gatewayConfig->setConfig($this->redirectConfig());
        $method = new PaymentMethod();
        (new ReflectionProperty($method, 'id'))->setValue($method, 5);
        $method->setGatewayConfig($gatewayConfig);
        $payment = new Payment();
        (new ReflectionProperty($payment, 'id'))->setValue($payment, $id);
        $payment->setOrder(new Order());
        $payment->setMethod($method);
        $payment->setCurrencyCode('EUR');
        $payment->setAmount(12000);
        $payment->setState($state);
        // Enter capture after payload conversion; token creation and database persistence are outside this test.
        $payment->setDetails(['payload' => ['payment' => [
            'purchase_amount' => 12000, 'installments_count' => 3,
            'custom_data' => ['payment_id' => $id],
        ]]]);

        return $payment;
    }

    private function captureRedirect(Gateway $gateway, Payment $payment): HttpRedirect
    {
        $reply = $gateway->execute(new Capture($payment), true);
        self::assertInstanceOf(HttpRedirect::class, $reply);
        self::assertSame(302, $reply->getStatusCode());
        self::assertSame(['Location' => $reply->getUrl()], $reply->getHeaders());

        return $reply;
    }

    private function bridge(): AlmaBridge
    {
        return new AlmaBridge(new NullLogger(), $this->createMock(PaymentDataBuilderInterface::class));
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null): string => '[' . $domain . '] ' . $id
        );

        return $translator;
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
