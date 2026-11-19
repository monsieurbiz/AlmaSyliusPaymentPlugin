<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use Alma\API\Entities\Payment;
use Alma\API\RequestError;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Bridge\PaymentIdMismatchException;
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
     * @param ValidatePayment $request
     *
     * @throws RequestError
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();
        $details = $payment->getDetails();
        $candidate = $request->getCandidatePaymentId();
        if (null !== $candidate && array_key_exists(AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID, $details)) {
            throw new PaymentIdMismatchException('Cannot bind an Alma payment ID to a payment that already has one.');
        }

        /** @var ?Payment $paymentData */
        $paymentData = null;
        $details[AlmaBridgeInterface::DETAILS_KEY_IS_VALID] = $this->api->validatePayment(
            $payment,
            $candidate ?? $details[AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID],
            $paymentData
        );
        if (null !== $candidate) {
            $details[AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID] = $candidate;
        }

        // Save Alma's payment data on Sylius' Payment details
        $details[AlmaBridgeInterface::DETAILS_KEY_PAYMENT_DATA] = $paymentData;

        $payment->setDetails($details);
    }

    public function supports($request): bool
    {
        return $request instanceof ValidatePayment
            && $request->getModel() instanceof PaymentInterface;
    }
}
