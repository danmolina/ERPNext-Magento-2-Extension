<?php if(!defined("ROOTPATH")){die("Access forbidden.".PHP_EOL);}
return array(
    'auth_url' => 'http://158.69.63.127/api/method/login',
    'api_url' => 'http://158.69.63.127/api/resource/',
    'auth' => array('usr' => 'Administrator', 'pwd' => 'Hello101!'),
    'cookie_file' => 'cookie.txt',
    'curl_timeout' => 30,

    'basic_auth' => array(),
//  'basic_auth' => array('usr' => '', 'pwd' => ''),

);