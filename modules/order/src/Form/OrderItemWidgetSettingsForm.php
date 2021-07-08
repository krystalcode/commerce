<?php

namespace Drupal\commerce_order\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Entity\Server;
use Drupal\file\Entity\File;

/**
 * Configure example settings for this site.
 */
class OrderItemWidgetSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_order_item_widget_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'commerce_order_item_widget.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('commerce_order_item_widget.settings');

    $wrapper_id = 'product_search_settings';
    $form['product_search'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Product Search'),
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
    ];

    $server_storage = \Drupal::entityTypeManager()->getStorage('search_api_server');
    /** @var \Drupal\search_api\ServerInterface[] $servers */
    $servers = $server_storage->loadMultiple();

    $server_options = [];
    foreach ($servers as $server_item) {
      $server_options[$server_item->id()] = $server_item->get('name');
    }

    $form['product_search']['server'] = [
      '#type' => 'select',
      '#title' => $this->t('Server'),
      '#options' => $server_options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $config->get('product_search_server'),
      '#ajax' => [
        'callback' => '::ajaxProductSearchRefresh',
        'wrapper' => $wrapper_id,
      ],
    ];

    if ($form_state->getValue('server')) {
      $server = $form_state->getValue('server');
    }
    elseif ($config->get('product_search_server')) {
      $server = $config->get('product_search_server');
    }

    if (isset($server)) {
      $server = Server::load($server);

      if (isset($server)) {
        $indexes = $server->getIndexes();

        $index_options = [];
        foreach ($indexes as $index) {
          $index_options[$index->id()] = $index->get('name');
        }

        $form['product_search']['index'] = [
          '#type' => 'select',
          '#title' => 'Search Index',
          '#options' => $index_options,
          '#default_value' => $config->get('product_search_index'),
          '#empty_option' => $this->t('- Select -'),
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory()->getEditable('commerce_order_item_widget.settings')
      // Set the submitted configuration setting.
      ->set('product_search_server', $form_state->getValue('server'))
      ->set('product_search_index', $form_state->getValue('index'))
      ->save();

    // Validation of course needed as well.
    parent::submitForm($form, $form_state);
  }

  /**
   * AJAX callback for the product search options.
   */
  public function ajaxProductSearchRefresh($form, &$form_state) {
    return $form['product_search'];
  }

}
