<?php

namespace Drupal\Tests\commerce_payment\Kernel;

use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\profile\Entity\Profile;
use Drupal\user\Entity\User;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;

/**
 * Tests the payment method storage.
 *
 * @group commerce
 */
class PaymentMethodStorageTest extends CommerceKernelTestBase {

  /**
   * A sample user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * A payment gateway.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface
   */
  protected $paymentGateway;

  /**
   * The payment method storage.
   *
   * @var \Drupal\commerce_payment\PaymentMethodStorageInterface
   */
  protected $storage;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'address',
    'entity_reference_revisions',
    'profile',
    'state_machine',
    'commerce_product',
    'commerce_order',
    'commerce_payment',
    'commerce_payment_example',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_payment_method');
    $this->installConfig('commerce_order');
    $this->installConfig('commerce_payment');

    // An order item type that doesn't need a purchasable entity, for simplicity.
    OrderItemType::create([
      'id' => 'test',
      'label' => 'Test',
      'orderType' => 'default',
    ])->save();

    $payment_gateway = PaymentGateway::create([
      'id' => 'example',
      'label' => 'Example',
      'plugin' => 'example_onsite',
    ]);
    $payment_gateway->getPlugin()->setConfiguration([
      'api_key' => '2342fewfsfs',
      'mode' => 'test',
      'payment_method_types' => ['credit_card'],
    ]);
    $payment_gateway->save();
    $this->paymentGateway = $this->reloadEntity($payment_gateway);

    $user = $this->createUser();
    $this->user = $this->reloadEntity($user);

    $this->storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment_method');
  }

  /**
   * Tests loading reusable payment methods.
   */
  public function testLoadReusable() {
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method_expired */
    $payment_method_expired = PaymentMethod::create([
      'type' => 'credit_card',
      'payment_gateway' => 'example',
      'payment_gateway_mode' => 'test',
      // Sat, 16 Jan 2016.
      'expires' => '1452902400',
      'uid' => $this->user->id(),
    ]);
    $payment_method_expired->save();
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method_active */
    $payment_method_active = PaymentMethod::create([
      'type' => 'credit_card',
      'payment_gateway' => 'example',
      'payment_gateway_mode' => 'test',
      // Thu, 16 Jan 2020.
      'expires' => '1579132800',
      'uid' => $this->user->id(),
    ]);
    $payment_method_active->save();
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method_unlimited */
    $payment_method_unlimited = PaymentMethod::create([
      'type' => 'credit_card',
      'payment_gateway' => 'example',
      'payment_gateway_mode' => 'test',
      'expires' => 0,
      'uid' => $this->user->id(),
    ]);
    $payment_method_unlimited->save();
    // Confirm that the expired payment method was not loaded.
    $reusable_payment_methods = $this->storage->loadReusable($this->user, $this->paymentGateway);
    $this->assertEquals([$payment_method_unlimited->id(), $payment_method_active->id()], array_keys($reusable_payment_methods));

    // Confirm that anonymous users cannot have reusable payment methods.
    $payment_method_active->setOwnerId(0);
    $payment_method_active->save();
    $payment_method_unlimited->setOwnerId(0);
    $payment_method_unlimited->save();
    $this->assertEmpty($this->storage->loadReusable(User::getAnonymousUser(), $this->paymentGateway));
    $this->assertEmpty($this->storage->loadReusable($this->user, $this->paymentGateway));

    // Changing the gateway from test to live should cause all of the testing
    // payment methods to be ignored.
    $payment_gateway_configuration = $this->paymentGateway->getPluginConfiguration();
    $payment_gateway_configuration['mode'] = 'live';
    $this->paymentGateway->getPlugin()->setConfiguration($payment_gateway_configuration);
    $this->paymentGateway->save();
    $reusable_payment_methods = $this->storage->loadReusable($this->user, $this->paymentGateway);
    $this->assertEmpty($reusable_payment_methods);
  }

  /**
   * Tests filtering reusable payment methods by billing country.
   */
  public function testBillingCountryFiltering() {
    /** @var \Drupal\profile\Entity\Profile $profile_fr */
    $profile_fr = Profile::create([
      'type' => OrderType::PROFILE_COMMON,
      'address' => [
        'organization' => '',
        'country_code' => 'FR',
        'postal_code' => '75002',
        'locality' => 'Paris',
        'address_line1' => 'A french street',
        'given_name' => 'John',
        'family_name' => 'LeSmith',
      ],
      'uid' => $this->user->id(),
    ]);
    $profile_fr->save();
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method_fr */
    $payment_method_fr = PaymentMethod::create([
      'type' => 'credit_card',
      'payment_gateway' => 'example',
      'expires' => '1579132800',
      'uid' => $this->user->id(),
      'billing_profile' => $profile_fr,
    ]);
    $payment_method_fr->save();

    $this->assertEmpty($this->storage->loadReusable($this->user, $this->paymentGateway, ['US']));

    $profile_us = Profile::create([
      'type' => OrderType::PROFILE_COMMON,
      'address' => [
        'country_code' => 'US',
        'postal_code' => '53177',
        'locality' => 'Milwaukee',
        'address_line1' => 'Pabst Blue Ribbon Dr',
        'administrative_area' => 'WI',
        'given_name' => 'Frederick',
        'family_name' => 'Pabst',
      ],
      'uid' => $this->user->id(),
    ]);
    $profile_us->save();
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method_fr */
    $payment_method_us = PaymentMethod::create([
      'type' => 'credit_card',
      'payment_gateway' => 'example',
      'expires' => '1579132800',
      'uid' => $this->user->id(),
      'billing_profile' => $profile_us,
    ]);
    $payment_method_us->save();

    $this->assertTrue($this->storage->loadReusable($this->user, $this->paymentGateway, ['US']));
  }

}
