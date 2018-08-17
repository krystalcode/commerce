<?php

namespace Drupal\commerce_order;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_order\Entity\OrderTypeInterface;
use Drupal\profile\Entity\ProfileInterface;

/**
 * Class MigrateExistingOrderProfiles.
 *
 * Migrates the existing order profiles to use billing/shipping profiles types.
 *
 * @package Drupal\commerce_order
 */
class MigrateExistingOrderProfiles {

  /**
   * Batch processing callback for migrating order profiles.
   *
   * Profiles that can be clearly associated only with shipping information or
   * only with billing information are migrated accordingly, keeping their IDs.
   *
   * Profiles that are associated with both shipping and billing information are
   * handled differently. The original is migrated to be a billing profile. Then
   * a copy is created that is migrated to be a shipping profile and the order
   * shipments that use it are updated to use the new one.
   *
   * @param array $order_ids
   *   An array of order IDs.
   * @param \Drupal\commerce_order\Entity\OrderTypeInterface $order_type
   *   The commerce_order_type entity.
   * @param array $context
   *   The batch context array.
   */
  public static function migrateProfiles(
    array $order_ids,
    OrderTypeInterface $order_type,
    array &$context
  ) {
    $message = 'Migrating existing order profiles to use separate profile types
     for billing and shipping...';

    $results = [];
    $order_storage = \Drupal::entityTypeManager()
      ->getStorage('commerce_order');
    foreach ($order_ids as $order_id) {
      /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
      $order_storage->load($order_id);

      $billing_profile = self::processBillingProfile($order);
      self::processShippingProfiles($order, $billing_profile);

      $results[] = $order->id();
    }

    $context['message'] = $message;
    $context['results'] = $results;
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      $message = \Drupal::translation()->translate(
        'The following orders were successfully migrated to
          use split profiles for billing and shipping: @order_ids.', [
            '@order_ids' => implode(', ', $results),
          ]
      );
    }
    else {
      $error_operation = reset($operations);
      $message = \Drupal::translation()->translate(
        'An error occurred while processing @operation with arguments: 
          @args for the following order IDs: @order_ids.',
        [
          '@operation' => $error_operation[0],
          '@args' => print_r($error_operation[0], TRUE),
          '@order_ids' => implode(', ', $results),
        ]
      );
    }
    \Drupal::messenger()->addMessage($message);
  }

  /**
   * Processes the billing profile for an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return \Drupal\profile\Entity\ProfileInterface
   *   The saved billing profile.
   */
  protected function processBillingProfile(OrderInterface $order) {
    // Grab the billing profile.
    /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
    $billing_profile = $order->getBillingProfile();
    if ($billing_profile) {
      // Change the billing profile from the customer profile type to the
      // customer_billing profile type.
      $billing_profile->set('type', OrderType::PROFILE_BILLING);
      $billing_profile->save();
    }

    return $billing_profile;
  }

  /**
   * Processes the billing profile for an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param \Drupal\profile\Entity\ProfileInterface $billing_profile
   *   The billing profile entity.
   */
  protected function processShippingProfiles(OrderInterface $order, ProfileInterface $billing_profile) {
    // Grab the shipping profiles.
    if (!$order->shipments) {
      return;
    }

    foreach ($order->shipments->referencedEntities() as $shipment) {
      /** @var \Drupal\profile\Entity\ProfileInterface $shipping_profile */
      $shipping_profile = $shipment->getShippingProfile();

      // We have distinct billing and shipping profile references.
      if ($shipping_profile->id() != $billing_profile->id()) {
        // Change the shipping profile from the customer profile type to the
        // customer_shipping profile type.
        $shipping_profile->set('type', OrderType::PROFILE_SHIPPING);
        $shipping_profile->save();
      }
      // Else, if the shipment references the same billing profile, create
      // and save a new duplicate and reference this new shipping profile.
      else {
        /** @var \Drupal\profile\Entity\ProfileInterface $new_shipping_profile */
        $new_shipping_profile = $shipping_profile->createDuplicate();

        // Change the shipping profile from the customer profile type to the
        // customer_shipping profile type.
        $new_shipping_profile->set('type', OrderType::PROFILE_SHIPPING);
        $new_shipping_profile->save();

        /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
        $shipment->setShippingProfile($new_shipping_profile);
        $shipment->save();
      }
    }
  }

}
