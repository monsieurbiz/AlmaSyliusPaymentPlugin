<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use Alma\API\Client as AlmaClient;
use Alma\API\RequestError;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Payum\Request\RedirectToPaymentPage;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class RedirectToPaymentPageAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /**
     * @var AlmaBridgeInterface
     */
    protected $api;

    /** @var LoggerInterface */
    protected $logger;

    private ?SessionInterface $session;

    /**
     * @param LoggerInterface $logger
     * @param RequestStack $requestStack
     */
    public function __construct(LoggerInterface $logger, RequestStack $requestStack)
    {
        $this->apiClass = AlmaBridge::class;
        $this->logger = $logger;
        $this->session = $requestStack->getMainRequest()?->getSession();
    }

    /**
     * @param RedirectToPaymentPage $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var Capture $captureRequest */
        $captureRequest = $request->getModel();

        /** @var AlmaClient $alma */
        $alma = $this->api->getDefaultClient();
        $details = ArrayObject::ensureArrayObject($captureRequest->getModel());

        try {
            $payment = $alma->payments->create((array) $details[AlmaBridgeInterface::DETAILS_KEY_PAYLOAD]);
        } catch (RequestError $e) {
            $this->logger->error('[Alma] Payment creation failed: ' . $e->getMessage());
            $this->addErrorFlash();

            $this->resetPaymentState($captureRequest->getFirstModel(), $details);

            // In case of error, redirecting to the "after url" will trigger the StatusAction that will assess the
            // status of the payment and redirect to the payment method choices when it's considered "new"
            throw new HttpRedirect((string) $captureRequest->getToken()?->getAfterUrl());
        }

        throw new HttpRedirect($payment->url);
    }

    private function addErrorFlash(): void
    {
        if (null === $this->session) {
            return;
        }

        /** @var FlashBagInterface $flashBag */
        $flashBag = $this->session->getBag('flashes');
        $flashBag->add('error', 'alma_sylius_payment_plugin.payment.creation_failed');
    }

    private function resetPaymentState(PaymentInterface $payment, ArrayObject $details): void
    {
        // Deleting payment's payload makes the payment "new" again to the eyes of the StatusAction
        $details->offsetUnset(AlmaBridgeInterface::DETAILS_KEY_PAYLOAD);
        $payment->setDetails($details->getArrayCopy());
    }

    public function supports($request): bool
    {
        return
            $request instanceof RedirectToPaymentPage
            && $request->getModel() instanceof Capture;
    }
}
