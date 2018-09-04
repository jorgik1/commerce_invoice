<?php
/**
 * Created by PhpStorm.
 * User: yuriy
 * Date: 04.09.18
 * Time: 18:39
 */

namespace Drupal\commerce_invoice\Form;

use Drupal\commerce_product\ProductVariationStorage;
use Drupal\commerce_stock\StockServiceManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;
use Drupal\commerce_product\Entity\ProductVariation;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;


class StockInventoryControlForm extends FormBase {

  /**
   * The product variation storage.
   *
   * @var \Drupal\commerce_product\ProductVariationStorage
   */
  protected $productVariationStorage;

  /**
   * The stock service manager.
   *
   * @var \Drupal\commerce_stock\StockServiceManager
   */
  protected $stockServiceManager;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Constructs a StockTransactions2 object.
   *
   * @param \Drupal\commerce_product\ProductVariationStorage $productVariationStorage
   *   The commerce product variation storage.
   * @param \Drupal\commerce_stock\StockServiceManager $stockServiceManager
   *   The stock service manager.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   */
  public function __construct(ProductVariationStorage $productVariationStorage, StockServiceManager $stockServiceManager, Request $request) {
    $this->productVariationStorage = $productVariationStorage;
    $this->stockServiceManager = $stockServiceManager;
    $this->request = $request;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')->getStorage('commerce_product_variation'),
      $container->get('commerce_stock.service_manager'),
      $container->get('request_stack')->getCurrentRequest()
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'stock_inventory_control_form';
  }
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#theme'] = array('stock_inventory_control_form');
    $form['sku'] = [
      '#type' => 'textfield',
      '#autocomplete_route_name' => 'commerce_stock.sku_autocomplete',
      '#placeholder' => t('Scan or Type SKU number...'),
      '#required' => FALSE,
      '#title' => $this->t('SKU'),
    ];
    $locations = \Drupal::entityTypeManager()->getStorage('commerce_stock_location')->loadMultiple();
    $options = [];
    $user = \Drupal::currentUser();
    foreach ($locations as $lid => $location) {
      if ($user->hasPermission('administer stock entity') || $user->hasPermission('edit stock entity at any location')) {
        $options[$lid] = $location->get('name')->value;
      } else if ($user->hasPermission('edit stock entity at own location')) {
        $uids = $location->get('uid');
        foreach ($uids as $manager) {
          if ($manager->target_id == $user->id()) {
            $options[$lid] = $location->get('name')->value;
          }
        }
      }
    }
    $form['target'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Move target'),
    ];

    $form['target']['target_location'] = [
      '#type' => 'select',
      '#title' => $this->t('Target Location'),
      '#options' => $options,
    ];

    $form['location'] = [
      '#type' => 'select',
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => isset($_SESSION['commerce_stock_movement_form_location_id']) ? $_SESSION['commerce_stock_movement_form_location_id'] : NULL,
      '#title' => $this->t('Stock Location'),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#default_value' => NUll,
      '#required' => FALSE,
      '#placeholder' => t('Please provide a log entry...'),
      '#title' => $this->t('Description'),
    ];

    $form['actions'] = array(
      '#type' => 'fieldset',
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
    );
    $form['actions']['sell'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sell'),
    ];
    $form['actions']['return'] = [
      '#type' => 'submit',
      '#value' => $this->t('Return'),
    ];
    $form['actions']['fill'] = [
      '#type' => 'submit',
      '#value' => $this->t('Fill'),
    ];
    $form['actions']['move'] = [
      '#type' => 'submit',
      '#value' => $this->t('Move'),
    ];
    $form['actions']['delete'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete'),
    ];
    $form['values'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('SKU'),
        $this->t('Quantity'),
        $this->t('Operations'),
      ],
    ];
    // If we have user submitted values, that means this is triggered by form rebuild because of SKU not found
    $user_submit = $form_state->getValue('values');
    if (isset($user_submit)) {
      $invalidSKUPos = $form_state->getStorage();
      foreach ($user_submit as $pos => $row) {
        $value_form = &$form['values'][$pos];
        $value_form = [
          '#parents' => ['values', $pos]
        ];
        $value_form['sku'] = [
          '#type' => 'textfield',
          '#default_value' => $row['sku'],
          '#required' => TRUE,
          '#attributes' => ['readonly' => 'readonly'],
          '#prefix' => '<div class="sku">',
          '#suffix' => '</div>',
        ];
        if (isset($invalidSKUPos[$pos]) && $invalidSKUPos[$pos]) {
          $value_form['sku']['#attributes']['class'][] = 'error';
        }
        $value_form['quantity'] = [
          '#type' => 'number',
          '#default_value' => $row['quantity'],
          '#required' => TRUE,
          '#prefix' => '<div class="quantity">',
          '#suffix' => '</div>',
        ];
        $value_form['remove'] = [
          '#markup' => '<div type="button" class="button delete-item-button">Remove</div>',
        ];
      }
    }
    return $form;
  }
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $user_submit = $form_state->getValue('values');
    if (empty($user_submit)) {
      $form_state->setErrorByName('sku', $this->t('Please at least provide one entry'));
    }
  }
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    /** @var \Drupal\Core\StringTranslation\TranslatableMarkup $transaction_type */
    $transaction_type = $form_state->getValue('op');
    $product_variation_sku = $form_state->getValue('values')[0]['sku'];
    $source_zone = $form_state->getValue('source_zone') ?? '';
    $qty = $form_state->getValue('values')[0]['quantity'];
    $transaction_note = $form_state->getValue('description');
    $product_variation = $this->productVariationStorage->loadBySku($product_variation_sku);
    $location_id = $form_state->getValue('location');
    $_SESSION['commerce_stock_movement_form_location_id'] = $location_id;
    $user_submit = &$form_state->getValue('values');
    // validate SKU first
    foreach ($user_submit as $pos => $row) {
      if (!$this->validateSku($row['sku'])) {
        drupal_set_message($this->t('SKU: @sku doesn\'t exist.', ['@sku' => $row['sku']]), 'error');
      }
    }
    if ($transaction_type->getUntranslatedString() === 'Fill') {
      $this->stockServiceManager->receiveStock($product_variation, $location_id, $source_zone, $qty, NULL, $transaction_note);
    }
    elseif ($transaction_type->getUntranslatedString() === 'Sell') {
      $order_id = $form_state->getValue('order');
      $user_id = $form_state->getValue('user');
      $this->stockServiceManager->sellStock($product_variation, $location_id, $source_zone, $qty, NULL, $order_id, $user_id, $transaction_note);
    }
    elseif ($transaction_type->getUntranslatedString() === 'Delete') {
      $order_id = $form_state->getValue('order');
      $user_id = $form_state->getValue('user');
      $this->stockServiceManager->returnStock($product_variation, $location_id, $source_zone, $qty, NULL, $order_id, $user_id, $transaction_note);
    }
    elseif ($transaction_type->getUntranslatedString() === 'Move') {
      $target_location = $form_state->getValue('target_location');
      $target_zone = $form_state->getValue('target_zone') ?? '';
      $this->stockServiceManager->moveStock($product_variation, $location_id, $target_location, $source_zone, $target_zone, $qty, NULL, $transaction_note);
    }

      drupal_set_message($this->t('Operation: ' . $transaction_type . ' succeeded!'));
  }

  /**
   * If a sku exists in database.
   *
   * @param $sku
   */
  protected function validateSku($sku) {
    $result = \Drupal::entityQuery('commerce_product_variation')
      ->condition('sku', $sku)
      ->condition('status', 1)
      ->execute();
    return $result ? TRUE : FALSE;
  }

  /**
   *
   * @param $sku
   * @param $location_id
   *
   * @return \Drupal\Core\Entity\EntityInterface
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getStock($sku, $location_id) {
    $connection = Database::getConnection('default', NULL);
    $query = $connection->select('commerce_product_variation__stock', 'cs');
    $query->join('commerce_product_variation_field_data', 'cr', 'cr.variation_id=cs.entity_id');
    $query->join('commerce_stock_field_data', 'csf', 'csf.stock_id=cs.stock_target_id');
    $query->fields('cs', ['stock_target_id']);
    $query->condition('cr.sku', $sku);
    $query->condition('csf.stock_location', $location_id);
    $stock_id = $query->execute()->fetchField();
    if ($stock_id) {
      return \Drupal::entityTypeManager()->getStorage('commerce_stock')->load($stock_id);
    } else return NULL;
  }
}