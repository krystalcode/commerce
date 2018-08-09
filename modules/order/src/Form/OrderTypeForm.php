<?php

namespace Drupal\commerce_order\Form;

use Drupal\commerce\EntityTraitManagerInterface;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_order\Entity\OrderTypeInterface;
use Drupal\commerce\Form\CommerceBundleEntityFormBase;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an order type form.
 */
class OrderTypeForm extends CommerceBundleEntityFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $configStorage;

  /**
   * Constructs a new CommerceBundleEntityFormBase object.
   *
   * @param \Drupal\commerce\EntityTraitManagerInterface $trait_manager
   *   The entity trait manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\StorageInterface $config_storage
   *   The config storage.
   */
  public function __construct(
    EntityTraitManagerInterface $trait_manager,
    EntityTypeManagerInterface $entity_type_manager,
    StorageInterface $config_storage
  ) {
    parent::__construct($trait_manager);
    $this->entityTypeManager = $entity_type_manager;
    $this->configStorage = $config_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.commerce_entity_trait'),
      $container->get('entity_type.manager'),
      $container->get('config.storage')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = $this->entity;
    $workflow_manager = \Drupal::service('plugin.manager.workflow');
    $workflows = $workflow_manager->getGroupedLabels('commerce_order');

    $form['#tree'] = TRUE;
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $order_type->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $order_type->id(),
      '#machine_name' => [
        'exists' => '\Drupal\commerce_order\Entity\OrderType::load',
        'source' => ['label'],
      ],
      '#maxlength' => EntityTypeInterface::BUNDLE_MAX_LENGTH,
    ];
    $form['workflow'] = [
      '#type' => 'select',
      '#title' => $this->t('Workflow'),
      '#options' => $workflows,
      '#default_value' => $order_type->getWorkflowId(),
      '#description' => $this->t('Used by all orders of this type.'),
    ];
    $form = $this->buildTraitForm($form, $form_state);

    $form['useSingleProfile'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use a single profile for both billing and shipping'),
      '#default_value' => $order_type->useSingleProfile(),
    ];

    $form['refresh'] = [
      '#type' => 'details',
      '#title' => $this->t('Order refresh'),
      '#weight' => 5,
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#tree' => FALSE,
    ];
    $form['refresh']['refresh_intro'] = [
      '#markup' => '<p>' . $this->t('These settings let you control how draft orders are refreshed, the process during which prices are recalculated.') . '</p>',
    ];
    $form['refresh']['refresh_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Order refresh mode'),
      '#options' => [
        OrderType::REFRESH_ALWAYS => $this->t('Refresh a draft order when it is loaded regardless of who it belongs to.'),
        OrderType::REFRESH_CUSTOMER => $this->t('Only refresh a draft order when it is loaded if it belongs to the current user.'),
      ],
      '#default_value' => ($order_type->isNew()) ? OrderType::REFRESH_CUSTOMER : $order_type->getRefreshMode(),
    ];
    $form['refresh']['refresh_frequency'] = [
      '#type' => 'number',
      '#title' => t('Order refresh frequency'),
      '#description' => t('Draft orders will only be refreshed if more than the specified number of seconds have passed since they were last refreshed.'),
      '#default_value' => ($order_type->isNew()) ? 300 : $order_type->getRefreshFrequency(),
      '#required' => TRUE,
      '#min' => 1,
      '#size' => 10,
      '#field_suffix' => t('seconds'),
    ];

    $form['emails'] = [
      '#type' => 'details',
      '#title' => $this->t('Emails'),
      '#weight' => 5,
      '#open' => TRUE,
      '#collapsible' => TRUE,
      '#tree' => FALSE,
    ];
    $form['emails']['notice'] = [
      '#markup' => '<p>' . $this->t('Emails are sent in the HTML format. You will need a module such as <a href="https://www.drupal.org/project/swiftmailer">Swiftmailer</a> to send HTML emails.') . '</p>',
    ];
    $form['emails']['sendReceipt'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email the customer a receipt when an order is placed'),
      '#default_value' => ($order_type->isNew()) ? TRUE : $order_type->shouldSendReceipt(),
    ];
    $form['emails']['receiptBcc'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Send a copy of the receipt to this email:'),
      '#default_value' => ($order_type->isNew()) ? '' : $order_type->getReceiptBcc(),
      '#states' => [
        'visible' => [
          ':input[name="sendReceipt"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $this->protectBundleIdElement($form);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\state_machine\WorkflowManager $workflow_manager */
    $workflow_manager = \Drupal::service('plugin.manager.workflow');
    /** @var \Drupal\state_machine\Plugin\Workflow\WorkflowInterface $workflow */
    $workflow = $workflow_manager->createInstance($form_state->getValue('workflow'));
    // Verify "Place" transition.
    if (!$workflow->getTransition('place')) {
      $form_state->setError($form['workflow'], $this->t('The @workflow workflow does not have a "Place" transition.', [
        '@workflow' => $workflow->getLabel(),
      ]));
    }
    // Verify "draft" state.
    if (!$workflow->getState('draft')) {
      $form_state->setError($form['workflow'], $this->t('The @workflow workflow does not have a "Draft" state.', [
        '@workflow' => $workflow->getLabel(),
      ]));
    }
    $this->validateTraitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $status = $this->entity->save();
    $this->submitTraitForm($form, $form_state);

    // If the user has selected to use a single profile, let's create the two
    // new profiles, if not already created.
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = $this->entity;
    if (!$order_type->useSingleProfile()) {
      $this->createBillingShippingProfiles($order_type);
    }

    $this->messenger()->addMessage($this->t('Saved the %label order type.', ['%label' => $this->entity->label()]));
    $form_state->setRedirect('entity.commerce_order_type.collection');

    if ($status == SAVED_NEW) {
      commerce_order_add_order_items_field($this->entity);
    }
  }

  /**
   * Create the billing and shipping profiles, if not already created.
   *
   * @param \Drupal\commerce_order\Entity\OrderTypeInterface $order_type
   *   The order type entity.
   */
  protected function createBillingShippingProfiles(OrderTypeInterface $order_type) {
    $profile_type_storage = $this->entityTypeManager->getStorage('profile_type');
    /** @var \Drupal\profile\Entity\ProfileTypeInterface $billing_profile_type */
    $billing_profile_type = $profile_type_storage->load('customer_billing');

    // Import YAML config.
    $config_path = drupal_get_path('module', 'commerce_order') . '/config/shipping_billing_profiles';
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
  }

}
