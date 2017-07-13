<?php
/**
 * FrappeClient-PHP
 *
 * ERPNext API Wrapper
 *
 * ERPNext(tm) : Frappe Technologies
 * Copyright 2013, Frappe Technologies Pvt Ltd. Numbai
 *
 * Licensed under The GPL v3 License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright 2013, Frappe Technologies Pvt Ltd. Numbai
 * @link          http://erpnext.com/ 
 * @since         ERPNext v5.0.22
 * @license       https://github.com/frappe/erpnext/blob/develop/license.txt
 * @see           https://github.com/frappe/frappe-client
 * @author        Danny M <danny@flama.co.jp>
 *
 * DO NOT put config.php and cookie.txt into public accessable area.
 *
 */
define('ROOTPATH',dirname(__FILE__));

class FrappeClient {

    const CONF_FILE = 'config.php';

    public $errorno = 0;
    public $error;
    public $body;
    public $header;
    public $is_auth = false;

    private $_auth_url = "";
    private $_api_url = "";
    private $_cookie_file = "";
    private $_auth = array();
    private $_basic_auth = array();
    private $_curl_timeout = 30;
    private $_limit_page_length = 20;

    function __construct($host = NULL, $username = NULL, $password = NULL){
            if(!is_file(ROOTPATH.'/'.self::CONF_FILE )){
                throw new FrappeClient_Exception(
                            "Config not found:".self::CONF_FILE
                            ,1
                        );
            }

            $conf = include(ROOTPATH.'/'.self::CONF_FILE);
            
            foreach($conf as $key => $value){
                if(property_exists($this, '_'.$key)){
                    $this->{'_'.$key} = $value;
                }
            }
            if(!is_writable( dirname( $this->_cookie_file) ) ){
                throw new FrappeClient_Exception(
                            "Cookie file not writable:".$this->_cookie_file
                            ,2
                        );
            }

            if($host) {
                $this->_auth = array(
                    'usr'   => $username,
                    'pwd'   => $password);
            }

            if($host) {
                $authUrl    = parse_url($this->_auth_url);
                $apiUrl     = parse_url($this->_api_url);


                $this->_auth_url    = $authUrl['scheme'].'://'.$host.$authUrl['path'];
                $this->_api_url     = $apiUrl['scheme'].'://'.$host.$apiUrl['path'];
            }

            $this->_auth();
        }

    private function _auth_check(){
        if($this->is_auth == false){
            throw new FrappeClient_Exception(
                        "Auth check fail."
                        ,3
                    );
        }else{
            return true;
        }
    }

    public function setConfig()
    {

    }

    private function _auth(){
        if(is_file($this->_cookie_file)){
            @unlink($this->_cookie_file);
        }
        $login_result = $this->_curl('AUTH',$this->_auth);
        if(isset($login_result->body->message)){
            $this->is_auth = true;
        }else{
            throw new FrappeClient_Exception(
                        "Auth fail."
                        ,4
                    );
        }
        if(!is_file($this->_cookie_file)){
            throw new FrappeClient_Exception(
                        "Cookie file not found:".$this->_cookie_file
                        ,5
                    );
        }
    }

    public function get(
            $doctype
            ,$key
        ){
            $this->_auth_check();
            return $this->_curl(
                'GET'
                , array(
                    'doctype' => $doctype
                    ,'data' => $key
                    )
                );
        }

    public function search(
            $doctype
            ,$conditions=array()
            ,$fields=array()
            ,$limit_start=0
            ,$limit_page_length=0
        ){
            $this->_auth_check();
            if($limit_page_length){
                $limit = $limit_page_length;
            }else{
                $limit = $this->_limit_page_length;
            }

            return $this->_curl(
                        'SEARCH'
                        , array(
                            'doctype' => $doctype,
                            'filters' => $conditions,
                            'fields' => $fields,
                            'limit_start' => $limit_start,
                            'limit_page_length' => $limit
                            )
                        );
        }

    public function update(
            $doctype
            ,$key
            ,$params
        ){
            $this->_auth_check();
            return $this->_curl(
                'UPDATE'
                , array('doctype' => $doctype, 'key' => $key,'data' => $params )
                );
        }

    public function insert(
            $doctype
            ,$params
        ){
            $this->_auth_check();
            return $this->_curl(
                'INSERT'
                , array('doctype' => $doctype, 'data' => $params )
                );
        }

    public function delete(
            $doctype
            ,$key
        ){
            $this->_auth_check();
            return $this->_curl(
                'DELETE'
                , array('doctype' => $doctype, 'key' => $key)
                );
        }

    private function _curl(
            $method='GET'
            ,$params=array()
        ){
            switch($method){
                case 'AUTH':
                        $url = $this->_auth_url;
                        $ch = curl_init($url);
                        curl_setopt($ch,CURLOPT_POST, true);
                        curl_setopt($ch,CURLOPT_POSTFIELDS, $this->_auth);
                        curl_setopt($ch,CURLOPT_CUSTOMREQUEST, 'POST');
                    break;
                case 'UPDATE':
                        $url = $this->_api_url.$params['doctype'].'/'.$params['key'];
                        $ch = curl_init($url);
                        curl_setopt(
                                $ch
                                ,CURLOPT_POSTFIELDS
                                ,array( 'data' => json_encode($params['data']) )
                            );
                        curl_setopt($ch,CURLOPT_CUSTOMREQUEST, 'PUT');
                    break;
                case 'INSERT':
                        $url = $this->_api_url.$params['doctype'];
                        $ch = curl_init($url);

                        //1.
                        //$data     = array('data' => json_encode($params['data']));
                        //$length   = strlen($params['data']);

                        //2.
                        // $data       = json_encode(array('data' => $params['data']));
                        // $length     = strlen($data);

                        //3.
                        $data       = json_encode($params['data']);
                        $length     = strlen($data);
                        

                        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt( $ch, CURLOPT_HEADER, true);
                        curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
                            'Content-Type: application/json',
                            'Content-Length: ' . $length));

                    break;
                case 'DELETE':
                        $url = $this->_api_url.$params['doctype'].'/'.$params['key'];
                        $ch = curl_init($url);
                        curl_setopt($ch,CURLOPT_CUSTOMREQUEST, 'DELETE');
                    break;
                case 'SEARCH':
                        $query = array();
                        if($params['filters']){
                            $query['filters'] = json_encode($params['filters']);
                        }
                        if($params['fields']){
                            $query['fields'] = json_encode($params['fields']);
                        }
                        if($params['limit_start']){
                            $query['limit_start'] = json_encode($params['limit_start']);
                        }
                        if($params['limit_page_length']){
                            $query['limit_page_length'] = json_encode($params['limit_page_length']);
                        }
                        $url = $this->_api_url.$params['doctype'];
                        if($query){
                            $url .= '?';
                            foreach($query as $key => $value){
                                $url .= $key.'='.$value.'&';
                            }
                        }

                        $ch = curl_init($url);
                        curl_setopt($ch,CURLOPT_CUSTOMREQUEST, 'GET');

                    break;
                case 'GET':
                default:
                        $query = array();
                        $url = $this->_api_url.$params['doctype'].'/'.urlencode($params['data']);
                        $ch = curl_init($url);
                        curl_setopt($ch,CURLOPT_CUSTOMREQUEST, 'GET');
                    break;
            }
            if(!empty($this->_basic_auth)){
                    curl_setopt($ch,CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt(
                    $ch
                ,CURLOPT_USERPWD
                    , $this->_basic_auth['usr'].':'.$this->_basic_auth['pwd']
                    );
            }

            curl_setopt($ch,CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch,CURLOPT_COOKIEJAR, $this->_cookie_file);
            curl_setopt($ch,CURLOPT_COOKIEFILE, $this->_cookie_file);
            curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch,CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch,CURLOPT_TIMEOUT, $this->_curl_timeout);
            $response = curl_exec($ch);
            $this->header = curl_getinfo($ch);
            $error_no = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            if($error_no){
                $this->error_no = $error_no;
            }
            if($error){
                $this->error = $error;
            }
            $this->body = @json_decode($response);
            if(JSON_ERROR_NONE != json_last_error()){
                $this->body = $response;
            }
            return $this;
        }

    }


class FrappeClient_Exception extends Exception {

    public function __construct(
            $message
            , $code = 0
        ) {
            parent::__construct(
                        $message
                        ,$code
                    );
        }
    public function __toString() {
        return __CLASS__ .': ['.$this->code.']: '.$this->message.PHP_EOL;
    }

}

