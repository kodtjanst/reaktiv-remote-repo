<?php

// uncomment this line for testing
set_site_transient( 'update_plugins', null );


/**
 * Allows plugins to use our own repo
 *
 * @author Andrew Norcross
 * @version 0.0.1
 */
class RKV_Remote_Updater {

	private $api_url  = '';
	private $api_data = array();
	private $name     = '';
	private $slug     = '';

	/**
	 * Class constructor.
	 *
	 * @uses plugin_basename()
	 * @uses hook()
	 *
	 * @param string $_api_url The URL pointing to the custom API endpoint.
	 * @param string $_plugin_file Path to the plugin file.
	 * @param array $_api_data Optional data to send with API calls.
	 * @return void
	 */

	function __construct( $_api_url, $_plugin_file, $_api_data = null ) {

		$this->api_url  = trailingslashit( $_api_url );
		$this->api_data = urlencode_deep( $_api_data );
		$this->name     = plugin_basename( $_plugin_file );
		$this->slug     = basename( $_plugin_file, '.php');
		$this->version  = $_api_data['version'];

		// Set up hooks.
		$this->hook();

	}

	/**
	 * Set up Wordpress filters to hook into WP's update process.
	 *
	 * @uses add_filter()
	 *
	 * @return void
	 */

	private function hook() {

		add_filter	(	'http_request_args',						array(	$this,	'disable_wporg'		),	5,	2	);

		add_filter	(	'pre_set_site_transient_update_plugins',	array(	$this,	'api_check'			)			);
		add_filter	(	'plugins_api',								array(	$this,	'api_data'			),	10,	3	);
	}

	/**
	 * run transient check
	 * @param  [type] $transient [description]
	 * @return [type]            [description]
	 */

	public function api_check( $_transient_data ) {

		if( empty( $_transient_data ) )
			return $_transient_data;

		$to_send = array( 'slug' => $this->slug );

		$api_response = $this->api_request( 'plugin_latest_version', $to_send );

		if( false !== $api_response && is_object( $api_response ) ) {
			if( version_compare( $this->version, $api_response->new_version, '<' ) )
				$_transient_data->response[$this->name] = $api_response;
		}

		return $_transient_data;

	}

	/**
	 * Disable request to wp.org plugin repository
	 * this function is to remove update request data of this plugin to wp.org
	 * so wordpress would not do update check for this plugin.
	 *
	 * @link http://markjaquith.wordpress.com/2009/12/14/excluding-your-plugin-or-theme-from-update-checks/
	 * @since 0.1.2
	 */
	public function disable_wporg( $r, $url ){

		/* WP.org plugin update check URL */
		$wp_url_string = 'api.wordpress.org/plugins/update-check';

		/* If it's not a plugin update check request, bail early */
		if ( false === strpos( $url, $wp_url_string ) ){
			return $r;
		}

		/* Get this plugin slug */
		$plugin_slug = dirname( $this->slug );

		/* Get response body (json/serialize data) */
		$r_body = wp_remote_retrieve_body( $r );

		/* Get plugins request */
		$r_plugins = '';
		$r_plugins_json = false;
		if( isset( $r_body['plugins'] ) ){

			/* Check if data can be serialized */
			if ( is_serialized( $r_body['plugins'] ) ){

				/* unserialize data ( PRE WP 3.7 ) */
				$r_plugins = @unserialize( $r_body['plugins'] );
				$r_plugins = (array) $r_plugins; // convert object to array
			}

			/* if unserialize didn't work ( POST WP.3.7 using json ) */
			else{
				/* use json decode to make body request to array */
				$r_plugins = json_decode( $r_body['plugins'], true );
				$r_plugins_json = true;
			}
		}

		/* this plugin */
		$to_disable = '';

		/* check if plugins request is not empty */
		if  ( !empty( $r_plugins ) ){

			/* All plugins */
			$all_plugins = $r_plugins['plugins'];

			/* Loop all plugins */
			foreach ( $all_plugins as $plugin_base => $plugin_data ){

				/* Only if the plugin have the same folder, because plugins can have different main file. */
				if ( dirname( $plugin_base ) == $plugin_slug ){

					/* get plugin to disable */
					$to_disable = $plugin_base;
				}
			}

			/* Unset this plugin only */
			if ( !empty( $to_disable ) ){
				unset(  $all_plugins[ $to_disable ] );
			}

			/* Merge plugins request back to request */
			if ( true === $r_plugins_json ){ // json encode data
				$r_plugins['plugins'] = $all_plugins;
				$r['body']['plugins'] = json_encode( $r_plugins );
			}
			else{ // serialize data
				$r_plugins['plugins'] = $all_plugins;
				$r_plugins_object = (object) $r_plugins;
				$r['body']['plugins'] = serialize( $r_plugins_object );
			}
		}

		/* return the request */
		return $r;
	}

	/**
	 * Updates information on the "View version x.x details" page with custom data.
	 *
	 * @uses api_request()
	 *
	 * @param mixed $_data
	 * @param string $_action
	 * @param object $_args
	 * @return object $_data
	 */
	function api_data( $_data, $_action = '', $_args = null ) {

		if ( ( $_action != 'plugin_information' ) || !isset( $_args->slug ) || ( $_args->slug != $this->slug ) )
			return $_data;

		$to_send = array( 'slug' => $this->slug );

		$api_response = $this->api_request( 'plugin_information', $to_send );

		if ( false !== $api_response )
			$_data = $api_response;

		return $_data;
	}

	/**
	 * Calls the API and, if successfull, returns the object delivered by the API.
	 *
	 * @uses get_bloginfo()
	 * @uses wp_remote_post()
	 * @uses is_wp_error()
	 *
	 * @param string $_action The requested action.
	 * @param array $_data Parameters for the API action.
	 * @return false||object
	 */
	private function api_request( $_action, $_data ) {

		global $wp_version;

		$data = array_merge( $this->api_data, $_data );
		if( $data['slug'] != $this->slug )
			return;

		$api_args = array(
			'method'	=> 'POST',
			'timeout'	=> 15,
			'sslverify' => false,
			'body'		=> array(
				'action'		=> $_action,
				'key'			=> $data['key'],
				'product'		=> $data['product'],
				'version'		=> $data['version'],
				'slug' 			=> $this->slug,
			),
		);

		$request = wp_remote_post( $this->api_url, $api_args );

		if ( ! is_wp_error( $request ) ):

			$response = json_decode( wp_remote_retrieve_body( $request ) );

			if ( is_object( $response ) && !empty( $response ) ) :

				// Create response data object
				$updates = new stdClass;
				$updates->new_version	= $response->new_version;
				$updates->package		= $response->package;
				$updates->slug			= $this->slug;
				$updates->url			= $this->api_url;


				return $updates;

			endif;

		else:

			return false;

		endif;

	}

}