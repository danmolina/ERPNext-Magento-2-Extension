<?php //-->

namespace Redscript\Erpnext\Observer;

class Erpnextproduct implements \Magento\Framework\Event\ObserverInterface
{
    protected $_request;

    public function __construct(\Magento\Framework\App\RequestInterface $request)
    {
        $this->_request = $request;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        //get the object manager
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        //get the config
        $config = $objectManager
            ->get('Magento\Framework\App\Config\ScopeConfigInterface');

        //get the config values
        $isEnabled  = $config->getValue('redscript_erpnext/general/enable');
        $host       = $config->getValue('redscript_erpnext/general/host');
        $username   = $config->getValue('redscript_erpnext/general/username');
        $password   = $config->getValue('redscript_erpnext/general/password');

        //get the request parameters
        $params = $this->_request->getParams();
        
        //if the request origin is from ERPNext
        if(isset($params['ERPNext'])) {
            //skip below process
            return $this;
        }

        //if the module is disabled
        if(!$isEnabled || $isEnabled != 1) {
            return $this;
        }
        
        //get the dispatched data
        $product = $observer->getProduct()->getData();

        //set the variables
        $id         = $product['entity_id'];
        $sku        = $product['sku'];
        $category   = 'Unknown';
        $qty        = 0;

        //if there is a category
        if(isset($product['category_ids'][0])) {
            //get the category name
            $categoryObject = $objectManager
                ->create('Magento\Catalog\Model\Category')
                ->load($product['category_ids'][0]);
            
            $category = $categoryObject->getName();
        }

        //if the quantity is greater than 0
        if($product['stock_data']['qty'] > 0) {
            $qty = $product['stock_data']['qty'];
        }

        //1. init the library
        require(dirname(__FILE__).'/lib/FrappeClient.php');
        $client = new \FrappeClient($host, $username, $password);

        //2. Add category
        $this->_addCategory($client, $product['magento_id'], $category);
        //3. Add product
        $this->_addProduct($client, $product, $category);
        //4. Add stocks
        $this->_addStocks($client, $sku, $qty);
        //5. Add image
        //if there is an image
        if(isset($product['media_gallery']['images']) 
        && !empty($product['media_gallery']['images'])) {
            //get the product image directory
            $productDir = dirname(__FILE__).'/../../../../../pub/media/catalog/product';

            //images can be found here /pub/media/catalog/product
            foreach($product['media_gallery']['images'] as $image) {
                //check if the file exist
                if(!file_exists($productDir.$image['file'])) {
                    continue;
                }

                //add the image
                $this->_addImage($client, $image, $sku);
            }
        }













        /*

        //require the library
        require(dirname(__FILE__).'/lib/FrappeClient.php');

        //init the class
        $client = new \FrappeClient($host, $username, $password);

        //let's create a category first
        $categoryName = 'Unknown';
        if(isset($product['category_ids'][0])) {
            //get the category name
            $category = $objectManager->create('Magento\Catalog\Model\Category')
                ->load($product['category_ids'][0]);
            
            $categoryName = $category->getName();
        }

        //save the category
        //$this->_saveCategory($client, $product, $categoryName);

        //save the product data
        $this->_saveProduct($client, $product, $categoryName);
        
        //if the quantity is greater than 0
        if($product['stock_data']['qty'] > 0) {
            //save the stocks
            $this->_saveStocks($client, $product);
        }
        
        //insert the images if not empty
        if(isset($product['media_gallery']['images']) 
        && !empty($product['media_gallery']['images'])) {
            //get the product image directory
            $productDir = dirname(__FILE__).'/../../../../../pub/media/catalog/product';

            //images can be found here /pub/media/catalog/product
            foreach($product['media_gallery']['images'] as $image) {
                //check if the file exist
                if(!file_exists($productDir.$image['file'])) {
                    continue;
                }

                //save the image
                try {
                    $this->_saveImage($client, $image, $product['sku']);

                    //create logs
                    $this->_createLogs(false, $username, $host, 'Add Image: SUCCESS');
                } catch(Exception $e) {
                    $this->_createLogs($e, $username, $host, 'Add Image: FAILED');
                }
            }
        }

        return $this;*/

        return $this;
    }

    private function _addCategory($client, $magentoId, $category)
    {
        //category information
        $setting = array(
            'magento_id'        => $magentoId,
            'doctype'           => 'Item Group',
            'item_group_name'   => $category,
            'is_group'          => 0,
            'show_in_website'   => 1,
            'name'              => $category,
            'parent_item_group' => 'All Item Groups',
            'old_parent'        => 'All Item Groups');

        //save the category
        $client->insert('Item Group', $setting);

        return $this;
    }

    private function _addImage($client, $image, $sku)
    {
        //get the host
        $host       = $_SERVER['HTTP_HOST'];
        $protocol   = $_SERVER['REQUEST_SCHEME'];

        //get the filename
        $filename   = explode('/', $image['file']);
        $name       = end($filename);

        //get the full url
        $uri = $protocol.'://'.$host.'/pub/media/catalog/product';
        //set the image information
        $setting = array(
            'file_name'             => $name,
            'file_url'              => $uri.$image['file'],
            'attached_to_name'      => $sku,
            'attached_to_doctype'   => 'Item',
            'is_private'            => 1);

        //save the file
        $client->insert('File', $setting);

        return $this;
    }

    private function _addProduct($client, $product, $category)
    {
        //product information
        $setting = array(
            'magento_id'        => $product['entity_id'],
            'item_code'         => $product['sku'],
            'item_name'         => $product['name'],
            'item_group'        => $category,
            'stock_uom'         => 'UNIT',
            'is_stock_item'     => '1',
            'valuation_rate'    => 1,
            'standard_rate'     => (float) $product['price']);

        if(!empty($product['weight'])) {
            $setting['net_weight'] = (float) $product['weight'];
        }

        if(!empty($product['description'])) {
            $setting['description'] = $product['description'];
        }

        //save the product
        $client->insert('Item', $setting);

        return $this;
    }

    private function _addStocks($client, $sku, $qty)
    {
        //if the quantity is empty or less than 1
        if(!$qty || $qty < 1) {
            return $this;
        }

        //stock information
        $setting = array(
            'doctype'           => 'Stock Entry',
            'title'             => 'Material Receipt',
            'to_warehouse'      => 'Stores - NB',
            'docstatus'         => 1,
            'company'           => 'NexusBond ASIA Inc.',
            'purpose'           => 'Material Receipt',
            'request_from'      => 'MAGENTO',
            'items'             => array(
                array(
                    'item_code'             => $sku,
                    'qty'                   => $qty,
                    't_warehouse'           => 'Stores - NB',
                    'basic_amount'          => 0.0,
                    'cost_center'           => 'Main - NB',
                    'stock_uom'             => 'Kg',
                    'conversion_factor'     => 1.0,
                    'docstatus'             => 1,
                    'uom'                   => 'Kg',
                    'basic_rate'            => 0.0,
                    'doctype'               => 'Stock Entry Detail',
                    'expense_account'       => 'Stock Adjustment - NB',
                    'parenttype'            => 'Stock Entry',
                    'parentfield'           => 'items')
            ));

        //save the stock
        $client->insert('Stock Entry', $setting);

        return $this;
    }



























    private function _createLogs($e, $username, $host, $title)
    {
        $log  = 'Date: '.date('Y-m-d H:i:s', time()).PHP_EOL;
        $log .= 'User: '.$_SERVER['REMOTE_ADDR'].' - '.date('F j, Y, g:i a').PHP_EOL;
        $log .= 'Host: '.$host.PHP_EOL;
        $log .= 'User: '.$username.PHP_EOL;
        $log .= 'Title: '.$title.PHP_EOL;

        if($e) {
            $log .= 'Message: '.$e->getMessage().PHP_EOL;
        } else {
            $log .= 'Message: Successfully uploaded data'.PHP_EOL;
        }
        
        $log .= '--------------------------------------------------'.PHP_EOL.PHP_EOL;

        $logsDir = dirname(__FILE__).'/logs';

        //check if the directory exist
        if(!is_dir($logsDir)) {
            mkdir($logsDir, 0777);
        }

        //Save string to log, use FILE_APPEND to append.
        file_put_contents($logsDir.'/log_'.date('j.n.Y').'.txt', $log, FILE_APPEND);
    }

    private function _saveProduct($client, $product, $categoryName)
    {
        $data = array(
            'magento_id'        => $product['entity_id'],
            'item_code'         => $product['sku'],
            'item_name'         => $product['name'],
            'item_group'        => $categoryName,
            'stock_uom'         => 'UNIT',
            'is_stock_item'     => $product['stock_data']['is_in_stock'],
            'valuation_rate'    => 1,
            'standard_rate'     => (float) $product['price']
        );

        if(!empty($product['weight'])) {
            $data['net_weight'] = (float) $product['weight'];
        }

        if(!empty($product['description'])) {
            $data['description'] = $product['description'];
        }

        //insert the product
        $client->insert('Item', $data);

        return $this;
    }

    private function _saveCategory($client, $product, $categoryName)
    {
        $category = array(
            'magento_id'        => $product['entity_id'],
            'doctype'           => 'Item Group',
            'item_group_name'   => $categoryName,
            'is_group'          => 0,
            'show_in_website'   => 1,
            'name'              => $categoryName,
            'parent_item_group' => 'All Item Groups',
            'old_parent'        => 'All Item Groups'
        );

        //insert the category
        $client->insert('Item Group', $category);

        $file = fopen(dirname(__FILE__).'/category-data.txt', 'w') or die("Unable to open file!");
        fwrite($file, json_encode($category));
        fclose($file);
        
        //if the uploaded category already exist
        if(strpos(serialize($client), 'Duplicate entry') !== false) {
            return false;
        }

        return $this;
    }

    private function _saveImage($client, $image, $sku)
    {
        //get the host
        $host       = $_SERVER['HTTP_HOST'];
        $protocol   = $_SERVER['REQUEST_SCHEME'];

        //get the filename
        $filename   = explode('/', $image['file']);
        $name       = end($filename);

        //get the full url
        $uri = $protocol.'://'.$host.'/pub/media/catalog/product';

        //set the image data
        $fileContent = array(
            'file_name'             => $name,
            'file_url'              => $uri.$image['file'],
            'attached_to_name'      => $sku,
            'attached_to_doctype'   => 'Item',
            'is_private'            => 1);

        //add the image
        $client->insert('File', $fileContent);

        return $this;
    }

    private function _saveStocks($client, $product)
    {
        $stock = array(
            'doctype'           => 'Stock Entry',
            'title'             => 'Material Receipt',
            'to_warehouse'      => 'Stores - NB',
            'docstatus'         => 1,
            'company'           => 'NexusBond ASIA Inc.',
            'purpose'           => 'Material Receipt',
            'request_from'      => 'MAGENTO',
            'items'             => array(
                array(
                    'item_code'             => $product['sku'],
                    'qty'                   => $product['stock_data']['qty'],
                    't_warehouse'           => 'Stores - NB',
                    'basic_amount'          => 0.0,
                    'cost_center'           => 'Main - NB',
                    'stock_uom'             => 'Kg',
                    'conversion_factor'     => 1.0,
                    'docstatus'             => 1,
                    'uom'                   => 'Kg',
                    'basic_rate'            => 0.0,
                    'doctype'               => 'Stock Entry Detail',
                    'expense_account'       => 'Stock Adjustment - NB',
                    'parenttype'            => 'Stock Entry',
                    'parentfield'           => 'items')
            )
        );

        //insert the STOCK
        $client->insert('Stock Entry', $stock);

        return $this;
    }
}