<?php

namespace Drupal\commerce_order\Controller;

use Drupal\search_api\ParseMode\ParseModePluginManager;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class OrderItemAutoComplete.
 *
 * @package Drupal\commerce_order\Controller
 */
class OrderItemAutoComplete extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * The entity reference selection handler plugin manager.
   *
   * @var \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface
   */
  protected $selectionManager;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The parse mode manager.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager|null
   */
  protected $parseModeManager;

  /**
   * The search api product index.
   *
   * @var \Drupal\search_api\Entity\Index
   */
  protected $index;

  /**
   * Constructs a new OrderItemAutoComplete object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface $selection_manager
   *   The entity reference selection handler plugin manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer interface.
   * @param \Drupal\search_api\ParseMode\ParseModePluginManager $parse_mode_manager
   *   The parse mode manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    SelectionPluginManagerInterface $selection_manager,
    RendererInterface $renderer,
    ParseModePluginManager $parse_mode_manager,
    ConfigFactoryInterface $config_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->selectionManager = $selection_manager;
    $this->renderer = $renderer;
    $this->parseModeManager = $parse_mode_manager;
    $config = $config_factory->get('commerce_order_item_widget.settings');
    $this->index = $config->get('product_search_index');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.entity_reference_selection'),
      $container->get('renderer'),
      $container->get('plugin.manager.search_api.parse_mode'),
      $container->get('config.factory')
    );
  }

  /**
   * Order item auto complete handler.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $entity_type
   *   The entity type.
   * @param string $view_mode
   *   The view mode.
   * @param int $limit
   *   The number of entities to list.
   *
   * @return object
   *   The Json object for auto complete suggestions.
   */
  public function orderItemAutoCompleteHandler(
    Request $request,
    $entity_type,
    $view_mode,
    $limit = 10
  ) {
    $results = [];

    if ($input = $request->query->get('q')) {
      $product_variations = $this->searchQueryString($entity_type, $input, $limit);

      foreach ($product_variations as $product_variation) {
        $view_builder = $this->entityTypeManager->getViewBuilder($entity_type);
        $product_render_array = $view_builder->view(
          $product_variation,
          $view_mode,
          $product_variation->language()->getId()
        );
        $results[] = [
          'value' => $product_variation->id(),
          'label' => '<div class="commerce-order-autocomplete-results">'
          . $this->renderer->renderPlain($product_render_array)
          . '</div>',
        ];
      }
    }

    return new JsonResponse($results);
  }

  /**
   * Helper function for searching for matching products.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $string
   *   The Query string.
   * @param int $limit
   *   The count of items to return.
   *
   * @return array
   *   An array of loaded product variations.
   */
  public function searchQueryString($entity_type, $string, $limit) {
    $product_variations = [];

    // If no search_api product index is configured.
    if (!$this->index) {
      $suggestions = $this->searchProducts($entity_type, $string, $limit);
      foreach ($suggestions as $product_variation_id) {
        $product_variations[] = $this
          ->entityTypeManager
          ->getStorage('commerce_product_variation')
          ->load($product_variation_id);
      }
    }
    // Else, let's search the search_api product index.
    else {
      $suggestions = $this->searchProductIndex($string, $limit);
      foreach ($suggestions->getResultItems() as $item) {
        $data = explode(':', $item->getId());
        $data = explode('/', $data[1]);
        $product_variations[] = $this
          ->entityTypeManager
          ->getStorage('commerce_product_variation')
          ->load($data[1]);
      }
    }

    return $product_variations;
  }

  /**
   * Helper function for searching for matches in the search api product index.
   *
   * @param string $string
   *   The Query string.
   * @param int $limit
   *   The count of items to return.
   *
   * @return mixed
   *   The query search result.
   */
  public function searchProductIndex($string, $limit) {
    $query = $this->index->query();

    $parse_mode = $this->parseModeManager->createInstance('direct');
    $parse_mode->setConjunction('OR');
    $query->setParseMode($parse_mode);

    // Set fulltext search keywords and fields.
    $query->keys($string);

    $query->range(0, $limit);

    $results = $query->execute();

    return $results;
  }

  /**
   * Helper function for searching for products in the database.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $string
   *   The Query string.
   * @param int $limit
   *   The count of items to return.
   *
   * @return array
   *   An array of product variation IDs if we have matches.
   */
  public function searchProducts($entity_type, $string, $limit) {
    $results = [];

    $options = [
      'target_type' => $entity_type,
      'handler' => 'default',
    ];
    $handler = $this->selectionManager->getInstance($options);

    if (isset($string)) {
      // Get an array of matching entities.
      $match_operator = !empty($selection_settings['match_operator']) ? $selection_settings['match_operator'] : 'CONTAINS';
      $entity_labels = $handler->getReferenceableEntities($string, $match_operator, $limit);

      // Loop through the entities and convert them into autocomplete output.
      foreach ($entity_labels as $values) {
        foreach ($values as $entity_id => $label) {
          $results[] = $entity_id;
        }
      }
    }

    return $results;
  }

}
