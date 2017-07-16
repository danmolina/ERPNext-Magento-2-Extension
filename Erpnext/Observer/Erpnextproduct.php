<?php //-->

namespace Redscript\Erpnext\Observer;

class Erpnextproduct implements \Magento\Framework\Event\ObserverInterface
{
    protected $_request;
    protected $_host        = NULL;
    protected $_username    = NULL;
    protected $_password    = NULL;
    protected $_client      = NULL;

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
        $isEnabled          = $config->getValue('redscript_erpnext/general/enable');
        $this->_host        = $config->getValue('redscript_erpnext/general/host');
        $this->_username    = $config->getValue('redscript_erpnext/general/username');
        $this->_password    = $config->getValue('redscript_erpnext/general/password');

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
        //authenticate the user
        //this will generate the cookie
        new \FrappeClient($this->_host, $this->_username, $this->_password);

        //Add product
        echo 'prepare prouct';
        $this->_addProduct($product, $category);
        echo 'done';
        return $this;






        //3. Add product
        //product information
        $productSetting = array(
            'magento_id'        => $id,
            'item_code'         => $sku,
            'item_name'         => $product['name'],
            'item_group'        => $category,
            'stock_uom'         => 'UNIT',
            'is_stock_item'     => '1',
            'valuation_rate'    => 1,
            'standard_rate'     => (float) $product['price']);

        if(!empty($product['weight'])) {
            $productSetting['net_weight'] = (float) $product['weight'];
        }

        if(!empty($product['description'])) {
            $productSetting['description'] = $product['description'];
        }

        echo 'first';
        $this->_sendPost('Item', $productSetting);
        echo 'last';
        exit;
        return $this;
















        //2. Add category
        $this->_addCategory($id, $category);
        
        //3. Add product
        $this->_addProduct($product, $category);

        //4. Add stocks
        $this->_addStocks($sku, $qty);
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
                $this->_addImage($image, $sku);
            }
        }

        return $this;
    }

    private function _addCategory($magentoId, $category)
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
        return $this->_sendPost('Item Group', $setting);
    }

    private function _addImage($image, $sku)
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

        return $this->_sendPost('File', $setting);
    }

    private function _addProduct($product, $category)
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

        return $this->_sendPost('Item', $setting);
    }

    private function _addStocks($sku, $qty)
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

        return $this->_sendPost('Stock Entry', $setting);
    }

    private function _sendPost($doctype, $setting)
    {
        $cookieFile = dirname(__FILE__).'/lib/cookie.txt';
        //set the url
        $url = $this->_host.'/api/resource/'.$doctype;

        $data   = json_encode($setting);
        $length = strlen($data);

        //setup the curl
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . $length));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

        $response = curl_exec($ch);

        if(curl_errno($ch)) {
            return $this;
        }
    }
}