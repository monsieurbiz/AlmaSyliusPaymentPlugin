# Changelog

v2.0.0
------

- Fix: Compatibility Sylius 1.12
- Feat: Add installment P10/12X
- Feat: Display installment's plan in checkout page

v1.3.0
------

- Support PHP 7.1 and later (tested up to 8.1.12)
- Support Sylius 1.6.9 and later (tested up to 1.11.10)

v1.2.0
------

- Add customer_cancel_url to payment data so that a cancel link shows in Alma's payment page when using the gateway as
  a redirect payment method
- Add support for payment expiration IPN

v1.1.0
------

- Add a new gateway option: payment page mode allows merchant to choose between in-page payment form rendering (default)
  and redirect to Alma's payment page

v1.0.2
------

- Better handling of errors that might happen during payment validation and eligibility calls
- Store Alma's payment data into Sylius' payment details column under the `payment_data` key

v1.0.1
------

- Fix issue with shipping info data: the `title` field is required by our API but the plugin used 
  ShippingMethod->getDescription(), which might be empty. Switched to getName().

v1.0.0
------

Initial release

- Payment method factory for Alma with ability to choose between 2, 3 & 4 installments
- Display of payment method conditioned by purchase eligibility
