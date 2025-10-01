<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use Alma\SyliusPaymentPlugin\Bridge\AlmaBridge;
use Alma\SyliusPaymentPlugin\Bridge\AlmaBridgeInterface;
use Alma\SyliusPaymentPlugin\Payum\Request\RenderInPagePayment;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\RenderTemplate;

final class RenderInPagePaymentAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /**
     * @var AlmaBridgeInterface
     */
    protected $api;

    public function __construct()
    {
        $this->apiClass = AlmaBridge::class;
    }

    /**
     * @param RenderInPagePayment $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $details = ArrayObject::ensureArrayObject($request->getModel());
        $config = $this->api->getGatewayConfig();

        $this->gateway->execute($renderTemplate = new RenderTemplate(
            $config->getPaymentFormTemplate(),
            [
                'payload' => $details[AlmaBridgeInterface::DETAILS_KEY_PAYLOAD],
                'merchantId' => $config->getMerchantId(),
                'apiMode' => $config->getApiMode(),
            ]
        ));

        throw new HttpResponse($renderTemplate->getResult());
    }

    public function supports($request): bool
    {
        return
            $request instanceof RenderInPagePayment
            && $request->getModel() instanceof ArrayObject;
    }
}
