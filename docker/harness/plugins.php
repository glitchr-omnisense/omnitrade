<?php

/**
 * Every provider package: its slug (omnitrade/<slug>, github.com/glitchr-omnisense/omnitrade-<slug>)
 * and its factory class.
 */
return [
    'stripe' => ['Omnitrade\\Stripe\\Tests\\', 'Omnitrade\\Stripe\\StripeGatewayFactory'],
    'paypal' => ['Omnitrade\\PayPal\\Tests\\', 'Omnitrade\\PayPal\\PayPalGatewayFactory'],
    'shopify' => ['Omnitrade\\Shopify\\Tests\\', 'Omnitrade\\Shopify\\ShopifyGatewayFactory'],
    'web' => ['Omnitrade\\Web\\Tests\\', 'Omnitrade\\Web\\WebGatewayFactory'],
    'amazon' => ['Omnitrade\\Amazon\\Tests\\', 'Omnitrade\\Amazon\\AmazonGatewayFactory'],
    'woocommerce' => ['Omnitrade\\WooCommerce\\Tests\\', 'Omnitrade\\WooCommerce\\WooCommerceGatewayFactory'],
];
