<?php
/**
 * A CakePHP datasource for interacting with the amazon eCommerce API.
 *
 * Copyright 2009-2011, Ministry of Web Development
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2009-2011, Ministry of Web Development
 * @version 0.1
 * @package datasources
 * @author Charlie van de Kerkhof <primeminister@mowd.nl>
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 */

class AmazonEcommerceSource extends DataSource {
	/**
	 * Holds the description of this datasource
	 *
	 * @var string
	 **/
	var $description = "Amazon eCommerce Data Source";

	/**
	* The current connection status
	*
	* @var boolean
	*/
    public $connected = false;

	/**
	* Holds the HTTP object
	*
	* @var boolean
	*/
    public $Http = null;

	/**
	 * The default configuration
	 *
	 * @var array
	 */
	public $_baseConfig = array(
    	'accessKey' => null,
    	'secretKey' => null
	);
	
	/**
	 * Holds the last error
	 *
	 * @var string
	 **/
	public $error = null;

    /**
     * Constructor
     *
     * @param string $config 
     * @access public
     * @author primeminister
     */
	public function __construct($config) {
		parent::__construct($config);
		$this->connected = $this->connect($config);
		return $config;
	}

	/**
	 * Destructor
	 *
	 * @return void
	 * @access public
	 * @author primeminister
	 **/
	public function __destruct() {
	    $this->connected = $this->close();
	}
	
	/**
	 * Connecting to datasource
	 *
	 * @return bool
	 * @access public
	 * @author primeminister
	 **/
	public function connect($config) {
		$this->error = null;
		if (empty($config['accessKey']) || empty($config['secretKey'])) {
			$this->error = "Please provide your accessKey and secretKey to the database config";
			$this->showError();
			return false;
		}
		App::import('HttpSocket');
		$this->Http = new HttpSocket();
		$this->Http->quirksMode = true;
		return true;
	}

	/**
	 * Close connection to datasource
	 *
	 * @return bool
	 * @access public
	 * @author primeminister
	 **/
	public function close() {
		$c = $this->Http->disconnect();
		$this->Http = null;
	    return $c;
	}	
	
    /**
     * find items or item on amazon eCommerce API
     *
     * @access public
     * @return mixed Array of records or Bool false.
     * @author primeminister
     */
	public function query() {
		$this->error = false;

		if (!$this->connected) {
			$this->error = "Datasource (HTTPSocket) is not connected";
			$this->showError();
			return false;
		}

		$args = func_get_args();

		$method = null;
		$queryData = null;

		if (count($args) == 2) {
			$method = $args[0];
			$queryData = $args[1];
		} elseif (count($args) > 2 && !empty($args[1])) {
			$method = $args[0];
			$queryData = $args[1][0];
		}

		if(!$method || !$queryData) {
			$this->error = "Please provide a method or keywords";
			$this->showError();
			return false;
		}

		if (!is_array($queryData)) {
			$queryData = array('Keywords' => $query);
		}
		// setup operation
		if ($method == 'first') {
		    $queryData['Operation'] = 'ItemLookup';
	    } else {
	        $queryData['Operation'] = 'ItemSearch';
        }
		// re-map shortcut parameters to the new AWS parameters
        $map  = array(
            'title' => 'Keywords',
            'info'  => 'ResponseGroup',
			'type'  => 'SearchIndex',
			'id'    => 'ItemId'
        );
		foreach ($map as $old => $new) {
            if (isset($queryData[$old])) {
			    $queryData[$new] = $queryData[$old];
			    unset($queryData[$old]);
			}
		}
		// camelize get parameters
		foreach ($queryData as $key => $val) {  
			if (preg_match('/^[a-z]+$/', $key)) {
				$queryData[Inflector::camelize($key)] = $val;
				unset($queryData[$key]);
			}
		}
		
		// merge with default options
		$queryData = am(array(
			'Service' => 'AWSECommerceService',
			'AWSAccessKeyId' => $this->config['accessKey'],
			'ResponseGroup' => 'Small',
			'Timestamp' => date('c'),
			'Version' => '20-10-01'
		), $queryData);
		// sign query
		$queryData = $this->_signRequest($queryData);

		// get the response
		$result = $this->Http->get('http://ecs.amazonaws.com/onca/xml', $queryData);
		if ($result) {
			App::import('Core', 'Xml');
			$xml = new Xml($result);
			$result = Set::reverse($xml);
			unset($xml);
		} else {
			$this->error = $this->Http->lastError();
		}
		
		if($this->error) {
			$this->showError();
			return false;
		} else {
			return $result;
		}
		return false;
	}
	
	/**
	 * Signing requests with your secretKey
	 *
	 * @param string $query 
	 * @param string $options 
	 * @access private
	 * @return string $query with Signature
	 * @author primeminister
	 * @link http://docs.amazonwebservices.com/AWSECommerceService/latest/DG/index.html?RequestAuthenticationArticle.html
	 */
	private function _signRequest($query, $options=array()) {
	    $options = am(array(
	        'method'=>'GET', 
	        'host'=>'ecs.amazonaws.com',
	        'uri'=>'/onca/xml')
	    , $options);
	    
	    // change query string to array
        if (!is_array($query)) {
            $arr = explode('&', $query);
            $query = array();
            foreach ($arr AS $a) {
                list($k,$v) = explode('=', $a);
                $query[$k] = $v;
            }
        }
        // sort the query array based on key
        ksort($query);
        // url encode the params names and its values
        // Do not URL encode any of the unreserved characters that RFC 3986 defines.
        //      These unreserved characters are A-Z, a-z, 0-9, hyphen ( - ), underscore ( _ ), period ( . ), and tilde ( ~ ).
        // Percent encode extended UTF-8 characters in the form %XY%ZA....
        // Percent encode the space character as %20 (and not +, as common encoding schemes do).
        // Percent encode all other characters with %XY, where X and Y are hex characters 0-9 and uppercase A-F.
        $toBeHashed = array();
        foreach ($query AS $k=>$v) {
            $v = urlencode($v);
            $unreserved = array('+','*','%7E');
            $replaced = array('%20','%2A','~');
            $v = str_replace($unreserved, $replaced, $v);
            $toBeHashed[] = urlencode($k).'='.$v;
        }
        // concatenate with & again
        $query_string = implode('&', $toBeHashed);
        // make the string to sign
        $strToSign = $options['method'] ."\n". 
            $options['host'] ."\n".
            $options['uri'] ."\n".
            $query_string;
        // hash and hmac this with the Amazon scretkey
        $query['Signature'] = base64_encode(hash_hmac('sha256', $strToSign, $this->config['secretKey'], true));
        // return sorted query array with the signature.
        return $query;
	}
	
	/**
	 * Shows an error message and outputs result if passed
	 *
	 * @return string 
	 */
	public function showError() {
		if (Configure::read() > 0) {
			if ($this->error) {
				trigger_error('<span style = "color:Red;text-align:left"><b>Amazon eCommerce Error:</b> ' . $this->error . '</span>', E_USER_WARNING);
			}
		}
	}
	
	/**
	 * Read from datasource
	 *
	 * @return void
	 * @access public
	 * @author primeminister
	 **/
	public function read() {
		
	}
	
	/**
	 * Describe datasource
	 *
	 * @return string
	 * @access public
	 * @author primeminister
	 **/
	public function describe() {
	    return $this->description;
	}

	/**
	 * Read column from datasource
	 *
	 * @return void
	 * @access public
	 * @author primeminister
	 **/
	public function column() {
	    
	}
	
	/**
	 * Read from datasource
	 *
	 * @return void
	 * @access public
	 * @author primeminister
	 **/
	public function isConnected() {
	    return $this->connected;
	}
	
	/**
	 * Read from datasource
	 *
	 * @return void
	 * @access public
	 * @author primeminister
	 **/
	public function showLog() {
	    
	}
	
	/**
	* Returns the available  methods
	*
	* @access public
	* @author primeminister
	* @return array List of methods
	*/
	public function listSources() {
		return $this->client->__getFunctions();
	}
}