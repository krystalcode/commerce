<?php

namespace Drupal\Tests\commerce_order\Kernel;

use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_order\Form\MultipleProfileTypesConfirmForm;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\Shipment;
use Drupal\commerce_shipping\ShipmentItem;
use Drupal\commerce_shipping\Entity\ShippingMethod;
use Drupal\physical\Weight;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\commerce_shipping\Kernel\ShippingKernelTestBase;

/**
 * Tests the multiple profile types functionality.
 *
 * @group commerce
 */
class MultipleProfileTypesTest extends ShippingKernelTestBase {

  /**
   * A sample shipment.
   *
   * @var \Drupal\commerce_shipping\Entity\ShipmentInterface
   */
  protected $shipment;

  /**
   * A sample order.
   *
   * @var \Drupal\commerce_order\Entity\OrderTypeInterface
   */
  protected $order_type;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->installEntitySchema('commerce_shipping_method');

    // Load the default order type.
    $order_type_storage = \Drupal::entityTypeManager()
      ->getStorage('commerce_order_type');
    /** @var \Drupal\commerce_order\Entity\OrderType $order_type */
    $order_type = $order_type_storage->load('default');
    $this->order_type = $order_type;
  }

  /**
   * Test the order only has a single profile type for billing and shipping.
   */
  public function testOrderSingleProfileType() {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    // Create an order.
    $order = $this->createOrder();

    // Create the billing and shipping profiles.
    $order = $this->createProfiles($order);

    // Now, let's test that by default the order type uses a single profile type
    // for both billing and shipping.
    // Billing.
    /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
    $billing_profile = $order->getBillingProfile();

    // Assert that the billing profile type for this order is 'customer'.
    $this->assertEquals(OrderType::PROFILE_COMMON, $billing_profile->bundle());

    // Assert the address is what we're expecting.
    $this->assertEquals(
      'US',
      $billing_profile->address->first()->getCountryCode()
    );

    // Shipping.
    foreach ($order->shipments->referencedEntities() as $shipment) {
      /** @var \Drupal\profile\Entity\ProfileInterface $shipping_profile */
      $shipping_profile = $shipment->getShippingProfile();

      // Assert that the shipping profile type for this order is 'customer'.
      $this->assertEquals(OrderType::PROFILE_COMMON, $shipping_profile->bundle());

      // Assert the address is what we're expecting.
      $this->assertEquals(
        'FR',
        $shipping_profile->address->first()->getCountryCode()
      );
    }
  }

  /**
   * Tests the creation of the new billing and shipping profile types.
   */
  public function testProfileTypesCreation() {
    // Create the new profile types.
    $profile_types_confirm_form = new MultipleProfileTypesConfirmForm(
      \Drupal::service('current_route_match'),
      \Drupal::service('entity_type.manager'),
      \Drupal::service('entity_field.manager')
    );
    $profile_types_confirm_form->createProfileTypes();

    // Assert that we have a customer_billing profile type.
    /** @var \Drupal\profile\Entity\ProfileTypeInterface $profile_type */
    $profile_type = \Drupal::service('entity_type.manager')
      ->getStorage('profile_type')
      ->load(OrderType::PROFILE_BILLING);
    $this->assertNotNull($profile_type);
    $this->assertEquals(OrderType::PROFILE_BILLING, $profile_type->id());
    $this->assertEquals(t('Customer Billing'), $profile_type->label());
    // Assert that the address field exists.
    $field_definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('profile', OrderType::PROFILE_BILLING);
    $this->assertNotNull($field_definitions['address']);

    // Assert that we have a customer_shipping profile type.
    /** @var \Drupal\profile\Entity\ProfileTypeInterface $profile_type */
    $profile_type = \Drupal::service('entity_type.manager')
      ->getStorage('profile_type')
      ->load(OrderType::PROFILE_SHIPPING);
    $this->assertNotNull($profile_type);
    $this->assertEquals(OrderType::PROFILE_SHIPPING, $profile_type->id());
    $this->assertEquals(t('Customer Shipping'), $profile_type->label());
    // Assert that the address field exists.
    $field_definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('profile', OrderType::PROFILE_SHIPPING);
    $this->assertNotNull($field_definitions['address']);
  }

  /**
   * Test the order has multiple profile types for billing and shipping.
   */
  public function testOrderMultipleProfileTypes() {
    // First, create our new profile types.
    $profile_types_confirm_form = new MultipleProfileTypesConfirmForm(
      \Drupal::service('current_route_match'),
      \Drupal::service('entity_type.manager'),
      \Drupal::service('entity_field.manager')
    );
    $profile_types_confirm_form->createProfileTypes();

    // Now, change the order type to use multiple profile types.
    /** @var \Drupal\commerce_order\Entity\OrderType $order_type */
    $order_type = $this->order_type;
    $order_type->setUseMultipleProfileTypes(TRUE);
    $order_type->save();
    $this->order_type = $this->reloadEntity($order_type);

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    // Create an order.
    $order = $this->createOrder();

    // Create the billing and shipping profiles.
    $order = $this->createProfiles($order);

    // Now, let's test that the order type now uses multiple profile types for
    // both billing and shipping.
    // Billing.
    /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
    $billing_profile = $order->getBillingProfile();

    // Assert that the billing profile type for this order is
    // 'customer_billing'.
    $this->assertEquals(OrderType::PROFILE_BILLING, $billing_profile->bundle());

    // Assert the address is what we're expecting.
    $this->assertEquals(
      'US',
      $billing_profile->address->first()->getCountryCode()
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
        'FR',
        $shipping_profile->address->first()->getCountryCode()
      );
    }
  }

  /**
   * Create an order.
   *
   * @return \Drupal\commerce_order\Entity\Order
   *   The newly created order entity.
   */
  protected function createOrder() {
    // Create a product variation and order item.
    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'test-product-01',
      'title' => 'Hat',
      'price' => new Price('10', 'USD'),
      'weight' => new Weight('10', 'g'),
    ]);
    $variation->save();

    $order_item = OrderItem::create([
      'type' => 'default',
      'quantity' => 2,
      'title' => $variation->getOrderItemTitle(),
      'purchased_entity' => $variation,
      'unit_price' => new Price('10', 'USD'),
    ]);
    $order_item->save();

    // Create the order.
    /** @var \Drupal\commerce_order\Entity\Order $order */
    $user = $this->createUser(['mail' => $this->randomString() . '@example.com']);
    $order = Order::create([
      'type' => 'default',
      'state' => 'draft',
      'mail' => $user->getEmail(),
      'uid' => $user->id(),
      'ip_address' => '127.0.0.1',
      'store_id' => $this->store->id(),
    ]);
    $order->save();
    $order = $this->reloadEntity($order);

    // Create a shipment with a shipping method.
    /** @var \Drupal\commerce_shipping\Entity\ShippingMethodInterface $shipping_method */
    $shipping_method = ShippingMethod::create([
      'name' => $this->randomString(),
      'plugin' => [
        'target_plugin_id' => 'flat_rate',
        'target_plugin_configuration' => [],
      ],
      'status' => 1,
    ]);
    $shipping_method->save();

    $shipment = Shipment::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'shipping_method' => $shipping_method,
      'items' => [
        new ShipmentItem([
          'order_item_id' => $order_item->id(),
          'title' => 'Hat',
          'quantity' => 1,
          'weight' => new Weight('10', 'kg'),
          'declared_value' => new Price('10', 'USD'),
        ]),
      ],
    ]);
    $shipment->save();
    $this->shipment = $this->reloadEntity($shipment);
    $order->set('shipments', [$shipment]);
    $order->save();
    $order = $this->reloadEntity($order);

    return $order;
  }

  /**
   * Create a billing and shipping profile and save it to the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity that we'll save the profiles to.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The updated order entity.
   */
  protected function createProfiles(OrderInterface $order) {
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $shipment = $this->shipment;

    // Create a billing profile and save it to the order.
    /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
    $billing_profile = Profile::create([
      'type' => $this->order_type->getBillingProfileTypeId(),
      'address' => [
        'country_code' => 'US',
      ],
    ]);
    $billing_profile->save();
    $billing_profile = $this->reloadEntity($billing_profile);
    $order->setBillingProfile($billing_profile);
    $order->save();
    $order = $this->reloadEntity($order);

    // Create a shipping profile and save it to the shipment.
    /** @var \Drupal\profile\Entity\ProfileInterface $profile */
    $shipping_profile = Profile::create([
      'type' => $this->order_type->getShippingProfileTypeId(),
      'address' => [
        'country_code' => 'FR',
      ],
    ]);
    $shipping_profile->save();
    $shipping_profile = $this->reloadEntity($shipping_profile);
    $shipment->setShippingProfile($shipping_profile);
    $shipment->save();
    $this->shipment = $this->reloadEntity($shipment);

    return $order;
  }

}
