<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Payum\Request\ValidatePayment;
use ArrayAccess;
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
    use GatewayAwareTrait;
    use ApiAwareTrait;

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

        // Make sure the payment's details include the Alma payment ID
        $details = ArrayObject::ensureArrayObject($request->getModel());
        $details[AlmaBridgeInterface::DETAILS_KEY_PAYMENT_ID] = (string) $query[AlmaBridgeInterface::QUERY_PARAM_PID];
        $payment->setDetails($details->getArrayCopy());

        // If payment hasn't been validated yet, validate its status against Alma's payment state
        if (in_array($payment->getState(), [PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING], true)) {
            try {
                $this->gateway->execute(new ValidatePayment($payment));
            } catch (\Exception $e) {
                $error = [
                    "error" => true,
                    "message" => $e->getMessage()
                ];

                throw new HttpResponse(
                    json_encode($error),
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    ["content-type" => "application/json"]
                );
            }
        }

        // $details is the request's model here, but we used a copy above passed down the ValidatePaymentAction through
        // the $payment object, which is the model of our own request.
        // Since Payum will use whatever is in the original details model to overwrite the payment's details data, we
        // need to make sure we copy everything that was set on the payment itself back to the NotifyRequest model.
        $details->replace($payment->getDetails());

        // Down here means the callback has been correctly handled, regardless of the final payment state
        throw new HttpResponse(
            json_encode(["success" => true, "state" => $payment->getDetails()[AlmaBridgeInterface::DETAILS_KEY_IS_VALID]]),
            Response::HTTP_OK,
            ["content-type" => "application/json"]
        );
    }

    public function supports($request): bool
    {
        return $request instanceof Notify
            && $request->getModel() instanceof ArrayAccess
            && $request->getFirstModel() instanceof PaymentInterface;
    }
}
