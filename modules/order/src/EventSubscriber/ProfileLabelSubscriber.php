<?php

namespace Drupal\commerce_order\EventSubscriber;

use Drupal\commerce_order\Entity\OrderTypeInterface;
use Drupal\profile\Event\ProfileLabelEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProfileLabelSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = [
      'profile.label' => 'onLabel',
    ];
    return $events;
  }

  /**
   * Sets the customer profile label to the first address line.
   *
   * @param \Drupal\profile\Event\ProfileLabelEvent $event
   *   The profile label event.
   */
  public function onLabel(ProfileLabelEvent $event) {
    /** @var \Drupal\profile\Entity\ProfileInterface $order */
    $profile = $event->getProfile();

    $supported_profile_types = [
      OrderTypeInterface::PROFILE_COMMON,
      OrderTypeInterface::PROFILE_BILLING,
      OrderTypeInterface::PROFILE_SHIPPING,
    ];

    if (!in_array($profile->bundle(), $supported_profile_types)) {
      return;
    }

    if ($profile->address->isEmpty()) {
      return;
    }

    $event->setLabel($profile->address->address_line1);
  }

}
