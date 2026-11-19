<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Payum\Request\ValidatePayment;
use ArrayAccess;
use Exception;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\Response;

class NotifyAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    public function __construct()
    {
        $this->apiClass = AlmaBridge::class;
    }

    /**
     * @param Notify $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getFirstModel();

        $httpRequest = new GetHttpRequest();
        $this->gateway->execute($httpRequest);
        $query = ArrayObject::ensureArrayObject($httpRequest->query);

        /* if notification does not include a payment ID, just return */
        if (!$query->offsetExists(AlmaBridgeInterface::QUERY_PARAM_PID)) {
            return;
        }

        $details = ArrayObject::ensureArrayObject($request->getModel());
        $paymentId = $query[AlmaBridgeInterface::QUERY_PARAM_PID];
        if (
            !\is_string($paymentId) || '' === trim($paymentId)
            || ($details->offsetExists(AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID)
                && $paymentId !== $details[AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID])
        ) {
            throw new HttpResponse('{"error":true}', Response::HTTP_BAD_REQUEST, ['content-type' => 'application/json']);
        }

        $candidate = $details->offsetExists(AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID) ? null : $paymentId;

        // Refresh non-terminal payments: Alma's state may have changed since the previous validation.
        if (\in_array($payment->getState(), [PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING], true)) {
            try {
                $this->gateway->execute(new ValidatePayment($payment, $candidate));
            } catch (Exception $e) {
                // Sylius queries the status again after a Payum reply, but not after an exception.
                if (null !== $candidate) {
                    throw $e;
                }

                $error = [
                    'error' => true,
                    'message' => $e->getMessage(),
                ];

                throw new HttpResponse((string) json_encode($error), Response::HTTP_INTERNAL_SERVER_ERROR, ['content-type' => 'application/json']);
            }
        }

        // Sylius copies the Notify model back to the payment after this action.
        $details->replace($payment->getDetails());

        // Down here means the callback has been correctly handled, regardless of the final payment state
        throw new HttpResponse((string) json_encode(['success' => true, 'state' => $details->get(AlmaBridgeInterface::DETAILS_KEY_IS_VALID)]), Response::HTTP_OK, ['content-type' => 'application/json']);
    }

    public function supports($request): bool
    {
        return $request instanceof Notify
            && $request->getModel() instanceof ArrayAccess
            && $request->getFirstModel() instanceof PaymentInterface;
    }
}
