<?php

declare(strict_types=1);

namespace Alma\SyliusPaymentPlugin\ValueObject;

use Sylius\Component\Core\Model\OrderInterface;

class Customer
{
    private $firstName;
    private $lastName;
    private $email;
    private $phoneNumber;

    protected function __construct(?string $firstName, ?string $lastName, ?string $email, ?string $phoneNumber)
    {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = $email;
        $this->phoneNumber = $phoneNumber;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    /**
     *
     * @psalm-type CustomerData = array{firstName: string, lastName: string, email:String, phoneNumber: String}
     *
     * @param OrderInterface $order
     *
     * @psalm-return CustomerData
     * @return array
     */
    private static function extractCustomerData(OrderInterface $order): array
    {
        $customer = $order->getCustomer();
        $user = $order->getUser();
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $sources = [$customer, $user, $billingAddress, $shippingAddress];
        $fields = ['firstName', 'lastName', 'email', 'phoneNumber'];

        /** @var CustomerData $result */
        $result = ['firstName' => null, 'lastName' => null, 'email' => null, 'phoneNumber' => null];

        foreach ($fields as $field) {
            $getter = "get" . ucfirst($field);

            foreach ($sources as $source) {
                $callable = [$source, $getter];
                if (is_callable($callable)) {
                    /** @var string | null | false $value */
                    $value = call_user_func($callable);

                    if ($value !== false && $value !== null && $value !== "") {
                        $result[$field] = $value;
                        break;
                    }
                }
            }
        }

        return $result;
    }

    public static function fromOrder(OrderInterface $order): self
    {
        $data = self::extractCustomerData($order);

        return new self($data['firstName'], $data['lastName'], $data['email'], $data['phoneNumber']);
    }
}
