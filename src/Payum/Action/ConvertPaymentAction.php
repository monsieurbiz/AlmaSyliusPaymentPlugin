<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\ValueObject\Customer;
use Alma\SyliusPaymentPlugin\ValueObject\Payment;
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
     * @var GenericTokenFactoryInterface
     */
    protected $tokenFactory = null;

    public function __construct()
    {
        $this->apiClass = AlmaBridge::class;
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

        // TODO: add: customer_cancel_url
        $paymentData = Payment::fromOrderPayment($payment)->getPayloadData();
        $paymentData['payment'] = array_merge($paymentData['payment'], [
            'installments_count' => $config->getInstallmentsCount(),
            'return_url' => $request->getToken()->getAfterUrl(),
            'ipn_callback_url' => $notifyToken->getTargetUrl(),
            'custom_data' => [
                'payment_id' => $payment->getId(),
            ]
        ]);

        $request->setResult(['payload' => $paymentData]);
    }

    public function supports($request): bool
    {
        return
            $request instanceof Convert &&
            $request->getSource() instanceof PaymentInterface &&
            $request->getTo() === 'array';
    }
}
