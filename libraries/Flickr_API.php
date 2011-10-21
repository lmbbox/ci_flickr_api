<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
 * CodeIgniter Flickr API
 * 
 * A CodeIgniter library which gives access to make calls to Flickr's API.
 * 
 * @package		CodeIgniter Flickr API
 * @author		LMB^Box (Thomas Montague)
 * @copyright	Copyright (c) 2009 - 2011, LMB^Box
 * @license		GNU Lesser General Public License (http://www.gnu.org/copyleft/lgpl.html)
 * @link		http://lmbbox.com/projects/ci-flickr-api/
 * @since		Version 0.0.1
 * @filesource
 */

// ------------------------------------------------------------------------

/**
 * CodeIgniter Flickr API Class
 * 
 * @package		CodeIgniter Flickr API
 * @subpackage	Libraries
 * @category	Libraries
 * @author		LMB^Box (Thomas Montague)
 * @link		http://codeigniter.lmbbox.com/user_guide/libraries/flickr_api.html
 */
class CI_Flickr_API {
	
	const VERSION						= '0.4.0';
	const API_AUTH_URL					= 'http://www.flickr.com/services/auth/';
	const API_REST_URL					= 'http://api.flickr.com/services/rest/';
	const API_XMLRPC_URL				= 'http://api.flickr.com/services/xmlrpc/';
	const API_SOAP_URL					= 'http://api.flickr.com/services/soap/';
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
	const PHOTO_SIZE_MEDIUM_640			= 'medium640';
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
	 * @param	array	$params	Initialization parameters
	 * @return	void
	 */
	public function __construct($params = array())
	{
		// Set the super object to a local variable for use throughout the class
		$this->CI =& get_instance();
		$this->CI->lang->load('flickr_api');
		
		// Initialize Parameters
		$this->initialize($params);
		
		log_message('debug', 'CI_Flickr_API Class Initialized');
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Initialize Preferences
	 * 
	 * @access	public
	 * @param	array	$params	Initialization parameters
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
	 * Set Debug
	 * 
	 * Toggle debugging on and off.
	 * 
	 * @access	public
	 * @param	bool	$debug
	 * @return	void
	 */
	public function set_debug($debug)
	{
		$this->debug = (bool) $debug;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Set Token
	 * 
	 * Set current Flickr user authentication token.
	 * 
	 * @access	public
	 * @param	string	$token	Flickr user authentication token
	 * @return	bool
	 */
	public function set_token($token)
	{
		if ('' == ($token = trim((string) $token)))
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
	 * @param	bool $run_cleanup	Run cache clean up process or not
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
	 * Delete expired cache records from DB.
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
	 * Check if cache table exists and if not, create it.
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
			$fields['request'] = array('type' => 'CHAR', 'constraint' => '40', 'null' => FALSE);
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
	 * @param	array	$request
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
		
		unset($request['api_sig']);
		
		$this->CI->db->select('response');
		$this->CI->db->where('request', sha1(serialize($request)));
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
	 * @param	array	$request
	 * @param	string	$response
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
		
		unset($request['api_sig']);
		$request_hash = sha1(serialize($request));
		
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
	 * @param	string	$error_code
	 * @param	string	$error_message
	 * @param	string	$exit_message
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
	 * @param	string	$method				Flickr API method
	 * @param	array	$params				Method arguments
	 * @param	bool	$nocache			Cache override
	 * @param	string	$request_format		Request Format
	 * @param	string	$response_format	Response Format
	 * @return	mixed
	 */
	public function request($method, $params = array(), $nocache = FALSE, $request_format = NULL, $response_format = NULL)
	{
		$request_format = is_null($request_format) ? $this->request_format : $request_format;
		$response_format = is_null($response_format) ? $this->response_format : $response_format;
		
		if ('' == $request_format || '' == $response_format || '' == $this->api_key || '' == $this->secret)
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_required_config_missing'), '%2$s');
			return FALSE;
		}
		
		if ('' == $method || !is_array($params))
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		if (FALSE === ($params = $this->_build_params($params, $method, $response_format, $request_format)))
		{
			return FALSE;
		}
		
		$this->_reset_error();
		$this->response = $this->_get_cached($params);
		
		if (FALSE === $this->response || TRUE === $nocache)
		{
			switch ($request_format)
			{
				case self::REQUEST_FORMAT_REST:
					if (FALSE === $this->_curl_post(self::API_REST_URL, $params))
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
					$this->_error(TRUE, __METHOD__ . ' - ' . sprintf($this->CI->lang->line('flickr_api_invalid_request_format'), $request_format), '%2$s');
					return FALSE;
					break;
			}
		}
		
		if (FALSE === $nocache)
		{
			$this->_cache($params, $this->response);
		}
		return TRUE === $this->parse_response ? $this->parsed_response = $this->parse_response($response_format, $this->response) : $this->response;
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Upload Photo
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/upload.api.html
	 * @link	http://www.flickr.com/services/api/upload.async.html
	 * @param	string	$photo	The file to upload.
	 * @param	array	$args
	 * @return	mixed
	 * @static
	 */
	function upload($photo, $params = array())
	{
		if ('' == $this->api_key || '' == $this->secret)
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_required_config_missing'), '%2$s');
			return FALSE;
		}
		
		if ('' == ($photo = realpath($photo)) || !is_array($params))
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		if (FALSE === ($params = $this->_build_params($params)))
		{
			return FALSE;
		}
		
		$params['photo'] = '@' . $photo;
		
		$this->_reset_error();
		
		if (FALSE === $this->_curl_post(self::API_UPLOAD_URL, $params))
		{
			return FALSE;
		}
		
		return $this->parsed_response = $this->parse_response(self::RESPONSE_FORMAT_REST, $this->response);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Replace Photo
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/replace.api.html
	 * @param	string	$photo	The file to upload.
	 * @param	int		$photo_id
	 * @param	bool	$async
	 * @return	mixed
	 * @static
	 */
	function replace($photo, $photo_id, $async = NULL)
	{
		if ('' == $this->api_key || '' == $this->secret)
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_required_config_missing'), '%2$s');
			return FALSE;
		}
		
		if ('' == ($photo = realpath($photo)))
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		$params['photo_id'] = $photo_id;
		$params['async'] = $async;
		
		if (FALSE === $params = $this->_build_params($params))
		{
			return FALSE;
		}
		
		$params['photo'] = '@' . $photo;
		
		$this->_reset_error();
		
		if (FALSE === $this->_curl_post(self::API_REPLACE_URL, $params))
		{
			return FALSE;
		}
		
		return $this->parsed_response = $this->parse_response(self::RESPONSE_FORMAT_REST, $this->response);
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Build API Call Parameters
	 * 
	 * @access	protected
	 * @param	array	$params
	 * @param	string	$method
	 * @param	string	$response_format
	 * @param	string	$request_format
	 * @return	array
	 */
	protected function _build_params($params, $method = '', $response_format = '', $request_format = '')
	{
		if ('' == $this->api_key || '' == $this->secret)
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_required_config_missing'), '%2$s');
			return FALSE;
		}
		
		if (!is_array($params))
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		$params['api_key'] = $this->api_key;
		
		if ('' != $this->token)
		{
			$params['auth_token'] = $this->token;
		}
		
		if ('' != $method)
		{
			$params['method'] = $method;
		}
		
		if ('' != $response_format)
		{
			$params['format'] = $response_format;
			
			if (self::RESPONSE_FORMAT_JSON == $response_format)
			{
				$params['nojsoncallback'] = 1;
			}
		}
		
		ksort($params);
		$auth_sig = '';
		
		foreach ($params as $param => $value)
		{
			if (is_null($value))
			{
				unset($params[$param]);
			}
			else
			{
				$auth_sig .= ('method' == $param && self::REQUEST_FORMAT_XMLRPC == $request_format) ? '' : $param . $value;
			}
		}
		
		$params['api_sig'] = md5($this->secret . $auth_sig);
		return $params;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * cURL Post
	 * 
	 * @access	protected
	 * @param	string			$url	Url to call
	 * @param	array|string	$params	Flickr API call
	 * @return	bool
	 */
	protected function _curl_post($url, $params)
	{
		if ('' == $url || (!is_array($params) && !is_string($params)) || (is_array($params) && empty($params)) || (is_string($params) && '' == $params))
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		$session = curl_init($url);
		curl_setopt($session, CURLOPT_POST, TRUE);
		curl_setopt($session, CURLOPT_POSTFIELDS, $params);
		curl_setopt($session, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($session, CURLOPT_FAILONERROR, TRUE);
		$this->response = curl_exec($session);
		
		if (TRUE === $this->debug)
		{
			log_message('debug', __METHOD__ . ' - cURL Request Info: ' . var_export(curl_getinfo($session), TRUE));
			log_message('debug', __METHOD__ . ' - cURL Request Params: ' . var_export($params, TRUE));
		}
		
		if (FALSE === $this->response)
		{
			$this->_error(curl_errno($session), curl_error($session), $this->CI->lang->line('flickr_api_send_request_error'));
			curl_close($session);
			return FALSE;
		}
		
		curl_close($session);
		return TRUE;
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Send XMLRPC API Call
	 * 
	 * @access	protected
	 * @param	array	$params	Flickr API call
	 * @return	bool
	 */
	protected function _send_xmlrpc($params)
	{
		if (!is_array($params) || empty($params) || '' == $params['method'])
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		$request = '<methodCall><methodName>' . $params['method'] . '</methodName><params><param><value><struct>';
		
		foreach ($params as $name => $value)
		{
			$request .= 'method' == $name ? '' : "<member><name>$name</name><value>$value</value></member>";
		}
		
		$request .= '</struct></value></param></params></methodCall>';
		
		if (TRUE === $this->debug)
		{
			log_message('debug', __METHOD__ . ' - XMLRPC Request: ' . var_export($request, TRUE));
			log_message('debug', __METHOD__ . ' - XMLRPC Request Params: ' . var_export($params, TRUE));
		}
		
		return $this->_curl_post(self::API_XMLRPC_URL, $request);
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Send SOAP API Call
	 * 
	 * @access	protected
	 * @param	array	$params	Flickr API call
	 * @return	bool
	 */
	protected function _send_soap($params)
	{
		if (!is_array($params) || empty($params) || '' == $params['method'])
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		$request = '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:xsi="http://www.w3.org/1999/XMLSchema-instance" xmlns:xsd="http://www.w3.org/1999/XMLSchema"><s:Body><x:FlickrRequest xmlns:x="urn:flickr">';
		
		foreach ($params as $name => $value)
		{
			$request .= "<$name>$value</$name>";
		}
		
		$request .= '</x:FlickrRequest></s:Body></s:Envelope>';
		
		if (TRUE === $this->debug)
		{
			log_message('debug', __METHOD__ . ' - SOAP Request: ' . var_export($request, TRUE));
			log_message('debug', __METHOD__ . ' - SOAP Request Params: ' . var_export($params, TRUE));
		}
		
		return $this->_curl_post(self::API_SOAP_URL, $request);
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Parse Response
	 * 
	 * @access	public
	 * @param	string	$format		Response Format
	 * @param	string	$response	Flickr API call response
	 * @return	mixed
	 */
	public function parse_response($format, $response)
	{
		if ('' == $format || '' == $response)
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		switch ($format)
		{
			case self::RESPONSE_FORMAT_REST:
				$response = simplexml_load_string($response);
				
				if ('fail' == $response['stat'])
				{
					$this->_error($response->err['code'], $response->err['msg'], $this->CI->lang->line('flickr_api_returned_error'));
					return FALSE;
				}
				
				return $response;
				break;
			case self::RESPONSE_FORMAT_XMLRPC:
				$response = simplexml_load_string($response);
				
				if (TRUE === isset($response->fault))
				{
					foreach ($response->fault->value->struct->member as $member)
					{
						if ('faultCode' == $member->name)
						{
							$err_code = $member->value->int;
						}
						elseif ('faultString' == $member->name)
						{
							$err_string = $member->value->string;
						}
					}
					
					$this->_error($err_code, $err_string, $this->CI->lang->line('flickr_api_returned_error'));
					return FALSE;
				}
				
				return simplexml_load_string('<xml>' . $response->params->param->value->string . '</xml>');
				break;
			case self::RESPONSE_FORMAT_SOAP:
				if (FALSE === $response_parsed = @simplexml_load_string(preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response)))
				{
					return $response;
				}
				
				if (TRUE === isset($response_parsed->sBody->sFault))
				{
					$this->_error(str_replace('flickr.error.', '',$response_parsed->sBody->sFault->faultcode), $response_parsed->sBody->sFault->faultstring, $this->CI->lang->line('flickr_api_returned_error'));
					return FALSE;
				}
				
				return simplexml_load_string('<xml>' . $response_parsed->sBody->xFlickrResponse . '</xml>');
				break;
			case self::RESPONSE_FORMAT_JSON:
				$response = $this->_parse_array(json_decode($response, TRUE));
				
				if ('fail' == $response['stat'])
				{
					$this->_error($response['code'], $response['message'], $this->CI->lang->line('flickr_api_returned_error'));
					return FALSE;
				}
				
				return $response;
				break;
			case self::RESPONSE_FORMAT_PHP_SERIAL:
				$response = $this->_parse_array(unserialize($response));
				
				if ('fail' == $response['stat'])
				{
					$this->_error($response['code'], $response['message'], $this->CI->lang->line('flickr_api_returned_error'));
					return FALSE;
				}
				
				return $response;
				break;
			default:
				$this->_error(TRUE, __METHOD__ . ' - ' . sprintf($this->CI->lang->line('flickr_api_invalid_response_format'), $format), '%2$s');
				return FALSE;
				break;
		}
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Parse Array Response
	 * 
	 * @access	protected
	 * @param	mixed	$response
	 * @return	mixed
	 */
	protected function _parse_array($response)
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
				$response[$key] = $this->_parse_array($value);
			}
			return($response);
		}
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Authenticate
	 * 
	 * @access	public
	 * @param	string	$permission	API account permission level
	 * @param	string	$redirect	Redirection url
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
	 * Get Photo Source
	 * 
	 * @access	public
	 * @param	int|string	$id
	 * @param	int|string	$farm
	 * @param	int|string	$server
	 * @param	string		$secret
	 * @param	string		$size
	 * @param	string		$original_secret
	 * @param	string		$original_format
	 * @return	string|bool
	 * @static
	 */
	public function get_photo_source($id, $farm, $server, $secret, $size = self::PHOTO_SIZE_MEDIUM, $original_secret = '', $original_format = '')
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
			case self::PHOTO_SIZE_MEDIUM_640:
				return 'http://farm' . $farm . '.static.flickr.com/' . $server . '/'. $id . '_' . $secret . '_z.jpg';
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
	 * Get Photo URL
	 * 
	 * @access	public
	 * @param	int|string	$id
	 * @param	int|string	$farm
	 * @param	int|string	$server
	 * @param	string		$secret
	 * @param	string		$size
	 * @param	string		$original_secret
	 * @param	string		$original_format
	 * @return	string|bool
	 * @static
	 */
	public function get_photo_url($owner_nsid, $photo_id, $size = self::PHOTO_SIZE_MEDIUM)
	{
		if (empty($owner_nsid) || empty($photo_id))
		{
			$this->_error(TRUE, __METHOD__ . ' - ' . $this->CI->lang->line('flickr_api_params_error'), '%2$s');
			return FALSE;
		}
		
		switch ($size)
		{
			case self::PHOTO_SIZE_ORIGINAL:
				return 'http://www.flickr.com/photos/' . $owner_nsid . '/'. $photo_id . '/sizes/o/';
				break;
			case self::PHOTO_SIZE_LARGE:
				return 'http://www.flickr.com/photos/' . $owner_nsid . '/'. $photo_id . '/sizes/l/';
				break;
			case self::PHOTO_SIZE_MEDIUM_640:
				return 'http://www.flickr.com/photos/' . $owner_nsid . '/'. $photo_id . '/sizes/z/';
				break;
			case self::PHOTO_SIZE_MEDIUM:
				return 'http://www.flickr.com/photos/' . $owner_nsid . '/'. $photo_id . '/sizes/m/';
				break;
			case self::PHOTO_SIZE_SMALL:
				return 'http://www.flickr.com/photos/' . $owner_nsid . '/'. $photo_id . '/sizes/s/';
				break;
			case self::PHOTO_SIZE_THUMBNAIL:
				return 'http://www.flickr.com/photos/' . $owner_nsid . '/'. $photo_id . '/sizes/t/';
				break;
			case self::PHOTO_SIZE_SQUARE:
				return 'http://www.flickr.com/photos/' . $owner_nsid . '/'. $photo_id . '/sizes/sq/';
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
	 * @param	string		$nsid
	 * @param	int|string	$icon_farm
	 * @param	int|string	$icon_server
	 * @param	bool		$return_default
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
	
	/**
	 * Activity User Comments
	 * 
	 * Returns a list of recent activity on photos commented on by the calling 
	 * user. Do not poll this method more than once an hour.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.activity.userComments.html
	 * @param	int		$page
	 * @param	int		$per_page
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
	 * @param	string	$timeframe
	 * @param	int		$page
	 * @param	int		$per_page
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
	 * @param	string	$mini_token
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
	 * @param	string	$frob
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
	 * @param	string	$service
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
	 * @param	string	$blog_id
	 * @param	int		$photo_id
	 * @param	string	$title
	 * @param	string	$description
	 * @param	string	$blog_password
	 * @param	string	$service
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
	 * @param	string	$collection_id
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
	 * @param	string	$collection_id
	 * @param	string	$user_id
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
	 * Contacts Add
	 * 
	 * Adds an user to a user's contact list.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.contacts.add.html
	 * @param	int		$photo_id
	 * @return	mixed
	 */
	public function contacts_add($user_id, $friend = NULL, $family = NULL)
	{
		return $this->request('flickr.contacts.add', array('user_id' => $user_id, 'friend' => $friend, 'family' => $family), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Contacts Get List
	 * 
	 * Get a list of contacts for the calling user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.contacts.getList.html
	 * @param	string	$filter
	 * @param	int		$page
	 * @param	int		$per_page
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
	 * @param	string	$user_id
	 * @param	int		$page
	 * @param	int		$per_page
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
	 * @param	int		$date_lastupload
	 * @param	string	$filter
	 * @return	mixed
	 */
	public function contacts_getListRecentlyUploaded($date_lastupload = NULL, $filter = NULL)
	{
		return $this->request('flickr.contacts.getListRecentlyUploaded', array('date_lastupload' => $date_lastupload, 'filter' => $filter));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Contacts Remove
	 * 
	 * Removes an user from a user's contact list.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.contacts.remove.html
	 * @param	int		$photo_id
	 * @return	mixed
	 */
	public function contacts_remove($user_id)
	{
		return $this->request('flickr.contacts.remove', array('user_id' => $user_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Favorites Add
	 * 
	 * Adds a photo to a user's favorites list.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.favorites.add.html
	 * @param	int		$photo_id
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
	 * @param	string	$user_id
	 * @param	int		$min_fave_date
	 * @param	int		$max_fave_date
	 * @param	string	$extras
	 * @param	int		$per_page
	 * @param	int		$page
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
	 * @param	string	$user_id
	 * @param	int		$min_fave_date
	 * @param	int		$max_fave_date
	 * @param	string	$extras
	 * @param	int		$per_page
	 * @param	int		$page
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
	 * @param	int		$photo_id
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
	 * @param	int		$cat_id
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
	 * @param	string	$group_id
	 * @param	string	$lang
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
	 * @param	string	$text
	 * @param	int		$per_page
	 * @param	int		$page
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
	 * @param	string	$group_id
	 * @param	string	$membertypes
	 * @param	int		$per_page
	 * @param	int		$page
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
	 * @param	int		$photo_id
	 * @param	string	$group_id
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
	 * @param	int		$photo_id
	 * @param	string	$group_id
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
	 * @param	int		$per_page
	 * @param	int		$page
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
	 * @param	string	$group_id
	 * @param	string	$tags
	 * @param	string	$user_id
	 * @param	string	$extras
	 * @param	int		$per_page
	 * @param	int		$page
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
	 * @param	int		$photo_id
	 * @param	string	$group_id
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
	 * @param	string	$date
	 * @param	string	$extras
	 * @param	int		$per_page
	 * @param	int		$page
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
	 * @param	string	$predicate
	 * @param	int		$per_page
	 * @param	int		$page
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
	 * @param	string	$namespace
	 * @param	string	$predicate
	 * @param	int		$per_page
	 * @param	int		$page
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
	 * @param	string	$namespace
	 * @param	int		$per_page
	 * @param	int		$page
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
	 * @param	string	$namespace
	 * @param	string	$predicate
	 * @param	int		$added_since
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
	 * @param	string	$namespace
	 * @param	string	$predicate
	 * @param	int		$per_page
	 * @param	int		$page
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
	 * @param	string	$panda_name
	 * @param	string	$extras
	 * @param	int		$per_page
	 * @param	int		$page
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
	 * @param	string	$find_email
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
	 * @param	string	$username
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
	 * @param	string	$user_id
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
	 * @param	string	$user_id
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
	 * @param	string	$user_id
	 * @param	int		$safe_search	Safe search setting: 1 for safe, 2 for moderate, 3 for restricted.
	 * @param	string	$extras
	 * @param	int		$per_page
	 * @param	int		$page
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
	 * @param	int		$photo_id
	 * @param	string	$tags
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
	 * @param	int		$photo_id
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
	 * @param	int		$photo_id
	 * @return	mixed
	 */
	public function photos_getAllContexts($photo_id)
	{
		return $this->request('flickr.photos.getAllContexts', array('photo_id' => $photo_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get Contact Photos
	 * 
	 * Fetch a list of recent photos from the calling users' contacts.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getContactsPhotos.html
	 * @param	int		$count
	 * @param	bool	$just_friends
	 * @param	bool	$single_photo
	 * @param	bool	$include_self
	 * @param	string	$extras
	 * @return	mixed
	 */
	public function photos_getContactsPhotos($count = NULL, $just_friends = NULL, $single_photo = NULL, $include_self = NULL, $extras = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.getContactsPhotos', array('count' => $count, 'just_friends' => $just_friends, 'single_photo' => $single_photo, 'include_self' => $include_self, 'extras' => $extras));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get Contacts Public Photos
	 * 
	 * Fetch a list of recent public photos from a users' contacts.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getContactsPublicPhotos.html
	 * @param	string	$user_id
	 * @param	int		$count
	 * @param	bool	$just_friends
	 * @param	bool	$single_photo
	 * @param	bool	$include_self
	 * @param	string	$extras
	 * @return	mixed
	 */
	public function photos_getContactsPublicPhotos($user_id, $count = NULL, $just_friends = NULL, $single_photo = NULL, $include_self = NULL, $extras = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.getContactsPublicPhotos', array('user_id' => $user_id, 'count' => $count, 'just_friends' => $just_friends, 'single_photo' => $single_photo, 'include_self' => $include_self, 'extras' => $extras));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get Context
	 * 
	 * Returns next and previous photos for a photo in a photostream.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getContext.html
	 * @param	int		$photo_id
	 * @return	mixed
	 */
	public function photos_getContext($photo_id)
	{
		return $this->request('flickr.photos.getContext', array('photo_id' => $photo_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get Counts
	 * 
	 * Gets a list of photo counts for the given date ranges for the calling user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getCounts.html
	 * @param	int		$dates
	 * @param	int		$taken_dates
	 * @return	mixed
	 */
	public function photos_getCounts($dates = NULL, $taken_dates = NULL)
	{
		return $this->request('flickr.photos.getCounts', array('dates' => $dates, 'taken_dates' => $taken_dates));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get Exif
	 * 
	 * Retrieves a list of EXIF/TIFF/GPS tags for a given photo. The calling 
	 * user must have permission to view the photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getExif.html
	 * @param	int		$photo_id
	 * @param	string	$secret
	 * @return	mixed
	 */
	public function photos_getExif($photo_id, $secret = NULL)
	{
		return $this->request('flickr.photos.getExif', array('photo_id' => $photo_id, 'secret' => $secret));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get Favorites
	 * 
	 * Returns the list of people who have favorited a given photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getFavorites.html
	 * @param	int		$photo_id
	 * @param	int		$page
	 * @param	int		$per_page
	 * @return	mixed
	 */
	public function photos_getFavorites($photo_id, $page = NULL, $per_page = NULL)
	{
		return $this->request('flickr.photos.getFavorites', array('photo_id' => $photo_id, 'page' => $page, 'per_page' => $per_page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get Info
	 * 
	 * Get information about a photo. The calling user must have permission to 
	 * view the photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getInfo.html
	 * @param	int		$photo_id
	 * @param	string	$secret
	 * @return	mixed
	 */
	public function photos_getInfo($photo_id, $secret = NULL)
	{
		return $this->request('flickr.photos.getInfo', array('photo_id' => $photo_id, 'secret' => $secret));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get Not In Set
	 * 
	 * Returns a list of your photos that are not part of any sets.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getNotInSet.html
	 * @param	int		$min_upload_date
	 * @param	int		$max_upload_date
	 * @param	string	$min_taken_date
	 * @param	string	$max_taken_date
	 * @param	int		$privacy_filter
	 * @param	string	$media
	 * @param	string	$extras
	 * @param	int		$page
	 * @param	int		$per_page
	 * @return	mixed
	 */
	public function photos_getNotInSet($min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL, $privacy_filter = NULL, $media = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.getNotInSet', array('min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date, 'privacy_filter' => $privacy_filter, 'media' => $media, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get Permissions
	 * 
	 * Get permissions for a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getPerms.html
	 * @param	int		$photo_id
	 * @return	mixed
	 */
	public function photos_getPerms($photo_id)
	{
		return $this->request('flickr.photos.getPerms', array('photo_id' => $photo_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get Recent
	 * 
	 * Returns a list of the latest public photos uploaded to flickr.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getRecent.html
	 * @param	string	$extras
	 * @param	int		$page
	 * @param	int		$per_page
	 * @return	mixed
	 */
	public function photos_getRecent($extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.getRecent', array('extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get Sizes
	 * 
	 * Returns the available sizes for a photo. The calling user must have 
	 * permission to view the photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getSizes.html
	 * @param	int		$photo_id
	 * @return	mixed
	 */
	public function photos_getSizes($photo_id)
	{
		return $this->request('flickr.photos.getSizes', array('photo_id' => $photo_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get Untagged
	 * 
	 * Returns a list of your photos with no tags.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getUntagged.html
	 * @param	int		$min_upload_date
	 * @param	int		$max_upload_date
	 * @param	string	$min_taken_date
	 * @param	string	$max_taken_date
	 * @param	int		$privacy_filter
	 * @param	string	$media
	 * @param	string	$extras
	 * @param	int		$page
	 * @param	int		$per_page
	 * @return	mixed
	 */
	public function photos_getUntagged($min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL, $privacy_filter = NULL, $media = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.getUntagged', array('min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date, 'privacy_filter' => $privacy_filter, 'media' => $media, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get With Geo Data
	 * 
	 * Returns a list of your geo-tagged photos.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getWithGeoData.html
	 * @param	int		$min_upload_date
	 * @param	int		$max_upload_date
	 * @param	string	$min_taken_date
	 * @param	string	$max_taken_date
	 * @param	int		$privacy_filter
	 * @param	string	$sort
	 * @param	string	$media
	 * @param	string	$extras
	 * @param	int		$page
	 * @param	int		$per_page
	 * @return	mixed
	 */
	public function photos_getWithGeoData($min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL, $privacy_filter = NULL, $sort = NULL, $media = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.getWithGeoData', array('min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date, 'privacy_filter' => $privacy_filter, 'sort' => $sort, 'media' => $media, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Get Without Geo Data
	 * 
	 * Returns a list of your photos which haven't been geo-tagged.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.getWithoutGeoData.html
	 * @param	int		$min_upload_date
	 * @param	int		$max_upload_date
	 * @param	string	$min_taken_date
	 * @param	string	$max_taken_date
	 * @param	int		$privacy_filter
	 * @param	string	$sort
	 * @param	string	$media
	 * @param	string	$extras
	 * @param	int		$page
	 * @param	int		$per_page
	 * @return	mixed
	 */
	public function photos_getWithoutGeoData($min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL, $privacy_filter = NULL, $sort = NULL, $media = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.getWithoutGeoData', array('min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date, 'privacy_filter' => $privacy_filter, 'sort' => $sort, 'media' => $media, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Recently Updated
	 * 
	 * Return a list of your photos that have been recently created or which 
	 * have been recently modified. Recently modified may mean that the photo's 
	 * metadata (title, description, tags) may have been changed or a comment 
	 * has been added (or just modified somehow :-)
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.recentlyUpdated.html
	 * @param	int		$min_date
	 * @param	string	$extras
	 * @param	int		$page
	 * @param	int		$per_page
	 * @return	mixed
	 */
	public function photos_recentlyUpdated($min_date, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.recentlyUpdated', array('min_date' => $min_date, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Remove Tag
	 * 
	 * Remove a tag from a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.removeTag.html
	 * @param	int		$tag_id
	 * @return	mixed
	 */
	public function photos_removeTag($tag_id)
	{
		return $this->request('flickr.photos.removeTag', array('tag_id' => $tag_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Search
	 * 
	 * Return a list of photos matching some criteria. Only photos visible to 
	 * the calling user will be returned. To return private or semi-private 
	 * photos, the caller must be authenticated with 'read' permissions, and 
	 * have permission to view the photos. Unauthenticated calls will only 
	 * return public photos.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.search.html
	 * @param	array		$args
	 * @return	mixed
	 */
	public function photos_search($args = array())
	{
		return $this->request('flickr.photos.search', $args);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Set Content Type
	 * 
	 * Set the content type of a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.setContentType.html
	 * @param	int		$photo_id
	 * @param	int		$content_type
	 * @return	mixed
	 */
	public function photos_setContentType($photo_id, $content_type)
	{
		return $this->request('flickr.photos.setContentType', array('photo_id' => $photo_id, 'content_type' => $content_type));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Set Dates
	 * 
	 * Set one or both of the dates for a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.setDates.html
	 * @param	int		$photo_id
	 * @param	int		$date_posted
	 * @param	string	$date_taken
	 * @param	int		$date_taken_granularity
	 * @return	mixed
	 */
	public function photos_setDates($photo_id, $date_posted = NULL, $date_taken = NULL, $date_taken_granularity = NULL)
	{
		return $this->request('flickr.photos.setDates', array('photo_id' => $photo_id, 'date_posted' => $date_posted, 'date_taken' => $date_taken, 'date_taken_granularity' => $date_taken_granularity), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Set Meta
	 * 
	 * Set the meta information for a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.setMeta.html
	 * @param	int		$photo_id
	 * @param	string	$title
	 * @param	string	$description
	 * @return	mixed
	 */
	public function photos_setMeta($photo_id, $title, $description)
	{
		return $this->request('flickr.photos.setMeta', array('photo_id' => $photo_id, 'title' => $title, 'description' => $description), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Set Permissions
	 * 
	 * Set permissions for a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.setPerms.html
	 * @param	int		$photo_id
	 * @param	int		$is_public
	 * @param	int		$is_friend
	 * @param	int		$is_family
	 * @param	int		$perm_comment
	 * @param	int		$perm_addmeta
	 * @return	mixed
	 */
	public function photos_setPerms($photo_id, $is_public, $is_friend, $is_family, $perm_comment, $perm_addmeta)
	{
		return $this->request('flickr.photos.setPerms', array('photo_id' => $photo_id, 'is_public' => $is_public, 'is_friend' => $is_friend, 'is_family' => $is_family, 'perm_comment' => $perm_comment, 'perm_addmeta' => $perm_addmeta), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Set Safety Level
	 * 
	 * Set the safety level of a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.setSafetyLevel.html
	 * @param	int		$photo_id
	 * @param	int		$safety_level
	 * @param	int		$hidden
	 * @return	mixed
	 */
	public function photos_setSafetyLevel($photo_id, $safety_level = NULL, $hidden = NULL)
	{
		return $this->request('flickr.photos.setSafetyLevel', array('photo_id' => $photo_id, 'safety_level' => $safety_level, 'hidden' => $hidden));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Set Tags
	 * 
	 * Set the tags for a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.setTags.html
	 * @param	int		$photo_id
	 * @param	string	$safety_level
	 * @return	mixed
	 */
	public function photos_setTags($photo_id, $tags)
	{
		return $this->request('flickr.photos.setTags', array('photo_id' => $photo_id, 'tags' => $tags), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Comments Add Comment
	 * 
	 * Add comment to a photo as the currently authenticated user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.comments.addComment.html
	 * @param	int		$photo_id
	 * @param	string	$comment_text
	 * @return	mixed
	 */
	public function photos_comments_addComment($photo_id, $comment_text)
	{
		return $this->request('flickr.photos.comments.addComment', array('photo_id' => $photo_id, 'comment_text' => $comment_text), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Comments Delete Comment
	 * 
	 * Delete a comment as the currently authenticated user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.comments.deleteComment.html
	 * @param	int		$comment_id
	 * @return	mixed
	 */
	public function photos_comments_deleteComment($comment_id)
	{
		return $this->request('flickr.photos.comments.deleteComment', array('comment_id' => $comment_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Comments Edit Comment
	 * 
	 * Edit the text of a comment as the currently authenticated user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.comments.editComment.html
	 * @param	int		$comment_id
	 * @param	string	$comment_text
	 * @return	mixed
	 */
	public function photos_comments_editComment($comment_id, $comment_text)
	{
		return $this->request('flickr.photos.comments.editComment', array('comment_id' => $comment_id, 'comment_text' => $comment_text), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Comments Get List
	 * 
	 * Returns the comments for a photo
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.comments.getList.html
	 * @param	int		$photo_id
	 * @param	int		$min_comment_date
	 * @param	int		$max_comment_date
	 * @return	mixed
	 */
	public function photos_comments_getList($photo_id, $min_comment_date = NULL, $max_comment_date = NULL)
	{
		return $this->request('flickr.photos.comments.getList', array('photo_id' => $photo_id, 'min_comment_date' => $min_comment_date, 'max_comment_date' => $max_comment_date));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Comments Get Recent For Contacts
	 * 
	 * Return the list of photos belonging to your contacts that have been 
	 * commented on recently.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.comments.getRecentForContacts.html
	 * @param	int		$date_lastcomment
	 * @param	string	$contacts_filter
	 * @param	string	$extras
	 * @param	int		$per_page
	 * @param	int		$page
	 * @return	mixed
	 */
	public function photos_comments_getRecentForContacts($date_lastcomment = NULL, $contacts_filter = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.comments.getRecentForContacts', array('date_lastcomment' => $date_lastcomment, 'contacts_filter' => $contacts_filter, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Geo Batch Correct Location
	 * 
	 * Correct the places hierarchy for all the photos for a user at a given 
	 * latitude, longitude and accuracy.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.geo.batchCorrectLocation.html
	 * @param	int		$lat
	 * @param	int		$lon
	 * @param	int		$accuracy
	 * @param	int		$place_id
	 * @param	int		$woe_id
	 * @return	mixed
	 */
	public function photos_geo_batchCorrectLocation($lat, $lon, $accuracy, $place_id = NULL, $woe_id = NULL)
	{
		return $this->request('flickr.photos.geo.batchCorrectLocation', array('lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy, 'place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Geo Correct Location
	 * 
	 * Correct the location of a photo
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.geo.correctLocation.html
	 * @param	int		$photo_id
	 * @param	int		$place_id
	 * @param	int		$woe_id
	 * @return	mixed
	 */
	public function photos_geo_correctLocation($photo_id, $place_id = NULL, $woe_id = NULL)
	{
		return $this->request('flickr.photos.geo.correctLocation', array('photo_id' => $photo_id, 'place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Geo Get Location
	 * 
	 * Get the geo data (latitude and longitude and the accuracy level) for a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.geo.getLocation.html
	 * @param	int		$photo_id
	 * @return	mixed
	 */
	public function photos_geo_getLocation($photo_id)
	{
		return $this->request('flickr.photos.geo.getLocation', array('photo_id' => $photo_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Geo Get Permissions
	 * 
	 * Get permissions for who may view geo data for a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.geo.getPerms.html
	 * @param	int		$photo_id
	 * @return	mixed
	 */
	public function photos_geo_getPerms($photo_id)
	{
		return $this->request('flickr.photos.geo.getPerms', array('photo_id' => $photo_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Geo Photos For Location
	 * 
	 * Return a list of photos for a user at a specific latitude, longitude 
	 * and accuracy
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.geo.photosForLocation.html
	 * @param	int		$lat
	 * @param	int		$lon
	 * @param	int		$accuracy
	 * @param	string	$extras
	 * @param	int		$per_page
	 * @param	int		$page
	 * @return	mixed
	 */
	public function photos_geo_photosForLocation($lat, $lon, $accuracy = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photos.geo.photosForLocation', array('lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Geo Remove Location
	 * 
	 * Removes the geo data associated with a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.geo.removeLocation.html
	 * @param	int		$photo_id
	 * @return	mixed
	 */
	public function photos_geo_removeLocation($photo_id)
	{
		return $this->request('flickr.photos.geo.removeLocation', array('photo_id' => $photo_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Geo Set Context
	 * 
	 * Indicate the state of a photo's geotagginess beyond latitude and longitude.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.geo.setContext.html
	 * @param	int		$photo_id
	 * @param	int		$context
	 * @return	mixed
	 */
	public function photos_geo_setContext($photo_id, $context)
	{
		return $this->request('flickr.photos.geo.setContext', array('photo_id' => $photo_id, 'context' => $context));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Geo Set Location
	 * 
	 * Sets the geo data (latitude and longitude and, optionally, the accuracy 
	 * level) for a photo. Before users may assign location data to a photo they 
	 * must define who, by default, may view that information. Users can edit 
	 * this preference at http://www.flickr.com/account/geo/privacy/. If a user 
	 * has not set this preference, the API method will return an error.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.geo.setLocation.html
	 * @param	int		$photo_id
	 * @param	int		$lat
	 * @param	int		$lon
	 * @param	int		$accuracy
	 * @param	int		$context
	 * @return	mixed
	 */
	public function photos_geo_setLocation($photo_id, $lat, $lon, $accuracy = NULL, $context = NULL)
	{
		return $this->request('flickr.photos.geo.setLocation', array('photo_id' => $photo_id, 'lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy, 'context' => $context), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Geo Set Permissions
	 * 
	 * Set the permission for who may view the geo data associated with a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.geo.setPerms.html
	 * @param	int		$photo_id
	 * @param	int		$is_public
	 * @param	int		$is_contact
	 * @param	int		$is_friend
	 * @param	int		$is_family
	 * @return	mixed
	 */
	public function photos_geo_setPerms($photo_id, $is_public, $is_contact, $is_friend, $is_family)
	{
		return $this->request('flickr.photos.geo.setPerms', array('is_public' => $is_public, 'is_contact' => $is_contact, 'is_friend' => $is_friend, 'is_family' => $is_family, 'photo_id' => $photo_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Licenses Get Info
	 * 
	 * Fetches a list of available photo licenses for Flickr.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.licenses.getInfo.html
	 * @return	mixed
	 */
	public function photos_licenses_getInfo()
	{
		return $this->request('flickr.photos.licenses.getInfo');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Licenses Set License
	 * 
	 * Sets the license for a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.licenses.setLicense.html
	 * @param	int		$photo_id
	 * @param	int		$license_id
	 * @return	mixed
	 */
	public function photos_licenses_setLicense($photo_id, $license_id)
	{
		return $this->request('flickr.photos.licenses.setLicense', array('photo_id' => $photo_id, 'license_id' => $license_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Notes Add
	 * 
	 * Add a note to a photo. Coordinates and sizes are in pixels, based on the 
	 * 500px image size shown on individual photo pages.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.notes.add.html
	 * @param	int		$photo_id
	 * @param	int		$note_x
	 * @param	int		$note_y
	 * @param	int		$note_w
	 * @param	int		$note_h
	 * @param	string	$note_text
	 * @return	mixed
	 */
	public function photos_notes_add($photo_id, $note_x, $note_y, $note_w, $note_h, $note_text)
	{
		return $this->request('flickr.photos.notes.add', array('photo_id' => $photo_id, 'note_x' => $note_x, 'note_y' => $note_y, 'note_w' => $note_w, 'note_h' => $note_h, 'note_text' => $note_text), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Notes Delete
	 * 
	 * Delete a note from a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.notes.delete.html
	 * @param	int		$note_id
	 * @return	mixed
	 */
	public function photos_notes_delete($note_id)
	{
		return $this->request('flickr.photos.notes.delete', array('note_id' => $note_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Notes Edit
	 * 
	 * Edit a note on a photo. Coordinates and sizes are in pixels, based on 
	 * the 500px image size shown on individual photo pages.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.notes.edit.html
	 * @param	int		$note_id
	 * @param	int		$note_x
	 * @param	int		$note_y
	 * @param	int		$note_w
	 * @param	int		$note_h
	 * @param	string	$note_text
	 * @return	mixed
	 */
	public function photos_notes_edit($note_id, $note_x, $note_y, $note_w, $note_h, $note_text)
	{
		return $this->request('flickr.photos.notes.edit', array('note_id' => $note_id, 'note_x' => $note_x, 'note_y' => $note_y, 'note_w' => $note_w, 'note_h' => $note_h, 'note_text' => $note_text), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Transform Rotate
	 * 
	 * Rotate a photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.transform.rotate.html
	 * @param	int		$photo_id
	 * @param	int		$degrees
	 * @return	mixed
	 */
	public function photos_transform_rotate($photo_id, $degrees)
	{
		return $this->request('flickr.photos.transform.rotate', array('photo_id' => $photo_id, 'degrees' => $degrees), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photos Upload Check Tickets
	 * 
	 * Checks the status of one or more asynchronous photo upload tickets.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photos.upload.checkTickets.html
	 * @param	string	$tickets
	 * @return	mixed
	 */
	public function photos_upload_checkTickets($tickets)
	{
		$tickets = is_array($tickets) && !empty($tickets) ? implode(',', $tickets) : $tickets;
		return $this->request('flickr.photos.upload.checkTickets', array('tickets' => $tickets), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Add Photo
	 * 
	 * Add a photo to the end of an existing photoset.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.addPhoto.html
	 * @param	int		$photoset_id
	 * @param	int		$photo_id
	 * @return	mixed
	 */
	public function photosets_addPhoto($photoset_id, $photo_id)
	{
		return $this->request('flickr.photosets.addPhoto', array('photoset_id' => $photoset_id, 'photo_id' => $photo_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Create
	 * 
	 * Create a new photoset for the calling user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.create.html
	 * @param	string	$title
	 * @param	string	$description
	 * @param	int		$primary_photo_id
	 * @return	mixed
	 */
	public function photosets_create($title, $description, $primary_photo_id)
	{
		return $this->request('flickr.photosets.create', array('title' => $title, 'primary_photo_id' => $primary_photo_id, 'description' => $description), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Delete
	 * 
	 * Delete a photoset.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.delete.html
	 * @param	int		$photoset_id
	 * @return	mixed
	 */
	public function photosets_delete($photoset_id)
	{
		return $this->request('flickr.photosets.delete', array('photoset_id' => $photoset_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Edit Meta
	 * 
	 * Modify the meta-data for a photoset.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.editMeta.html
	 * @param	int		$photoset_id
	 * @param	string	$title
	 * @param	string	$description
	 * @return	mixed
	 */
	public function photosets_editMeta($photoset_id, $title, $description = NULL)
	{
		return $this->request('flickr.photosets.editMeta', array('photoset_id' => $photoset_id, 'title' => $title, 'description' => $description), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Edit Photos
	 * 
	 * Modify the photos in a photoset. Use this method to add, remove and 
	 * re-order photos.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.editPhotos.html
	 * @param	int		$photoset_id
	 * @param	int		$primary_photo_id
	 * @param	string	$photo_ids
	 * @return	mixed
	 */
	public function photosets_editPhotos($photoset_id, $primary_photo_id, $photo_ids)
	{
		return $this->request('flickr.photosets.editPhotos', array('photoset_id' => $photoset_id, 'primary_photo_id' => $primary_photo_id, 'photo_ids' => $photo_ids), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Get Context
	 * 
	 * Returns next and previous photos for a photo in a set.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.getContext.html
	 * @param	int		$photoset_id
	 * @param	int		$photo_id
	 * @return	mixed
	 */
	public function photosets_getContext($photoset_id, $photo_id)
	{
		return $this->request('flickr.photosets.getContext', array('photo_id' => $photo_id, 'photoset_id' => $photoset_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Get Info
	 * 
	 * Gets information about a photoset.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.getInfo.html
	 * @param	int		$photoset_id
	 * @return	mixed
	 */
	public function photosets_getInfo($photoset_id)
	{
		return $this->request('flickr.photosets.getInfo', array('photoset_id' => $photoset_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Get List
	 * 
	 * Returns the photosets belonging to the specified user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.getList.html
	 * @param	string	$user_id
	 * @return	mixed
	 */
	public function photosets_getList($user_id = NULL)
	{
		return $this->request('flickr.photosets.getList', array('user_id' => $user_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Get Photos
	 * 
	 * Get the list of photos in a set.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.getPhotos.html
	 * @param	int		$photoset_id
	 * @param	int		$privacy_filter
	 * @param	string	$media
	 * @param	string	$extras
	 * @param	int		$per_page
	 * @param	int		$page
	 * @return	mixed
	 */
	public function photosets_getPhotos($photoset_id, $privacy_filter = NULL, $media = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		$extras = is_array($extras) && !empty($extras) ? implode(',', $extras) : $extras;
		return $this->request('flickr.photosets.getPhotos', array('photoset_id' => $photoset_id, 'extras' => $extras, 'privacy_filter' => $privacy_filter, 'per_page' => $per_page, 'page' => $page, 'media' => $media));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Order Sets
	 * 
	 * Set the order of photosets for the calling user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.orderSets.html
	 * @param	string	$photoset_ids
	 * @return	mixed
	 */
	public function photosets_orderSets($photoset_ids)
	{
		$photoset_ids = is_array($photoset_ids) && !empty($photoset_ids) ? implode(',', $photoset_ids) : $photoset_ids;
		return $this->request('flickr.photosets.orderSets', array('photoset_ids' => $photoset_ids), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Remove Photo
	 * 
	 * Remove a photo from a photoset.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.removePhoto.html
	 * @param	int		$photoset_id
	 * @param	int		$photo_id
	 * @return	mixed
	 */
	public function photosets_removePhoto($photoset_id, $photo_id)
	{
		return $this->request('flickr.photosets.removePhoto', array('photoset_id' => $photoset_id, 'photo_id' => $photo_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Comments Add Comment
	 * 
	 * Add a comment to a photoset.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.comments.addComment.html
	 * @param	int		$photoset_id
	 * @param	string	$comment_text
	 * @return	mixed
	 */
	public function photosets_comments_addComment($photoset_id, $comment_text)
	{
		return $this->request('flickr.photosets.comments.addComment', array('photoset_id' => $photoset_id, 'comment_text' => $comment_text), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Comments Delete Comment
	 * 
	 * Delete a photoset comment as the currently authenticated user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.comments.deleteComment.html
	 * @param	int		$comment_id
	 * @return	mixed
	 */
	public function photosets_comments_deleteComment($comment_id)
	{
		return $this->request('flickr.photosets.comments.deleteComment', array('comment_id' => $comment_id), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Comments Edit Comment
	 * 
	 * Edit the text of a comment as the currently authenticated user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.comments.editComment.html
	 * @param	int		$comment_id
	 * @param	string	$comment_text
	 * @return	mixed
	 */
	public function photosets_comments_editComment($comment_id, $comment_text)
	{
		return $this->request('flickr.photosets.comments.editComment', array('comment_id' => $comment_id, 'comment_text' => $comment_text), TRUE);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Photosets Comments Get List
	 * 
	 * Returns the comments for a photoset.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.photosets.comments.getList.html
	 * @param	int		$photoset_id
	 * @return	mixed
	 */
	public function photosets_comments_getList($photoset_id)
	{
		return $this->request('flickr.photosets.comments.getList', array('photoset_id' => $photoset_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Places Find
	 * 
	 * Return a list of place IDs for a query string. The flickr.places.find 
	 * method is not a geocoder. It will round "up" to the nearest place type to 
	 * which place IDs apply. For example, if you pass it a street level address 
	 * it will return the city that contains the address rather than the 
	 * street, or building, itself.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.places.find.html
	 * @param	string	$query
	 * @return	mixed
	 */
	public function places_find($query)
	{
		return $this->request('flickr.places.find', array('query' => $query));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Places Find By Latitude and Longitude
	 * 
	 * Return a place ID for a latitude, longitude and accuracy triple.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.places.findByLatLon.html
	 * @param	int		$lat
	 * @param	int		$lon
	 * @param	int		$accuracy
	 * @return	mixed
	 */
	public function places_findByLatLon($lat, $lon, $accuracy = NULL)
	{
		return $this->request('flickr.places.findByLatLon', array('lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Places Get Children With Photos Public
	 * 
	 * Return a list of locations with public photos that are parented by a 
	 * Where on Earth (WOE) or Places ID.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.places.getChildrenWithPhotosPublic.html
	 * @param	string	$place_id
	 * @param	int		$woe_id
	 * @return	mixed
	 */
	public function places_getChildrenWithPhotosPublic($place_id = NULL, $woe_id = NULL)
	{
		return $this->request('flickr.places.getChildrenWithPhotosPublic', array('place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Places Get Info
	 * 
	 * Get informations about a place.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.places.getInfo.html
	 * @param	string	$place_id
	 * @param	int		$woe_id
	 * @return	mixed
	 */
	public function places_getInfo($place_id = NULL, $woe_id = NULL)
	{
		return $this->request('flickr.places.getInfo', array('place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Places Get Info By Url
	 * 
	 * Lookup information about a place, by its flickr.com/places URL.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.places.getInfoByUrl.html
	 * @param	string	$url
	 * @return	mixed
	 */
	public function places_getInfoByUrl($url)
	{
		return $this->request('flickr.places.getInfoByUrl', array('url' => $url));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Places Get Place Types
	 * 
	 * Fetches a list of available place types for Flickr.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.places.getPlaceTypes.html
	 * @return	mixed
	 */
	public function places_getPlaceTypes()
	{
		return $this->request('flickr.places.getPlaceTypes', array());
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Places Get Shape History
	 * 
	 * Return an historical list of all the shape data generated for a Places 
	 * or Where on Earth (WOE) ID.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.places.getShapeHistory.html
	 * @param	string	$place_id
	 * @param	int		$woe_id
	 * @return	mixed
	 */
	public function places_getShapeHistory($place_id = NULL, $woe_id = NULL)
	{
		return $this->request('flickr.places.getShapeHistory', array('place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Places Get Top Places List
	 * 
	 * Return the top 100 most geotagged places for a day.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.places.getTopPlacesList.html
	 * @param	int		$place_type_id
	 * @param	string	$date
	 * @param	string	$place_id
	 * @param	int		$woe_id
	 * @return	mixed
	 */
	public function places_getTopPlacesList($place_type_id, $date = NULL, $place_id = NULL, $woe_id = NULL)
	{
		return $this->request('flickr.places.getTopPlacesList', array('place_type_id' => $place_type_id, 'date' => $date, 'woe_id' => $woe_id, 'place_id' => $place_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Places For Bounding Box
	 * 
	 * Return all the locations of a matching place type for a bounding box.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.places.placesForBoundingBox.html
	 * @param	string	$bbox
	 * @param	int		$place_type_id
	 * @return	mixed
	 */
	public function places_placesForBoundingBox($bbox, $place_type_id = NULL)
	{
		return $this->request('flickr.places.placesForBoundingBox', array('bbox' => $bbox, 'place_type_id' => $place_type_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Places For Contacts
	 * 
	 * Return a list of the top 100 unique places clustered by a given 
	 * placetype for a user's contacts.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.places.placesForContacts.html
	 * @param	int		$place_type_id
	 * @param	int		$woe_id
	 * @param	string	$place_id
	 * @param	int		$threshold
	 * @param	string	$contacts
	 * @param	int		$min_upload_date
	 * @param	int		$max_upload_date
	 * @param	string	$min_taken_date
	 * @param	string	$max_taken_date
	 * @return	mixed
	 */
	public function places_placesForContacts($place_type_id = NULL, $woe_id = NULL, $place_id = NULL, $threshold = NULL, $contacts = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL)
	{
		return $this->request('flickr.places.placesForContacts', array('place_type_id' => $place_type_id, 'woe_id' => $woe_id, 'place_id' => $place_id, 'threshold' => $threshold, 'contacts' => $contacts, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Places For Tags
	 * 
	 * Return a list of the top 100 unique places clustered by a given 
	 * placetype for set of tags or machine tags.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.places.placesForTags.html
	 * @param	int		$place_type_id
	 * @param	int		$woe_id
	 * @param	string	$place_id
	 * @param	int		$threshold
	 * @param	string	$tags
	 * @param	string	$tag_mode
	 * @param	string	$machine_tags
	 * @param	string	$machine_tag_mode
	 * @param	int		$min_upload_date
	 * @param	int		$max_upload_date
	 * @param	string	$min_taken_date
	 * @param	string	$max_taken_date
	 * @return	mixed
	 */
	public function places_placesForTags($place_type_id, $woe_id = NULL, $place_id = NULL, $threshold = NULL, $tags = NULL, $tag_mode = NULL, $machine_tags = NULL, $machine_tag_mode = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL)
	{
		return $this->request('flickr.places.placesForTags', array('place_type_id' => $place_type_id, 'woe_id' => $woe_id, 'place_id' => $place_id, 'threshold' => $threshold, 'tags' => $tags, 'tag_mode' => $tag_mode, 'machine_tags' => $machine_tags, 'machine_tag_mode' => $machine_tag_mode, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Places For User
	 * 
	 * Return a list of the top 100 unique places clustered by a given 
	 * placetype for a user. 
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.places.placesForUser.html
	 * @param	int		$place_type_id
	 * @param	int		$woe_id
	 * @param	string	$place_id
	 * @param	int		$threshold
	 * @param	int		$min_upload_date
	 * @param	int		$max_upload_date
	 * @param	string	$min_taken_date
	 * @param	string	$max_taken_date
	 * @return	mixed
	 */
	public function places_placesForUser($place_type_id = NULL, $woe_id = NULL, $place_id = NULL, $threshold = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL)
	{
		return $this->request('flickr.places.placesForUser', array('place_type_id' => $place_type_id, 'woe_id' => $woe_id, 'place_id' => $place_id, 'threshold' => $threshold, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Places Tags For Place
	 * 
	 * Return a list of the top 100 unique tags for a Flickr Places 
	 * or Where on Earth (WOE) ID
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.places.tagsForPlace.html
	 * @param	int		$woe_id
	 * @param	string	$place_id
	 * @param	int		$min_upload_date
	 * @param	int		$max_upload_date
	 * @param	string	$min_taken_date
	 * @param	string	$max_taken_date
	 * @return	mixed
	 */
	function places_tagsForPlace($woe_id = NULL, $place_id = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL)
	{
		return $this->request('flickr.places.tagsForPlace', array('woe_id' => $woe_id, 'place_id' => $place_id, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Preferences Get Content Type
	 * 
	 * Returns the default content type preference for the user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.prefs.getContentType.html
	 * @return	mixed
	 */
	public function prefs_getContentType()
	{
		return $this->request('flickr.prefs.getContentType');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Preferences Get Geo Permissions
	 * 
	 * Returns the default privacy level for geographic information attached 
	 * to the user's photos and whether or not the user has chosen to use 
	 * geo-related EXIF information to automatically geotag their photos.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.prefs.getGeoPerms.html
	 * @return	mixed
	 */
	public function prefs_getGeoPerms()
	{
		return $this->request('flickr.prefs.getGeoPerms');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Preferences Get Hidden
	 * 
	 * Returns the default hidden preference for the user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.prefs.getHidden.html
	 * @return	mixed
	 */
	public function prefs_getHidden()
	{
		return $this->request('flickr.prefs.getHidden');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Preferences Get Privacy
	 * 
	 * Returns the default privacy level preference for the user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.prefs.getPrivacy.html
	 * @return	mixed
	 */
	public function prefs_getPrivacy()
	{
		return $this->request('flickr.prefs.getPrivacy');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Preferences Get Safety Level
	 * 
	 * Returns the default safety level preference for the user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.prefs.getSafetyLevel.html
	 * @return	mixed
	 */
	public function prefs_getSafetyLevel()
	{
		return $this->request('flickr.prefs.getSafetyLevel');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Reflection Get Method Info
	 * 
	 * Returns information for a given flickr API method.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.reflection.getMethodInfo.html
	 * @param	string	$method_name
	 * @return	mixed
	 */
	public function reflection_getMethodInfo($method_name)
	{
		return $this->request('flickr.reflection.getMethodInfo', array('method_name' => $method_name));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Reflection Get Methods
	 * 
	 * Returns a list of available flickr API methods.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.reflection.getMethods.html
	 * @return	mixed
	 */
	public function reflection_getMethods()
	{
		return $this->request('flickr.reflection.getMethods');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Tags Get Cluster Photos
	 * 
	 * Returns the first 24 photos for a given tag cluster
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.tags.getClusterPhotos.html
	 * @param	string	$tag
	 * @param	string	$cluster_id
	 * @return	mixed
	 */
	public function tags_getClusterPhotos($tag, $cluster_id)
	{
		return $this->request('flickr.tags.getClusterPhotos', array('tag' => $tag, 'cluster_id' => $cluster_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Tags Get Clusters
	 * 
	 * Gives you a list of tag clusters for the given tag.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.tags.getClusters.html
	 * @param	string	$tag
	 * @return	mixed
	 */
	public function tags_getClusters($tag)
	{
		return $this->request('flickr.tags.getClusters', array('tag' => $tag));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Tags Get Hot List
	 * 
	 * Returns a list of hot tags for the given period.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.tags.getHotList.html
	 * @param	string	$period
	 * @param	int		$count
	 * @return	mixed
	 */
	public function tags_getHotList($period = NULL, $count = NULL)
	{
		return $this->request('flickr.tags.getHotList', array('period' => $period, 'count' => $count));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Tags Get List Photo
	 * 
	 * Get the tag list for a given photo.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.tags.getListPhoto.html
	 * @param	int		$photo_id
	 * @return	mixed
	 */
	public function tags_getListPhoto($photo_id)
	{
		return $this->request('flickr.tags.getListPhoto', array('photo_id' => $photo_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Tags Get List User
	 * 
	 * Get the tag list for a given user (or the currently logged in user).
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.tags.getListUser.html
	 * @param	string	$user_id
	 * @return	mixed
	 */
	public function tags_getListUser($user_id = NULL)
	{
		return $this->request('flickr.tags.getListUser', array('user_id' => $user_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Tags Get List User Popular
	 * 
	 * Get the popular tags for a given user (or the currently logged in user).
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.tags.getListUserPopular.html
	 * @param	string	$user_id
	 * @param	int		$count
	 * @return	mixed
	 */
	public function tags_getListUserPopular($user_id = NULL, $count = NULL)
	{
		return $this->request('flickr.tags.getListUserPopular', array('user_id' => $user_id, 'count' => $count));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Tags Get List User Raw
	 * 
	 * Get the raw versions of a given tag (or all tags) for the currently 
	 * logged-in user.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.tags.getListUserRaw.html
	 * @param	string	$tag
	 * @return	mixed
	 */
	public function tags_getListUserRaw($tag = NULL)
	{
		return $this->request('flickr.tags.getListUserRaw', array('tag' => $tag));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Tags Get Related
	 * 
	 * Returns a list of tags 'related' to the given tag, based on clustered 
	 * usage analysis.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.tags.getRelated.html
	 * @param	string	$tag
	 * @return	mixed
	 */
	public function tags_getRelated($tag)
	{
		return $this->request('flickr.tags.getRelated', array('tag' => $tag));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Test Echo
	 * 
	 * A testing method which echo's all parameters back in the response.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.test.echo.html
	 * @param	array	$args
	 * @return	mixed
	 */
	public function test_echo($args = array())
	{
		return $this->request('flickr.test.echo', $args);
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Test Login
	 * 
	 * A testing method which checks if the caller is logged in then returns 
	 * their username.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.test.login.html
	 * @return	mixed
	 */
	public function test_login()
	{
		return $this->request('flickr.test.login');
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Urls Get Group
	 * 
	 * Returns the url to a group's page.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.urls.getGroup.html
	 * @param	string	$group_id
	 * @return	mixed
	 */
	public function urls_getGroup($group_id)
	{
		return $this->request('flickr.urls.getGroup', array('group_id' => $group_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Urls Get User Photos
	 * 
	 * Returns the url to a user's photos.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.urls.getUserPhotos.html
	 * @param	string	$user_id
	 * @return	mixed
	 */
	public function urls_getUserPhotos($user_id = NULL)
	{
		return $this->request('flickr.urls.getUserPhotos', array('user_id' => $user_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Urls Get User Profile
	 * 
	 * Returns the url to a user's profile.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.urls.getUserProfile.html
	 * @param	string	$user_id
	 * @return	mixed
	 */
	public function urls_getUserProfile($user_id = NULL)
	{
		return $this->request('flickr.urls.getUserProfile', array('user_id' => $user_id));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Urls Lookup Group
	 * 
	 * Returns a group NSID, given the url to a group's page or photo pool.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.urls.lookupGroup.html
	 * @param	string	$url
	 * @return	mixed
	 */
	public function urls_lookupGroup($url)
	{
		return $this->request('flickr.urls.lookupGroup', array('url' => $url));
	}
	
	// --------------------------------------------------------------------------
	
	/**
	 * Urls Lookup User
	 * 
	 * Returns a user NSID, given the url to a user's photos or profile.
	 * 
	 * @access	public
	 * @link	http://www.flickr.com/services/api/flickr.urls.lookupUser.html
	 * @param	string	$url
	 * @return	mixed
	 */
	public function urls_lookupUser($url)
	{
		return $this->request('flickr.urls.lookupUser', array('url' => $url));
	}
}

/* End of file Flickr_API.php */
/* Location: ./application/libraries/Flickr_API.php */