<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use Alma\API\RequestError;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Payum\Request\ValidatePayment;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\GetStatusInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Sylius\Component\Core\Model\PaymentInterface;


final class StatusAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface, LoggerAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;
    use LoggerAwareTrait;

    /** @var AlmaBridge */
    protected $api;

    public function __construct()
    {
        $this->apiClass = AlmaBridge::class;
    }

    /**
     * @param GetStatusInterface $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getModel();
        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        $httpRequest = new GetHttpRequest();
        $this->gateway->execute($httpRequest);
        $query = ArrayObject::ensureArrayObject($httpRequest->query);

        if (
            !$details->offsetExists(AlmaBridgeInterface::DETAILS_KEY_PAYLOAD)
            && !$details->offsetExists(AlmaBridgeInterface::DETAILS_KEY_IS_VALID)
        ) {
            $request->markNew();

            return;
        }

        if (!$query->offsetExists(AlmaBridgeInterface::QUERY_PARAM_PID)) {
            $request->markPending();

            return;
        }

        // Make sure the payment's details include the Alma payment ID
        $details[AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID] = (string)$query[AlmaBridgeInterface::QUERY_PARAM_PID];
        $payment->setDetails($details->getArrayCopy());

        // If payment hasn't been validated yet, validate its status against Alma's payment state
        if (
            !$details->offsetExists(AlmaBridgeInterface::DETAILS_KEY_IS_VALID)
            && in_array($payment->getState(), [PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING], true)
        ) {
            try {
                $this->gateway->execute(new ValidatePayment($payment));
            } catch (RequestError $e) {
                $details = ArrayObject::ensureArrayObject($payment->getDetails());
                $details[AlmaBridgeInterface::DETAILS_KEY_IS_VALID] = false;
                $payment->setDetails($details->getArrayCopy());
            }

            // Refresh details to get validation status
            $details = ArrayObject::ensureArrayObject($payment->getDetails());
        }

        /** @var bool|null $isValid */
        $isValid = $details->get(AlmaBridgeInterface::DETAILS_KEY_IS_VALID);

        // Explicitly compare to true/false, as a null value (i.e. no IS_VALID_KEY in $details) means unknown state
        if ($isValid === true) {
            $request->markCaptured();
            $this->cleanPayload($payment);
        } elseif ($isValid === false) {
            $request->markFailed();
            $this->cleanPayload($payment);
        }
    }

    // Payment's payload will uselessly occupy database space, so clean it once it's been used
    private function cleanPayload(PaymentInterface $payment): void
    {
        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        if (!$details->offsetExists(AlmaBridgeInterface::DETAILS_KEY_PAYLOAD)) {
            return;
        }

        $details->offsetUnset(AlmaBridgeInterface::DETAILS_KEY_PAYLOAD);
        $payment->setDetails($details->getArrayCopy());
    }

    public function supports($request): bool
    {
        return
            $request instanceof GetStatusInterface &&
            $request->getModel() instanceof PaymentInterface;
    }
}
