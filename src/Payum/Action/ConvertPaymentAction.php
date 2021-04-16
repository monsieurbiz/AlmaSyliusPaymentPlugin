<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\DataBuilder\PaymentDataBuilder;
use Alma\SyliusPaymentPlugin\ValueObject\Customer;
use Alma\SyliusPaymentPlugin\ValueObject\Payment;
use ArrayAccess;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Convert;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;
use Payum\Core\Security\GenericTokenFactoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Webmozart\Assert\Assert;

final class ConvertPaymentAction implements ActionInterface, ApiAwareInterface, GenericTokenFactoryAwareInterface
{
    use ApiAwareTrait;
    use GenericTokenFactoryAwareTrait;

    /**
     * @var AlmaBridge
     */
    protected $api;

    /**
     * @var GenericTokenFactoryInterface|null
     */
    protected $tokenFactory = null;
    /**
     * @var PaymentDataBuilder
     */
    private $paymentDataBuilder;

    public function __construct(PaymentDataBuilder $paymentDataBuilder)
    {
        $this->apiClass = AlmaBridge::class;
        $this->paymentDataBuilder = $paymentDataBuilder;
    }

    /**
     * @param Convert $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        Assert::notNull($this->tokenFactory);

        /** @var PaymentInterface $payment */
        $payment = $request->getSource();
        $config = $this->api->getGatewayConfig();

        $token = $request->getToken();
        $notifyToken = $this->tokenFactory->createNotifyToken($token->getGatewayName(), $token->getDetails());

        $builder = $this->paymentDataBuilder;
        $builder->addBuilder(
            function (array $data, PaymentInterface $payment) use ($config, $request, $notifyToken): array {
                $data['payment'] = array_merge($data['payment'], [
                    'installments_count' => $config->getInstallmentsCount(),
                    'return_url' => $request->getToken()->getAfterUrl(),
                    'ipn_callback_url' => $notifyToken->getTargetUrl(),
                    'customer_cancel_url' => $request->getToken()->getAfterUrl()
                ]);

                return $data;
            }
        );

        $request->setResult([AlmaBridgeInterface::DETAILS_KEY_PAYLOAD => $builder([], $payment)]);
    }

    public function supports($request): bool
    {
        return
            $request instanceof Convert &&
            $request->getSource() instanceof PaymentInterface &&
            $request->getTo() === 'array';
    }
}
