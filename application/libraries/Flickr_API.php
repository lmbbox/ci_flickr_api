<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
 * CodeIgniter Flickr API
 *
 * A Flickr API Library for CodeIgniter based off phpFlickr (http://phpflickr.com/)
 *
 * @package		CodeIgniter Flickr API
 * @author		LMB^Box (Thomas Montague)
 * @copyright	Copyright (c) 2009 - 2010, LMB^Box
 * @license		GNU Lesser General Public License (http://www.gnu.org/copyleft/lgpl.html)
 * @link		http://lmbbox.com/projects/ci-flickr-api/
 * @version		Version 0.3
 * @filesource
 */

// ------------------------------------------------------------------------

/**
 * Flickr API Class
 *
 * @package		CodeIgniter Flickr API
 * @subpackage	Libraries
 * @category	Flickr API
 * @author		LMB^Box (Thomas Montague)
 * @link		http://codeigniter.lmbbox.com/user_guide/libraries/flickr_api.html
 * @version		Version 0.3
 */
class Flickr_API {
	
	const VERSION						= 0.3;
	const API_AUTH_URL					= 'http://www.flickr.com/services/auth/'; // http://www.23hq.com/services/auth/
	const API_REST_URL					= 'http://api.flickr.com/services/rest/';
	const API_XMLRPC_URL				= 'http://api.flickr.com/services/xmlrpc/';
	const API_UPLOAD_URL				= 'http://api.flickr.com/services/upload/';
	const API_REPLACE_URL				= 'http://api.flickr.com/services/replace/';
	const REQUEST_FORMAT_REST			= 'rest';
	const REQUEST_FORMAT_XMLRPC			= 'xmlrpc';
	const REQUEST_FORMAT_SOAP			= 'soap';
	const RESPONSE_FORMAT_REST			= 'rest';
	const RESPONSE_FORMAT_XMLRPC		= 'xmlrpc';
	const RESPONSE_FORMAT_SOAP			= 'soap';
	const RESPONSE_FORMAT_JSON			= 'json';
	const RESPONSE_FORMAT_PHP_SERIAL	= 'php_serial';
	const PHOTO_SIZE_ORIGINAL			= 'original';
	const PHOTO_SIZE_LARGE				= 'large';
	const PHOTO_SIZE_MEDIUM				= 'medium';
	const PHOTO_SIZE_SMALL				= 'small';
	const PHOTO_SIZE_THUMBNAIL			= 'thumbnail';
	const PHOTO_SIZE_SQUARE				= 'square';
	
	protected $request_format			= '';
	protected $response_format			= '';
	protected $api_key					= '';
	protected $secret					= '';
	protected $token					= '';
	protected $cache_use_db				= FALSE;
	protected $cache_table_name			= 'flickr_api_cache';
	protected $cache_expiration			= 600;
	protected $cache_max_rows			= 1000;
	protected $parse_response			= TRUE;
	protected $exit_on_error			= FALSE;
	protected $debug					= FALSE;
	protected $error_code				= FALSE;
	protected $error_message			= FALSE;
	protected $response;
	protected $parsed_response;
	protected $CI;
	
	/**
	 * Constructor
	 *
	 * @access	public
	 * @param	array $params initialization parameters
	 * @return	void
	 */
	public function __construct($params = array())
	{
		// Set the super object to a local variable for use throughout the class
		$this->CI =& get_instance();
		$this->CI->lang->load('flickr_api');
		
		// Initialize Parameters
		$this->initialize($params);
		
		log_message('debug', 'Flickr_API Class Initialized');
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Initialize Preferences
	 *
	 * @access	public
	 * @param	array $params initialization parameters
	 * @return	void
	 */
	public function initialize($params = array())
	{
		if (is_array($params) && !empty($params))
		{
			// Protect restricted variables
			unset($params['CI']);
			unset($params['error_code']);
			unset($params['error_message']);
			unset($params['response']);
			unset($params['parsed_response']);
			
			foreach ($params as $key => $val)
			{
				if (isset($this->$key))
				{
					$this->$key = $val;
				}
			}
		}
		
		// Start cache if enabled
		if (TRUE === $this->cache_use_db) $this->start_cache(TRUE);
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Toggle Debugging
	 * 
	 * @access	public
	 * @param	bool $debug
	 * @return	void
	 */
	public function set_debug($debug)
	{
		$this->debug = (bool) $debug;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Set current authentication token
	 * 
	 * @access	public
	 * @param	string $token authentication token
	 * @return	bool
	 */
	public function set_token($token)
	{
		if ('' == $token = trim((string) $token))
		{
			log_message('error', __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		$this->token = $token;
		return TRUE;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Start Cache
	 * 
	 * @access	public
	 * @param	bool $run_cleanup
	 * @return	void
	 */
	public function start_cache($run_cleanup = FALSE)
	{
		$this->cache_use_db = TRUE;
		$this->_create_table_cache();
		if (TRUE === $run_cleanup) $this->cleanup_cache();
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Stop Cache
	 * 
	 * @access	public
	 * @return	void
	 */
	public function stop_cache()
	{
		$this->cache_use_db = FALSE;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Cleanup Cache
	 * 
	 * @access	public
	 * @return	bool
	 */
	public function cleanup_cache()
	{
		if (FALSE === $this->cache_use_db || '' == $this->cache_table_name)
		{
			return FALSE;
		}
		
		if ($this->CI->db->count_all($this->cache_table_name) > $this->cache_max_rows)
		{
			$this->CI->db->where('expire_date <', time() - $this->cache_expiration);
			$this->CI->db->delete($this->cache_table_name);
			
			$this->CI->load->dbutil();
			$this->CI->dbutil->optimize_table($this->cache_table_name);
		}
		return TRUE;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Create Cache Table
	 * 
	 * @access	protected
	 * @return	bool
	 */
	protected function _create_table_cache()
	{
		if (FALSE === $this->cache_use_db || '' == $this->cache_table_name)
		{
			return FALSE;
		}
		
		$this->CI->load->database();
		if (FALSE === $this->CI->db->table_exists($this->cache_table_name))
		{
			$fields['request'] = array('type' => 'CHAR', 'constraint' => '35', 'null' => FALSE);
			$fields['response'] = array('type' => 'MEDIUMTEXT', 'null' => FALSE);
			$fields['expire_date'] = array('type' => 'INT', 'constraint' => '10', 'unsigned' => TRUE, 'null' => FALSE, 'default' => '0');
			
			$this->CI->load->dbforge();
			$this->CI->dbforge->add_field($fields);
			$this->CI->dbforge->add_key('request', TRUE);
			$this->CI->dbforge->create_table($this->cache_table_name);
			
//			$this->CI->db->query('ALTER TABLE `' . $this->CI->db->dbprefix . $this->cache_table_name . '` ENGINE=InnoDB;');
		}
		return TRUE;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Get Cached Request
	 * 
	 * @access	protected
	 * @param	array $request
	 * @return	string|bool
	 */
	protected function _get_cached($request)
	{
		if (!is_array($request) || empty($request))
		{
			log_message('error', __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		if (FALSE === $this->cache_use_db || '' == $this->cache_table_name)
		{
			return FALSE;
		}
		
		$this->CI->db->select('response');
		$this->CI->db->where('request', md5(serialize($request)));
		$this->CI->db->where('expire_date >=', time() - $this->cache_expiration);
		$query = $this->CI->db->get($this->cache_table_name);
		
		if ($query->num_rows() == 0)
		{
			return FALSE;
		}
		
		$row = $query->result_array();
		return $row[0]['response'];
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Cache Request
	 * 
	 * @access	protected
	 * @param	array $request
	 * @param	string $response
	 * @return	bool
	 */
	protected function _cache($request, $response)
	{
		if (!is_array($request) || empty($request) || empty($response))
		{
			log_message('error', __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		if (FALSE === $this->cache_use_db || '' == $this->cache_table_name)
		{
			return FALSE;
		}
		
		$request_hash = md5(serialize($request));
		
		$this->CI->db->where('request', $request_hash);
		$query = $this->CI->db->get($this->cache_table_name);
		
		if ($query->num_rows() == 1)
		{
			$this->CI->db->set('response', $response);
			$this->CI->db->set('expire_date', time() + $this->cache_expiration);
			$this->CI->db->where('request', $request_hash);
			$this->CI->db->update($this->cache_table_name);
			
			if ($this->CI->db->affected_rows() != 1)
			{
				log_message('error', __METHOD__ . ' - ' . sprintf($this->CI->lang->line('flickr_api_error_updating_cache'), $this->cache_table_name), '%2$s');
				return FALSE;
			}
		}
		else
		{
			$this->CI->db->set('request', $request_hash);
			$this->CI->db->set('response', $response);
			$this->CI->db->set('expire_date', time() + $this->cache_expiration);
			if (FALSE === $this->CI->db->insert($this->cache_table_name))
			{
				log_message('error', __METHOD__ . ' - ' . sprintf($this->CI->lang->line('flickr_api_error_creating_cache'), $this->cache_table_name), '%2$s');
				return FALSE;
			}
		}
		
		return TRUE;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Reset Error
	 * 
	 * @access	protected
	 * @return	void
	 */
	protected function _reset_error()
	{
		$this->error_code = FALSE;
		$this->error_message = FALSE;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Set Error
	 * 
	 * @access	protected
	 * @param	string $error_code
	 * @param	string $error_message
	 * @param	string $exit_message
	 * @return	void
	 */
	protected function _error($error_code, $error_message, $exit_message)
	{
		if (TRUE === $this->debug)
		{
			log_message('debug', sprintf($exit_message, $error_code, $error_message));
		}
		
		if (TRUE === $this->exit_on_error)
		{
			exit(sprintf($exit_message, $error_code, $error_message));
		}
		else
		{
			$this->error_code = $error_code;
			$this->error_message = $error_message;
		}
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Get Error Code
	 * 
	 * @access	public
	 * @return	string
	 */
	public function get_error_code()
	{
		return $this->error_code;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Get Error Message
	 * 
	 * @access	public
	 * @return	string
	 */
	public function get_error_message()
	{
		return $this->error_message;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Request
	 * 
	 * @access	public
	 * @param	string $method flickr api method
	 * @param	array $params method arguments
	 * @param	bool $nocache use cache or not
	 * @return	mixed
	 */
	public function request($method, $params = array(), $nocache = FALSE)
	{
		if ('' == $this->request_format || '' == $this->response_format || '' == $this->api_key || '' == $this->secret)
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_required_config_missing'), '%2$s');
			return FALSE;
		}
		
		if ('' == $method || !is_array($params))
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		foreach ($params as $param => $value)
		{
			if (is_null($value))
			{
				unset($params[$param]);
			}
		}
		
		$params = array_merge($params, array('method' => $method, 'api_key' => $this->api_key, 'format' => $this->response_format));
		if ('' != $this->token)
		{
			$params = array_merge($params, array('auth_token' => $this->token));
		}
		ksort($params);
		
		$this->_reset_error();
		$this->response = $this->_get_cached($params);
		
		if (FALSE === $this->response || TRUE === $nocache)
		{
			if (self::REQUEST_FORMAT_XMLRPC == $this->request_format)
			{
				unset($params['method']);
			}
			
			if ('' != $this->secret)
			{
				$auth_sig = '';
				foreach ($params as $param => $value)
				{
					$auth_sig .= $param . $value;
				}
				$api_sig = md5($this->secret . $auth_sig);
				$params = array_merge($params, array('api_sig' => $api_sig));
			}
			
			switch ($this->request_format)
			{
				case self::REQUEST_FORMAT_REST:
					if (FALSE === $this->_send_rest($params))
					{
						return FALSE;
					}
					break;
				case self::REQUEST_FORMAT_XMLRPC:
					if (FALSE === $this->_send_xmlrpc($params))
					{
						return FALSE;
					}
					break;
				case self::REQUEST_FORMAT_SOAP:
					if (FALSE === $this->_send_soap($params))
					{
						return FALSE;
					}
					break;
				default:
					$this->_error(TRUE, __METHOD__ . ' - ' . sprintf($this->CI->lang->line('flickr_api_invalid_request_format'), $this->request_format), '%2$s');
					return FALSE;
					break;
			}
		}
		
		return TRUE === $this->parse_response ? $this->parsed_response = $this->parse_response($this->response) : $this->response;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Send Rest API Call
	 * 
	 * @access	protected
	 * @param	array $params flickr api call
	 * @return	bool
	 */
	protected function _send_rest($params)
	{
		if (!is_array($params) || empty($params))
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		$session = curl_init(self::API_REST_URL);
		curl_setopt($session, CURLOPT_POST, TRUE);
		curl_setopt($session, CURLOPT_POSTFIELDS, $params);
		curl_setopt($session, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($session, CURLOPT_FAILONERROR, TRUE);
		$this->response = curl_exec($session);
		
		if (TRUE === $this->debug)
		{
			log_message('debug', __METHOD__ . ' - cURL Request Info: ' . print_r(curl_getinfo($session), TRUE));
		}
		
		if (FALSE === $this->response)
		{
			$this->_error(curl_errno($session), curl_error($session), $this->CI->lang->line('flickr_api_send_request_error'));
			curl_close($session);
			return FALSE;
		}
		
		curl_close($session);
		$this->_cache($params, $this->response);
		return TRUE;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Send XMLRPC API Call
	 * 
	 * @access	protected
	 * @param	array $params flickr api call
	 * @return	bool
	 */
	protected function _send_xmlrpc($params)
	{
		if (!is_array($params) || empty($params) || '' == $params['method'])
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		$this->CI->load->library('xmlrpc');
		$this->CI->xmlrpc->set_debug(TRUE === $this->debug ? TRUE : FALSE);
		$this->CI->xmlrpc->server(self::API_XMLRPC_URL);
		$this->CI->xmlrpc->method($params['method']);
		$this->CI->xmlrpc->request(array(array($params, 'struct')));
		
		if (FALSE === $this->CI->xmlrpc->send_request())
		{
			$this->_error($this->CI->xmlrpc->result->errno, $this->CI->xmlrpc->display_error(), $this->CI->lang->line('flickr_api_send_request_error'));
			return FALSE;
		}
		
		$this->response = $this->CI->xmlrpc->display_response();
		$this->_cache($params, $this->response);
		return TRUE;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Parse Response
	 * 
	 * @access	public
	 * @param	string $response flickr api call response
	 * @return	mixed
	 */
	public function parse_response($response)
	{
		if ('' == $this->response_format)
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_required_config_missing'), '%2$s');
			return FALSE;
		}
		
		if ('' == $response)
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		switch ($this->response_format)
		{
			case self::RESPONSE_FORMAT_REST:
				
				break;
			case self::RESPONSE_FORMAT_XMLRPC:
				if (!class_exists('SimpleXMLElement'))
				{
					$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_simplexmlelement_missing'), '%2$s');
					return FALSE;
				}
				
				return new SimpleXMLElement($response);
				break;
			case self::RESPONSE_FORMAT_SOAP:
				
				break;
			case self::RESPONSE_FORMAT_JSON:
				
				break;
			case self::RESPONSE_FORMAT_PHP_SERIAL:
				$response = $this->_parse_php_serial(unserialize($response));
				
				if ($response['stat'] != 'ok')
				{
					$this->_error($response['code'], $response['message'], $this->CI->lang->line('flickr_api_returned_error'));
					return FALSE;
				}
				
				return $response;
				break;
			default:
				$this->_error(TRUE, __METHOD__ . ' - ' . sprintf($this->CI->lang->line('flickr_api_invalid_response_format'), $this->response_format), '%2$s');
				return FALSE;
				break;
		}
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Parse PHP Serial Response
	 * 
	 * @access	protected
	 * @param	mixed $response
	 * @return	mixed
	 */
	protected function _parse_php_serial($response)
	{
		if (!is_array($response))
		{
			return $response;
		}
		elseif (count($response) == 0)
		{
			return $response;
		}
		elseif (count($response) == 1 && array_key_exists('_content', $response))
		{
			return $response['_content'];
		}
		else
		{
			foreach ($response as $key => $value)
			{
				$response[$key] = $this->_parse_php_serial($value);
			}
			return($response);
		}
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Authenticate
	 * 
	 * @access	public
	 * @param	string $permission api account permission level
	 * @param	string $redirect redirection url
	 * @return	string|bool
	 */
	public function authenticate($permission = 'read', $redirect = NULL)
	{
		if ('' == $this->api_key || '' == $this->secret)
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_required_config_missing'), '%2$s');
			return FALSE;
		}
		
		$this->_reset_error();
		if (empty($this->token))
		{
			$this->CI->load->helper('url');
			$redirect = is_null($redirect) ? uri_string() : $redirect;
			$api_sig = md5($this->secret . 'api_key' . $this->api_key . 'extra' . $redirect . 'perms' . $permission);
			header('Location: ' . self::API_AUTH_URL . '?api_key=' . $this->api_key . '&extra=' . $redirect . '&perms=' . $permission . '&api_sig='. $api_sig);
			exit();
		}
		else
		{
			$exit_on_error = $this->exit_on_error;
			$this->exit_on_error = false;
			$response = $this->auth_checkToken();
			if (FALSE !== $this->get_error_code()) $this->authenticate($permission, $redirect);
			$this->exit_on_error = $exit_on_error;
			return $response['perms'];
		}
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Get Photo URL
	 * 
	 * @access	public
	 * @param	int|string $id
	 * @param	int|string $farm
	 * @param	int|string $server
	 * @param	string $secret
	 * @param	string $size
	 * @param	string $original_secret
	 * @param	string $original_format
	 * @return	string|bool
	 * @static
	 */
	public function get_photo_url($id, $farm, $server, $secret, $size = self::PHOTO_SIZE_MEDIUM, $original_secret = '', $original_format = '')
	{
		if (empty($id) || empty($farm) || empty($server) || empty($secret))
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		switch ($size)
		{
			case self::PHOTO_SIZE_ORIGINAL:
				if ('' == $original_secret && '' == $original_format)
				{
					$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_photo_missing_org_secret_format'), '%2$s');
					return FALSE;
				}
				
				return 'http://farm' . $farm . '.static.flickr.com/' . $server . '/'. $id . '_' . $original_secret . '_o.' . $original_format;
				break;
			case self::PHOTO_SIZE_LARGE:
				return 'http://farm' . $farm . '.static.flickr.com/' . $server . '/'. $id . '_' . $secret . '_b.jpg';
				break;
			case self::PHOTO_SIZE_MEDIUM:
				return 'http://farm' . $farm . '.static.flickr.com/' . $server . '/'. $id . '_' . $secret . '.jpg';
				break;
			case self::PHOTO_SIZE_SMALL:
				return 'http://farm' . $farm . '.static.flickr.com/' . $server . '/'. $id . '_' . $secret . '_m.jpg';
				break;
			case self::PHOTO_SIZE_THUMBNAIL:
				return 'http://farm' . $farm . '.static.flickr.com/' . $server . '/'. $id . '_' . $secret . '_t.jpg';
				break;
			case self::PHOTO_SIZE_SQUARE:
				return 'http://farm' . $farm . '.static.flickr.com/' . $server . '/'. $id . '_' . $secret . '_s.jpg';
				break;
			default:
				$this->_error(TRUE, __METHOD__ . ' - ' . sprintf($this->CI->lang->line('flickr_api_photo_size_unknown'), $size), '%2$s');
				return FALSE;
				break;
		}
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Get Buddy Icon URL
	 * 
	 * @access	public
	 * @param	string $nsid
	 * @param	int|string $icon_farm
	 * @param	int|string $icon_server
	 * @param	bool $return_default
	 * @return	string|bool
	 * @static
	 */
	public function get_buddy_icon_url($nsid, $icon_farm, $icon_server, $return_default = TRUE)
	{
		if ('' == $nsid || empty($icon_farm))
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		if ($icon_server > 0)
		{
			return 'http://farm' . $icon_farm . '.static.flickr.com/' . $icon_server . '/buddyicons/' . $nsid . '.jpg';
		}
		elseif (TRUE === $return_default)
		{
			return 'http://www.flickr.com/images/buddyicon.jpg';
		}
	}
	
	// --------------------------------------------------------------------------
	
	
	// Functions needing to be finished
	
	function sync_upload ($photo, $title = null, $description = null, $tags = null, $is_public = null, $is_friend = null, $is_family = null) {
		$upload_req =& new HTTP_Request();
		$upload_req->setMethod(HTTP_REQUEST_METHOD_POST);


		$upload_req->setURL($this->Upload);
		$upload_req->clearPostData();

		//Process arguments, including method and login data.
		$args = array("api_key" => $this->api_key, "title" => $title, "description" => $description, "tags" => $tags, "is_public" => $is_public, "is_friend" => $is_friend, "is_family" => $is_family);
		if (!empty($this->email)) {
			$args = array_merge($args, array("email" => $this->email));
		}
		if (!empty($this->password)) {
			$args = array_merge($args, array("password" => $this->password));
		}
		if (!empty($this->token)) {
			$args = array_merge($args, array("auth_token" => $this->token));
		} elseif (!empty($_SESSION['phpFlickr_auth_token'])) {
			$args = array_merge($args, array("auth_token" => $_SESSION['phpFlickr_auth_token']));
		}

		ksort($args);
		$auth_sig = "";
		foreach ($args as $key => $data) {
			if ($data !== null) {
				$auth_sig .= $key . $data;
				$upload_req->addPostData($key, $data);
			}
		}
		if (!empty($this->secret)) {
			$api_sig = md5($this->secret . $auth_sig);
			$upload_req->addPostData("api_sig", $api_sig);
		}

		$photo = realpath($photo);

		$result = $upload_req->addFile("photo", $photo);

		if (PEAR::isError($result)) {
			die($result->getMessage());
		}

		//Send Requests
		if ($upload_req->sendRequest()) {
			$this->response = $upload_req->getResponseBody();
		} else {
			die("There has been a problem sending your command to the server.");
		}

		$rsp = explode("\n", $this->response);
		foreach ($rsp as $line) {
			if (ereg('<err code="([0-9]+)" msg="(.*)"', $line, $match)) {
				if ($this->die_on_error)
					die("The Flickr API returned the following error: #{$match[1]} - {$match[2]}");
				else {
					$this->error_code = $match[1];
					$this->error_msg = $match[2];
					$this->parsed_response = false;
					return false;
				}
			} elseif (ereg("<photoid>(.*)</photoid>", $line, $match)) {
				$this->error_code = false;
				$this->error_msg = false;
				return $match[1];
			}
		}
	}

	function async_upload ($photo, $title = null, $description = null, $tags = null, $is_public = null, $is_friend = null, $is_family = null) {
		$upload_req =& new HTTP_Request();
		$upload_req->setMethod(HTTP_REQUEST_METHOD_POST);

		$upload_req->setURL($this->Upload);
		$upload_req->clearPostData();

		//Process arguments, including method and login data.
		$args = array("async" => 1, "api_key" => $this->api_key, "title" => $title, "description" => $description, "tags" => $tags, "is_public" => $is_public, "is_friend" => $is_friend, "is_family" => $is_family);
		if (!empty($this->email)) {
			$args = array_merge($args, array("email" => $this->email));
		}
		if (!empty($this->password)) {
			$args = array_merge($args, array("password" => $this->password));
		}
		if (!empty($this->token)) {
			$args = array_merge($args, array("auth_token" => $this->token));
		} elseif (!empty($_SESSION['phpFlickr_auth_token'])) {
			$args = array_merge($args, array("auth_token" => $_SESSION['phpFlickr_auth_token']));
		}

		ksort($args);
		$auth_sig = "";
		foreach ($args as $key => $data) {
			if ($data !== null) {
				$auth_sig .= $key . $data;
				$upload_req->addPostData($key, $data);
			}
		}
		if (!empty($this->secret)) {
			$api_sig = md5($this->secret . $auth_sig);
			$upload_req->addPostData("api_sig", $api_sig);
		}

		$photo = realpath($photo);

		$result = $upload_req->addFile("photo", $photo);

		if (PEAR::isError($result)) {
			die($result->getMessage());
		}

		//Send Requests
		if ($upload_req->sendRequest()) {
			$this->response = $upload_req->getResponseBody();
		} else {
			die("There has been a problem sending your command to the server.");
		}

		$rsp = explode("\n", $this->response);
		foreach ($rsp as $line) {
			if (ereg('<err code="([0-9]+)" msg="(.*)"', $line, $match)) {
				if ($this->die_on_error)
					die("The Flickr API returned the following error: #{$match[1]} - {$match[2]}");
				else {
					$this->error_code = $match[1];
					$this->error_msg = $match[2];
					$this->parsed_response = false;
					return false;
				}
			} elseif (ereg("<ticketid>(.*)</", $line, $match)) {
				$this->error_code = false;
				$this->error_msg = false;
				return $match[1];
			}
		}
	}

	// Interface for new replace API method.
	function replace ($photo, $photo_id, $async = null) {
		$upload_req =& new HTTP_Request();
		$upload_req->setMethod(HTTP_REQUEST_METHOD_POST);

		$upload_req->setURL($this->Replace);
		$upload_req->clearPostData();

		//Process arguments, including method and login data.
		$args = array("api_key" => $this->api_key, "photo_id" => $photo_id, "async" => $async);
		if (!empty($this->email)) {
			$args = array_merge($args, array("email" => $this->email));
		}
		if (!empty($this->password)) {
			$args = array_merge($args, array("password" => $this->password));
		}
		if (!empty($this->token)) {
			$args = array_merge($args, array("auth_token" => $this->token));
		} elseif (!empty($_SESSION['phpFlickr_auth_token'])) {
			$args = array_merge($args, array("auth_token" => $_SESSION['phpFlickr_auth_token']));
		}

		ksort($args);
		$auth_sig = "";
		foreach ($args as $key => $data) {
			if ($data !== null) {
				$auth_sig .= $key . $data;
				$upload_req->addPostData($key, $data);
			}
		}
		if (!empty($this->secret)) {
			$api_sig = md5($this->secret . $auth_sig);
			$upload_req->addPostData("api_sig", $api_sig);
		}

		$photo = realpath($photo);

		$result = $upload_req->addFile("photo", $photo);

		if (PEAR::isError($result)) {
			die($result->getMessage());
		}

		//Send Requests
		if ($upload_req->sendRequest()) {
			$this->response = $upload_req->getResponseBody();
		} else {
			die("There has been a problem sending your command to the server.");
		}
		if ($async == 1)
			$find = 'ticketid';
		 else
			$find = 'photoid';

		$rsp = explode("\n", $this->response);
		foreach ($rsp as $line) {
			if (ereg('<err code="([0-9]+)" msg="(.*)"', $line, $match)) {
				if ($this->die_on_error)
					die("The Flickr API returned the following error: #{$match[1]} - {$match[2]}");
				else {
					$this->error_code = $match[1];
					$this->error_msg = $match[2];
					$this->parsed_response = false;
					return false;
				}
			} elseif (ereg("<" . $find . ">(.*)</", $line, $match)) {
				$this->error_code = false;
				$this->error_msg = false;
				return $match[1];
			}
		}
	}







	
	// --------------------------------------------------------------------------
	
	/**
	 * Activity User Comments
	 * 
	 * Returns a list of recent activity on photos commented on by the calling 
	 * user. Do not poll this method more than once an hour.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.activity.userComments.html
	 * @param	int $page
	 * @param	int $per_page
	 * @return	mixed
	 */
	public function activity_userComments($per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.activity.userComments', array('per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Activity User Photos
	 * 
	 * Returns a list of recent activity on photos belonging to the calling 
	 * user. Do not poll this method more than once an hour.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.activity.userPhotos.html
	 * @param	string $timeframe
	 * @param	int $page
	 * @param	int $per_page
	 * @return	mixed
	 */
	public function activity_userPhotos($timeframe = NULL, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.activity.userPhotos', array('timeframe' => $timeframe, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Authentication Check Token
	 * 
	 * Returns the credentials attached to an authentication token. This call 
	 * must be signed as specified in the authentication API spec.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.auth.checkToken.html
	 * @return	mixed
	 */
	public function auth_checkToken()
	{
		return $this->request('flickr.auth.checkToken');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Authentication Get Frob
	 * 
	 * Returns a frob to be used during authentication. This method call must be 
	 * signed.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.auth.getFrob.html
	 * @return	mixed
	 */
	public function auth_getFrob()
	{
		return $this->request('flickr.auth.getFrob');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Authentication Get Full Token
	 * 
	 * Get the full authentication token for a mini-token. This method call must 
	 * be signed.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.auth.getFullToken.html
	 * @param	string $mini_token
	 * @return	mixed
	 */
	public function auth_getFullToken($mini_token)
	{
		return $this->request('flickr.auth.getFullToken', array('mini_token' => $mini_token));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Authentication Get Token
	 * 
	 * Returns the auth token for the given frob, if one has been attached. This 
	 * method call must be signed.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.auth.getToken.html
	 * @param	string $frob
	 * @return	mixed
	 */
	public function auth_getToken($frob)
	{
		return $this->request('flickr.auth.getToken', array('frob' => $frob));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Blogs Get List
	 * 
	 * Get a list of configured blogs for the calling user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.blogs.getList.html
	 * @param	string $service
	 * @return	mixed
	 */
	public function blogs_getList($service = NULL)
	{
		return $this->request('flickr.blogs.getList', array('service' => $service));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Blogs Get Services
	 * 
	 * Return a list of Flickr supported blogging services.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.blogs.getServices.html
	 * @return	mixed
	 */
	public function blogs_getServices()
	{
		return $this->request('flickr.blogs.getServices');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Blogs Post Photo
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.blogs.postPhoto.html
	 * @param	string $blog_id
	 * @param	int $photo_id
	 * @param	string $title
	 * @param	string $description
	 * @param	string $blog_password
	 * @param	string $service
	 * @return	mixed
	 */
	public function blogs_postPhoto($blog_id = NULL, $photo_id, $title, $description, $blog_password = NULL, $service = NULL)
	{
		return $this->request('flickr.blogs.postPhoto', array('blog_id' => $blog_id, 'photo_id' => $photo_id, 'title' => $title, 'description' => $description, 'blog_password' => $blog_password, 'service' => $service), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Collections Get Info
	 * 
	 * Returns information for a single collection. Currently can only be called 
	 * by the collection owner, this may change.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.collections.getInfo.html
	 * @param	string $collection_id
	 * @return	mixed
	 */
	public function collections_getInfo($collection_id)
	{
		return $this->request('flickr.collections.getInfo', array('collection_id' => $collection_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Collections Get Tree
	 * 
	 * Returns a tree (or sub tree) of collections belonging to a given user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.collections.getTree.html
	 * @param	string $collection_id
	 * @param	string $user_id
	 * @return	mixed
	 */
	public function collections_getTree($collection_id = NULL, $user_id = NULL)
	{
		return $this->request('flickr.collections.getTree', array('collection_id' => $collection_id, 'user_id' => $user_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Commons Get Institutions
	 * 
	 * Retrieves a list of the current Commons institutions.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.commons.getInstitutions.html
	 * @return	mixed
	 */
	public function commons_getInstitutions()
	{
		return $this->request('flickr.commons.getInstitutions');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Contacts Get List
	 * 
	 * Get a list of contacts for the calling user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.contacts.getList.html
	 * @param	string $filter
	 * @param	int $page
	 * @param	int $per_page
	 * @return	mixed
	 */
	public function contacts_getList($filter = NULL, $page = NULL, $per_page = NULL)
	{
		return $this->request('flickr.contacts.getList', array('filter' => $filter, 'page' => $page, 'per_page' => $per_page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Contacts Get Public List
	 * 
	 * Get the contact list for a user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.contacts.getPublicList.html
	 * @param	string $user_id
	 * @param	int $page
	 * @param	int $per_page
	 * @return	mixed
	 */
	public function contacts_getPublicList($user_id, $page = NULL, $per_page = NULL)
	{
		return $this->request('flickr.contacts.getPublicList', array('user_id' => $user_id, 'page' => $page, 'per_page' => $per_page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Contacts Get List Recently Uploaded
	 * 
	 * Return a list of contacts for a user who have recently uploaded photos 
	 * along with the total count of photos uploaded. This method is still 
	 * considered experimental. We don't plan for it to change or to go away but 
	 * so long as this notice is present you should write your code accordingly.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.contacts.getListRecentlyUploaded.html
	 * @param	int $date_lastupload
	 * @param	string $filter
	 * @return	mixed
	 */
	public function contacts_getListRecentlyUploaded($date_lastupload = NULL, $filter = NULL)
	{
		return $this->request('flickr.contacts.getListRecentlyUploaded', array('date_lastupload' => $date_lastupload, 'filter' => $filter));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Favorites Add
	 * 
	 * Adds a photo to a user's favorites list.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.favorites.add.html
	 * @param	int $photo_id
	 * @return	mixed
	 */
	public function favorites_add($photo_id)
	{
		return $this->request('flickr.favorites.add', array('photo_id' => $photo_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Favorites Get List
	 * 
	 * Returns a list of the user's favorite photos. Only photos which the 
	 * calling user has permission to see are returned.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.favorites.getList.html
	 * @param	string $user_id
	 * @param	int $min_fave_date
	 * @param	int $max_fave_date
	 * @param	string $extras
	 * @param	int $per_page
	 * @param	int $page
	 * @return	mixed
	 */
	public function favorites_getList($user_id = NULL, $min_fave_date = NULL, $max_fave_date = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.favorites.getList', array('user_id' => $user_id, 'min_fave_date' => $min_fave_date, 'max_fave_date' => $max_fave_date, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Favorites Get Public List
	 * 
	 * Returns a list of favorite public photos for the given user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.favorites.getPublicList.html
	 * @param	string $user_id
	 * @param	int $min_fave_date
	 * @param	int $max_fave_date
	 * @param	string $extras
	 * @param	int $per_page
	 * @param	int $page
	 * @return	mixed
	 */
	public function favorites_getPublicList($user_id, $min_fave_date = NULL, $max_fave_date = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.favorites.getPublicList', array('user_id' => $user_id, 'min_fave_date' => $min_fave_date, 'max_fave_date' => $max_fave_date, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Favorites Remove
	 * 
	 * Removes a photo from a user's favorites list.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.favorites.remove.html
	 * @param	int $photo_id
	 * @return	mixed
	 */
	public function favorites_remove($photo_id)
	{
		return $this->request('flickr.favorites.remove', array('photo_id' => $photo_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Groups Browse
	 * 
	 * Browse the group category tree, finding groups and sub-categories.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.groups.browse.html
	 * @param	int $cat_id
	 * @return	mixed
	 */
	public function groups_browse($cat_id = NULL)
	{
		return $this->request('flickr.groups.browse', array('cat_id' => $cat_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Groups Get Info
	 * 
	 * Get information about a group.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.groups.getInfo.html
	 * @param	string $group_id
	 * @param	string $lang
	 * @return	mixed
	 */
	public function groups_getInfo($group_id, $lang = NULL)
	{
		return $this->request('flickr.groups.getInfo', array('group_id' => $group_id, 'lang' => $lang));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Groups Search
	 * 
	 * Search for groups. 18+ groups will only be returned for authenticated 
	 * calls where the authenticated user is over 18.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.groups.search.html
	 * @param	string $text
	 * @param	int $per_page
	 * @param	int $page
	 * @return	mixed
	 */
	public function groups_search($text, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.groups.search', array('text' => $text, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Groups Members Get List
	 * 
	 * Get a list of the members of a group. The call must be signed on behalf 
	 * of a Flickr member, and the ability to see the group membership will be 
	 * determined by the Flickr member's group privileges.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.groups.members.getList.html
	 * @param	string $group_id
	 * @param	string $membertypes
	 * @param	int $per_page
	 * @param	int $page
	 * @return	mixed
	 */
	public function groups_members_getList($group_id, $membertypes = NULL, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.groups.members.getList', array('group_id' => $group_id, 'membertypes' => $membertypes, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Groups Pools Add
	 * 
	 * Add a photo to a group's pool.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.groups.pools.add.html
	 * @param	int $photo_id
	 * @param	string $group_id
	 * @return	mixed
	 */
	public function groups_pools_add($photo_id, $group_id)
	{
		return $this->request('flickr.groups.pools.add', array('photo_id' => $photo_id, 'group_id' => $group_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Groups Pools Get Context
	 * 
	 * Returns next and previous photos for a photo in a group pool.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.groups.pools.getContext.html
	 * @param	int $photo_id
	 * @param	string $group_id
	 * @return	mixed
	 */
	public function groups_pools_getContext($photo_id, $group_id)
	{
		return $this->request('flickr.groups.pools.getContext', array('photo_id' => $photo_id, 'group_id' => $group_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Groups Pools Get Groups
	 * 
	 * Returns a list of groups to which you can add photos.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.groups.pools.getGroups.html
	 * @param	int $per_page
	 * @param	int $page
	 * @return	mixed
	 */
	public function groups_pools_getGroups($page = NULL, $per_page = NULL)
	{
		return $this->request('flickr.groups.pools.getGroups', array('page' => $page, 'per_page' => $per_page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Groups Pools Get Photos
	 * 
	 * Returns a list of pool photos for a given group, based on the permissions 
	 * of the group and the user logged in (if any).
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.groups.pools.getPhotos.html
	 * @param	string $group_id
	 * @param	string $tags
	 * @param	string $user_id
	 * @param	string $extras
	 * @param	int $per_page
	 * @param	int $page
	 * @return	mixed
	 */
	public function groups_pools_getPhotos($group_id, $tags = NULL, $user_id = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.groups.pools.getPhotos', array('group_id' => $group_id, 'tags' => $tags, 'user_id' => $user_id, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Groups Pools Remove
	 * 
	 * Remove a photo from a group pool.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.groups.pools.remove.html
	 * @param	int $photo_id
	 * @param	string $group_id
	 * @return	mixed
	 */
	public function groups_pools_remove($photo_id, $group_id)
	{
		return $this->request('flickr.groups.pools.remove', array('photo_id' => $photo_id, 'group_id' => $group_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Interestingness Get List
	 * 
	 * Returns the list of interesting photos for the most recent day or a 
	 * user-specified date.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.interestingness.getList.html
	 * @param	string $date
	 * @param	string $extras
	 * @param	int $per_page
	 * @param	int $page
	 * @return	mixed
	 */
	public function interestingness_getList($date = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.interestingness.getList', array('date' => $date, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Machine Tags Get Namespaces
	 * 
	 * Return a list of unique namespaces, optionally limited by a given 
	 * predicate, in alphabetical order.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.machinetags.getNamespaces.html
	 * @param	string $predicate
	 * @param	int $per_page
	 * @param	int $page
	 * @return	mixed
	 */
	public function machinetags_getNamespaces($predicate = NULL, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.machinetags.getNamespaces', array('predicate' => $predicate, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Machine Tags Get Pairs
	 * 
	 * Return a list of unique namespace and predicate pairs, optionally limited 
	 * by predicate or namespace, in alphabetical order.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.machinetags.getPairs.html
	 * @param	string $namespace
	 * @param	string $predicate
	 * @param	int $per_page
	 * @param	int $page
	 * @return	mixed
	 */
	public function machinetags_getPairs($namespace = NULL, $predicate = NULL, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.machinetags.getPairs', array('namespace' => $namespace, 'predicate' => $predicate, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Machine Tags Get Predicates
	 * 
	 * Return a list of unique predicates, optionally limited by a given 
	 * namespace.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.machinetags.getPredicates.html
	 * @param	string $namespace
	 * @param	int $per_page
	 * @param	int $page
	 * @return	mixed
	 */
	public function machinetags_getPredicates($namespace = NULL, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.machinetags.getPredicates', array('namespace' => $namespace, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Machine Tags Get Recent Values
	 * 
	 * Fetch recently used (or created) machine tags values.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.machinetags.getRecentValues.html
	 * @param	string $namespace
	 * @param	string $predicate
	 * @param	int $added_since
	 * @return	mixed
	 */
	public function machinetags_getRecentValues($namespace = NULL, $predicate = NULL, $added_since = NULL)
	{
		return $this->request('flickr.machinetags.getRecentValues', array('namespace' => $namespace, 'predicate' => $predicate, 'added_since' => $added_since));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Machine Tags Get Values
	 * 
	 * Return a list of unique values for a namespace and predicate.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.machinetags.getValues.html
	 * @param	string $namespace
	 * @param	string $predicate
	 * @param	int $per_page
	 * @param	int $page
	 * @return	mixed
	 */
	public function machinetags_getValues($namespace, $predicate, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.machinetags.getValues', array('namespace' => $namespace, 'predicate' => $predicate, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Panda Get List
	 * 
	 * Return a list of Flickr pandas, from whom you can request photos using 
	 * the flickr.panda.getPhotos API method.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.panda.getList.html
	 * @return	mixed
	 */
	function panda_getList()
	{
		return $this->request('flickr.panda.getList');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Panda Get Photos
	 * 
	 * Ask the Flickr Pandas for a list of recent public (and "safe") photos.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.panda.getPhotos.html
	 * @param	string $panda_name
	 * @param	string $extras
	 * @param	int $per_page
	 * @param	int $page
	 * @return	mixed
	 */
	function panda_getPhotos($panda_name, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.panda.getPhotos', array('panda_name' => $panda_name, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * People Find By Email
	 * 
	 * Return a user's NSID, given their email address
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.people.findByEmail.html
	 * @param	string $find_email
	 * @return	mixed
	 */
	public function people_findByEmail($find_email)
	{
		return $this->request('flickr.people.findByEmail', array('find_email' => $find_email));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * People Find By Username
	 * 
	 * Return a user's NSID, given their username.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.people.findByUsername.html
	 * @param	string $username
	 * @return	mixed
	 */
	public function people_findByUsername($username)
	{
		return $this->request('flickr.people.findByUsername', array('username' => $username));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * People Get Info
	 * 
	 * Get information about a user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.people.getInfo.html
	 * @param	string $user_id
	 * @return	mixed
	 */
	public function people_getInfo($user_id)
	{
		return $this->request('flickr.people.getInfo', array('user_id' => $user_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * People Get Public Groups
	 * 
	 * Returns the list of public groups a user is a member of.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.people.getPublicGroups.html
	 * @param	string $user_id
	 * @return	mixed
	 */
	public function people_getPublicGroups($user_id)
	{
		return $this->request('flickr.people.getPublicGroups', array('user_id' => $user_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * People Get Public Photos
	 * 
	 * Get a list of public photos for the given user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.people.getPublicPhotos.html
	 * @param	string $user_id
	 * @param	int $safe_search Safe search setting: 1 for safe, 2 for moderate, 3 for restricted.
	 * @param	string $extras
	 * @param	int $per_page
	 * @param	int $page
	 * @return	mixed
	 */
	public function people_getPublicPhotos($user_id, $safe_search = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.people.getPublicPhotos', array('user_id' => $user_id, 'safe_search' => $safe_search, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * People Get Upload Status
	 * 
	 * Returns information for the calling user related to photo uploads.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.people.getUploadStatus.html
	 * @return	mixed
	 */
	public function people_getUploadStatus()
	{
		return $this->request('flickr.people.getUploadStatus');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Add Tags
	 * 
	 * Add tags to a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.addTags.html
	 * @param	int $photo_id
	 * @param	string $tags
	 * @return	mixed
	 */
	public function photos_addTags($photo_id, $tags)
	{
		return $this->request('flickr.photos.addTags', array('photo_id' => $photo_id, 'tags' => $tags), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Delete
	 * 
	 * Delete a photo from flickr.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.delete.html
	 * @param	int $photo_id
	 * @return	mixed
	 */
	public function photos_delete($photo_id)
	{
		return $this->request('flickr.photos.delete', array('photo_id' => $photo_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get All Contexts
	 * 
	 * Returns all visible sets and pools the photo belongs to.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getAllContexts.html
	 * @param	int $photo_id
	 * @return	mixed
	 */
	public function photos_getAllContexts($photo_id)
	{
		return $this->request('flickr.photos.getAllContexts', array('photo_id' => $photo_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.getContactsPhotos.html */
	public function photos_getContactsPhotos($count = NULL, $just_friends = NULL, $single_photo = NULL, $include_self = NULL, $extras = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.getContactsPhotos', array('count' => $count, 'just_friends' => $just_friends, 'single_photo' => $single_photo, 'include_self' => $include_self, 'extras' => $extras));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.getContactsPublicPhotos.html */
	public function photos_getContactsPublicPhotos($user_id, $count = NULL, $just_friends = NULL, $single_photo = NULL, $include_self = NULL, $extras = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.getContactsPublicPhotos', array('user_id' => $user_id, 'count' => $count, 'just_friends' => $just_friends, 'single_photo' => $single_photo, 'include_self' => $include_self, 'extras' => $extras));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.getContext.html */
	public function photos_getContext($photo_id)
	{
		return $this->request('flickr.photos.getContext', array('photo_id' => $photo_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.getCounts.html */
	public function photos_getCounts($dates = NULL, $taken_dates = NULL)
	{
		return $this->request('flickr.photos.getCounts', array('dates' => $dates, 'taken_dates' => $taken_dates));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.getExif.html */
	public function photos_getExif($photo_id, $secret = NULL)
	{
		return $this->request('flickr.photos.getExif', array('photo_id' => $photo_id, 'secret' => $secret));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.getFavorites.html */
	public function photos_getFavorites($photo_id, $page = NULL, $per_page = NULL)
	{
		return $this->request('flickr.photos.getFavorites', array('photo_id' => $photo_id, 'page' => $page, 'per_page' => $per_page));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.getInfo.html */
	public function photos_getInfo($photo_id, $secret = NULL)
	{
		return $this->request('flickr.photos.getInfo', array('photo_id' => $photo_id, 'secret' => $secret));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.getNotInSet.html */
	public function photos_getNotInSet($min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL, $privacy_filter = NULL, $media = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.getNotInSet', array('min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date, 'privacy_filter' => $privacy_filter, 'media' => $media, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.getPerms.html */
	public function photos_getPerms($photo_id)
	{
		return $this->request('flickr.photos.getPerms', array('photo_id' => $photo_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.getRecent.html */
	public function photos_getRecent($extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.getRecent', array('extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.getSizes.html */
	public function photos_getSizes($photo_id)
	{
		return $this->request('flickr.photos.getSizes', array('photo_id' => $photo_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.getUntagged.html */
	public function photos_getUntagged($min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL, $privacy_filter = NULL, $media = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.getUntagged', array('min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date, 'privacy_filter' => $privacy_filter, 'media' => $media, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* See the documentation included with the photos_search() function.
	 * I'm using the same style of arguments for this function. The only
	 * difference here is that this doesn't require any arguments. The
	 * flickr.photos.search method requires at least one search parameter.
	 */
	/* http://www.flickr.com/services/api/flickr.photos.getWithGeoData.html */
	public function photos_getWithGeoData($args = array())
	{
		return $this->request('flickr.photos.getWithGeoData', $args);
	}
	
	/* See the documentation included with the photos_search() function.
	 * I'm using the same style of arguments for this function. The only
	 * difference here is that this doesn't require any arguments. The
	 * flickr.photos.search method requires at least one search parameter.
	 */
	/* http://www.flickr.com/services/api/flickr.photos.getWithoutGeoData.html */
	public function photos_getWithoutGeoData($args = array())
	{
		return $this->request('flickr.photos.getWithoutGeoData', $args);
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.recentlyUpdated.html */
	public function photos_recentlyUpdated($min_date, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.recentlyUpdated', array('min_date' => $min_date, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.removeTag.html */
	public function photos_removeTag($tag_id)
	{
		return $this->request('flickr.photos.removeTag', array('tag_id' => $tag_id), TRUE);
	}
	
	/* This function strays from the method of arguments that I've
	 * used in the other functions for the fact that there are just
	 * so many arguments to this API method. What you'll need to do
	 * is pass an associative array to the function containing the
	 * arguments you want to pass to the API.  For example:
	 *   $photos = $f->photos_search(array('tags' => 'brown,cow', 'tag_mode' => 'any'));
	 * This will return photos tagged with either 'brown' or 'cow'
	 * or both. See the API documentation (link below) for a full
	 * list of arguments.
	 */
	/* http://www.flickr.com/services/api/flickr.photos.search.html */
	public function photos_search($args = array())
	{
		return $this->request('flickr.photos.search', $args);
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.setContentType.html */
	public function photos_setContentType($photo_id, $content_type)
	{
		return $this->request('flickr.photos.setContentType', array('photo_id' => $photo_id, 'content_type' => $content_type));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.setDates.html */
	public function photos_setDates($photo_id, $date_posted = NULL, $date_taken = NULL, $date_taken_granularity = NULL)
	{
		return $this->request('flickr.photos.setDates', array('photo_id' => $photo_id, 'date_posted' => $date_posted, 'date_taken' => $date_taken, 'date_taken_granularity' => $date_taken_granularity), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.setMeta.html */
	public function photos_setMeta($photo_id, $title, $description)
	{
		return $this->request('flickr.photos.setMeta', array('photo_id' => $photo_id, 'title' => $title, 'description' => $description), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.setPerms.html */
	public function photos_setPerms($photo_id, $is_public, $is_friend, $is_family, $perm_comment, $perm_addmeta)
	{
		return $this->request('flickr.photos.setPerms', array('photo_id' => $photo_id, 'is_public' => $is_public, 'is_friend' => $is_friend, 'is_family' => $is_family, 'perm_comment' => $perm_comment, 'perm_addmeta' => $perm_addmeta), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.setSafetyLevel.html */
	public function photos_setSafetyLevel($photo_id, $safety_level = NULL, $hidden = NULL)
	{
		return $this->request('flickr.photos.setSafetyLevel', array('photo_id' => $photo_id, 'safety_level' => $safety_level, 'hidden' => $hidden));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.setTags.html */
	public function photos_setTags($photo_id, $tags)
	{
		return $this->request('flickr.photos.setTags', array('photo_id' => $photo_id, 'tags' => $tags), TRUE);
	}
	
	
	/* Photos - Comments Methods */
	/* http://www.flickr.com/services/api/flickr.photos.comments.addComment.html */
	public function photos_comments_addComment($photo_id, $comment_text)
	{
		return $this->request('flickr.photos.comments.addComment', array('photo_id' => $photo_id, 'comment_text' => $comment_text), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.comments.deleteComment.html */
	public function photos_comments_deleteComment($comment_id)
	{
		return $this->request('flickr.photos.comments.deleteComment', array('comment_id' => $comment_id), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.comments.editComment.html */
	public function photos_comments_editComment($comment_id, $comment_text)
	{
		return $this->request('flickr.photos.comments.editComment', array('comment_id' => $comment_id, 'comment_text' => $comment_text), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.comments.getList.html */
	public function photos_comments_getList($photo_id, $min_comment_date = NULL, $max_comment_date = NULL)
	{
		return $this->request('flickr.photos.comments.getList', array('photo_id' => $photo_id, 'min_comment_date' => $min_comment_date, 'max_comment_date' => $max_comment_date));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.comments.getRecentForContacts.html */
	public function photos_comments_getRecentForContacts($date_lastcomment = NULL, $contacts_filter = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.comments.getRecentForContacts', array('date_lastcomment' => $date_lastcomment, 'contacts_filter' => $contacts_filter, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	
	/* Photos - Geo Methods */
	/* http://www.flickr.com/services/api/flickr.photos.geo.batchCorrectLocation.html */
	public function photos_geo_batchCorrectLocation($lat, $lon, $accuracy, $place_id = NULL, $woe_id = NULL)
	{
		return $this->request('flickr.photos.geo.batchCorrectLocation', array('lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy, 'place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.geo.correctLocation.html */
	public function photos_geo_correctLocation($photo_id, $place_id = NULL, $woe_id = NULL)
	{
		return $this->request('flickr.photos.geo.correctLocation', array('photo_id' => $photo_id, 'place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.geo.getLocation.html */
	public function photos_geo_getLocation($photo_id)
	{
		return $this->request('flickr.photos.geo.getLocation', array('photo_id' => $photo_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.geo.getPerms.html */
	public function photos_geo_getPerms($photo_id)
	{
		return $this->request('flickr.photos.geo.getPerms', array('photo_id' => $photo_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.geo.photosForLocation.html */
	public function photos_geo_photosForLocation($lat, $lon, $accuracy = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.geo.photosForLocation', array('lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.geo.removeLocation.html */
	public function photos_geo_removeLocation($photo_id)
	{
		return $this->request('flickr.photos.geo.removeLocation', array('photo_id' => $photo_id), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.geo.setContext.html */
	public function photos_geo_setContext($photo_id, $context)
	{
		return $this->request('flickr.photos.geo.setContext', array('photo_id' => $photo_id, 'context' => $context));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.geo.setLocation.html */
	public function photos_geo_setLocation($photo_id, $lat, $lon, $accuracy = NULL, $context = NULL)
	{
		return $this->request('flickr.photos.geo.setLocation', array('photo_id' => $photo_id, 'lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy, 'context' => $context), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.geo.setPerms.html */
	public function photos_geo_setPerms($is_public, $is_contact, $is_friend, $is_family, $photo_id)
	{
		return $this->request('flickr.photos.geo.setPerms', array('is_public' => $is_public, 'is_contact' => $is_contact, 'is_friend' => $is_friend, 'is_family' => $is_family, 'photo_id' => $photo_id));
	}
	
	/* Photos - Licenses Methods */
	/* http://www.flickr.com/services/api/flickr.photos.licenses.getInfo.html */
	public function photos_licenses_getInfo()
	{
		return $this->request('flickr.photos.licenses.getInfo');
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.licenses.setLicense.html */
	/* Requires Authentication */
	public function photos_licenses_setLicense($photo_id, $license_id)
	{
		return $this->request('flickr.photos.licenses.setLicense', array('photo_id' => $photo_id, 'license_id' => $license_id), TRUE);
	}
	
	
	/* Photos - Notes Methods */
	/* http://www.flickr.com/services/api/flickr.photos.notes.add.html */
	public function photos_notes_add($photo_id, $note_x, $note_y, $note_w, $note_h, $note_text)
	{
		return $this->request('flickr.photos.notes.add', array('photo_id' => $photo_id, 'note_x' => $note_x, 'note_y' => $note_y, 'note_w' => $note_w, 'note_h' => $note_h, 'note_text' => $note_text), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.notes.delete.html */
	public function photos_notes_delete($note_id)
	{
		return $this->request('flickr.photos.notes.delete', array('note_id' => $note_id), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.notes.edit.html */
	public function photos_notes_edit($note_id, $note_x, $note_y, $note_w, $note_h, $note_text)
	{
		return $this->request('flickr.photos.notes.edit', array('note_id' => $note_id, 'note_x' => $note_x, 'note_y' => $note_y, 'note_w' => $note_w, 'note_h' => $note_h, 'note_text' => $note_text), TRUE);
	}
	
	
	/* Photos - Transform Methods */
	/* http://www.flickr.com/services/api/flickr.photos.transform.rotate.html */
	public function photos_transform_rotate($photo_id, $degrees)
	{
		return $this->request('flickr.photos.transform.rotate', array('photo_id' => $photo_id, 'degrees' => $degrees), TRUE);
	}
	
	
	/* Photos - Upload Methods */
	/* http://www.flickr.com/services/api/flickr.photos.upload.checkTickets.html */
	public function photos_upload_checkTickets($tickets)
	{
		if (is_array($tickets)) {
			$tickets = implode(',', $tickets);
		}
		return $this->request('flickr.photos.upload.checkTickets', array('tickets' => $tickets), TRUE);
	}
	
	
	/* Photosets Methods */
	/* http://www.flickr.com/services/api/flickr.photosets.addPhoto.html */
	public function photosets_addPhoto($photoset_id, $photo_id)
	{
		return $this->request('flickr.photosets.addPhoto', array('photoset_id' => $photoset_id, 'photo_id' => $photo_id), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photosets.create.html */
	public function photosets_create($title, $description, $primary_photo_id)
	{
		return $this->request('flickr.photosets.create', array('title' => $title, 'primary_photo_id' => $primary_photo_id, 'description' => $description), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photosets.delete.html */
	public function photosets_delete($photoset_id)
	{
		return $this->request('flickr.photosets.delete', array('photoset_id' => $photoset_id), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photosets.editMeta.html */
	public function photosets_editMeta($photoset_id, $title, $description = NULL)
	{
		return $this->request('flickr.photosets.editMeta', array('photoset_id' => $photoset_id, 'title' => $title, 'description' => $description), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photosets.editPhotos.html */
	public function photosets_editPhotos($photoset_id, $primary_photo_id, $photo_ids)
	{
		return $this->request('flickr.photosets.editPhotos', array('photoset_id' => $photoset_id, 'primary_photo_id' => $primary_photo_id, 'photo_ids' => $photo_ids), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photosets.getContext.html */
	public function photosets_getContext($photo_id, $photoset_id)
	{
		return $this->request('flickr.photosets.getContext', array('photo_id' => $photo_id, 'photoset_id' => $photoset_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.photosets.getInfo.html */
	public function photosets_getInfo($photoset_id)
	{
		return $this->request('flickr.photosets.getInfo', array('photoset_id' => $photoset_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.photosets.getList.html */
	public function photosets_getList($user_id = NULL)
	{
		return $this->request('flickr.photosets.getList', array('user_id' => $user_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.photosets.getPhotos.html */
	public function photosets_getPhotos($photoset_id, $extras = NULL, $privacy_filter = NULL, $per_page = NULL, $page = NULL, $media = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photosets.getPhotos', array('photoset_id' => $photoset_id, 'extras' => $extras, 'privacy_filter' => $privacy_filter, 'per_page' => $per_page, 'page' => $page, 'media' => $media));
	}
	
	/* http://www.flickr.com/services/api/flickr.photosets.orderSets.html */
	public function photosets_orderSets($photoset_ids)
	{
		if (is_array($photoset_ids)) {
			$photoset_ids = implode(',', $photoset_ids);
		}
		return $this->request('flickr.photosets.orderSets', array('photoset_ids' => $photoset_ids), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photosets.removePhoto.html */
	public function photosets_removePhoto($photoset_id, $photo_id)
	{
		return $this->request('flickr.photosets.removePhoto', array('photoset_id' => $photoset_id, 'photo_id' => $photo_id), TRUE);
	}
	
	
	/* Photosets Comments Methods */
	/* http://www.flickr.com/services/api/flickr.photosets.comments.addComment.html */
	public function photosets_comments_addComment($photoset_id, $comment_text)
	{
		return $this->request('flickr.photosets.comments.addComment', array('photoset_id' => $photoset_id, 'comment_text' => $comment_text), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photosets.comments.deleteComment.html */
	public function photosets_comments_deleteComment($comment_id)
	{
		return $this->request('flickr.photosets.comments.deleteComment', array('comment_id' => $comment_id), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photosets.comments.editComment.html */
	public function photosets_comments_editComment($comment_id, $comment_text)
	{
		return $this->request('flickr.photosets.comments.editComment', array('comment_id' => $comment_id, 'comment_text' => $comment_text), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.photosets.comments.getList.html */
	public function photosets_comments_getList($photoset_id)
	{
		return $this->request('flickr.photosets.comments.getList', array('photoset_id' => $photoset_id));
	}
	
	
	/* Places Methods */
	/* http://www.flickr.com/services/api/flickr.places.find.html */
	public function places_find($query)
	{
		return $this->request('flickr.places.find', array('query' => $query));
	}
	
	/* http://www.flickr.com/services/api/flickr.places.findByLatLon.html */
	public function places_findByLatLon($lat, $lon, $accuracy = NULL)
	{
		return $this->request('flickr.places.findByLatLon', array('lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy));
	}
	
	/* http://www.flickr.com/services/api/flickr.places.getChildrenWithPhotosPublic.html */
	public function places_getChildrenWithPhotosPublic($place_id = NULL, $woe_id = NULL)
	{
		return $this->request('flickr.places.getChildrenWithPhotosPublic', array('place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.places.getInfo.html */
	public function places_getInfo($place_id = NULL, $woe_id = NULL)
	{
		return $this->request('flickr.places.getInfo', array('place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.places.getInfoByUrl.html */
	public function places_getInfoByUrl($url)
	{
		return $this->request('flickr.places.getInfoByUrl', array('url' => $url));
	}
	
	/* http://www.flickr.com/services/api/flickr.places.getPlaceTypes.html */
	public function places_getPlaceTypes()
	{
		return $this->request('flickr.places.getPlaceTypes', array());
	}
	
	/* http://www.flickr.com/services/api/flickr.places.getShapeHistory.html */
	public function places_getShapeHistory($place_id = NULL, $woe_id = NULL)
	{
		return $this->request('flickr.places.getShapeHistory', array('place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.places.getTopPlacesList.html */
	public function places_getTopPlacesList($place_type_id, $date = NULL, $woe_id = NULL, $place_id = NULL)
	{
		return $this->request('flickr.places.getTopPlacesList', array('place_type_id' => $place_type_id, 'date' => $date, 'woe_id' => $woe_id, 'place_id' => $place_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.places.placesForBoundingBox.html */
	public function places_placesForBoundingBox($bbox, $place_type = NULL, $place_type_id = NULL)
	{
		return $this->request('flickr.places.placesForBoundingBox', array('bbox' => $bbox, 'place_type' => $place_type, 'place_type_id' => $place_type_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.places.placesForContacts.html */
	public function places_placesForContacts($place_type = NULL, $place_type_id = NULL, $woe_id = NULL, $place_id = NULL, $threshold = NULL, $contacts = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL)
	{
		return $this->request('flickr.places.placesForContacts', array('place_type' => $place_type, 'place_type_id' => $place_type_id, 'woe_id' => $woe_id, 'place_id' => $place_id, 'threshold' => $threshold, 'contacts' => $contacts, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
	}
	
	/* http://www.flickr.com/services/api/flickr.places.placesForTags.html */
	public function places_placesForTags($place_type_id, $woe_id = NULL, $place_id = NULL, $threshold = NULL, $tags = NULL, $tag_mode = NULL, $machine_tags = NULL, $machine_tag_mode = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL)
	{
		return $this->request('flickr.places.placesForTags', array('place_type_id' => $place_type_id, 'woe_id' => $woe_id, 'place_id' => $place_id, 'threshold' => $threshold, 'tags' => $tags, 'tag_mode' => $tag_mode, 'machine_tags' => $machine_tags, 'machine_tag_mode' => $machine_tag_mode, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
	}
	
	/* http://www.flickr.com/services/api/flickr.places.placesForUser.html */
	public function places_placesForUser($place_type_id = NULL, $place_type = NULL, $woe_id = NULL, $place_id = NULL, $threshold = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL)
	{
		return $this->request('flickr.places.placesForUser', array('place_type_id' => $place_type_id, 'place_type' => $place_type, 'woe_id' => $woe_id, 'place_id' => $place_id, 'threshold' => $threshold, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
	}
	
	/* http://www.flickr.com/services/api/flickr.places.resolvePlaceId.html */
	public function places_resolvePlaceId($place_id)
	{
		return $this->request('flickr.places.resolvePlaceId', array('place_id' => $place_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.places.resolvePlaceURL.html */
	public function places_resolvePlaceURL($url)
	{
		return $this->request('flickr.places.resolvePlaceURL', array('url' => $url));
	}
	
	/* http://www.flickr.com/services/api/flickr.places.tagsForPlace.html */
	function places_tagsForPlace($woe_id = NULL, $place_id = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL)
	{
		return $this->request('flickr.places.tagsForPlace', array('woe_id' => $woe_id, 'place_id' => $place_id, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
	}
	
	
	/* Prefs Methods */
	/* http://www.flickr.com/services/api/flickr.prefs.getContentType.html */
	public function prefs_getContentType()
	{
		return $this->request('flickr.prefs.getContentType');
	}
	
	/* http://www.flickr.com/services/api/flickr.prefs.getGeoPerms.html */
	public function prefs_getGeoPerms()
	{
		return $this->request('flickr.prefs.getGeoPerms');
	}
	
	/* http://www.flickr.com/services/api/flickr.prefs.getHidden.html */
	public function prefs_getHidden()
	{
		return $this->request('flickr.prefs.getHidden');
	}
	
	/* http://www.flickr.com/services/api/flickr.prefs.getPrivacy.html */
	public function prefs_getPrivacy()
	{
		return $this->request('flickr.prefs.getPrivacy');
	}
	
	/* http://www.flickr.com/services/api/flickr.prefs.getSafetyLevel.html */
	public function prefs_getSafetyLevel()
	{
		return $this->request('flickr.prefs.getSafetyLevel');
	}
	
	
	/* Reflection Methods */
	/* http://www.flickr.com/services/api/flickr.reflection.getMethodInfo.html */
	public function reflection_getMethodInfo($method_name)
	{
		return $this->request('flickr.reflection.getMethodInfo', array('method_name' => $method_name));
	}
	
	/* http://www.flickr.com/services/api/flickr.reflection.getMethods.html */
	public function reflection_getMethods()
	{
		return $this->request('flickr.reflection.getMethods');
	}
	
	
	/* Tags Methods */
	/* http://www.flickr.com/services/api/flickr.tags.getClusterPhotos.html */
	public function tags_getClusterPhotos($tag, $cluster_id)
	{
		return $this->request('flickr.tags.getClusterPhotos', array('tag' => $tag, 'cluster_id' => $cluster_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.tags.getClusters.html */
	public function tags_getClusters($tag)
	{
		return $this->request('flickr.tags.getClusters', array('tag' => $tag));
	}
	
	/* http://www.flickr.com/services/api/flickr.tags.getHotList.html */
	public function tags_getHotList($period = NULL, $count = NULL)
	{
		return $this->request('flickr.tags.getHotList', array('period' => $period, 'count' => $count));
	}
	
	/* http://www.flickr.com/services/api/flickr.tags.getListPhoto.html */
	public function tags_getListPhoto($photo_id)
	{
		return $this->request('flickr.tags.getListPhoto', array('photo_id' => $photo_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.tags.getListUser.html */
	public function tags_getListUser($user_id = NULL)
	{
		return $this->request('flickr.tags.getListUser', array('user_id' => $user_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.tags.getListUserPopular.html */
	public function tags_getListUserPopular($user_id = NULL, $count = NULL)
	{
		return $this->request('flickr.tags.getListUserPopular', array('user_id' => $user_id, 'count' => $count));
	}
	
	/* http://www.flickr.com/services/api/flickr.tags.getListUserRaw.html */
	public function tags_getListUserRaw($tag = NULL)
	{
		return $this->request('flickr.tags.getListUserRaw', array('tag' => $tag));
	}
	
	/* http://www.flickr.com/services/api/flickr.tags.getRelated.html */
	public function tags_getRelated($tag)
	{
		return $this->request('flickr.tags.getRelated', array('tag' => $tag));
	}
	
	/* http://www.flickr.com/services/api/flickr.test.echo.html */
	public function test_echo($args = array())
	{
		return $this->request('flickr.test.echo', $args);
	}
	
	/* http://www.flickr.com/services/api/flickr.test.login.html */
	public function test_login()
	{
		return $this->request('flickr.test.login');
	}
	
	/* http://www.flickr.com/services/api/flickr.urls.getGroup.html */
	public function urls_getGroup($group_id)
	{
		return $this->request('flickr.urls.getGroup', array('group_id' => $group_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.urls.getUserPhotos.html */
	public function urls_getUserPhotos($user_id = NULL)
	{
		return $this->request('flickr.urls.getUserPhotos', array('user_id' => $user_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.urls.getUserProfile.html */
	public function urls_getUserProfile($user_id = NULL)
	{
		return $this->request('flickr.urls.getUserProfile', array('user_id' => $user_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.urls.lookupGroup.html */
	public function urls_lookupGroup($url)
	{
		return $this->request('flickr.urls.lookupGroup', array('url' => $url));
	}
	
	/* http://www.flickr.com/services/api/flickr.photos.notes.edit.html */
	public function urls_lookupUser($url)
	{
		return $this->request('flickr.urls.lookupUser', array('url' => $url));
	}



}

/* End of file Flickr_API.php */
/* Location: ./system/application/libraries/Flickr_API.php */