<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;


use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Payum\Request\ValidatePayment;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\RequestNotSupportedException;
use Sylius\Component\Core\Model\PaymentInterface;


final class ValidatePaymentAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait;

    /** @var AlmaBridgeInterface */
    protected $api;

    public function __construct()
    {
        $this->apiClass = AlmaBridge::class;
    }

    /**
     * @inheritDoc
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();
        $details = $payment->getDetails();

        $details[AlmaBridgeInterface::DETAILS_KEY_IS_VALID] =
            $this->api->validatePayment($payment, $details[AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID]);

        $payment->setDetails($details);
    }

    /**
     * @inheritDoc
     */
    public function supports($request)
    {
        return $request instanceof ValidatePayment
            && $request->getModel() instanceof PaymentInterface;
    }
}
