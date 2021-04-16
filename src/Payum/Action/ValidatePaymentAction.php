<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;


use Alma\API\Entities\Payment;
use Alma\API\RequestError;
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
     * @throws RequestError
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();
        $details = $payment->getDetails();

        /** @var Payment $paymentData */
        $paymentData = null;
        $details[AlmaBridgeInterface::DETAILS_KEY_IS_VALID] = $this->api->validatePayment(
            $payment,
            $details[AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID],
            $paymentData
        );

        // Save Alma's payment data on Sylius' Payment details
        $details[AlmaBridgeInterface::DETAILS_KEY_PAYMENT_DATA] = $paymentData;

        $payment->setDetails($details);
    }

    /**
     * @inheritDoc
     */
    public function supports($request): bool
    {
        return $request instanceof ValidatePayment
            && $request->getModel() instanceof PaymentInterface;
    }
}
