<?php

/**
 * Class GFPSW_Updater
 *
 * Plugin Update API
 *
 * @since 0.1.0
 */
class GFPSW_Updater {

	/**
	 * URL to send update requests
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $api_url = '';

	/**
	 * Unique software ID
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $slug = '';

	/**
	 * Directory and main file name
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $path = '';

	/**
	 * Software version
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $version = '';

	/**
	 * Any additional data like license key
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $data = '';


	/**
	 * Constructor
	 *
	 * @since 0.1.0
	 *
	 * @param string $api_url
	 * @param string $slug         e.g. gfp-example-plugin
	 * @param string $path         e.g. gfp-example-plugin/example-plugin.php
	 * @param array  $data         {
	 *
	 * @type string  $version      Required.
	 * @type string  $license_key  Required.
	 * @type bool    $early_access Optional. Check for early access versions
	 *        }
	 */
	public function __construct ( $api_url, $slug, $path, $data ) {

		$this->api_url = trailingslashit( $api_url );
		$this->slug    = $slug;
		$this->path    = $path;
		$this->version = $data['version'];
		$this->data    = $data;
		$this->data['license_key'] = md5( $this->data['license_key'] );
		if ( empty( $data['early_access'] ) ) {
			$this->data['early_access'] = false;
		}

		add_action( 'init', array( $this, 'init' ) );
	}

	public function init () {

		if ( is_admin() ) {

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'pre_set_site_transient_update_plugins' ) );
			add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );

			if ( basename( $_SERVER['PHP_SELF'] ) == 'plugins.php' ) {
				add_action( 'after_plugin_row_' . $this->path, array( $this, 'after_plugin_row' ) );
			}

		}
	}

	function pre_set_site_transient_update_plugins ( $update_plugins_option ) {
		if ( empty( $update_plugins_option->checked ) ) {
			return $update_plugins_option;
		}

		return $this->check_update( $this->path, $this->slug, $this->api_url, $this->slug, $this->data['license_key'], $this->version, $this->data['early_access'], $update_plugins_option );

	}

	public function plugins_api ( $result, $action, $args ) {
		if ( ( 'plugin_information' != $action ) || ( $args->slug != $this->slug ) ) {
			return $result;
		}

		return $this->get_version_details( $this->slug, $this->data['license_key'], $this->version, $this->data['early_access'] );
	}

	public function after_plugin_row () {
		$version_info = $this->get_version_info( $this->slug, $this->data['license_key'], $this->version, $this->data['early_access'] );

		if ( ! $version_info['is_valid_key'] ) {
			$new_version = '';
			$message     = $new_version . __( 'Register your license key to receive automatic updates.', '' ) . '</div></td>';
			$this->display_plugin_message( $message );
		}
	}


	/**
	 * Check for a plugin update
	 *
	 * @since 0.1.0
	 *
	 * @param $plugin_path
	 * @param $plugin_slug
	 * @param $plugin_url
	 * @param $product
	 * @param $key
	 * @param $version
	 * @param $option
	 *
	 * @return mixed
	 */
	public function check_update ( $plugin_path, $plugin_slug, $plugin_url, $product, $key, $version, $early_access, $option ) {

		$version_info = $this->get_version_info( $product, $key, $version, $early_access, false );
		$this->set_version_info( $version_info );


		if ( $version_info == false ) {
			return $option;
		}

		if ( empty( $option->response[$plugin_path] ) ) {
			$option->response[$plugin_path] = new stdClass();
		}

		//Empty response means that the key is invalid. Do not queue for upgrade
		if ( ! $version_info['is_valid_key'] || version_compare( $version, $version_info['version'], '>=' ) ) {
			unset( $option->response[$plugin_path] );
		}
		else {
			$option->response[$plugin_path]->url         = $plugin_url;
			$option->response[$plugin_path]->slug        = $plugin_slug;
			$option->response[$plugin_path]->package     = str_replace( "{KEY}", $key, $version_info['package'] );
			$option->response[$plugin_path]->new_version = $version_info['version'];
			$option->response[$plugin_path]->id          = '0';
		}

		return $option;

	}


	/**
	 * Displays current version details on Plugin's page
	 *
	 * @since 0.1.0
	 *
	 * @param $product
	 * @param $key
	 * @param $version
	 */
	public function get_version_details ( $product, $key, $version, $early_access ) {

		$version_info = $this->get_version_info( $product, $key, $version, $early_access, false );
		if ( ( $version_info == false ) || ( ! array_key_exists( 'version_details', $version_info ) || ( empty( $version_info['version_details'] ) ) ) ) {
			return WP_Error( 'no_version_info', __( 'An unexpected error occurred. Unable to find version details for this plugin. Please contact support.' ) );
		}

		$response = new stdClass();

		$response->name          = $version_info['version_details']['name'];
		$response->slug          = $version_info['version_details']['slug'];
		$response->version       = $version_info['version'];
		$response->download_link = str_replace( "{KEY}", $key, $version_info['package'] );
		$response->author        = $version_info['version_details']['author'];
		$response->requires      = $version_info['version_details']['requires'];
		$response->tested        = $version_info['version_details']['tested'];
		$response->last_updated  = $version_info['version_details']['last_updated'];
		$response->homepage      = $version_info['version_details']['homepage'];
		$response->sections      = $version_info['version_details']['sections'];

		return $response;

	}


	/**
	 * Get version info from API
	 *
	 * @since 0.1.0
	 *
	 * @param      $product
	 * @param      $key
	 * @param      $version
	 * @param bool $use_cache
	 *
	 * @return array|int|mixed
	 */
	public function get_version_info ( $product, $key, $version, $early_access, $use_cache = true ) {

		$version_info = function_exists( 'get_site_transient' ) ? get_site_transient( "{$this->slug}_version" ) : get_transient( "{$this->slug}_version" );
		if ( ! $version_info || ! $use_cache || ( false == $version_info ) ) {
			if ( ( empty( $key ) ) || ( home_url() == $this->api_url ) ) {
				return false;
			}
			$body               = array( 'key'  => $key,
										 'data' => $this->data );
			$options            = array( 'timeout'   => 3,
										 'sslverify' => false,
										 'body'      => $body
			);
			$options['headers'] = array(
				'Content-Type'   => 'application/x-www-form-urlencoded; charset=' . get_option( 'blog_charset' ),
				'User-Agent'     => 'WordPress/' . get_bloginfo( 'version' ),
				'Referer'        => get_bloginfo( 'url' )
			);
			$url                = $this->api_url . $this->get_remote_request_params( 'get_version', $product, $key, $version, $early_access );
			$raw_response       = wp_remote_post( $url, $options );

			if ( is_wp_error( $raw_response ) || ( 200 != wp_remote_retrieve_response_code( $raw_response ) ) ) {
				$version_info = false;
			}
			else {
				$response     = json_decode( wp_remote_retrieve_body( $raw_response ), true );
				$version_info = array(
					'is_valid_key'    => $response['is_valid_key'],
					'version'         => $response['version'],
					'package'         => urldecode( $response['package'] ),
					'version_details' => $response['version_details'],
				);
			}
			$this->set_version_info( $version_info );
		}

		return $version_info;
	}

	/**
	 * Cache version info
	 *
	 * @since 0.1.0
	 *
	 * @param $version_info
	 */
	public function set_version_info ( $version_info ) {
		if ( function_exists( 'set_site_transient' ) ) {
			set_site_transient( "{$this->slug}_version", $version_info, 60 * 60 * 12 );
		}
		else {
			set_transient( "{$this->slug}_version", $version_info, 60 * 60 * 12 );
		}
	}

	/**
	 * Encodes API request parameters
	 *
	 * @since 0.1.0
	 *
	 * @param $product
	 * @param $key
	 * @param $version
	 *
	 * @return string
	 */
	public function get_remote_request_params ( $action, $product, $key, $version, $early_access ) {
		global $wpdb;

		return sprintf( "?gfpsw=%s&product=%s&key=%s&v=%s&wp=%s&php=%s&mysql=%s&earlyaccess=%s", urlencode( $action ), urlencode( $product ), urlencode( $key ), urlencode( $version ), urlencode( get_bloginfo( 'version' ) ), urlencode( phpversion() ), urlencode( $wpdb->db_version() ), urlencode( $early_access ) );
	}

	/**
	 * Display a plugin message in the Plugins list table
	 *
	 * @since 0.1.0
	 *
	 * @param      $message
	 * @param bool $is_error
	 */
	public function display_plugin_message ( $message, $is_error = false ) {

		$style = '';

		if ( $is_error ) {
			$style = 'style="background-color: #ffebe8;"';
		}

		echo '</tr><tr class="plugin-update-tr"><td colspan="5" class="plugin-update"><div class="update-message" ' . $style . '>' . $message . '</div></td>';
	}

	/**
	 * Create a plugin upgrade message
	 *
	 * @since 0.1.0
	 *
	 * @param $plugin_name
	 * @param $plugin_title
	 * @param $version
	 * @param $message
	 */
	public function display_upgrade_message ( $plugin_name, $plugin_title, $version, $message ) {
		$upgrade_message = $message . ' <a class="thickbox" title="' . $plugin_title . '" href="plugin-install.php?tab=plugin-information&plugin=' . $plugin_name . '&TB_iframe=true&width=640&height=808">' . sprintf( __( 'View version %s Details', '' ), $version ) . '</a>. ';
		$this->display_plugin_message( $upgrade_message );
	}
}