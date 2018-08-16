<?php

namespace Drupal\commerce_order\Form;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\CurrentRouteMatch;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A form to confirm the use of multiple profiles for shipping and billing.
 */
class UseMultiProfileConfirmForm extends ConfirmFormBase {

  /**
   * The current order type.
   *
   * @var \Drupal\commerce_order\Entity\OrderTypeInterface
   */
  protected $entity;

  /**
   * The config storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $configStorage;

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
   * @param \Drupal\Core\Config\StorageInterface $config_storage
   *   The config storage.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManager $entity_field_manager
   *   The configurable field manager service.
   */
  public function __construct(
    CurrentRouteMatch $current_route_match,
    StorageInterface $config_storage,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManager $entity_field_manager
  ) {
    $this->entity = $current_route_match->getParameter('commerce_order_type');
    $this->configStorage = $config_storage;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_route_match'),
      $container->get('config.storage'),
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
       using the single profile again.')
      . '</strong>';

    return $description;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() : string {
    return $this->entity->getEntityTypeId() . '_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to switch to using multiple
      profiles for shipping and billing for the %label order type?', [
        '%label' => $this->entity->label(),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Switch to Using Multiple Profiles');
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
        running incompatible versions for the switch to split profiles and it
        could possibly render the site as unusable.
        <p><strong>@modules</strong></p>
        <p>Please upgrade and try again.</p>', [
          '@modules' => implode('<br>', $incompatible_modules)
        ]
      ));

      return;
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = $this->entity;

    // Create the billing and shipping profile types.
    $this->createBillingShippingProfiles();

    // Migrate any fields added to the customer profile type to the billing and
    // shipping profile types.
    $this->migrateProfileFields();

    // Batch process to migrate the existing order profiles from the customer
    // profile type to use the billing/shipping profile types.
    $this->migrateExistingProfiles();

    // Set the useSingleProfile field to FALSE now that we've processed
    // everything.
    $order_type->setUseSingleProfile(FALSE);
    $order_type->save();

    $form_state->setRedirectUrl($order_type->toUrl('collection'));
  }

  /**
   * Create the billing and shipping profile types, if not already created.
   */
  protected function createBillingShippingProfiles() {
    $profile_type_storage = $this->entityTypeManager->getStorage('profile_type');
    /** @var \Drupal\profile\Entity\ProfileTypeInterface $billing_profile_type */
    $billing_profile_type = $profile_type_storage->load('customer_billing');

    // Import YAML config.
    $config_path = drupal_get_path('module',
        'commerce_order') . '/config/shipping_billing_profiles';
    $source = new FileStorage($config_path);
    $config_storage = $this->configStorage;

    if (!$billing_profile_type) {
      $billing_configs = [
        'core.entity_form_display.profile.customer_billing.default',
        'core.entity_view_display.profile.customer_billing.default',
        'field.field.profile.customer_billing.address',
        'profile.type.customer_billing',
      ];
      foreach ($billing_configs as $config_name) {
        $config_storage->write($config_name, $source->read($config_name));
      }
    }

    /** @var \Drupal\profile\Entity\ProfileTypeInterface $shipping_profile_type */
    $shipping_profile_type = $profile_type_storage->load('customer_shipping');

    if (!$shipping_profile_type) {
      $shipping_configs = [
        'core.entity_form_display.profile.customer_shipping.default',
        'core.entity_view_display.profile.customer_shipping.default',
        'field.field.profile.customer_shipping.address',
        'profile.type.customer_shipping',
      ];
      foreach ($shipping_configs as $config_name) {
        $config_storage->write($config_name, $source->read($config_name));
      }
    }

    if (!$billing_profile_type || !$shipping_profile_type) {
      $this->messenger()->addMessage($this->t('Billing and shipping profile
        types have been successfully created.'
      ));
    }
  }

  /**
   * Migrate the fields on the customer profile to the newly created types.
   *
   * We only migrate the user-defined fields added to the customer profile type
   * to the billing and shipping profile types.
   */
  protected function migrateProfileFields() {
    // Grab the field definitions from the customer profile type.
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('profile', 'customer');

    // Let's copy the fields in the 'customer' profile to the billing/shipping
    // profile types.
    $profile_bundles = [
      'customer_billing',
      'customer_shipping',
    ];
    foreach ($profile_bundles as $bundle) {
      // Grab the field definitions from the customer profile type.
      $existing_field_definitions = $this->entityFieldManager->getFieldDefinitions('profile', $bundle);

      foreach ($field_definitions as $field_name => $field_definition) {
        /** @var \Drupal\Core\Field\FieldDefinitionInterface $field_definition */
        // Don't copy the base fields and the address field.
        if ($field_definition->getFieldStorageDefinition()->isBaseField() == TRUE) {
          continue;
        }

        // If the field already exists on the profile type, move on.
        if (isset($existing_field_definitions[$field_name])) {
          continue;
        }

        $new_field_definition = $field_definition->createDuplicate();
        $new_field_definition->set('entity_type', 'profile');
        $new_field_definition->set('bundle', $bundle);
        $new_field_definition->save();
      }
    }

    $this->messenger()->addMessage($this->t('Fields from the customer 
      profile have been successfully copied to the billing and shipping profile
      entities.'
    ));
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
        be migrated to use the split shipping and billing profiles.'
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

    $affected_modules = [
      'commerce_pos' => '2.2',
      'commerce_shipping' => '2.2',
      'commerce_amws' => '2.2',
    ];

    foreach ($affected_modules as $module => $expected_version) {
      $module_info = system_get_info('module', $module);
      if (empty($module_info)) {
        continue;
      }

      if (empty($module_info['version'])) {
        continue;
      }

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
