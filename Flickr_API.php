<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');
/**
 * LMB^Box CodeIgniter Flickr API
 *
 * A Flickr API Library for CodeIgniter
 *
 * @package		CI_Flickr_API
 * @author		Thomas Montague
 * @copyright	Copyright (c) 2009, LMB^Box
 * @license		
 * @link		http://lmbbox.com
 * @since		Version 0.1
 * @filesource
 */

// ------------------------------------------------------------------------

/**
 * CI_Flickr_API Class
 *
 * @package		CI_Flickr_API
 * @subpackage	Libraries
 * @category	Flickr_API
 * @author		Thomas Montague
 * @link		http://downr.lmbbox.com
 */
class CI_Flickr_API {

	var $api_key			= '';
	var $secret				= '';
	var $token				= '';
	var $cache_use_db		= FALSE;
	var $cache_table_name	= 'flickr_api_cache';
	var $cache_expiration	= 600;
	var $cache_max_rows		= 1000;
	var $parse_response		= TRUE;
	var $exit_on_error		= FALSE;
	var $debug				= FALSE;
//	var $api_rest_url		= 'http://api.flickr.com/services/rest/';
	var $api_auth_url		= 'http://www.flickr.com/services/auth/'; // http://www.23hq.com/services/auth/
	var $api_xmlrpc_url		= 'http://api.flickr.com/services/xmlrpc/';
	var $api_upload_url		= 'http://api.flickr.com/services/upload/';
	var $api_replace_url	= 'http://api.flickr.com/services/replace/';
	
	var $error_code			= FALSE;
	var $error_message		= FALSE;
	var $response;
	var $parsed_response;
	var $CI;
	
	/**
	 * Constructor
	 *
	 * @access	public
	 * @param	array	initialization parameters
	 */
	function CI_Flickr_API($params = array())
	{
		// Set the super object to a local variable for use throughout the class
		$this->CI =& get_instance();
		
		if (count($params) > 0) $this->initialize($params);
		if (TRUE === $this->cache_use_db) $this->start_cache(TRUE);
		
		log_message('debug', 'Flickr_API Class Initialized');
	}
	
	// --------------------------------------------------------------------
	
	/**
	 * Initialize Preferences
	 *
	 * @access	public
	 * @param	array	initialization parameters
	 * @return	void
	 */
	function initialize($params = array())
	{
		if (count($params) > 0)
		{
			foreach ($params as $key => $val)
			{
				if (isset($this->$key))
				{
					$this->$key = $val;
				}
			}
		}
	}
	
	function set_debug($debug)
	{
		$this->debug = (bool) $debug;
	}
	
	function set_token($token)
	{
		$this->token = (string) $token;
	}
	
	function start_cache($run_cleanup = FALSE)
	{
		$this->cache_use_db = TRUE;
		$this->_create_table_cache();
		if (TRUE === $run_cleanup) $this->cleanup_cache();
	}
	
	function stop_cache()
	{
		$this->cache_use_db = FALSE;
	}
	
	function cleanup_cache()
	{
		if (TRUE === $this->cache_use_db AND $this->cache_table_name != '')
		{
			if ($this->CI->db->count_all($this->cache_table_name) > $this->cache_max_rows)
			{
				$this->CI->db->where('expire_date <', time() - $this->cache_expiration);
				$this->CI->db->delete($this->cache_table_name);
				
				$this->CI->load->dbutil();
				$this->CI->dbutil->optimize_table($this->cache_table_name);
			}
			return TRUE;
		}
		return FALSE;
	}
	
	function _create_table_cache()
	{
		if (TRUE === $this->cache_use_db AND $this->cache_table_name != '')
		{
			$this->CI->load->database();
			if (FALSE === $this->CI->db->table_exists($this->cache_table_name))
			{
				$fields['request'] = array('type' => 'CHAR', 'constraint' => '35', 'null' => FALSE);
				$fields['response'] = array('type' => 'MEDIUMTEXT', 'null' => FALSE);
				$fields['expire_date'] = array('type' => 'INT', 'constraint' => '10', 'unsigned' => TRUE, 'null' => FALSE, 'default' => '0');
				
				$this->CI->load->dbforge();
				$this->CI->dbforge->add_field($fields);
				$this->CI->dbforge->add_key('request', TRUE);
				$this->CI->dbforge->create_table($this->cache_table_name, TRUE);
				
				$this->CI->db->query('ALTER TABLE `' . $this->CI->db->dbprefix . $this->cache_table_name . '` ENGINE=InnoDB;');
			}
			return TRUE;
		}
		return FALSE;
	}
	
	function _get_cached($method, $params)
	{
		if (!empty($method) && is_array($params) && !empty($params))
		{
			if (TRUE === $this->cache_use_db AND $this->cache_table_name != '')
			{
				$this->CI->db->select('response');
				$this->CI->db->where('request', md5(serialize(array('method' => $method, 'params' => $params))));
				$this->CI->db->where('expire_date >=', time() - $this->cache_expiration);
				$query = $this->CI->db->get($this->cache_table_name);
				
				if ($query->num_rows() > 0)
				{
					$row = $query->result_array();
					return $row[0]['response'];
				}
			}
		}
		else
		{
			log_message('error', 'All parameters were not passed or correct.');
		}
		return FALSE;
	}
	
	function _cache($method, $params, $response)
	{
		if (!empty($method) && is_array($params) && !empty($params) && !empty($response))
		{
			if (TRUE === $this->cache_use_db AND $this->cache_table_name != '')
			{
				$request_hash = md5(serialize(array('method' => $method, 'params' => $params)));
				
				$this->CI->db->where('request', $request_hash);
				$query = $this->CI->db->get($this->cache_table_name);
				
				if ($query->num_rows() > 0)
				{
					$this->CI->db->set('response', $response);
					$this->CI->db->set('expire_date', time() + $this->cache_expiration);
					$this->CI->db->where('request', $request_hash);
					$this->CI->db->update($this->cache_table_name);
					
					if ($this->CI->db->affected_rows() == 1)
					{
						return TRUE;
					}
					else
					{
						log_message('error', 'Error updating ' . $this->cache_table_name . ' record!');
					}
				}
				else
				{
					$this->CI->db->set('request', $request_hash);
					$this->CI->db->set('response', $response);
					$this->CI->db->set('expire_date', time() + $this->cache_expiration);
					if (TRUE === $this->CI->db->insert($this->cache_table_name))
					{
						return TRUE;
					}
					else
					{
						log_message('error', 'Error creating ' . $this->cache_table_name . ' record!');
					}
				}
			}
		}
		else
		{
			log_message('error', 'All parameters were not passed or correct.');
		}
		return FALSE;
	}
	
	function _reset_error()
	{
		$this->error_code = FALSE;
		$this->error_message = FALSE;
	}
	
	function _error($error_code, $error_message, $exit_message)
	{
		if (TRUE === $this->debug) log_message('debug', sprintf($exit_message, $error_code, $error_message));
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
	
	function get_error_code()
	{
		return $this->error_code;
	}
	
	function get_error_message()
	{
		return $this->error_message;
	}
	
	function request($method, $params = array(), $nocache = FALSE)
	{
		if (!empty($this->api_key) && !empty($this->api_xmlrpc_url))
		{
			if (!empty($method) && is_array($params))
			{
				foreach ($params as $param => $value) if (is_null($value)) unset($params[$param]);
				
				$params = array_merge(array('api_key' => $this->api_key), $params);
				if (!empty($this->token)) $params = array_merge($params, array('auth_token' => $this->token));
				ksort($params);
				
				$this->_reset_error();
				$this->response = $this->_get_cached($method, $params);
				if (FALSE === $this->response || TRUE === $nocache)
				{
					$auth_sig = '';
					foreach ($params as $param => $value) $auth_sig .= $param . $value;
					
					if (!empty($this->secret))
					{
						$api_sig = md5($this->secret . $auth_sig);
						$params = array_merge($params, array('api_sig' => $api_sig));
					}
					
					$this->CI->load->library('xmlrpc');
					$this->CI->xmlrpc->server($this->api_xmlrpc_url);
					$this->CI->xmlrpc->method($method);
					$this->CI->xmlrpc->request(array(array($params, 'struct')));
					if ($this->CI->xmlrpc->send_request())
					{
						$this->response = $this->CI->xmlrpc->display_response();
						$this->_cache($method, $params, $this->response);
					}
					else
					{
						$this->_error($this->CI->xmlrpc->result->errno, $this->CI->xmlrpc->display_error(), 'There has been a problem sending your command to the server. Error #%s: "%s"');
						return FALSE;
					}
				}
				
				return TRUE === $this->parse_response ? $this->parsed_response = $this->parse_response($this->response) : $this->response;
			}
			else
			{
				$this->_error(TRUE, 'All parameters were not passed or correct.', '%2$s');
			}
		}
		else
		{
			$this->_error(TRUE, 'Required config(s) missing.', '%2$s');
		}
		return FALSE;
	}
	
	function parse_response($response)
	{
		if (!empty($response))
		{
			if (class_exists('SimpleXMLElement'))
			{
				return new SimpleXMLElement($response);
			}
			else
			{
				$this->_error(TRUE, 'SimpleXMLElement class does not exist.', '%2$s');
			}
		}
		else
		{
			$this->_error(TRUE, 'All parameters were not passed or correct.', '%2$s');
		}
		return FALSE;
	}
	
	function authenticate($permission = 'read', $redirect = NULL)
	{
		if (!empty($this->api_key) && !empty($this->secret) && !empty($this->api_auth_url))
		{
			$this->_reset_error();
			if (empty($this->token))
			{
				$this->CI->load->helper('url');
				$redirect = is_null($redirect) ? uri_string() : $redirect;
				$api_sig = md5($this->secret . 'api_key' . $this->api_key . 'extra' . $redirect . 'perms' . $permission);
				header('Location: ' . $this->api_auth_url . '?api_key=' . $this->api_key . '&extra=' . $redirect . '&perms=' . $permission . '&api_sig='. $api_sig);
				exit();
			}
			else
			{
				$exit_on_error = $this->exit_on_error;
				$this->exit_on_error = false;
				$response = $this->auth_checkToken();
				if (FALSE !== $this->get_error_code()) $this->auth($permission, $redirect);
				$this->exit_on_error = $exit_on_error;
				return $response['perms'];
			}
		}
		else
		{
			$this->_error(TRUE, 'Required config(s) missing.', '%2$s');
		}
		return FALSE;
	}
	
	function get_photo_url($photo, $size = 'original')
	{
		if (is_array($photo) && !empty($photo['id']) && !empty($photo['farm']) && !empty($photo['server']) && !empty($photo['secret']))
		{
			switch ($size)
			{
				case 'square':
					return 'http://farm' . $photo['farm'] . '.static.flickr.com/' . $photo['server'] . '/'. $photo['id'] . '_' . $photo['secret'] . '_s.jpg';
					break;
				case 'thumbnail':
					return 'http://farm' . $photo['farm'] . '.static.flickr.com/' . $photo['server'] . '/'. $photo['id'] . '_' . $photo['secret'] . '_t.jpg';
					break;
				case 'small':
					return 'http://farm' . $photo['farm'] . '.static.flickr.com/' . $photo['server'] . '/'. $photo['id'] . '_' . $photo['secret'] . '_m.jpg';
					break;
				case 'medium':
					return 'http://farm' . $photo['farm'] . '.static.flickr.com/' . $photo['server'] . '/'. $photo['id'] . '_' . $photo['secret'] . '.jpg';
					break;
				case 'large':
					return 'http://farm' . $photo['farm'] . '.static.flickr.com/' . $photo['server'] . '/'. $photo['id'] . '_' . $photo['secret'] . '_b.jpg';
					break;
				case 'original':
				default:
					if (array_key_exists('originalsecret', $photo) && array_key_exists('originalformat', $photo))
					{
						return 'http://farm' . $photo['farm'] . '.static.flickr.com/' . $photo['server'] . '/'. $photo['id'] . '_' . $photo['originalsecret'] . '_o.' . $photo['originalformat'];
					}
					else
					{
						return 'http://farm' . $photo['farm'] . '.static.flickr.com/' . $photo['server'] . '/'. $photo['id'] . '_' . $photo['secret'] . '_b.jpg';
					}
					break;
			}
		}
		else
		{
			$this->_error(TRUE, 'All parameters were not passed or correct.', '%2$s');
		}
		return FALSE;
	}
	
	function get_buddy_icon_url($nsid, $icon_farm, $icon_server, $return_default = TRUE)
	{
		if (!empty($nsid) && !empty($icon_farm))
		{
			if ($icon_server > 0)
			{
				return 'http://farm' . $icon_farm . '.static.flickr.com/' . $icon_server . '/buddyicons/' . $nsid . '.jpg';
			}
			elseif (TRUE === $return_default)
			{
				return 'http://www.flickr.com/images/buddyicon.jpg';
			}
		}
		else
		{
			$this->_error(TRUE, 'All parameters were not passed or correct.', '%2$s');
		}
		return FALSE;
	}



// --------------------------------------------------------------------------
// Functions need to be finished



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
// Method functions

	/* Activity methods */

	/* http://www.flickr.com/services/api/flickr.activity.userComments.html */
	function activity_userComments($per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.activity.userComments', array('per_page' => $per_page, 'page' => $page));
	}
	
	/* http://www.flickr.com/services/api/flickr.activity.userPhotos.html */
	function activity_userPhotos($timeframe = NULL, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.activity.userPhotos', array('timeframe' => $timeframe, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* Authentication methods */
	
	/* http://www.flickr.com/services/api/flickr.auth.checkToken.html */
	function auth_checkToken()
	{
		return $this->request('flickr.auth.checkToken');
	}
	
	/* http://www.flickr.com/services/api/flickr.auth.getFrob.html */
	function auth_getFrob()
	{
		return $this->request('flickr.auth.getFrob');
	}
	
	/* http://www.flickr.com/services/api/flickr.auth.getFullToken.html */
	function auth_getFullToken($mini_token)
	{
		return $this->request('flickr.auth.getFullToken', array('mini_token' => $mini_token));
	}
	
	/* http://www.flickr.com/services/api/flickr.auth.getToken.html */
	function auth_getToken($frob)
	{
		return $this->request('flickr.auth.getToken', array('frob' => $frob));
	}
	
	/* Blogs methods */
	/* http://www.flickr.com/services/api/flickr.blogs.getList.html */
	function blogs_getList()
	{
		return $this->request('flickr.blogs.getList');
	}
	
	/* http://www.flickr.com/services/api/flickr.blogs.postPhoto.html */
	function blogs_postPhoto($blog_id, $photo_id, $title, $description, $blog_password = NULL)
	{
		return $this->request('flickr.blogs.postPhoto', array('blog_id' => $blog_id, 'photo_id' => $photo_id, 'title' => $title, 'description' => $description, 'blog_password' => $blog_password), TRUE);
	}
	
	/* Contacts Methods */
	/* http://www.flickr.com/services/api/flickr.contacts.getList.html */
	function contacts_getList($filter = NULL, $page = NULL, $per_page = NULL)
	{
		return $this->request('flickr.contacts.getList', array('filter' => $filter, 'page' => $page, 'per_page' => $per_page));
	}
	
	/* http://www.flickr.com/services/api/flickr.contacts.getPublicList.html */
	function contacts_getPublicList($user_id, $page = NULL, $per_page = NULL)
	{
		return $this->request('flickr.contacts.getPublicList', array('user_id' => $user_id, 'page' => $page, 'per_page' => $per_page));
	}
	
	/* Favorites Methods */
	/* http://www.flickr.com/services/api/flickr.favorites.add.html */
	function favorites_add($photo_id)
	{
		return $this->request('flickr.favorites.add', array('photo_id' => $photo_id), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.favorites.getList.html */
	function favorites_getList($user_id = NULL, $min_fave_date = NULL, $max_fave_date = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.favorites.getList', array('user_id' => $user_id, 'min_fave_date' => $min_fave_date, 'max_fave_date' => $max_fave_date, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* http://www.flickr.com/services/api/flickr.favorites.getPublicList.html */
	function favorites_getPublicList($user_id, $min_fave_date = NULL, $max_fave_date = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.favorites.getPublicList', array('user_id' => $user_id, 'min_fave_date' => $min_fave_date, 'max_fave_date' => $max_fave_date, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* http://www.flickr.com/services/api/flickr.favorites.remove.html */
	function favorites_remove($photo_id)
	{
		return $this->request('flickr.favorites.remove', array('photo_id' => $photo_id), TRUE);
	}
	
	/* Groups Methods */
	/* http://www.flickr.com/services/api/flickr.groups.browse.html */
	function groups_browse($cat_id = NULL)
	{
		return $this->request('flickr.groups.browse', array('cat_id' => $cat_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.groups.getInfo.html */
	function groups_getInfo($group_id, $lang = NULL)
	{
		return $this->request('flickr.groups.getInfo', array('group_id' => $group_id, 'lang' => $lang));
	}
	
	/* http://www.flickr.com/services/api/flickr.groups.search.html */
	function groups_search($text, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.groups.search', array('text' => $text, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* Groups Pools Methods */
	/* http://www.flickr.com/services/api/flickr.groups.pools.add.html */
	function groups_pools_add($photo_id, $group_id)
	{
		return $this->request('flickr.groups.pools.add', array('photo_id' => $photo_id, 'group_id' => $group_id), TRUE);
	}
	
	/* http://www.flickr.com/services/api/flickr.groups.pools.getContext.html */
	function groups_pools_getContext($photo_id, $group_id)
	{
		return $this->request('flickr.groups.pools.getContext', array('photo_id' => $photo_id, 'group_id' => $group_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.groups.pools.getGroups.html */
	function groups_pools_getGroups($page = NULL, $per_page = NULL)
	{
		return $this->request('flickr.groups.pools.getGroups', array('page' => $page, 'per_page' => $per_page));
	}
	
	/* http://www.flickr.com/services/api/flickr.groups.pools.getPhotos.html */
	function groups_pools_getPhotos($group_id, $tags = NULL, $user_id = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		if (is_array($extras)) {
			$extras = implode(',', $extras);
		}
		return $this->request('flickr.groups.pools.getPhotos', array('group_id' => $group_id, 'tags' => $tags, 'user_id' => $user_id, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* http://www.flickr.com/services/api/flickr.groups.pools.remove.html */
	function groups_pools_remove($photo_id, $group_id)
	{
		return $this->request('flickr.groups.pools.remove', array('photo_id' => $photo_id, 'group_id' => $group_id), TRUE);
	}
	
	/* Interestingness methods */
	/* http://www.flickr.com/services/api/flickr.interestingness.getList.html */
	function interestingness_getList($date = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{
		if (is_array($extras)) {
			$extras = implode(',', $extras);
		}
		return $this->request('flickr.interestingness.getList', array('date' => $date, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* Machine Tag methods */
	/* http://www.flickr.com/services/api/flickr.machinetags.getNamespaces.html */
	function machinetags_getNamespaces($predicate = NULL, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.machinetags.getNamespaces', array('predicate' => $predicate, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* http://www.flickr.com/services/api/flickr.machinetags.getPairs.html */
	function machinetags_getPairs($namespace = NULL, $predicate = NULL, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.machinetags.getPairs', array('namespace' => $namespace, 'predicate' => $predicate, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* http://www.flickr.com/services/api/flickr.machinetags.getPredicates.html */
	function machinetags_getPredicates($namespace = NULL, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.machinetags.getPredicates', array('namespace' => $namespace, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* http://www.flickr.com/services/api/flickr.machinetags.getValues.html */
	function machinetags_getValues($namespace, $predicate, $per_page = NULL, $page = NULL)
	{
		return $this->request('flickr.machinetags.getValues', array('namespace' => $namespace, 'predicate' => $predicate, 'per_page' => $per_page, 'page' => $page));
	}
	
	/* People methods */
	/* http://www.flickr.com/services/api/flickr.people.findByEmail.html */
	function people_findByEmail($find_email)
	{
		return $this->request('flickr.people.findByEmail', array('find_email' => $find_email));
	}
	
	/* http://www.flickr.com/services/api/flickr.people.findByUsername.html */
	function people_findByUsername($username)
	{
		return $this->request('flickr.people.findByUsername', array('username' => $username));
	}
	
	/* http://www.flickr.com/services/api/flickr.people.getInfo.html */
	function people_getInfo($user_id)
	{
		return $this->request('flickr.people.getInfo', array('user_id' => $user_id));
	}
	
	/* http://www.flickr.com/services/api/flickr.people.getPublicGroups.html */
	function people_getPublicGroups($user_id)
	{
		return $this->request('flickr.people.getPublicGroups', array('user_id' => $user_id));
	}
	
	function people_getPublicPhotos($user_id, $safe_search = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{	/* http://www.flickr.com/services/api/flickr.people.getPublicPhotos.html */
		return $this->request('flickr.people.getPublicPhotos', array('user_id' => $user_id, 'safe_search' => $safe_search, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	function people_getUploadStatus()
	{	/* http://www.flickr.com/services/api/flickr.people.getUploadStatus.html */
		/* Requires Authentication */
		return $this->request('flickr.people.getUploadStatus');
	}
	
	
	/* Photos Methods */
	function photos_addTags($photo_id, $tags)
	{	/* http://www.flickr.com/services/api/flickr.photos.addTags.html */
		return $this->request('flickr.photos.addTags', array('photo_id' => $photo_id, 'tags' => $tags), TRUE);
	}
	
	function photos_delete($photo_id)
	{	/* http://www.flickr.com/services/api/flickr.photos.delete.html */
		return $this->request('flickr.photos.delete', array('photo_id' => $photo_id), TRUE);
	}
	
	function photos_getAllContexts($photo_id)
	{	/* http://www.flickr.com/services/api/flickr.photos.getAllContexts.html */
		return $this->request('flickr.photos.getAllContexts', array('photo_id' => $photo_id));
	}
	
	function photos_getContactsPhotos($count = NULL, $just_friends = NULL, $single_photo = NULL, $include_self = NULL, $extras = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.getContactsPhotos.html */
		return $this->request('flickr.photos.getContactsPhotos', array('count' => $count, 'just_friends' => $just_friends, 'single_photo' => $single_photo, 'include_self' => $include_self, 'extras' => $extras));
	}
	
	function photos_getContactsPublicPhotos($user_id, $count = NULL, $just_friends = NULL, $single_photo = NULL, $include_self = NULL, $extras = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.getContactsPublicPhotos.html */
		return $this->request('flickr.photos.getContactsPublicPhotos', array('user_id' => $user_id, 'count' => $count, 'just_friends' => $just_friends, 'single_photo' => $single_photo, 'include_self' => $include_self, 'extras' => $extras));
	}
	
	function photos_getContext($photo_id)
	{	/* http://www.flickr.com/services/api/flickr.photos.getContext.html */
		return $this->request('flickr.photos.getContext', array('photo_id' => $photo_id));
	}
	
	function photos_getCounts($dates = NULL, $taken_dates = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.getCounts.html */
		return $this->request('flickr.photos.getCounts', array('dates' => $dates, 'taken_dates' => $taken_dates));
	}
	
	function photos_getExif($photo_id, $secret = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.getExif.html */
		return $this->request('flickr.photos.getExif', array('photo_id' => $photo_id, 'secret' => $secret));
	}
	
	function photos_getFavorites($photo_id, $page = NULL, $per_page = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.getFavorites.html */
		return $this->request('flickr.photos.getFavorites', array('photo_id' => $photo_id, 'page' => $page, 'per_page' => $per_page));
	}
	
	function photos_getInfo($photo_id, $secret = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.getInfo.html */
		return $this->request('flickr.photos.getInfo', array('photo_id' => $photo_id, 'secret' => $secret));
	}
	
	function photos_getNotInSet($min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL, $privacy_filter = NULL, $media = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.getNotInSet.html */
		return $this->request('flickr.photos.getNotInSet', array('min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date, 'privacy_filter' => $privacy_filter, 'media' => $media, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	function photos_getPerms($photo_id)
	{	/* http://www.flickr.com/services/api/flickr.photos.getPerms.html */
		return $this->request('flickr.photos.getPerms', array('photo_id' => $photo_id));
	}
	
	function photos_getRecent($extras = NULL, $per_page = NULL, $page = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.getRecent.html */
		if (is_array($extras)) {
			$extras = implode(',', $extras);
		}
		return $this->request('flickr.photos.getRecent', array('extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	function photos_getSizes($photo_id)
	{	/* http://www.flickr.com/services/api/flickr.photos.getSizes.html */
		return $this->request('flickr.photos.getSizes', array('photo_id' => $photo_id));
	}
	
	function photos_getUntagged($min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL, $privacy_filter = NULL, $media = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.getUntagged.html */
		return $this->request('flickr.photos.getUntagged', array('min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date, 'privacy_filter' => $privacy_filter, 'media' => $media, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	function photos_getWithGeoData($args = array())
	{	/* See the documentation included with the photos_search() function.
		 * I'm using the same style of arguments for this function. The only
		 * difference here is that this doesn't require any arguments. The
		 * flickr.photos.search method requires at least one search parameter.
		 */
		/* http://www.flickr.com/services/api/flickr.photos.getWithGeoData.html */
		return $this->request('flickr.photos.getWithGeoData', $args);
	}
	
	function photos_getWithoutGeoData($args = array())
	{	/* See the documentation included with the photos_search() function.
		 * I'm using the same style of arguments for this function. The only
		 * difference here is that this doesn't require any arguments. The
		 * flickr.photos.search method requires at least one search parameter.
		 */
		/* http://www.flickr.com/services/api/flickr.photos.getWithoutGeoData.html */
		return $this->request('flickr.photos.getWithoutGeoData', $args);
	}
	
	function photos_recentlyUpdated($min_date, $extras = NULL, $per_page = NULL, $page = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.recentlyUpdated.html */
		return $this->request('flickr.photos.recentlyUpdated', array('min_date' => $min_date, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	function photos_removeTag($tag_id)
	{	/* http://www.flickr.com/services/api/flickr.photos.removeTag.html */
		return $this->request('flickr.photos.removeTag', array('tag_id' => $tag_id), TRUE);
	}
	
	function photos_search($args = array())
	{	/* This function strays from the method of arguments that I've
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
		return $this->request('flickr.photos.search', $args);
	}
	
	function photos_setContentType($photo_id, $content_type)
	{	/* http://www.flickr.com/services/api/flickr.photos.setContentType.html */
		return $this->request('flickr.photos.setContentType', array('photo_id' => $photo_id, 'content_type' => $content_type));
	}
	
	function photos_setDates($photo_id, $date_posted = NULL, $date_taken = NULL, $date_taken_granularity = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.setDates.html */
		return $this->request('flickr.photos.setDates', array('photo_id' => $photo_id, 'date_posted' => $date_posted, 'date_taken' => $date_taken, 'date_taken_granularity' => $date_taken_granularity), TRUE);
	}
	
	function photos_setMeta($photo_id, $title, $description)
	{	/* http://www.flickr.com/services/api/flickr.photos.setMeta.html */
		return $this->request('flickr.photos.setMeta', array('photo_id' => $photo_id, 'title' => $title, 'description' => $description), TRUE);
	}
	
	function photos_setPerms($photo_id, $is_public, $is_friend, $is_family, $perm_comment, $perm_addmeta)
	{	/* http://www.flickr.com/services/api/flickr.photos.setPerms.html */
		return $this->request('flickr.photos.setPerms', array('photo_id' => $photo_id, 'is_public' => $is_public, 'is_friend' => $is_friend, 'is_family' => $is_family, 'perm_comment' => $perm_comment, 'perm_addmeta' => $perm_addmeta), TRUE);
	}
	
	function photos_setSafetyLevel($photo_id, $safety_level = NULL, $hidden = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.setSafetyLevel.html */
		return $this->request('flickr.photos.setSafetyLevel', array('photo_id' => $photo_id, 'safety_level' => $safety_level, 'hidden' => $hidden));
	}
	
	function photos_setTags($photo_id, $tags)
	{	/* http://www.flickr.com/services/api/flickr.photos.setTags.html */
		return $this->request('flickr.photos.setTags', array('photo_id' => $photo_id, 'tags' => $tags), TRUE);
	}
	
	/* Photos - Comments Methods */
	function photos_comments_addComment($photo_id, $comment_text)
	{	/* http://www.flickr.com/services/api/flickr.photos.comments.addComment.html */
		return $this->request('flickr.photos.comments.addComment', array('photo_id' => $photo_id, 'comment_text' => $comment_text), TRUE);
	}
	
	function photos_comments_deleteComment($comment_id)
	{	/* http://www.flickr.com/services/api/flickr.photos.comments.deleteComment.html */
		return $this->request('flickr.photos.comments.deleteComment', array('comment_id' => $comment_id), TRUE);
	}
	
	function photos_comments_editComment($comment_id, $comment_text)
	{	/* http://www.flickr.com/services/api/flickr.photos.comments.editComment.html */
		return $this->request('flickr.photos.comments.editComment', array('comment_id' => $comment_id, 'comment_text' => $comment_text), TRUE);
	}
	
	function photos_comments_getList($photo_id, $min_comment_date = NULL, $max_comment_date = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.comments.getList.html */
		return $this->request('flickr.photos.comments.getList', array('photo_id' => $photo_id, 'min_comment_date' => $min_comment_date, 'max_comment_date' => $max_comment_date));
	}
	
	/* Photos - Geo Methods */
	function photos_geo_batchCorrectLocation($lat, $lon, $accuracy, $place_id = NULL, $woe_id = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.geo.batchCorrectLocation.html */
		return $this->request('flickr.photos.geo.batchCorrectLocation', array('lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy, 'place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	function photos_geo_correctLocation($photo_id, $place_id = NULL, $woe_id = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.geo.correctLocation.html */
		return $this->request('flickr.photos.geo.correctLocation', array('photo_id' => $photo_id, 'place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	function photos_geo_getLocation($photo_id)
	{	/* http://www.flickr.com/services/api/flickr.photos.geo.getLocation.html */
		return $this->request('flickr.photos.geo.getLocation', array('photo_id' => $photo_id));
	}
	
	function photos_geo_getPerms($photo_id)
	{	/* http://www.flickr.com/services/api/flickr.photos.geo.getPerms.html */
		return $this->request('flickr.photos.geo.getPerms', array('photo_id' => $photo_id));
	}
	
	function photos_geo_photosForLocation($lat, $lon, $accuracy = NULL, $extras = NULL, $per_page = NULL, $page = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.geo.photosForLocation.html */
		return $this->request('flickr.photos.geo.photosForLocation', array('lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy, 'extras' => $extras, 'per_page' => $per_page, 'page' => $page));
	}
	
	function photos_geo_removeLocation($photo_id)
	{	/* http://www.flickr.com/services/api/flickr.photos.geo.removeLocation.html */
		return $this->request('flickr.photos.geo.removeLocation', array('photo_id' => $photo_id), TRUE);
	}
	
	function photos_geo_setContext($photo_id, $context)
	{	/* http://www.flickr.com/services/api/flickr.photos.geo.setContext.html */
		return $this->request('flickr.photos.geo.setContext', array('photo_id' => $photo_id, 'context' => $context));
	}
	
	function photos_geo_setLocation($photo_id, $lat, $lon, $accuracy = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photos.geo.setLocation.html */
		return $this->request('flickr.photos.geo.setLocation', array('photo_id' => $photo_id, 'lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy), TRUE);
	}
	
	function photos_geo_setPerms($is_public, $is_contact, $is_friend, $is_family, $photo_id)
	{	/* http://www.flickr.com/services/api/flickr.photos.geo.setPerms.html */
		return $this->request('flickr.photos.geo.setPerms', array('is_public' => $is_public, 'is_contact' => $is_contact, 'is_friend' => $is_friend, 'is_family' => $is_family, 'photo_id' => $photo_id));
	}
	
	/* Photos - Licenses Methods */
	function photos_licenses_getInfo()
	{	/* http://www.flickr.com/services/api/flickr.photos.licenses.getInfo.html */
		return $this->request('flickr.photos.licenses.getInfo');
	}
	
	function photos_licenses_setLicense($photo_id, $license_id)
	{	/* http://www.flickr.com/services/api/flickr.photos.licenses.setLicense.html */
		/* Requires Authentication */
		return $this->request('flickr.photos.licenses.setLicense', array('photo_id' => $photo_id, 'license_id' => $license_id), TRUE);
	}
	
	/* Photos - Notes Methods */
	function photos_notes_add($photo_id, $note_x, $note_y, $note_w, $note_h, $note_text)
	{	/* http://www.flickr.com/services/api/flickr.photos.notes.add.html */
		return $this->request('flickr.photos.notes.add', array('photo_id' => $photo_id, 'note_x' => $note_x, 'note_y' => $note_y, 'note_w' => $note_w, 'note_h' => $note_h, 'note_text' => $note_text), TRUE);
	}
	
	function photos_notes_delete($note_id)
	{	/* http://www.flickr.com/services/api/flickr.photos.notes.delete.html */
		return $this->request('flickr.photos.notes.delete', array('note_id' => $note_id), TRUE);
	}
	
	function photos_notes_edit($note_id, $note_x, $note_y, $note_w, $note_h, $note_text)
	{	/* http://www.flickr.com/services/api/flickr.photos.notes.edit.html */
		return $this->request('flickr.photos.notes.edit', array('note_id' => $note_id, 'note_x' => $note_x, 'note_y' => $note_y, 'note_w' => $note_w, 'note_h' => $note_h, 'note_text' => $note_text), TRUE);
	}
	
	/* Photos - Transform Methods */
	function photos_transform_rotate($photo_id, $degrees)
	{	/* http://www.flickr.com/services/api/flickr.photos.transform.rotate.html */
		return $this->request('flickr.photos.transform.rotate', array('photo_id' => $photo_id, 'degrees' => $degrees), TRUE);
	}
	
	/* Photos - Upload Methods */
	function photos_upload_checkTickets($tickets)
	{	/* http://www.flickr.com/services/api/flickr.photos.upload.checkTickets.html */
		if (is_array($tickets)) {
			$tickets = implode(',', $tickets);
		}
		return $this->request('flickr.photos.upload.checkTickets', array('tickets' => $tickets), TRUE);
	}
	
	/* Photosets Methods */
	function photosets_addPhoto($photoset_id, $photo_id)
	{	/* http://www.flickr.com/services/api/flickr.photosets.addPhoto.html */
		return $this->request('flickr.photosets.addPhoto', array('photoset_id' => $photoset_id, 'photo_id' => $photo_id), TRUE);
	}
	
	function photosets_create($title, $description, $primary_photo_id)
	{	/* http://www.flickr.com/services/api/flickr.photosets.create.html */
		return $this->request('flickr.photosets.create', array('title' => $title, 'primary_photo_id' => $primary_photo_id, 'description' => $description), TRUE);
	}
	
	function photosets_delete($photoset_id)
	{	/* http://www.flickr.com/services/api/flickr.photosets.delete.html */
		return $this->request('flickr.photosets.delete', array('photoset_id' => $photoset_id), TRUE);
	}
	
	function photosets_editMeta($photoset_id, $title, $description = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photosets.editMeta.html */
		return $this->request('flickr.photosets.editMeta', array('photoset_id' => $photoset_id, 'title' => $title, 'description' => $description), TRUE);
	}
	
	function photosets_editPhotos($photoset_id, $primary_photo_id, $photo_ids)
	{	/* http://www.flickr.com/services/api/flickr.photosets.editPhotos.html */
		return $this->request('flickr.photosets.editPhotos', array('photoset_id' => $photoset_id, 'primary_photo_id' => $primary_photo_id, 'photo_ids' => $photo_ids), TRUE);
	}
	
	function photosets_getContext($photo_id, $photoset_id)
	{	/* http://www.flickr.com/services/api/flickr.photosets.getContext.html */
		return $this->request('flickr.photosets.getContext', array('photo_id' => $photo_id, 'photoset_id' => $photoset_id));
	}
	
	function photosets_getInfo($photoset_id)
	{	/* http://www.flickr.com/services/api/flickr.photosets.getInfo.html */
		return $this->request('flickr.photosets.getInfo', array('photoset_id' => $photoset_id));
	}
	
	function photosets_getList($user_id = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photosets.getList.html */
		return $this->request('flickr.photosets.getList', array('user_id' => $user_id));
	}
	
	function photosets_getPhotos($photoset_id, $extras = NULL, $privacy_filter = NULL, $per_page = NULL, $page = NULL, $media = NULL)
	{	/* http://www.flickr.com/services/api/flickr.photosets.getPhotos.html */
		return $this->request('flickr.photosets.getPhotos', array('photoset_id' => $photoset_id, 'extras' => $extras, 'privacy_filter' => $privacy_filter, 'per_page' => $per_page, 'page' => $page, 'media' => $media));
	}
	
	function photosets_orderSets($photoset_ids)
	{	/* http://www.flickr.com/services/api/flickr.photosets.orderSets.html */
		if (is_array($photoset_ids)) {
			$photoset_ids = implode(',', $photoset_ids);
		}
		return $this->request('flickr.photosets.orderSets', array('photoset_ids' => $photoset_ids), TRUE);
	}
	
	function photosets_removePhoto($photoset_id, $photo_id)
	{	/* http://www.flickr.com/services/api/flickr.photosets.removePhoto.html */
		return $this->request('flickr.photosets.removePhoto', array('photoset_id' => $photoset_id, 'photo_id' => $photo_id), TRUE);
	}
	
	/* Photosets Comments Methods */
	function photosets_comments_addComment($photoset_id, $comment_text)
	{	/* http://www.flickr.com/services/api/flickr.photosets.comments.addComment.html */
		return $this->request('flickr.photosets.comments.addComment', array('photoset_id' => $photoset_id, 'comment_text' => $comment_text), TRUE);
	}
	
	function photosets_comments_deleteComment($comment_id)
	{	/* http://www.flickr.com/services/api/flickr.photosets.comments.deleteComment.html */
		return $this->request('flickr.photosets.comments.deleteComment', array('comment_id' => $comment_id), TRUE);
	}
	
	function photosets_comments_editComment($comment_id, $comment_text)
	{	/* http://www.flickr.com/services/api/flickr.photosets.comments.editComment.html */
		return $this->request('flickr.photosets.comments.editComment', array('comment_id' => $comment_id, 'comment_text' => $comment_text), TRUE);
	}
	
	function photosets_comments_getList($photoset_id)
	{	/* http://www.flickr.com/services/api/flickr.photosets.comments.getList.html */
		return $this->request('flickr.photosets.comments.getList', array('photoset_id' => $photoset_id));
	}
	
	/* Places Methods */
	function places_find($query)
	{	/* http://www.flickr.com/services/api/flickr.places.find.html */
		return $this->request('flickr.places.find', array('query' => $query));
	}
	
	function places_findByLatLon($lat, $lon, $accuracy = NULL)
	{	/* http://www.flickr.com/services/api/flickr.places.findByLatLon.html */
		return $this->request('flickr.places.findByLatLon', array('lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy));
	}
	
	function places_getChildrenWithPhotosPublic($place_id = NULL, $woe_id = NULL)
	{	/* http://www.flickr.com/services/api/flickr.places.getChildrenWithPhotosPublic.html */
		return $this->request('flickr.places.getChildrenWithPhotosPublic', array('place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	function places_getInfo($place_id = NULL, $woe_id = NULL)
	{	/* http://www.flickr.com/services/api/flickr.places.getInfo.html */
		return $this->request('flickr.places.getInfo', array('place_id' => $place_id, 'woe_id' => $woe_id));
	}
	
	function places_getInfoByUrl($url)
	{	/* http://www.flickr.com/services/api/flickr.places.getInfoByUrl.html */
		return $this->request('flickr.places.getInfoByUrl', array('url' => $url));
	}
	
	function places_getPlaceTypes()
	{	/* http://www.flickr.com/services/api/flickr.places.getPlaceTypes.html */
		return $this->request('flickr.places.getPlaceTypes', array());
	}
	
	function places_placesForBoundingBox($bbox, $place_type = NULL, $place_type_id = NULL)
	{	/* http://www.flickr.com/services/api/flickr.places.placesForBoundingBox.html */
		return $this->request('flickr.places.placesForBoundingBox', array('bbox' => $bbox, 'place_type' => $place_type, 'place_type_id' => $place_type_id));
	}
	
	function places_placesForContacts($place_type = NULL, $place_type_id = NULL, $woe_id = NULL, $place_id = NULL, $threshold = NULL, $contacts = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL)
	{	/* http://www.flickr.com/services/api/flickr.places.placesForContacts.html */
		return $this->request('flickr.places.placesForContacts', array('place_type' => $place_type, 'place_type_id' => $place_type_id, 'woe_id' => $woe_id, 'place_id' => $place_id, 'threshold' => $threshold, 'contacts' => $contacts, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
	}
	
	function places_placesForTags($place_type_id, $woe_id = NULL, $place_id = NULL, $threshold = NULL, $tags = NULL, $tag_mode = NULL, $machine_tags = NULL, $machine_tag_mode = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL)
	{	/* http://www.flickr.com/services/api/flickr.places.placesForTags.html */
		return $this->request('flickr.places.placesForTags', array('place_type_id' => $place_type_id, 'woe_id' => $woe_id, 'place_id' => $place_id, 'threshold' => $threshold, 'tags' => $tags, 'tag_mode' => $tag_mode, 'machine_tags' => $machine_tags, 'machine_tag_mode' => $machine_tag_mode, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
	}
	
	function places_placesForUser($place_type_id = NULL, $place_type = NULL, $woe_id = NULL, $place_id = NULL, $threshold = NULL, $min_upload_date = NULL, $max_upload_date = NULL, $min_taken_date = NULL, $max_taken_date = NULL)
	{	/* http://www.flickr.com/services/api/flickr.places.placesForUser.html */
		return $this->request('flickr.places.placesForUser', array('place_type_id' => $place_type_id, 'place_type' => $place_type, 'woe_id' => $woe_id, 'place_id' => $place_id, 'threshold' => $threshold, 'min_upload_date' => $min_upload_date, 'max_upload_date' => $max_upload_date, 'min_taken_date' => $min_taken_date, 'max_taken_date' => $max_taken_date));
	}
	
	function places_resolvePlaceId($place_id)
	{	/* http://www.flickr.com/services/api/flickr.places.resolvePlaceId.html */
		return $this->request('flickr.places.resolvePlaceId', array('place_id' => $place_id));
	}
	
	function places_resolvePlaceURL($url)
	{	/* http://www.flickr.com/services/api/flickr.places.resolvePlaceURL.html */
		return $this->request('flickr.places.resolvePlaceURL', array('url' => $url));
	}
	
	/* Prefs Methods */
	function prefs_getContentType()
	{	/* http://www.flickr.com/services/api/flickr.prefs.getContentType.html */
		return $this->request('flickr.prefs.getContentType');
	}
	
	function prefs_getGeoPerms()
	{	/* http://www.flickr.com/services/api/flickr.prefs.getGeoPerms.html */
		return $this->request('flickr.prefs.getGeoPerms');
	}
	
	function prefs_getHidden()
	{	/* http://www.flickr.com/services/api/flickr.prefs.getHidden.html */
		return $this->request('flickr.prefs.getHidden');
	}
	
	function prefs_getPrivacy()
	{	/* http://www.flickr.com/services/api/flickr.prefs.getPrivacy.html */
		return $this->request('flickr.prefs.getPrivacy');
	}
	
	function prefs_getSafetyLevel()
	{	/* http://www.flickr.com/services/api/flickr.prefs.getSafetyLevel.html */
		return $this->request('flickr.prefs.getSafetyLevel');
	}
	
	/* Reflection Methods */
	function reflection_getMethodInfo($method_name)
	{	/* http://www.flickr.com/services/api/flickr.reflection.getMethodInfo.html */
		return $this->request('flickr.reflection.getMethodInfo', array('method_name' => $method_name));
	}
	
	function reflection_getMethods()
	{	/* http://www.flickr.com/services/api/flickr.reflection.getMethods.html */
		return $this->request('flickr.reflection.getMethods');
	}
	
	/* Tags Methods */
	function tags_getClusterPhotos($tag, $cluster_id)
	{	/* http://www.flickr.com/services/api/flickr.tags.getClusterPhotos.html */
		return $this->request('flickr.tags.getClusterPhotos', array('tag' => $tag, 'cluster_id' => $cluster_id));
	}
	
	function tags_getClusters($tag)
	{	/* http://www.flickr.com/services/api/flickr.tags.getClusters.html */
		return $this->request('flickr.tags.getClusters', array('tag' => $tag));
	}
	
	function tags_getHotList($period = NULL, $count = NULL)
	{	/* http://www.flickr.com/services/api/flickr.tags.getHotList.html */
		return $this->request('flickr.tags.getHotList', array('period' => $period, 'count' => $count));
	}
	
	function tags_getListPhoto($photo_id)
	{	/* http://www.flickr.com/services/api/flickr.tags.getListPhoto.html */
		return $this->request('flickr.tags.getListPhoto', array('photo_id' => $photo_id));
	}
	
	function tags_getListUser($user_id = NULL)
	{	/* http://www.flickr.com/services/api/flickr.tags.getListUser.html */
		return $this->request('flickr.tags.getListUser', array('user_id' => $user_id));
	}
	
	function tags_getListUserPopular($user_id = NULL, $count = NULL)
	{	/* http://www.flickr.com/services/api/flickr.tags.getListUserPopular.html */
		return $this->request('flickr.tags.getListUserPopular', array('user_id' => $user_id, 'count' => $count));
	}
	
	function tags_getListUserRaw($tag = NULL)
	{	/* http://www.flickr.com/services/api/flickr.tags.getListUserRaw.html */
		return $this->request('flickr.tags.getListUserRaw', array('tag' => $tag));
	}
	
	function tags_getRelated($tag)
	{	/* http://www.flickr.com/services/api/flickr.tags.getRelated.html */
		return $this->request('flickr.tags.getRelated', array('tag' => $tag));
	}
	
	function test_echo($args = array())
	{	/* http://www.flickr.com/services/api/flickr.test.echo.html */
		return $this->request('flickr.test.echo', $args);
	}
	
	function test_login()
	{	/* http://www.flickr.com/services/api/flickr.test.login.html */
		return $this->request('flickr.test.login');
	}
	
	function urls_getGroup($group_id)
	{	/* http://www.flickr.com/services/api/flickr.urls.getGroup.html */
		return $this->request('flickr.urls.getGroup', array('group_id' => $group_id));
	}
	
	function urls_getUserPhotos($user_id = NULL)
	{	/* http://www.flickr.com/services/api/flickr.urls.getUserPhotos.html */
		return $this->request('flickr.urls.getUserPhotos', array('user_id' => $user_id));
	}
	
	function urls_getUserProfile($user_id = NULL)
	{	/* http://www.flickr.com/services/api/flickr.urls.getUserProfile.html */
		return $this->request('flickr.urls.getUserProfile', array('user_id' => $user_id));
	}
	
	function urls_lookupGroup($url)
	{	/* http://www.flickr.com/services/api/flickr.urls.lookupGroup.html */
		return $this->request('flickr.urls.lookupGroup', array('url' => $url));
	}
	
	function urls_lookupUser($url)
	{	/* http://www.flickr.com/services/api/flickr.photos.notes.edit.html */
		return $this->request('flickr.urls.lookupUser', array('url' => $url));
	}



}

/* End of file Flickr_API.php */
/* Location: ./app/libraries/CI_Flickr_API/Flickr_API.php */