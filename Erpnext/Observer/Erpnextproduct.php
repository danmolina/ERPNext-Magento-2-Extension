<?php //-->

namespace Redscript\Erpnext\Observer;

class Erpnextproduct implements \Magento\Framework\Event\ObserverInterface
{
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        //get the dispatched data
        $product = $observer->getProduct()->getData();

        // $pr = fopen(dirname(__FILE__).'/product.txt', 'w') or die("Unable to open file!");
        // fwrite($pr, json_encode($product));
        // fclose($pr);

        $gallery = array();
        if(isset($product['media_gallery'])) {
            $gallery = $product['media_gallery'];
        }

        //get the data
        $data = array(
            'magento_id'        => $product['entity_id'],
            'item_code'         => $product['sku'],
            'item_group'        => 'Products',
            'stock_uom'         => 'UNIT',
            'is_stock_item'     => $product['stock_data']['qty'],

            'store_id'          => $product['store_id'],
            'type_id'           => $product['type_id'],
            'attribute_set_id'  => $product['attribute_set_id'],
            'visibility'        => $product['visibility'],
            'tax_class_id'      => $product['tax_class_id'],
            'categories'        => $product['category_ids'],
            'product'           => array(
                'id'        => $product['entity_id'],
                'name'      => $product['name'],
                'sku'       => $product['sku'],
                'price'     => $product['price'],
                'status'    => $product['status'],
                'weight'    => $product['weight'],
                'new'       => array(
                    'news_from_date'    => $product['news_from_date'],
                    'news_to_date'      => $product['news_to_date']),
                'description'           => $product['description'],
                'short_description'     => $product['short_description']),
            'stock'             => array(
                'qty'       => $product['stock_data']['qty'],
                'in_stock'  => $product['stock_data']['is_in_stock']),
            'created_at'    => $product['created_at'],

            //images can be found here /pub/media/catalog/product
            'media_gallery' => $gallery);


        require(dirname(__FILE__).'/lib/FrappeClient.php');

        $client = new \FrappeClient();
        $result = $client->insert('Item', $data);
    }
}