# CodeIgniter Flickr API

A CodeIgniter library which gives access to make calls to Flickr's API.

## Requirements

1. PHP 5.1+
2. CodeIgniter 2.0.0+

## Usage

	// Load the ci_flickr_api spark
	$this->load->spark('ci_flickr_api/0.4.0');
	
	// Create config array
	$flickr_api_config = array(
								'request_format'	=> CI_Flickr_API::REQUEST_FORMAT_REST,
								'response_format'	=> CI_Flickr_API::RESPONSE_FORMAT_PHP_SERIAL,
								'api_key'			=> 'APIKEY',
								'secret'			=> 'SECRET',
								'cache_use_db'		=> TRUE,
								'cache_expiration'	=> 600,
								'cache_max_rows'	=> 1000,
							);
	
	// Initialize library with config
	$this->flickr_api->initialize($flickr_api_config);
	
	// Send authentication request for user account access
	$this->flickr_api->authenticate('read');
	
	// Get frob from call back from Flickr
	$this->flickr_api->auth_getToken($_GET['frob']);
	
	// Search for some photos
	$photos = $this->flickr_api->photos_search();

For more details and functions, please review the library.