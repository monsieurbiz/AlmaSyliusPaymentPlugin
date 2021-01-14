<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\Payum\Action;

use GuzzleHttp\Client;
use Payum\Core\Request\Capture;
use Alma\API\Endpoints\Payments;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Action\ActionInterface;
use GuzzleHttp\Exception\RequestException;
use Alma\SyliusPaymentPlugin\Payum\AlmaApi;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\Exception\RequestNotSupportedException;
use Sylius\Component\Core\Model\PaymentInterface as SyliusPaymentInterface;

final class CaptureAction implements ActionInterface, ApiAwareInterface
{

    /** @var Client */
    private $client;
    /** @var SyliusApi */
    private $api;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var SyliusPaymentInterface $payment */
        $payment = $request->getModel();
        $shipping = $payment->getOrder()->getShippingAddress();
        $customer = $payment->getOrder()->getCustomer();


        $body = [
            'payment'  => [
                'purchase_amount'       => $payment->getAmount(),
                'installments_count'    => $this->api->getPnx(),
                'return_url'            => $request->getToken()->getAfterUrl(),
                'shipping_address'      => [
                    'line1'       => $shipping->getStreet(),
                    'postal_code' => $shipping->getPostcode(),
                    'city'        => $shipping->getCity(),
                ],
            ],
            'customer' => [
                'first_name' => $customer->getFirstName(),
                'last_name'  => $customer->getLastName(),
                'email'      => $customer->getEmail(),
                'phone'      => $customer->getPhoneNumber(),
            ],
        ];

        $header = [
            'Accept'        => 'application/json',
            'Authorization' => "Alma-Auth {$this->api->getApiKey()}"
        ];


        try {
            $response = $this->client->request('POST', "{$this->api->getApiurl()}/v1/payments/eligibility", [
                'body' => json_encode($body),
                'headers' => $header,
            ]);
            //$response = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            // echo '<pre>';
            // print_r($response);
            // echo '</pre>';
            // die();
        } catch (RequestException $exception) {
            $response = $exception->getResponse();
        } finally {
            $payment->setDetails(['status' => $response->getStatusCode()]);
        }
    }

    public function supports($request): bool
    {
        return
            $request instanceof Capture &&
            $request->getModel() instanceof SyliusPaymentInterface;
    }

    public function setApi($api): void
    {
        if (!$api instanceof AlmaApi) {
            throw new UnsupportedApiException('Not supported. Expected an instance of ' . AlmaApi::class);
        }

        $this->api = $api;
    }
}
