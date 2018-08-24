<?php

namespace Drupal\Tests\commerce_order\FunctionalJavascript;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
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

    // Create a payment gateway.
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

    // Create a product variation type.
    $product_variation_type = ProductVariationType::load('default');
    $product_variation_type->setTraits(['purchasable_entity_shippable']);
    $product_variation_type->save();

    // Set third party settings.
    $order_type = OrderType::load('default');
    $order_type->setThirdPartySetting('commerce_checkout', 'checkout_flow', 'shipping');
    $order_type->setThirdPartySetting('commerce_shipping', 'shipment_type', 'default');
    $order_type->save();

    // Create the order field.
    $field_definition = commerce_shipping_build_shipment_field_definition($order_type->id());
    \Drupal::service('commerce.configurable_field_manager')
      ->createField($field_definition);

    // Install the variation trait.
    $trait_manager = \Drupal::service('plugin.manager.commerce_entity_trait');
    $trait = $trait_manager->createInstance('purchasable_entity_shippable');
    $trait_manager->installTrait($trait, 'commerce_product_variation', 'default');

    // Create a product.
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

    // Create a flat rate shipping method.
    $shipping_method = $this->createEntity('commerce_shipping_method', [
      'name' => 'Standard shipping',
      'stores' => [$this->store->id()],
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
   * Tests ensuring that the multiple profile types cancel works as expected.
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

    // Assert that we only have a single profile type created for this order.
    $this->assertSingleProfileTypesCreated();

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

    // Assert that the existing order has been migrated to use split profile
    // types.
    $this->assertMigratingExistingOrders($order);
  }

  /**
   * Assert we only have a single profile type for both billing and shipping.
   */
  protected function assertSingleProfileTypesCreated() {
    $web_assert = $this->assertSession();

    // Assert that we have 2 profiles created and it's the 'customer' profile
    // type.
    $this->drupalGet('/admin/people/profiles');
    $web_assert->elementsCount('css', 'td.priority-medium', 2);
    // First row, should be the shipping profile.
    $web_assert->elementTextContains(
      'css',
      'body > div > div > main > div > div > table > tbody > tr.odd > td.priority-medium',
      OrderType::PROFILE_COMMON
    );
    // Assert the profile ID.
    $web_assert->elementAttributeContains(
      'css',
      'body > div > div > main > div > div > table > tbody > tr.odd > td:nth-child(1) > a',
      'href',
      '/profile/1'
    );
    // Second row, should be the billing profile.
    $web_assert->elementTextContains(
      'css',
      'body > div > div > main > div > div > table > tbody > tr.even > td.priority-medium',
      OrderType::PROFILE_COMMON
    );
    // Assert the profile ID.
    $web_assert->elementAttributeContains(
      'css',
      'body > div > div > main > div > div > table > tbody > tr.even > td:nth-child(1) > a',
      'href',
      '/profile/2'
    );
  }

  /**
   * Assert existing orders have been migrated to use split profile types.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   */
  protected function assertMigratingExistingOrders(OrderInterface $order) {
    $web_assert = $this->assertSession();

    // Assert that we have a customer_billing profile type.
    /** @var \Drupal\profile\Entity\ProfileTypeInterface $profile_type */
    $profile_type = \Drupal::service('entity_type.manager')
      ->getStorage('profile_type')
      ->load(OrderType::PROFILE_BILLING);
    $this->assertNotNull($profile_type);

    // Assert that we have a customer_shipping profile type.
    /** @var \Drupal\profile\Entity\ProfileTypeInterface $profile_type */
    $profile_type = \Drupal::service('entity_type.manager')
      ->getStorage('profile_type')
      ->load(OrderType::PROFILE_SHIPPING);
    $this->assertNotNull($profile_type);

    // Now, let's test that the order profiles has been changed for existing
    // orders and now has split profile types for billing and shipping.
    $controller = \Drupal::service('entity.manager')
      ->getStorage($order->getEntityTypeId());
    $controller->resetCache([$order->id()]);
    $order = $controller->load($order->id());

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

    // Now, let's assert this via the display. Ensure we have 2 profiles but
    // they're now split profile types.
    $this->drupalGet('/admin/people/profiles');
    // Assert that we have only 2 profiles created.
    $web_assert->elementsCount('css', 'td.priority-medium', 2);
    // First row, the shipping profile comes first.
    // Assert the profile type is 'customer_shipping'.
    $web_assert->elementTextContains(
      'css',
      'body > div > div > main > div > div > table > tbody > tr.odd > td.priority-medium',
      OrderType::PROFILE_SHIPPING
    );
    // Assert the profile ID, to ensure it hasn't changed.
    $web_assert->elementAttributeContains(
      'css',
      'body > div > div > main > div > div > table > tbody > tr.odd > td:nth-child(1) > a',
      'href',
      '/profile/1'
    );
    // Second row, this is the billing profile.
    // Assert the profile type is 'customer_billing'.
    $web_assert->elementTextContains(
      'css',
      'body > div > div > main > div > div > table > tbody > tr.even > td.priority-medium',
      OrderType::PROFILE_BILLING
    );
    // Assert the profile ID.
    $web_assert->elementAttributeContains(
      'css',
      'body > div > div > main > div > div > table > tbody > tr.even > td:nth-child(1) > a',
      'href',
      '/profile/2'
    );
  }

  /**
   * Create an order.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The newly created order.
   */
  protected function createOrder() {
    $this->drupalGet($this->firstProduct->toUrl()->toString());
    $this->submitForm([], 'Add to cart');

    $this->drupalGet('checkout/1');

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

    // Submit the form.
    $this->submitForm([], 'Pay and complete purchase');

    $order = Order::load(1);
    return $order;
  }

}
