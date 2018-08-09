<?php

namespace Drupal\commerce_order\Entity;

use Drupal\commerce\Entity\CommerceBundleEntityInterface;

/**
 * Defines the interface for order types.
 */
interface OrderTypeInterface extends CommerceBundleEntityInterface {

  // Refresh modes.
  const REFRESH_ALWAYS = 'always';
  const REFRESH_CUSTOMER = 'customer';

  /**
   * Gets the order type's workflow ID.
   *
   * Used by the $order->state field.
   *
   * @return string
   *   The order type workflow ID.
   */
  public function getWorkflowId();

  /**
   * Sets the workflow ID of the order type.
   *
   * @param string $workflow_id
   *   The workflow ID.
   *
   * @return $this
   */
  public function setWorkflowId($workflow_id);

  /**
   * Whether we should use a single profile for both shipping and billing.
   *
   * @return bool
   *   TRUE if we should only use a single profile for this order type.
   */
  public function useSingleProfile();

  /**
   * Sets whether we should use a single profile for both shipping and billing.
   *
   * @param bool $use_single_profile
   *   Boolean indicating if we should use a single profile.
   *
   * @return $this
   */
  public function setUseSingleProfile($use_single_profile);

  /**
   * Gets the order type's billing profile ID.
   *
   * Dependent on the useSingleProfile field on the order_type.
   *
   * @return string
   *   The order type's billing profile ID name.
   */
  public function getBillingProfileId();

  /**
   * Gets the order type's shipping profile ID.
   *
   * Dependent on the useSingleProfile field on the order_type.
   *
   * @return string
   *   The order type's shipping profile ID name.
   */
  public function getShippingProfileId();

  /**
   * Gets the order type's refresh mode.
   *
   * Used by the order refresh process.
   *
   * @return string
   *   The refresh mode.
   */
  public function getRefreshMode();

  /**
   * Sets the refresh mode for the order type.
   *
   * @param string $refresh_mode
   *   The refresh mode.
   *
   * @return $this
   */
  public function setRefreshMode($refresh_mode);

  /**
   * Gets the order type's refresh frequency.
   *
   * @return int
   *   The frequency, in seconds.
   */
  public function getRefreshFrequency();

  /**
   * Sets the refresh frequency for the order type.
   *
   * @param int $refresh_frequency
   *   The frequency, in seconds.
   *
   * @return $this
   */
  public function setRefreshFrequency($refresh_frequency);

  /**
   * Gets whether to email the customer a receipt when an order is placed.
   *
   * @return bool
   *   TRUE if the receipt email should be sent, FALSE otherwise.
   */
  public function shouldSendReceipt();

  /**
   * Sets whether to email the customer a receipt when an order is placed.
   *
   * @param bool $send_receipt
   *   TRUE if the receipt email should be sent, FALSE otherwise.
   *
   * @return $this
   */
  public function setSendReceipt($send_receipt);

  /**
   * Gets the receipt BCC email.
   *
   * If provided, this email will receive a copy of the receipt email.
   *
   * @return string
   *   The receipt BCC email.
   */
  public function getReceiptBcc();

  /**
   * Sets the receipt BCC email.
   *
   * @param string $receipt_bcc
   *   The receipt BCC email.
   *
   * @return $this
   */
  public function setReceiptBcc($receipt_bcc);

}
