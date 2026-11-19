<?php

declare(strict_types=1);

namespace Tests\Alma\SyliusPaymentPlugin\Payum;

use Alma\API\Client;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\DataBuilder\PaymentDataBuilder;
use Payum\Bundle\PayumBundle\Action\GetHttpRequestAction;
use Payum\Bundle\PayumBundle\Builder\CoreGatewayFactoryBuilder;
use Payum\Bundle\PayumBundle\DependencyInjection\Compiler\BuildConfigsPass;
use Payum\Bundle\PayumBundle\DependencyInjection\Compiler\BuildGatewayFactoriesBuilderPass;
use Payum\Core\GatewayInterface;
use Payum\Core\PayumBuilder;
use Payum\Core\Request\Capture;
use Payum\Core\Security\TokenFactoryInterface;
use Payum\Core\Security\TokenInterface;
use Payum\Core\Storage\StorageInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Bundle\PayumBundle\Provider\PaymentDescriptionProviderInterface;
use Sylius\Bundle\PayumBundle\SyliusPayumBundle;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\ResolveClassPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

trait CompiledCaptureGateway
{
    private const GATEWAY_CONFIG = ['merchant_id' => 'merchant_fixture', 'api_mode' => Client::TEST_MODE, 'installments_count' => 3];

    private function captureGateway(
        string $uri,
        PaymentDataBuilder $builder,
        LoggerInterface $logger,
        TranslatorInterface $translator,
    ): GatewayInterface {
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/config/services'));
        $loader->load('actions.yaml');
        $loader->load('extensions.yaml');
        $loader->load('gateway.yaml');
        $sylius = dirname((new ReflectionClass(SyliusPayumBundle::class))->getFileName());
        $loader = new XmlFileLoader($container, new FileLocator($sylius . '/Resources/config/services'));
        $loader->load('action.xml');
        $loader->load('extension.xml');

        $stack = new RequestStack();
        $httpRequest = Request::create($uri);
        $httpRequest->setSession(new Session(new MockArraySessionStorage()));
        $stack->push($httpRequest);
        $http = new GetHttpRequestAction();
        $http->setHttpRequestStack($stack);
        $container->set('logger', $logger);
        $container->set('request_stack', $stack);
        $container->set('translator', $translator);
        $container->set('alma_sylius_payment_plugin.payment_data_builder', $builder);
        $container->set('alma_sylius_payment_plugin.bridge', new AlmaBridge($logger, $builder));
        $container->set('sylius_abstraction.state_machine', $this->createMock(StateMachineInterface::class));
        $container->set('sylius_payum.provider.payment_description', $this->createMock(PaymentDescriptionProviderInterface::class));
        $tokenFactory = $this->createMock(TokenFactoryInterface::class);
        $tokenFactory->method('createToken')->willReturn($this->createMock(TokenInterface::class));
        $container->register('payum.builder', PayumBuilder::class)
            ->addMethodCall('setTokenStorage', [$this->createMock(StorageInterface::class)])
            ->addMethodCall('setTokenFactory', [$tokenFactory])
            ->addMethodCall('setCoreGatewayFactory', [new CoreGatewayFactoryBuilder($container)])
            ->addMethodCall('addCoreGatewayFactoryConfig', [[
                'payum.action.get_http_request' => $http,
                'httplug.message_factory' => new \stdClass(),
                'httplug.stream_factory' => new \stdClass(),
                'httplug.client' => new \stdClass(),
            ]]);

        (new ResolveClassPass())->process($container);
        (new BuildConfigsPass())->process($container);
        (new BuildGatewayFactoriesBuilderPass())->process($container);

        return $container->get('payum.builder')->getPayum()->getGatewayFactory('alma_payments')->create(self::GATEWAY_CONFIG);
    }

    private function existingPayment(string $state, array $extra = []): Payment
    {
        $gatewayConfig = new GatewayConfig();
        (new ReflectionProperty($gatewayConfig, 'id'))->setValue($gatewayConfig, 7);
        $method = new PaymentMethod();
        (new ReflectionProperty($method, 'id'))->setValue($method, 5);
        $method->setGatewayConfig($gatewayConfig);
        $payment = new Payment();
        (new ReflectionProperty($payment, 'id'))->setValue($payment, 42);
        $payment->setOrder(new Order());
        $payment->setMethod($method);
        $payment->setAmount(12000);
        $payment->setCurrencyCode('EUR');
        $payment->setState($state);
        $payment->setDetails($extra + ['payment_id' => 'alma_existing', 'creation_context' => [
            'payment_method_id' => 5, 'gateway_config_id' => 7, 'merchant_id' => 'merchant_fixture',
            'api_mode' => Client::TEST_MODE, 'currency' => 'EUR',
        ]]);

        return $payment;
    }

    private function captureRequest(Payment $payment): Capture
    {
        $token = $this->createMock(TokenInterface::class);
        $capture = new Capture($token);
        $capture->setModel($payment);

        return $capture;
    }
}
