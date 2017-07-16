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
        $categoryExist = false;

        //if there is a category
        if(isset($product['category_ids'][0])) {
            //get the category name
            $categoryObject = $objectManager
                ->create('Magento\Catalog\Model\Category')
                ->load($product['category_ids'][0]);
            
            $category = $categoryObject->getName();
        }

        //check if the category already exist
        //init the library
        require(dirname(__FILE__).'/lib/FrappeClient.php');
        //authenticate the user
        //this will generate the cookie
        $client = new \FrappeClient($this->_host, $this->_username, $this->_password);

        // GET ITEMS
        $result = $client->search('Item Group', array());
        echo '<pre>';
        print_r($result);
        echo '</pre>';
        foreach($result->body->data as $data) {
            echo '<pre>';
            print_r($data->name);
            echo '</pre>';
            //if category exist
            if(strtoupper($data->name) == strtoupper($category)) {
                $categoryExist = true;
                break;
            }
        }

        //if category does not exist
        if(!$categoryExist) {
            //let's create it
            $this->_sendPost('Item Group', array(
                'magento_id'        => $id,
                'doctype'           => 'Item Group',
                'item_group_name'   => $category,
                'is_group'          => 0,
                'show_in_website'   => 1,
                'name'              => $category,
                'parent_item_group' => 'All Item Groups',
                'old_parent'        => 'All Item Groups'));
        }

        echo 'Category: '.$category.' <br />';
        echo 'Is exist?: '.($categoryExist) ? 'yes' : 'no';
        exit;


        //if the quantity is greater than 0
        if($product['stock_data']['qty'] > 0) {
            $qty = $product['stock_data']['qty'];
        }

        //init the settings
        $settings = array();

        //PRODUCT INFORMATION
        $settings['product'] = array(
            'magento_id'        => $id,
            'item_code'         => $sku,
            'item_name'         => $product['name'],
            'item_group'        => $category,
            'stock_uom'         => 'UNIT',
            'is_stock_item'     => '1',
            'valuation_rate'    => 1,
            'standard_rate'     => (float) $product['price']);

        if(!empty($product['weight'])) {
            $settings['product']['net_weight'] = (float) $product['weight'];
        }

        if(!empty($product['description'])) {
            $settings['product']['description'] = $product['description'];
        }

        //save the product
        $this->_sendPost('Item', $settings['product']);
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
    }
}