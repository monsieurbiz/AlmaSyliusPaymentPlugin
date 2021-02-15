v1.0.1
------

- Fix issue with shipping info data: the `title` field is required by our API but the plugin used 
  ShippingMethod->getDescription(), which might be empty. Switched to getName().

v1.0.0
------

Initial release

- Payment method factory for Alma with ability to choose between 2, 3 & 4 installments
- Display of payment method conditioned by purchase eligibility
