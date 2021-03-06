<?php
class AAOnepage_Api{
    
    protected $apiVersion   = 'Version 1.2';
    protected $apiUrl       = 'https://app.onepagecrm.com/api/';
    protected $apiFormat    = '.json';
    protected $uid          = null;       // onepage userid
    protected $apiKey       = null;
    protected $username     = null;
    protected $password     = null;
    protected $sslVerify    = false;

    public function getOnePageAccount(){
         // Have we account details already?
        $account_details = get_transient( 'aa_onepage_account_details' );           

        // We've never signed in, or the transient needs refreshing
        if(! $account_details ){
            $un = get_option( 'aa_onepage_username' );            
            $pw = get_option( 'aa_onepage_pwd' );            

            if( !empty($un) && !empty( $pw ) ){
                $this->setUsername( $un );
                $this->setPassword( $pw );
                $onePageLogin = $this->login();                 

                // 
                if( is_wp_error( $onePageLogin )){
                    // if ( current_user_can('manage_options') ){                    
                        return $onePageLogin;
                    // }
                    // return 'Aplogies, there has been an error.';                        
                }                    
            }else{
                return new WP_Error('broke', __( 'Plese sign in to OnePageCRM' ));
            }
        }else{
            // have a transient account details obj => grab the uid  & key              
            $this->setUid( $account_details->data->uid );
            $this->setApiKey( base64_decode($account_details->data->key));
            
            return $account_details; 
        }

        return null; // Shouldn't get here
    }
    
    /**
     * Generic HTTP interaction handler. Also manages building auth calls 
     * where needed
     * 
     * @param string $url
     * @param Mixed $args
     * @param String $method [optional: default 'GET'] [POST | GET | PUT | DELETE ]
     * @param Bool $requireAuth [optional: default true]
     * @return Object 
     */
    public function doApiCall( $url, $args, $method = 'GET', $requireAuth = true){
        // Are we logged in?
        if((null === $this->uid || null === $this->apiKey) && $requireAuth){
            $this->getOnePageAccount();
        }

        $defaults = array(
            'sslverify'     => true,          
            'method'        => $method,
            'timeout'       => 10,
            'redirection'   => 10,
            'httpversion'   => '1.1',
            'blocking'      => true,
            'headers'       => array(),
            'body'          => null,
            'cookies'       => array()
        );        
        $args = wp_parse_args( $args , $defaults);

        // Get hostname from URL
        $url_data = array();
        preg_match('/^http[s]?:\/\/([^\/]+)/', $this->apiUrl, $url_data);
        $args['headers']['Host'] = $url_data[1];
        
        $url = $this->apiUrl . $url . $this->apiFormat;
                
        // Auth not required for Login method only
        if($requireAuth){                                       
            
            if(isset( $args['body'])){
                $auth = $this->calculateAuth( $url, $method, $args['body'] );
            }else{
                $auth = $this->calculateAuth( $url, $method );
            }
                        
            // set onepage auth headers
            $args['headers'] = array_merge($args['headers'], $auth);
        } 
        
        // DELETE also needs a querystring, but it's not being implemented here
        // @link http://www.onepagecrm.com/api/api-doc-for-dev-request-message-format.html
        if( $method === 'GET'  && is_array( $args['body'] )){
            $url .= '?'. http_build_query( $args['body'], null, '&' );
            $args['body'] = null;
        }        
        
        $response = wp_remote_request($url, $args);
        
        if(! is_wp_error( $response ) ){
            
            if($this->apiFormat === '.json'){                
                return json_decode( $response['body'] );
            }else{
                return $response['body'];
            }
        }else{            
            /**
             * @todo manage WP http error
             */
            print_r( $response );
            return null;
        }
              
    }
    
    
    /**
     * Build the Auth Headers to interact with the API. Needs to be done for
     * every request
     * @link http://www.onepagecrm.com/api/api-doc-for-dev-signature-value.html
     * 
     * @param String $url - full url being hit
     * @param String $method - GET | POST | PUT | DELETE
     * @param String $body [optional] - Required for POST | PUT requests
     * 
     * @return Mixed 
     */
    protected function calculateAuth( $url, $method, $body = null){
            
        $uid        = $this->getUid();
        $timestamp  = mktime();
        $shaUrl     = hash('sha1', $url ); 
        $apiKey     = $this->getApiKey();

        $authKey    = $uid . '.' . $timestamp . '.' . $method . '.' . $shaUrl;            

        if( $method === 'POST' || $method === 'PUT'){
            $httpBody   = http_build_query( $body, null, '&' );
            $shaBody    = hash('sha1', $httpBody );
            $authKey   .= '.' . $shaBody;
        }

        return array(
            'X-OnePageCRM-UID'  => $uid,
            'X-OnePageCRM-TS'   => $timestamp,
            'X-OnePageCRM-Auth' => hash_hmac('sha256', $authKey , $apiKey  )
        );
    }
    
    
    /**
     * Login to OnePage. Set the uid & api key
     */
    public function login(){

        $args = array();
        $loginUrl = 'auth/login';

        $authValues = array(
            'login'     => $this->getUsername(),
            'password'  => $this->getPassword()
        );                

        $args['body'] = $authValues;                                    

        $loginData = $this->doApiCall( $loginUrl, $args, 'POST', false);

        // Use the returned data
        if( is_wp_error( $loginData )){
            return $loginData;
        }elseif( $loginData->message === 'OK' ){
            set_transient( 'aa_onepage_account_details', $loginData);
            $this->setUid( $loginData->data->uid );
            $this->setApiKey( $loginData->data->key);
            $this->setPassword( null );
        }else{
            return new WP_Error('broke', __( $loginData->message ));
        }        

        return null;
    }
    
    
    public function getSslVerify(){
        return $this->sslVerify;
    }
    
    public function setUid( $uid = null){
        $this->uid = $uid;
    }
    
    public function getUid(){
        return $this->uid;
    }
    
    public function getUsername(){
        return $this->username;
    }
    
    /**
     * 
     * @param String $key 
     */
    public function setApiKey( $key ){
        $this->apiKey = $key;
    }
    
    public function getApiKey(){
        return $this->apiKey;
    }    
    
    protected function setUsername( $un ){
        $this->username = $un;
    }
    
    public function getPassword(){
        return $this->password;
    }
    protected function setPassword( $pwd ){
        $this->password = $pwd;
    }
    
    
}