<p align="center">
    <img src="public/img/alma-logo.svg" alt="logo alma" height="75" style="margin-right:30px" />
</p>

<h1 align="center">Sylius Alma Payment Plugin</h1>

<p align="center">Integrate Alma installments and pay later payments with your Sylius shop</p>

## Documentation

### Installation

1. Use Composer to install the plugin:

```
$ composer require alma/sylius-payment-plugin
```

2. Import configurations:

```
# config/packages/sylius_alma.yaml
imports:
    - { resource: "@AlmaSyliusPaymentPlugin/config/config.yaml" }
```


3. Import routes:

```
# config/routes.yaml

sylius_alma:
    resource: "@AlmaSyliusPaymentPlugin/config/shop_routing.yaml"
    prefix: /{_locale}
    requirements:
        _locale: ^[A-Za-z]{2,4}(_([A-Za-z]{4}|[0-9]{3}))?(_([A-Za-z]{2}|[0-9]{3}))?$
```

4. Export assets:

```
bin/console sylius:install:asset
```

5. Finally, clear your cache:

```
$ php bin/console cache:clear
```

### Requirements

- PHP version >= 8.1
- Sylius version >= 2.0

Alma currently accepts Euros only; make sure you activate your payment method on channels that use that currency, else 
you won't see it at checkout.

Your Alma payment methods will only show for eligible carts. Eligibility is mainly based on the purchased amount, which
by default should be between 100€ and 2000€; if you want those limits changed, you can talk to your sales representative
at Alma, or contact [support@getalma.eu](mailto:support@getalma.eu).

### Usage
1. Go to the Payment Methods admin page and choose to create a new "Alma Payments" method

2. Grab your API keys [from your dashboard](https://dashboard.getalma.eu/api) and paste them into the appropriate fields

3. Choose the installments count to apply for this payment method. If you want to offer multiple installments counts to 
   your customers, you can create one Alma payment method per installments count.

4. Set the API mode to Test if you want to first test the integration with a fake credit card, on your preproduction 
   servers for instance.  
   When you're ready for production, set the API mode to Live.

5. Choose a name for your method in the languages relevant to your shop.

6. You're done! Save the payment method to start accepting instalments payments on your shop!
