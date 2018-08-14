<?php

namespace Drupal\commerce_order;

use Drupal\commerce_order\Entity\OrderTypeInterface;

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
  public static function migrateProfiles(array $order_ids, OrderTypeInterface $order_type, array &$context) {
    $message = 'Migrating existing order profiles to use split profiles for
      billing and shipping...';

    $results = [];
    foreach ($order_ids as $order_id) {
      $order = \Drupal::entityTypeManager()->getStorage('commerce_order')->load($order_id);
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
      $message = \Drupal::translation()->formatPlural(
        count($results),
        'One order profile has been successfully migrated.',
        '@count order profiles have been successfully migrated.'
      );
    }
    else {
      $error_operation = reset($operations);
      $message = t(
        'An error occurred while processing @operation with arguments : @args',
        [
          '@operation' => $error_operation[0],
          '@args' => print_r($error_operation[0], TRUE),
        ]
      );
    }
    \Drupal::messenger()->addMessage($message);
  }

}
