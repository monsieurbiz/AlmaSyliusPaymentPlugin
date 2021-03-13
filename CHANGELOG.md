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
