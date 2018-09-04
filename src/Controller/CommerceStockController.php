<?php
/**
 * Created by PhpStorm.
 * User: yuriy
 * Date: 04.09.18
 * Time: 18:52
 */

namespace Drupal\commerce_invoice\Controller;


use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
class CommerceStockController extends ControllerBase {

  protected $connection;

  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  public function handleAutocomplete(Request $request) {
    $matches = [];
    if ($input = $request->query->get('q')) {
      $query = $this->connection->select('commerce_product_variation_field_data', 'cpv');
      $query->fields('cpv', ['sku']);
      $query->condition('cpv.sku', '%' . $input . '%', 'LIKE');
      $query->range(0, 20);
      $result = $query->execute()->fetchCol();
    }
    return new JsonResponse($result);
  }
}