<?php

namespace Drupal\Tests\commerce_order\FunctionalJavascript;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\Tests\commerce\FunctionalJavascript\JavascriptTestTrait;

/**
 * Tests the multiple profile types functionality.
 *
 * @group commerce
 */
class MultipleProfileTypesFormTest extends CommerceBrowserTestBase {

  use JavascriptTestTrait;

  /**
   * First sample product.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected $firstProduct;

  /**
   * Second sample product.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected $secondProduct;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_payment',
    'commerce_payment_example',
    'commerce_shipping_test',
    'profile',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge([
      'access administration pages',
      'access checkout',
      'access user profiles',
      'administer commerce_order_type',
      'administer profile',
      'administer profile types',
      'administer users',
      'update default commerce_order',
      'view commerce_order',
    ], parent::getAdministratorPermissions());
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    \Drupal::service('module_installer')->install(['profile']);

    // Limit the available countries.
    $this->store->shipping_countries = ['US', 'FR', 'DE'];
    $this->store->save();

    /** @var \Drupal\commerce_payment\Entity\PaymentGateway $gateway */
    $gateway = PaymentGateway::create([
      'id' => 'example_onsite',
      'label' => 'Example',
      'plugin' => 'example_onsite',
    ]);
    $gateway->getPlugin()->setConfiguration([
      'api_key' => '2342fewfsfs',
      'payment_method_types' => ['credit_card'],
    ]);
    $gateway->save();

    $product_variation_type = ProductVariationType::load('default');
    $product_variation_type->setTraits(['purchasable_entity_shippable']);
    $product_variation_type->save();

    $order_type = OrderType::load('default');
    $order_type->setThirdPartySetting('commerce_checkout', 'checkout_flow', 'shipping');
    $order_type->setThirdPartySetting('commerce_shipping', 'shipment_type', 'default');
    $order_type->save();

    // Create the order field.
    $field_definition = commerce_shipping_build_shipment_field_definition($order_type->id());
    \Drupal::service('commerce.configurable_field_manager')->createField($field_definition);

    // Install the variation trait.
    $trait_manager = \Drupal::service('plugin.manager.commerce_entity_trait');
    $trait = $trait_manager->createInstance('purchasable_entity_shippable');
    $trait_manager->installTrait($trait, 'commerce_product_variation', 'default');

    // Create two products.
    $variation = $this->createEntity('commerce_product_variation', [
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'price' => [
        'number' => '7.99',
        'currency_code' => 'USD',
      ],
    ]);
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $this->firstProduct = $this->createEntity('commerce_product', [
      'type' => 'default',
      'title' => 'Conference hat',
      'variations' => [$variation],
      'stores' => [$this->store],
    ]);

    $variation = $this->createEntity('commerce_product_variation', [
      'type' => 'default',
      'sku' => strtolower($this->randomMachineName()),
      'price' => [
        'number' => '8.99',
        'currency_code' => 'USD',
      ],
    ]);
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $this->secondProduct = $this->createEntity('commerce_product', [
      'type' => 'default',
      'title' => 'Conference bow tie',
      'variations' => [$variation],
      'stores' => [$this->store],
    ]);

    /** @var \Drupal\commerce_shipping\Entity\PackageType $package_type */
    $package_type = $this->createEntity('commerce_package_type', [
      'id' => 'package_type_a',
      'label' => 'Package Type A',
      'dimensions' => [
        'length' => 20,
        'width' => 20,
        'height' => 20,
        'unit' => 'mm',

      ],
      'weight' => [
        'number' => 20,
        'unit' => 'g',
      ],
    ]);
    \Drupal::service('plugin.manager.commerce_package_type')->clearCachedDefinitions();

    // Create two flat rate shipping methods.
    $first_shipping_method = $this->createEntity('commerce_shipping_method', [
      'name' => 'Overnight shipping',
      'stores' => [$this->store->id()],
      'plugin' => [
        'target_plugin_id' => 'flat_rate',
        'target_plugin_configuration' => [
          'default_package_type' => 'commerce_package_type:' . $package_type->get('uuid'),
          'rate_label' => 'Overnight shipping',
          'rate_amount' => [
            'number' => '19.99',
            'currency_code' => 'USD',
          ],
        ],
      ],
    ]);
    $second_shipping_method = $this->createEntity('commerce_shipping_method', [
      'name' => 'Standard shipping',
      'stores' => [$this->store->id()],
      // Ensure that Standard shipping shows before overnight shipping.
      'weight' => -10,
      'plugin' => [
        'target_plugin_id' => 'flat_rate',
        'target_plugin_configuration' => [
          'rate_label' => 'Standard shipping',
          'rate_amount' => [
            'number' => '9.99',
            'currency_code' => 'USD',
          ],
        ],
      ],
    ]);
    $second_store = $this->createStore();
    // Should never be shown cause it doesn't belong to the order's store.
    $third_shipping_method = $this->createEntity('commerce_shipping_method', [
      'name' => 'Secret shipping',
      'stores' => [$second_store->id()],
      'plugin' => [
        'target_plugin_id' => 'flat_rate',
        'target_plugin_configuration' => [
          'rate_label' => 'Secret shipping',
          'rate_amount' => [
            'number' => '9.99',
            'currency_code' => 'USD',
          ],
        ],
      ],
    ]);
  }

  /**
   * Tests ensuring that the useMultipleProfileTypes field works as expected.
   *
   * @group failing
   */
  public function testUseMultipleProfileTypesFieldExists() {
    $web_assert = $this->assertSession();

    $this->drupalGet('/admin/commerce/config/order-types');

    // Assert that the default order type exists.
    $web_assert->elementContains(
      'css', 'body > div > div > main > div > div > table > tbody > tr > td:nth-child(1)',
      'Default'
    );
    // Click on the 'Edit' link.
    $this->clickLink('Edit');
    $web_assert->statusCodeEquals(200);

    // Verify the useMultipleProfileTypes field exists and is turned off by
    // default.
    $multiple_profile_types_checkbox = $this->xpath(
      '//input[@type="checkbox" and @name="useMultipleProfileTypes" and not(@checked)]'
    );
    $this->assertTrue(
      count($multiple_profile_types_checkbox) === 1,
      'The "use multiple profile types" checkbox exists and is not checked.'
    );
  }

  /**
   * Tests ensuring that the multiple profile types confirm works as expected.
   *
   * @group failing
   */
  public function testMultipleProfileTypesConfirmFormCancel() {
    $web_assert = $this->assertSession();

    // Let's submit the default order type form with the useMultipleProfileTypes
    // checkbox checked.
    $this->drupalGet('/admin/commerce/config/order-types/default/edit');
    $this->submitForm([
      'useMultipleProfileTypes' => TRUE,
    ], t('Save'));

    // Ensure we are taken to the confirm form.
    $web_assert->pageTextContains(t('Are you sure you want to switch to using multiple profile types for shipping and billing for the Default order type?'));
    $web_assert->buttonExists('Switch to Multiple Profile Types');

    // Cancel out of confirming.
    $this->clickLink('Cancel');

    // Verify the useMultipleProfileTypes field has been turned back off.
    $this->drupalGet('/admin/commerce/config/order-types/default/edit');
    $multiple_profile_types_checkbox = $this->xpath(
      '//input[@type="checkbox" and @name="useMultipleProfileTypes" and not(@checked)]'
    );
    $this->assertTrue(
      count($multiple_profile_types_checkbox) === 1,
      'The "use multiple profile types" checkbox exists and is not checked.'
    );
  }

  /**
   * Tests ensuring that the multiple profile types confirm works as expected.
   *
   * @group failing
   */
  public function testMultipleProfileTypesConfirmFormSubmit() {
    $web_assert = $this->assertSession();

    // Create a new order which will use single profile type for billing and
    // shipping.
    $order = $this->createOrder();

    // Assert that we have 2 profiles created and it's the 'customer' profile
    // type.
    $this->drupalGet('/admin/people/profiles');
    $web_assert->elementsCount('css', 'td.priority-medium', 2);
    $web_assert->elementTextContains(
      'css',
      'body > div > div > main > div > div > table > tbody > tr.odd > td.priority-medium',
      OrderType::PROFILE_COMMON
    );
    $web_assert->elementTextContains(
      'css',
      'body > div > div > main > div > div > table > tbody > tr.even > td.priority-medium',
      OrderType::PROFILE_COMMON
    );

    // Let's submit the default order type form with the useMultipleProfileTypes
    // checkbox checked.
    $this->drupalGet('/admin/commerce/config/order-types/default/edit');
    $this->submitForm([
      'useMultipleProfileTypes' => TRUE,
    ], t('Save'));

    // Ensure we are taken to the confirm form.
    $web_assert->pageTextContains(t('The default order type contains 1 order on your site.'));
    $web_assert->buttonExists('Switch to Multiple Profile Types');

    // Confirm and submit the form.
    $this->submitForm([], t('Switch to Multiple Profile Types'));
    $web_assert->statusCodeEquals(200);

    // Verify the useMultipleProfileTypes field is checked now.
    $this->drupalGet('/admin/commerce/config/order-types/default/edit');
    $web_assert->fieldValueEquals('useMultipleProfileTypes', TRUE);

    // Assert that we have a customer_billing profile type.
    /** @var \Drupal\profile\Entity\ProfileTypeInterface $profile_type */
    $profile_type = \Drupal::service('entity_type.manager')->getStorage('profile_type')->load(OrderType::PROFILE_BILLING);
    $this->assertNotNull($profile_type);

    // Assert that we have a customer_shipping profile type.
    /** @var \Drupal\profile\Entity\ProfileTypeInterface $profile_type */
    $profile_type = \Drupal::service('entity_type.manager')->getStorage('profile_type')->load(OrderType::PROFILE_SHIPPING);
    $this->assertNotNull($profile_type);

    // Now, let's test that the order profiles has been changed to now use split
    // profile types for billing and shipping.
    // First, create a new order.
    $controller = \Drupal::service('entity.manager')->getStorage($order->getEntityTypeId());
    $controller->resetCache([$order->id()]);
    $order = $controller->load($order->id());
    $this->drupalGet('/admin/commerce/orders/1/edit');

    // Billing.
    /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
    $billing_profile = $order->getBillingProfile();

    // Assert that the billing profile type for this order is
    // 'customer_billing'.
    $this->assertEquals(OrderType::PROFILE_BILLING, $billing_profile->bundle());

    // Assert the address is what we're expecting.
    $this->assertEquals(
      'NY',
      $billing_profile->address->first()->getAdministrativeArea()
    );

    // Shipping.
    foreach ($order->shipments->referencedEntities() as $shipment) {
      /** @var \Drupal\profile\Entity\ProfileInterface $shipping_profile */
      $shipping_profile = $shipment->getShippingProfile();

      // Assert that the shipping profile type for this order is
      // 'customer_shipping'.
      $this->assertEquals(OrderType::PROFILE_SHIPPING, $shipping_profile->bundle());

      // Assert the address is what we're expecting.
      $this->assertEquals(
        'CA',
        $shipping_profile->address->first()->getAdministrativeArea()
      );
    }

    // Now, assert via the form.
    // Assert that we have 2 split profile types for the same order.
    $this->drupalGet('/admin/people/profiles');
    $web_assert->elementsCount('css', 'td.priority-medium', 2);
    // The shipping profile comes first.
    $web_assert->elementTextContains(
      'css',
      'body > div > div > main > div > div > table > tbody > tr.odd > td.priority-medium',
      OrderType::PROFILE_SHIPPING
    );
    $web_assert->elementTextContains(
      'css',
      'body > div > div > main > div > div > table > tbody > tr.even > td.priority-medium',
      OrderType::PROFILE_BILLING
    );
  }

  /**
   * Create an order.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The newly created order.
   */
  protected function createOrder() {
    $web_assert = $this->assertSession();

    $this->drupalGet($this->firstProduct->toUrl()->toString());
    $this->submitForm([], 'Add to cart');
    $this->drupalGet($this->secondProduct->toUrl()->toString());
    $this->submitForm([], 'Add to cart');

    $this->drupalGet('checkout/1');
    $web_assert->pageTextContains('Shipping information');
    $web_assert->pageTextNotContains('Shipping method');

    $address = [
      'given_name' => 'John',
      'family_name' => 'Smith',
      'address_line1' => '1098 Alta Ave',
      'locality' => 'Mountain View',
      'administrative_area' => 'CA',
      'postal_code' => '94043',
    ];
    $address_prefix = 'shipping_information[shipping_profile][address][0][address]';

    $page = $this->getSession()->getPage();
    $page->fillField($address_prefix . '[country_code]', 'US');
    $this->waitForAjaxToFinish();
    foreach ($address as $property => $value) {
      $page->fillField($address_prefix . '[' . $property . ']', $value);
    }
    $page->findButton('Recalculate shipping')->click();
    $this->waitForAjaxToFinish();

    $web_assert->pageTextContains('Shipping method');
    $first_radio_button = $page->findField('Standard shipping: $9.99');
    $second_radio_button = $page->findField('Overnight shipping: $19.99');
    $this->assertNotNull($first_radio_button);
    $this->assertNotNull($second_radio_button);
    $this->assertTrue($first_radio_button->getAttribute('checked'));
    $this->submitForm([
      'payment_information[add_payment_method][payment_details][number]' => '4111111111111111',
      'payment_information[add_payment_method][payment_details][expiration][month]' => '02',
      'payment_information[add_payment_method][payment_details][expiration][year]' => '2020',
      'payment_information[add_payment_method][payment_details][security_code]' => '123',
      'payment_information[add_payment_method][billing_information][address][0][address][given_name]' => 'Johnny',
      'payment_information[add_payment_method][billing_information][address][0][address][family_name]' => 'Appleseed',
      'payment_information[add_payment_method][billing_information][address][0][address][address_line1]' => '123 New York Drive',
      'payment_information[add_payment_method][billing_information][address][0][address][locality]' => 'New York City',
      'payment_information[add_payment_method][billing_information][address][0][address][administrative_area]' => 'NY',
      'payment_information[add_payment_method][billing_information][address][0][address][postal_code]' => '10001',
    ], 'Continue to review');

    // Confirm that the review is rendered correctly.
    $web_assert->pageTextContains('Shipping information');
    foreach ($address as $property => $value) {
      $web_assert->pageTextContains($value);
    }
    $web_assert->pageTextContains('Standard shipping');
    $web_assert->pageTextNotContains('Secret shipping');

    // Confirm the integrity of the shipment.
    $this->submitForm([], 'Pay and complete purchase');

    $order = Order::load(1);
    return $order;
  }

}
