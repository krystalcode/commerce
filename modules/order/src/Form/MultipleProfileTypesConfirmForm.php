<?php

namespace Drupal\commerce_order\Form;

use Drupal\commerce_order\Entity\OrderType;
use Drupal\profile\Entity\ProfileTypeInterface;

use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\CurrentRouteMatch;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A form to confirm the use of multiple profile types for shipping and billing.
 */
class MultipleProfileTypesConfirmForm extends ConfirmFormBase {

  /**
   * The current order type.
   *
   * @var \Drupal\commerce_order\Entity\OrderTypeInterface
   */
  protected $entity;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $entityFieldManager;

  /**
   * Constructs a ContentEntityForm object.
   *
   * @param \Drupal\Core\Routing\CurrentRouteMatch $current_route_match
   *   The current route match.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManager $entity_field_manager
   *   The configurable field manager service.
   */
  public function __construct(
    CurrentRouteMatch $current_route_match,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManager $entity_field_manager
  ) {
    $this->entity = $current_route_match->getParameter('commerce_order_type');
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('commerce.configurable_field_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    // Check if there are existing orders on this order type.
    $description = $this->getExistingOrderCountDescription();

    $description .= '<strong>'
      . $this->t('This action cannot be undone. You cannot switch back to
       using a single profile type again.')
      . '</strong>';

    return $description;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() : string {
    return 'commerce_order_type_use_multiple_profile_types_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to switch to using multiple
      profile types for shipping and billing for the %label order type?', [
        '%label' => $this->entity->label(),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Switch to Multiple Profile Types');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->entity->toUrl('collection');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Check if we have incompatible module versions as modules like Commerce
    // Shipping and Commerce POS will be affected when we switch to split
    // profiles.
    $incompatible_modules = $this->getIncompatibleModules();

    // If we have modules that are running incompatible versions, output a
    // warning message to the user.
    if ($incompatible_modules) {
      $this->messenger()->addWarning($this->t('The following modules are
        running versions that are incompatible with using multiple profile types
        and it could possibly render the site as unusable.
        <p><strong>@modules</strong></p>
        <p>Please upgrade and try again.</p>', [
          '@modules' => implode('<br>', $incompatible_modules)
        ]
      ));

      return;
    }

    $this->messenger()->addWarning($this->t('
      If you choose to proceed, profiles for existing orders will be migrated to
      use separate profile types. That can take some time and it might cause 
      errors if the users try to view their existing orders during the process;
      if you are running this on a production site please make sure that you are
      in maintenance mode.'
    ));

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = $this->entity;

    // Create the billing and shipping profile types.
    $this->createProfileTypes();

    // Batch process to migrate the existing order profiles from the customer
    // profile type to use the billing/shipping profile types.
    $this->migrateExistingProfiles();

    // Set the useMultipleProfileTypes field to TRUE now that we've processed
    // everything.
    $order_type->setUseMultipleProfileTypes(TRUE);
    $order_type->save();

    $form_state->setRedirectUrl($order_type->toUrl('collection'));
  }

  /**
   * Create the billing and shipping profile types, if not already created.
   */
  public function createProfileTypes() {
    $profile_types = [
      OrderType::PROFILE_BILLING => $this->t('Customer Billing'),
      OrderType::PROFILE_SHIPPING => $this->t('Customer Shipping'),
    ];

    // Load the 'customer' profile type so we can just duplicate that for the
    // new billing and shipping profile types.
    $profile_type_storage = $this->entityTypeManager->getStorage('profile_type');
    /** @var \Drupal\commerce_order\Entity\OrderType $order_type */
    $customer_profile_type = $profile_type_storage->load(OrderType::PROFILE_COMMON);

    // Fetch the non-base fields from the 'customer' profile type so we can copy
    // the same to the new profile types.
    $extra_fields_to_add = [];
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('profile', OrderType::PROFILE_COMMON);
    foreach ($field_definitions as $field_name => $field_definition) {
      if ($field_definition->getFieldStorageDefinition()->isBaseField()) {
        continue;
      }

      $extra_fields_to_add[$field_name] = $field_definition;
    }

    // Now create the profile type.
    foreach ($profile_types as $id => $profile_type) {
      $this->createProfileType([$id => $profile_type], $customer_profile_type, $extra_fields_to_add);
      $this->createProfileDisplayModes([$id => $profile_type]);
    }
  }

  /**
   * Creates a new profile type entity from the base 'customer' profile.
   *
   * @param array $profile_type
   *   The profile type to add, keyed on the profile type ID.
   * @param \Drupal\profile\Entity\ProfileTypeInterface $customer_profile_type
   *   The customer profile type.
   * @param array $extra_fields_to_add
   *   An array of field definitions to add to the new profile type.
   */
  protected function createProfileType(
    array $profile_type,
    ProfileTypeInterface $customer_profile_type,
    array $extra_fields_to_add
  ) {
    // If the profile type is already created, return.
    $bundle = key($profile_type);
    $profile_type_exists = $this->entityTypeManager->getStorage('profile_type')->load($bundle);
    if ($profile_type_exists) {
      return;
    }

    // Create the new profile type.
    $new_profile_type = $customer_profile_type->createDuplicate();
    $new_profile_type->set('id', $bundle);
    $new_profile_type->set('label', $profile_type[$bundle]);
    $new_profile_type->save();

    // Now add the non-base fields to this new profile type.
    foreach ($extra_fields_to_add as $field_name => $field_definition) {
      $new_field_definition = $field_definition->createDuplicate();
      $new_field_definition->set('entity_type', 'profile');
      $new_field_definition->set('bundle', $bundle);
      $new_field_definition->save();
    }
  }

  /**
   * Create the display modes for the new profile type from the original.
   *
   * @param array $profile_type
   *   The profile type to add, keyed on the profile type ID.
   */
  protected function createProfileDisplayModes(array $profile_type) {
    $bundle = key($profile_type);
    $entity_type = 'profile';

    $display_mode_types = [
      'entity_view_display',
      'entity_form_display',
    ];

    foreach ($display_mode_types as $display_mode_type) {
      // Fetch all the view display modes the 'customer' profile type has.
      $entity_display_modes = $this
        ->entityTypeManager
        ->getStorage($display_mode_type)
        ->loadByProperties(
          [
            'targetEntityType' => $entity_type,
            'bundle' => OrderType::PROFILE_COMMON,
          ]
        );

      // Save each entity display mode in our new profile type.
      foreach ($entity_display_modes as $mode) {
        $view_mode = $mode->getMode();

        // Create the new display mode in the new profile type, if it doesn't
        // exist.
        $new_entity_display = $this
          ->entityTypeManager
          ->getStorage($display_mode_type)
          ->load($entity_type . '.' . $bundle . '.' . $view_mode);

        if (empty($new_entity_display)) {
          $values = array(
            'targetEntityType' => $entity_type,
            'bundle' => $bundle,
            'mode' => $view_mode,
            'status' => TRUE,
          );
          $new_entity_display = $this
            ->entityTypeManager
            ->getStorage($display_mode_type)
            ->create($values);
        }

        // Now, let's also copy the fields in the display mode.
        $fields_to_copy = $mode->getComponents();
        // Remove the components first, in case, it already has some fields as
        // they might not be in the correct order as the original profile's.
        foreach ($fields_to_copy as $key => $value) {
          $new_entity_display->removeComponent($key, $value);
        }

        // Now, save the components in the correct order again.
        foreach ($fields_to_copy as $key => $value) {
          $new_entity_display->setComponent($key, $value);
        }

        // Finally, save the new display mode.
        $new_entity_display->save();
      }
    }
  }

  /**
   * Migrate the existing order profiles to use split shipping/billing profiles.
   *
   * We'll be using a batch_process to do this as we might have lots of orders.
   */
  protected function migrateExistingProfiles() {
    $order_ids = $this->getExistingOrders();

    $batch = [
      'title' => t('Migrating Order Profiles...'),
      'operations' => [
        [
          '\Drupal\commerce_order\MigrateExistingOrderProfiles::migrateProfiles',
          [
            $order_ids,
            $this->entity,
          ],
        ],
      ],
      'finished' => '\Drupal\commerce_order\MigrateExistingOrderProfiles::batchFinished',
    ];

    batch_set($batch);
  }

  /**
   * Get the existing orders for this order type.
   *
   * @return array
   *   Returns an array of order IDs.
   */
  protected function getExistingOrders() {
    /** @var \Drupal\Core\Config\Entity\ConfigEntityType $bundle_entity_type */
    $bundle_entity_type = $this->entityTypeManager->getDefinition($this->entity->getEntityTypeId());
    /** @var \Drupal\Core\Entity\ContentEntityType $content_entity_type */
    $content_entity_type = $this->entityTypeManager->getDefinition($bundle_entity_type->getBundleOf());
    $orders = $this->entityTypeManager->getStorage($content_entity_type->id())
      ->getQuery()
      ->condition($content_entity_type->getKey('bundle'), $this->entity->id())
      ->execute();

    return $orders;
  }

  /**
   * Returns a description on existing orders for this order type.
   *
   * @return string
   *   The description text.
   */
  protected function getExistingOrderCountDescription() {
    $description = '';

    $orders = $this->getExistingOrders();

    if ($order_count = count($orders)) {
      $description = '<p>' . $this->formatPlural($order_count,
          'The %type order type contains 1 order on your site.',
          'The %type order type contains @count orders on your site.',
          [
            '%type' => $this->entity->label(),
          ]) . '</p>';

      $description .= '<p>' . $this->t('All of these existing orders will
        be migrated to use the split shipping and billing profile types.'
        ) . '</p>';
    }

    return $description;
  }

  /**
   * Get all modules that will be affected and incompatible with the switch.
   *
   * @return array
   *   An array of module names and the expected versions.
   */
  protected function getIncompatibleModules() {
    $incompatible_modules = [];

    // TODO: We don't know yet which module version will be supporting multiple
    // profile types yet. Setting to empty for now.
    $affected_modules = [
      'commerce_pos' => '',
      'commerce_shipping' => '',
      'commerce_amws' => '',
      'commerce_qb_webconnect' => '',
    ];

    foreach ($affected_modules as $module => $expected_version) {
      $module_info = system_get_info('module', $module);
      if (empty($module_info)) {
        continue;
      }

      if (empty($module_info['version'])) {
        continue;
      }

      if (empty($expected_version)) {
        $incompatible_modules[] = $this->t(
          '@module_name does not have a compatible version yet', [
            '@module_name' => $module,
          ]
        );
      }

      // TODO: Find a reliable way to extract the module version.
      $current_version = substr($module_info['version'], 4);

      if (version_compare($current_version, $expected_version, '<')) {
        $incompatible_modules[] = $this->t(
          '@module_name at least version 8.x-@version or higher', [
            '@module_name' => $module,
            '@version' => $expected_version,
          ]
        );
      }
    }

    return $incompatible_modules;
  }

}
