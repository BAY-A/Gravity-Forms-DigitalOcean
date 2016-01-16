<?php
/*
Plugin Name: Gravity Forms + DigitalOcean
Plugin URI: https://bay-a.co.uk/gravity-forms-digitalocean/
Description: The only Gravity Forms to DigitalOcean integration. Create a new DigitalOcean droplet from a successful gravity form submission with total control over all settings.
Version: 1.1.1
Author: BAY.A
Author URI: https://bay-a.co.uk
*/

/*
THANKS TO:

- Naomi Bush - https://gravityplus.pro/
- Steven Henty - http://stevenhenty.com/
- Antoine Corcy - http://sbin.dk/

*/

if ( ! function_exists( 'is_plugin_active' ) ){
	require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
}

//Required for the DigitalOcean API
require_once("api/vendor/autoload.php");
use DigitalOceanV2\Adapter\BuzzAdapter;
use DigitalOceanV2\DigitalOceanV2;

// Only do the rest if Gravity Forms is alive
if (class_exists("GFForms")) {

	//Gravity Forms + Stripe integration
	if(class_exists("GFP_Stripe")){

		//Add the GFDO code into the Stripe feed section
		add_action('gfp_stripe_feed_after_billing', array('GFDigitalOcean', 'gf_stripe_feed'), 10, 2);

		//Select whether to kill the droplet or not if subscription fails
		add_action('gform_subscription_canceled', array('GFDigitalOcean', 'gf_stripe_gfdo_do_cancellation'), 10, 4);

		//Save the settings related to the Stripe feed
		add_filter( 'gfp_stripe_before_save_feed', array( 'GFDigitalOcean', 'gf_stripe_save_gfdo' ), 10, 2 );
	}

	//Gravity Forms Authorize.Net Add-on integration
	if ( is_plugin_active( 'gravityformsauthorizenet/authorizenet.php' ) ) {

		//Add the GFDO code into the Auth.net feed section + do save things
		add_action('gform_authorizenet_action_fields', array('GFDigitalOcean', 'gf_authnet_feed'), 10, 2);

		//Select whether to kill the droplet or not if subscription fails
		add_action('gform_subscription_canceled', array('GFDigitalOcean', 'gf_authnet_gfdo_do_cancellation'), 10, 4);

	}

	//Gravity Forms Paypal Add-on integration
	if ( is_plugin_active( 'gravityformspaypal/paypal.php' ) ) {

		//Add the GFDO code into the Paypal feed section
		add_action('gform_paypal_add_option_group', array( 'GFDigitalOcean', 'gf_paypal_feed' ), 10, 2);

		//Save the settings related to the Paypal feed
		add_filter('gform_paypal_save_config', array( 'GFDigitalOcean', 'gf_paypal_feed_save' ));

		//Select whether to kill the droplet or not if the subscription fails
		add_filter('gform_subscription_canceled', array( 'GFDigitalOcean', 'gf_paypal_gfdo_do_cancellation' ), 10, 3);

		//Only create droplet upon paypal success.
		//This is different to the other two payment methods, as the form
		//will submit regardless, so we can't assume success
		add_action('gform_paypal_fulfillment', array( 'GFDigitalOcean', 'gfdo_paypal_successful_payment' ), 10, 4);
	}

	//Special functions for this plugin
	require_once("functions/gravityforms-digitalocean-functions.php");

	//Include addon framework
	GFForms::include_addon_framework();

	class GFDigitalOcean extends GFAddOn {

		//CLASS VARS

		protected $_version = "1.1.1";
		protected $_min_gravityforms_version = "1.8.7";
		protected $_slug = "gravityforms-digitalocean";
		protected $_path = "gravityforms-digitalocean/gravityforms-digitalocean.php";
		protected $_full_path = __FILE__;
		protected $_api_url = "https://gravitydo.com";
		protected $_title = "Gravity Forms + DigitalOcean";
		protected $_short_title = "Gravity Forms + DigitalOcean";

		public function pre_init(){
			parent::pre_init();

			//Check for updates
			add_action('plugins_loaded', array($this, 'check_update'));
		}

		public function init_frontend() {
			parent::init_frontend();

			//Only run this code if the Paypal add-on ISN'T being used.
			//Paypal doesn't validate transaction until after submission,
			//so this isn't good for Paypal
			if ( !is_plugin_active( 'gravityformspaypal/paypal.php' ) ) {
				add_action('gform_after_submission', array($this, 'after_submission'), 10, 2);
			}

			//The wp_cron job to assign the domain to the droplet.
			//This can't be done instantly as when the droplet is still
			//being created, I've found it to be unresponsive to
			//new commands (like adding a domain).
			//So we delay this action a bit..
			add_action('do_domain_for_droplet','do_domain', 10, 3);

			//Store options for the plugin
			$gfdo_settings = get_option('gfdosettings');
			if(!is_array($gfdo_settings)){
				$gfdo_settings = array();
			}

			//Update version
			$gfdo_settings['version'] = $this->_version;

			//Update API URL
			$gfdo_settings['api_url'] = $this->_api_url;

			//Update slug
			$gfdo_settings['slug'] = $this->_slug;

			//Update path
			$gfdo_settings['path'] = $this->_path;

			update_option('gfdosettings', $gfdo_settings);
		}

		//Some overrides for URLS
		public function get_plugin_settings_url() {
			return add_query_arg( array( 'page' => 'gf_settings', 'subview' => $this->get_slug() ), admin_url('admin.php') );
		}

		//And another
		public function plugin_settings_link( $links, $file ) {
			if ( $file != $this->_path ){
				return $links;
			}

			array_unshift($links, '<a href="' . admin_url("admin.php") . '?page=gf_settings&subview=' . $this->get_slug() . '">' . __( 'Settings', 'gravityforms' ) . '</a>');

			return $links;
		}

		//Getters
		//Get path
		public function get_path(){
			return $this->_path;
		}

		//Get slug
		public function get_slug(){
			return $this->_slug;
		}

		//Get version
		public function get_version(){
			return $this->_version;
		}

		//Get API URL
		public function get_api_url(){
			return $this->_api_url;
		}

		//Subscription cancellation function for Auth.net
		function gf_authnet_gfdo_do_cancellation($lead, $feed, $transaction_id, $type){

			//Get a new Gravity Forms + DigitalOcean object
			$GFDO = new GFDigitalOcean;

			//Get the addon settings from it
			$addon_settings = $GFDO->get_plugin_settings();

			//And get the DigitalOcean token
			$DOToken = $addon_settings['digitalocean_token'];

			//Log things if logging is turned on
			$GFDO->log_debug( __( "GFDO - AUTHORIZE.NET SUBSCRIPTION CANCELLED" ) );
			$GFDO->log_debug( __( "Droplet ID: ".$lead['droplet_id'] ) );
			$GFDO->log_debug( "LEAD: " . print_r( $lead, true ) );
			$GFDO->log_debug( "FEED: " . print_r( $feed, true ) );
			$GFDO->log_debug( "TRANSACTION ID: " . print_r( $transaction_id, true ) );


			if($lead['droplet_id'] != "" && $lead['droplet_id'] != "NO RESPONSE"){

				//Specify the type
				$type = "authorize.net";

				//Get options
				$gfdo_settings = get_option('gfdosettings');

				//Is it enabled?
				$GFDOStripeEnabled = $gfdo_settings[$lead['form_id']]['authnet'][$feed['id']]['gfdo'];

				//What do to on cancellation?
				$GFDOStripeAction = $gfdo_settings[$lead['form_id']]['authnet'][$feed['id']]['action'];

				//ONLY IF ENABLED
				if($GFDOStripeEnabled == 1){

					//Get API ready
					$adapter = new BuzzAdapter($DOToken);
					$do = new DigitalOceanV2($adapter);

					//Get droplet object ready
					$droplet = $do->droplet();

					//Take the correct course of action
					if($GFDOStripeAction == "power_down"){

						//Power down droplet
						$droplet->powerOff($lead['droplet_id']);
						return true;
					}
					else if($GFDOStripeAction == "destroy"){

							//Destroy droplet
							$droplet->delete($lead['droplet_id']);
							return true;
						}
					else{

						//Do nothing option
						return true;
					}
				}
				return true;
			}
			else{
				return false;
			}
		}

		function gf_stripe_gfdo_do_cancellation($lead, $feed, $transaction_id, $type){

			//Get a new Gravity Forms + DigitalOcean object
			$GFDO = new GFDigitalOcean;

			//Get the add-on settings from it
			$addon_settings = $GFDO->get_plugin_settings();

			//And get the DigitalOcean token
			$DOToken = $addon_settings['digitalocean_token'];

			//Log things if logging is turned on
			$GFDO->log_debug( __( "GFDO - STRIPE SUBSCRIPTION CANCELLED" ) );
			$GFDO->log_debug( __( "Droplet ID: ".$lead['droplet_id'] ) );
			$GFDO->log_debug( "LEAD: " . print_r( $lead, true ) );
			$GFDO->log_debug( "FEED: " . print_r( $feed, true ) );
			$GFDO->log_debug( "TRANSACTION ID: " . print_r( $transaction_id, true ) );


			if($lead['droplet_id'] != "" && $lead['droplet_id'] != "NO RESPONSE"){

				//Specifiy the type
				$type = "stripe";

				//Get options
				$gfdo_settings = get_option('gfdosettings');

				//Is it enabled?
				$GFDOStripeEnabled = $gfdo_settings[$lead['form_id']]['stripe'][$feed['id']]['gfdo'];

				//What do to on cancellation?
				$GFDOStripeAction = $gfdo_settings[$lead['form_id']]['stripe'][$feed['id']]['action'];

				//ONLT IF ENABLED
				if($GFDOStripeEnabled == 1){

					//Get API ready
					$adapter = new BuzzAdapter($DOToken);
					$do = new DigitalOceanV2($adapter);

					//Get droplet object ready
					$droplet = $do->droplet();

					//Take the correct course of action
					if($GFDOStripeAction == "power_down"){

						//Power down droplet
						$droplet->powerOff($lead['droplet_id']);
						return true;
					}
					else if($GFDOStripeAction == "destroy"){

							//Destroy droplet
							$droplet->delete($lead['droplet_id']);
							return true;
						}
					else{

						//Do nothing option
						return true;
					}
				}
				return true;
			}
			else{
				return false;
			}
		}

		public static function gf_stripe_save_gfdo($feed, $form){

			if(array_key_exists('id', (array)$feed)){
				//Get a new Gravity Forms + DigitalOcean object
				$GFDO = new GFDigitalOcean;

				//Do logging if it's enabled
				$GFDO->log_debug( __( "GFDO - STRIPE FEED SAVED" ) );

				//Get options
				$gfdo_settings = get_option('gfdosettings');

				//Get integration setting
				$gfdo_settings[$form['id']]['stripe'][$feed['id']]['gfdo'] = rgpost('gfdo_stripe_integrate');

				//Get cancellation action setting
				$gfdo_settings[$form['id']]['stripe'][$feed['id']]['action'] = rgpost('gfdo_stripe_feed_action');

				//Update options
				update_option('gfdosettings', $gfdo_settings);
			}
			return $feed;
		}

		public static function gf_stripe_feed($feed, $form){

			//The Stripe feed itself

			//Give our new section a title
?>
            		<p><strong>Gravity Forms + DigitalOcean integration</strong></p>
            	<?php

			//Only continue if the feed's been saved and is a subscription type
			if(array_key_exists('type', (array)$feed['meta']) && 'subscription' == $feed['meta']['type']){

				//Set all vars to defaults, to be safe..
				$optionNothing = "";
				$optionPowerDown = "";
				$optionDestroy="";
				$scriptOut = "";
				$gfdo_checked = "";
				$hideSettings = "display: none;";
				$gfDOWithFeed = 0;
				$feedActionIfCancelled = "nothing";

				//Get options
				$gfdo_settings = get_option('gfdosettings');

				//Only do this if a feed ID has been detected
				if(array_key_exists('id', (array)$feed)){
					if(rgget('id') != 0){
						//Enabled?
						$gfDOWithFeed = $gfdo_settings[$form['id']]['stripe'][$feed['id']]['gfdo'];

						//What to do on cancellation?
						$feedActionIfCancelled = $gfdo_settings[$form['id']]['stripe'][$feed['id']]['action'];
					}
				}
				else{

					//Disabled
					$gfDOWithFeed = 0;
					$feedActionIfCancelled = "";
				}

				//Only continue if enabled
				if(trim($gfDOWithFeed) != 0){

					//Make sure we force the checkbox to be checked, as GF
					//over-rides the HTML value and unchecks it normally
					$scriptOut = "
            		<script>
            			var j = jQuery.noConflict();
            			j(document).ready(function(){
            				j('#gfdo_stripe_integrate').attr('checked', true);
            			});
            		</script>
            		";

					//Give the HTML value anyway
					$gfdo_checked = "checked='checked'";

					//Don't hide anything
					$hideSettings = "";
				}

				//Check the correct one
				if($feedActionIfCancelled == "nothing"){
					$optionNothing = "checked";
				}

				else if($feedActionIfCancelled == "power_down"){
						$optionPowerDown = "checked";
					}

				else if($feedActionIfCancelled == "destroy"){
						$optionDestroy = "checked";
					}

				//Output HTML below
?>
            		<?php echo $scriptOut; ?>
            		<label for="gfdo_stripe_integrate">Integrate this Stripe feed with Gravity Forms + Digital Ocean?</label>&nbsp;<input type="checkbox" name="gfdo_stripe_integrate" id="gfdo_stripe_integrate" value="1" <?php echo $gfdo_checked; ?> onclick="if(jQuery(this).is(':checked')) jQuery('#gfdo_integration_container').show('slow'); else jQuery('#gfdo_integration_container').hide('slow');" />
            		<div style="<?php echo $hideSettings; ?> margin-top:  1em;" id="gfdo_integration_container">
            			<hr />
            			<p>Upon cancellation of the user's subscription to this Stripe feed, what should happen to their droplet?</p><br />
            			<input type="radio" name="gfdo_stripe_feed_action" value="nothing" <?php echo $optionNothing; ?>/>Do nothing<br />
            			<input type="radio" name="gfdo_stripe_feed_action" value="power_down" <?php echo $optionPowerDown; ?>/>Power down droplet<br />
            			<input type="radio" name="gfdo_stripe_feed_action" value="destroy" <?php echo $optionDestroy; ?>/>Destroy droplet<br />
            		</div>
            	<?php
			}
			else{

				//Give message that it must be saved first
				$errMsg = "To integrate this Stripe feed with Gravity Forms + DigitalOcean, first create and save a Stripe feed of Transaction Type \"Subscriptions\", and make sure that Gravity Forms + DigitalOcean is enabled for this form..";
?>
            		<p><?php echo $errMsg; ?></p>
            		<?php
			}
		}

		public static function gf_authnet_feed($feed, $form){
			//The Auth.net feed itself


			//Form's been saved?
			if(rgpost('gfdo_authnet_integrate') == 1 && rgpost("gf_authorizenet_submit") && array_key_exists('type', (array)$feed['meta']) &&
				array_key_exists('id', (array)$feed)){

				//New Gravity Forms + DigitalOcean object
				$GFDO = new GFDigitalOcean;

				//Loggers gonna log
				$GFDO->log_debug( __( "GFDO - AUTHORIZE.NET FEED SAVED" ) );

				//Get settings
				$gfdo_settings = get_option('gfdosettings');

				//Integrate?
				$gfdo_settings[$feed['form_id']]['authnet'][$feed['id']]['gfdo'] = rgpost('gfdo_authnet_integrate');

				//What to do, if enabled, on cancellation?
				$gfdo_settings[$feed['form_id']]['authnet'][$feed['id']]['action'] = rgpost('gfdo_authnet_feed_action');

				//Update options
				update_option('gfdosettings', $gfdo_settings);
			}

			//Give our new section a title
?>
            		<p><strong>Gravity Forms + DigitalOcean integration</strong></p>
            	<?php

			//Only continue if a subscription feed has been selected
			if(array_key_exists('type', (array)$feed['meta']) && 'subscription' == $feed['meta']['type']){

				//Set all vars to defaults, to be safe..
				$optionNothing = "";
				$optionPowerDown = "";
				$optionDestroy="";
				$scriptOut = "";
				$gfdo_checked = "";
				$hideSettings = "display: none;";

				//Get options
				$gfdo_settings = get_option('gfdosettings');

				//Only do this if a feed ID has been detected
				if(array_key_exists('id', (array)$feed) && array_key_exists($feed['form_id'],(array)$gfdo_settings)){

					if(array_key_exists('authnet', (array)$gfdo_settings[$feed['form_id']])){

						if(array_key_exists($feed['id'],(array)$gfdo_settings[$feed['form_id']]['authnet']))
						{

							//Enabled?
							$gfDOWithFeed = $gfdo_settings[$feed['form_id']]['authnet'][$feed['id']]['gfdo'];

							//What to do on cancellation?
							$feedActionIfCancelled = $gfdo_settings[$feed['form_id']]['authnet'][$feed['id']]['action'];
						}
					}

				}
				else{

					//Disabled
					$gfDOWithFeed = 0;
					$feedActionIfCancelled = "";
				}

				//Only continue if enabled
				if(trim($gfDOWithFeed) != 0){

					//Checkbox checking
					$scriptOut = "
            		<script>
            			var j = jQuery.noConflict();
            			j(document).ready(function(){
            				j('#gfdo_stripe_integrate').attr('checked', true);
            			});
            		</script>
            		";

					//HTML version of the above
					$gfdo_checked = "checked='checked'";
					$hideSettings = "";
				}

				//Check the correct checkbox
				if($feedActionIfCancelled == "nothing"){
					$optionNothing = "checked";
				}
				else if($feedActionIfCancelled == "power_down"){
						$optionPowerDown = "checked";
					}
				else if($feedActionIfCancelled == "destroy"){
						$optionDestroy = "checked";
					}

				//Output the HTML
?>
            		<?php echo $scriptOut; ?>
            		<label for="gfdo_authnet_integrate">Integrate this Authorize.net feed with Gravity Forms + Digital Ocean?</label>&nbsp;<input type="checkbox" name="gfdo_authnet_integrate" id="gfdo_authnet_integrate" value="1" <?php echo $gfdo_checked; ?> onclick="if(jQuery(this).is(':checked')) jQuery('#gfdo_integration_container').show('slow'); else jQuery('#gfdo_integration_container').hide('slow');" />
            		<div style="<?php echo $hideSettings; ?> margin-top:  1em;" id="gfdo_integration_container">
            			<hr />
            			<p>Upon cancellation of the user's subscription to this Authorize.net feed, what should happen to their droplet?</p><br />
            			<input type="radio" name="gfdo_authnet_feed_action" value="nothing" <?php echo $optionNothing; ?>/>Do nothing<br />
            			<input type="radio" name="gfdo_authnet_feed_action" value="power_down" <?php echo $optionPowerDown; ?>/>Power down droplet<br />
            			<input type="radio" name="gfdo_authnet_feed_action" value="destroy" <?php echo $optionDestroy; ?>/>Destroy droplet<br />
            		</div>
            	<?php
			}
			else{

				//Otherwise display an error message
				$errMsg = "To integrate this Authorize.net feed with Gravity Forms + DigitalOcean, first create and save an Authorize.net feed of Transaction Type \"Subscriptions\", and make sure that Gravity Forms + DigitalOcean is enabled for this form..";
?>
            		<p><?php echo $errMsg; ?></p>
            		<?php
			}
		}

		function gf_paypal_gfdo_do_cancellation($entry, $config, $transaction_id){
			//Get a new Gravity Forms + DigitalOcean object
			$GFDO = new GFDigitalOcean;

			//Get the add-on settings
			$addon_settings = $GFDO->get_plugin_settings();

			//Get the token
			$DOToken = $addon_settings['digitalocean_token'];

			//Log away
			$GFDO->log_debug( __( "GFDO - PAYPAL SUBSCRIPTION CANCELLED" ) );
			$GFDO->log_debug( __( "Droplet ID: ".$entry['droplet_id'] ) );
			$GFDO->log_debug( "LEAD: " . print_r( $entry, true ) );
			$GFDO->log_debug( "FEED: " . print_r( $config, true ) );
			$GFDO->log_debug( "TRANSACTION ID: " . print_r( $transaction_id, true ) );

			//If an action other than 'nothing' is specified, continue..
			if($entry['droplet_id'] != "" && $entry['droplet_id'] != "NO RESPONSE"){

				//Get options
				$gfdo_settings = get_option('gfdosettings');

				//Is it enabled?
				$GFDOStripeEnabled = $gfdo_settings[$entry['form_id']]['paypal'][$config['id']]['gfdo'];

				//What to do?
				$GFDOStripeAction = $gfdo_settings[$entry['form_id']]['paypal'][$config['id']]['action'];

				//Only continue if enabled
				if($GFDOStripeEnabled == 1){

					//Get API ready
					$adapter = new BuzzAdapter($DOToken);
					$do = new DigitalOceanV2($adapter);

					//Get a new droplet object
					$droplet = $do->droplet();

					//Carry out the correct action
					if($GFDOStripeAction == "power_down"){

						//Power down droplet
						$droplet->powerOff($entry['droplet_id']);
						return true;
					}
					else if($GFDOStripeAction == "destroy"){

							//Destroy droplet
							$droplet->delete($entry['droplet_id']);
							return true;
						}
					else{

						//Do nothing option
						return true;
					}
				}
				return true;
			}
			else{
				return false;
			}
		}

		//Save the Paypal feed
		public static function gf_paypal_feed_save($config){

			if(rgget('id') != 0){
				//Get a new Gravity Forms + DigitalOcean object
				$GFDO = new GFDigitalOcean;

				//Log
				$GFDO->log_debug( __( "GFDO - PAYPAL FEED SAVED" ) );

				//Get options
				$gfdo_settings = get_option('gfdosettings');

				//Integrate with paypal?
				$gfdo_settings[$config['form_id']]['paypal'][rgget('id')]['gfdo'] = rgpost('gfdo_paypal_integrate');

				//What to do if cancelleD?
				$gfdo_settings[$config['form_id']]['paypal'][rgget('id')]['action'] = rgpost('gfdo_paypal_feed_action');

				//Update options
				update_option('gfdosettings', $gfdo_settings);
			}

			return $config;
		}

		public static function gf_paypal_feed($config, $form){

			//Gravity Forms + DigitalOcean code for the Paypal feed
?>
  					<p><strong>Gravity Forms + DigitalOcean integration</strong></p>
  				<?php

			//Display this if not saved yet
			if(!isset($config['meta']['type'])){
?>
  					<p>Please save this Paypal feed before applying Gravity Forms + DigitalOcean settings</p>
  					<?php
			}

			//Otherwise, only show this if it's saved as a subscription type
			else if('subscription' == $config['meta']['type'] && rgget('id') != 0){

					//Set all vars to defaults, to be safe..
					$optionNothing = "";
					$optionPowerDown = "";
					$optionDestroy="";
					$scriptOut = "";
					$gfdo_checked = "";
					$hideSettings = "display: none;";

					//Get options
					$gfdo_settings = get_option('gfdosettings');
					if(array_key_exists($config['form_id'], (array)$gfdo_settings)){

						if(array_key_exists('paypal', (array)$gfdo_settings[$config['form_id']])){

							if(array_key_exists(rgget('id'), (array)$gfdo_settings[$config['form_id']]['paypal'])){

								//Is enabled?
								$gfDOWithFeed = $gfdo_settings[$config['form_id']]['paypal'][rgget('id')]['gfdo'];

								//What to do if cancelled?
								$configActionIfCancelled = $gfdo_settings[$config['form_id']]['paypal'][rgget('id')]['action'];
							}
						}

					}

					else{

						$gfDOWithFeed = 0;
						$configActionIfCancelled = "nothing";
					}


					//If enabled
					if(trim($gfDOWithFeed) != 0){

						//Check the checkbox
						$scriptOut = "
  					<script>
  						var j = jQuery.noConflict();
  						j(document).ready(function(){
  							j('#gfdo_paypal_integrate').attr('checked', true);
  						});
  					</script>
  					";

						//Do it the HTML way too
						$gfdo_checked = "checked='checked'";
						$hideSettings = "";
					}

					//Check the correct box
					if($configActionIfCancelled == "nothing"){
						$optionNothing = "checked";
					}
					else if($configActionIfCancelled == "power_down"){
							$optionPowerDown = "checked";
						}
					else if($configActionIfCancelled == "destroy"){
							$optionDestroy = "checked";
						}

					//Output the HTML
?>
  					<?php echo $scriptOut; ?>
  					<label for="gfdo_paypal_integrate">Integrate this Paypal subscription feed with Gravity Forms + Digital Ocean?</label>&nbsp;<input type="checkbox" name="gfdo_paypal_integrate" id="gfdo_paypal_integrate" value="1" <?php echo $gfdo_checked; ?> onclick="if(jQuery(this).is(':checked')) jQuery('#gfdo_integration_container').show('slow'); else jQuery('#gfdo_integration_container').hide('slow');" />
  					<div style="<?php echo $hideSettings; ?> margin-top:  1em;" id="gfdo_integration_container">
  						<hr />
  						<p>Upon cancellation of the user's subscription to this Paypal feed, what should happen to their droplet?</p><br />
  						<input type="radio" name="gfdo_paypal_feed_action" value="nothing" <?php echo $optionNothing; ?>/>Do nothing<br />
  						<input type="radio" name="gfdo_paypal_feed_action" value="power_down" <?php echo $optionPowerDown; ?>/>Power down droplet<br />
  						<input type="radio" name="gfdo_paypal_feed_action" value="destroy" <?php echo $optionDestroy; ?>/>Destroy droplet<br />
  					</div>
  				<?php
				}
			else{

				//Or output this error message
				$errMsg = "To integrate this Paypal feed with Gravity Forms + DigitalOcean, first create and save a Paypal feed of Transaction Type \"Subscriptions\", and make sure that Gravity Forms + DigitalOcean is enabled for this form. After saving, go back to the Paypal feed list and choose your feed again.";
?>
  					<p><?php echo $errMsg; ?></p>
  					<?php
			}
		}

		//Uninstall function - delete all settings
		public function uninstall() {
			delete_option('gfdosettings');
		}

		//Do this after updating.
		//This will save the new values into the options
		public function upgrade($previous_version) {

			//Get options
			$gfdo_settings = get_option('gfdosettings');

			//If not already existing, create blank array
			(!is_array($gfdo_settings) ? $gfdo_settings = array() : "");

			//Update version
			$gfdo_settings['version'] = $this->_version;

			//Update API URL
			$gfdo_settings['api_url'] = $this->_api_url;

			//Update slug
			$gfdo_settings['slug'] = $this->_slug;

			//Update path
			$gfdo_settings['path'] = $this->_path;

			//Save options
			update_option('gfdosettings', $gfdo_settings);
		}

		//Check for updates
		public static function check_update(){

			//We need the update script
			if( ! class_exists( 'GFPSW_Updater' ) ) {
				include( dirname( __FILE__ ) . '/class-gfpsw-updater.php' );
			}

			//Get options
			$gfdo_settings = get_option('gfdosettings');

			//If it's an array (therefore we already
			//have options saved)
			if(is_array($gfdo_settings)){

				//Get API URL
				$api_url = $gfdo_settings['api_url'];

				//Get slug
				$slug = $gfdo_settings['slug'];

				//Get path
				$path = $gfdo_settings['path'];

				//Get version
				$version = $gfdo_settings['version'];

				//If we have a license key, save to a var
				if(array_key_exists('gfdo_key', (array)$gfdo_settings)){
					$license_key = $gfdo_settings['gfdo_key'];
				}
				//Or make the var blank
				else{
					$license_key = "";
				}
			}
			//If we don't already have options saved
			else{
				$api_url = "";
				$slug = "";
				$path = "";
				$version = "";
				$license_key = "";
			}

			//Data array
			$data = array( 'version' => $version,
				'license_key' => $license_key
			);

			//Run updater
			$gfpsw_updater = new GFPSW_Updater( $api_url, $slug, $path, $data );
		}


		function after_submission($entry, $form) {

			//Nullify the new Droplet, just in case..
			$newDroplet = null;

			//Get form settings
			$form_settings = $this->get_form_settings($form);

			//Get addon settings
			$addon_settings = $this->get_plugin_settings();

			//Exit if not enabled
			if (!$addon_settings || !$form_settings || !$form_settings["isEnabled"]) {
				return;
			}

			//API token
			$DOToken = $addon_settings["digitalocean_token"];

			//Return if token is empty
			if(empty($DOToken)){
				return;
			}

			//Get the chosen droplet settings for the entry
			$droplet_size = $form_settings["selectDropletSize"];
			$droplet_name = $entry[$form_settings["selectDropletName"]];
			$droplet_image = $form_settings["selectDropletImage"];
			$droplet_region = $form_settings["selectDropletRegion"];

			//Need a droplet name
			if(strlen($droplet_name) == 0){
				return;
			}

			/*******************
                //Create the droplet
                *******************/

			//First let's make sure a droplet of the name we've chosen doesn't already exist
			$allDropletNames = get_all_droplet_names($this->get_plugin_settings());

			//Only continue if we have any existing droplets
			if($allDropletNames != false){

				//If a droplet of this name already exists
				if (in_array($droplet_name, $allDropletNames)) {

					//Append a '1' to the name if it exists
					$droplet_name = $droplet_name."1";
				}
			}

			//Update the entry droplet name with our new one
			$entry["droplet_name"] = $droplet_name;

			//Create an API instance
			$adapter = new BuzzAdapter($DOToken);
			$do = new DigitalOceanV2($adapter);

			//Create a droplet object
			$droplet = $do->droplet();

			//Check if backups are enabled
			($form_settings['backupsEnabled'] == 1 ? $anyBackups = true : $anyBackups = false);

			//Check if private networking is enabled
			($form_settings['privateNetworkEnabled'] == 1 ? $anyPN = true : $anyPN = false);

			//Check if SSH keys enabled.
			//Get them if they are
			if($form_settings['SSHKeysEnabled'] == 1){
				$theSSHKeys = get_ssh_keys_as_array($form_settings);
			}

			//Otherwise have a blank array
			else{
				$theSSHKeys = array();
			}

			//CREATE THE NEW DROPLET
			try {
				$newDroplet = $droplet->create($droplet_name, $droplet_region, $droplet_size, $droplet_image, $anyBackups, false, $anyPN, $theSSHKeys);
				
				$GFDO = new GFDigitalOcean;
				$GFDO->log_debug( "DROPLET CREATED: " . print_r( $newDroplet, true ) );
				$GFDO->log_debug( "droplet_name: " . print_r( $droplet_name, true ) );
				$GFDO->log_debug( "droplet_region: " . print_r( $droplet_region, true ) );
				$GFDO->log_debug( "droplet_size: " . print_r( $droplet_size, true ) );
				$GFDO->log_debug( "droplet_image: " . print_r( $droplet_image, true ) );
				$GFDO->log_debug( "droplet_backups: " . print_r( $anyBackups, true ) );
				$GFDO->log_debug( "droplet_private_network: " . print_r( $anyPN, true ) );
				$GFDO->log_debug( "droplet_ssh_keys: " . print_r( $theSSHKeys, true ) );
				
				//Get its status
				$dStatus = $newDroplet->status;
				
				//Get its ID
				$dId = $newDroplet->id;
				
				//Check if we want to assign a domain to the new droplet,
				//and that it was successfully created
				if($form_settings['domainEnabled'] == 1 &&
				$form_settings['chooseDomain'] != "" && $dStatus == "new"){
					
					//2 mins from now
					$nowPlusTwo = time() + 120;
					
					//Schedule it for 2 mins from now as an event
					wp_schedule_single_event($nowPlusTwo,'do_domain_for_droplet', array($DOToken,$dId,$entry[$form_settings['chooseDomain']]));
				}
				
				//Record the Droplet's ID
				$form_settings["droplet_id"] = trim($dId);
				$entry["droplet_id"] = trim($dId);
				
				//Record SSH Keys
				if(count($theSSHKeys > 0)){
					$entry["ssh_keys"] = ssh_ids_to_names($addon_settings, $theSSHKeys);
				}
				
				//and the 'verdict'
				$form_settings["droplet_creation_success"] = trim($dStatus);
				
				//Save it all
				GFAPI::update_entry($entry);
				$this->save_form_settings($form, $form_settings);
				
				do_action('gfdo_after_droplet_creation', $newDroplet, $entry, $form);
				
			} catch (Exception $e) {
				$GFDO = new GFDigitalOcean;
				if($e !== null){
					$GFDO->log_debug( "DROPLET NOT CREATED: ".$e );
				}
				else{
					$GFDO->log_debug( "DROPLET NOT CREATED: ");
				}
				$GFDO->log_debug( "droplet_name: " . print_r( $droplet_name, true ) );
				$GFDO->log_debug( "droplet_region: " . print_r( $droplet_region, true ) );
				$GFDO->log_debug( "droplet_size: " . print_r( $droplet_size, true ) );
				$GFDO->log_debug( "droplet_image: " . print_r( $droplet_image, true ) );
				$GFDO->log_debug( "droplet_backups: " . print_r( $anyBackups, true ) );
				$GFDO->log_debug( "droplet_private_network: " . print_r( $anyPN, true ) );
				$GFDO->log_debug( "droplet_ssh_keys: " . print_r( $theSSHKeys, true ) );
				
				//Save it all
				GFAPI::update_entry($entry);
				$this->save_form_settings($form, $form_settings);
			}
			
		}

		//The Paypal version of the AFTER_SUBMISSION function
		function gfdo_paypal_successful_payment($entry, $config, $transaction_id, $amount) {

			//Gravity Forms + DigitalOcean object
			$GFDO = new GFDigitalOcean;

			//Log it up
			$GFDO->log_debug( __( "GFDO - PAYPAL SUCCESFUL PAYMENT" ) );
			$GFDO->log_debug( "ENTRY : " . print_r( $entry, true ) );
			$GFDO->log_debug( "CONFIG : " . print_r( $config, true ) );


			//NULL the Droplet
			$newDroplet = null;

			//Get the form
			$form = GFFormsModel::get_form_meta($entry['form_id']);

			//Keep on loggin' in the free world
			$GFDO->log_debug( "NEW FORM OBJECT : " . print_r( $form, true ) );

			//Get form settings
			$form_settings = $GFDO->get_form_settings($form);

			//Get add-on settings
			$addon_settings = $GFDO->get_plugin_settings();

			//If it's not enabled, exit
			if (!$addon_settings || !$form_settings || !$form_settings["isEnabled"]) {
				return;
			}

			//API token
			$DOToken = $addon_settings["digitalocean_token"];

			//Return if token empty
			if(empty($DOToken)){
				return;
			}

			//Get the chosen settings for the droplet
			$droplet_size = $form_settings["selectDropletSize"];
			$droplet_name = $entry[$form_settings["selectDropletName"]];
			$droplet_image = $form_settings["selectDropletImage"];
			$droplet_region = $form_settings["selectDropletRegion"];

			//Get all droplet names
			$allDropletNames = get_all_droplet_names($GFDO->get_plugin_settings());

			//If we don't have any existing droplets, don't bother continuing
			if($allDropletNames != false){

				//If the chosen droplet name already exists..
				if (in_array($droplet_name, $allDropletNames)) {

					//Append a '1' to the name if it exists
					$droplet_name = $droplet_name."1";
				}
			}

			//Update the entry droplet name with our new one
			$entry["droplet_name"] = $droplet_name;

			//Create an API instance
			$adapter = new BuzzAdapter($DOToken);
			$do = new DigitalOceanV2($adapter);

			//Create a droplet object
			$droplet = $do->droplet();


			//Are backups enabled?
			($form_settings['backupsEnabled'] == 1 ? $anyBackups = true : $anyBackups = false);

			//Are private networks enabled?
			($form_settings['privateNetworkEnabled'] == 1 ? $anyPN = true : $anyPN = false);

			//Any SSH keys?

			//Store them if so
			if($form_settings['SSHKeysEnabled'] == 1){
				$theSSHKeys = get_ssh_keys_as_array($form_settings);
			}

			//Or make a blank array if not
			else{
				$theSSHKeys = array();
			}

			//Create the droplet
			try {
				$newDroplet = $droplet->create($droplet_name, $droplet_region, $droplet_size, $droplet_image, $anyBackups, false, $anyPN, $theSSHKeys);
				
				$GFDO = new GFDigitalOcean;
				$GFDO->log_debug( "DROPLET CREATED: " . print_r( $newDroplet, true ) );
				$GFDO->log_debug( "droplet_name: " . print_r( $droplet_name, true ) );
				$GFDO->log_debug( "droplet_region: " . print_r( $droplet_region, true ) );
				$GFDO->log_debug( "droplet_size: " . print_r( $droplet_size, true ) );
				$GFDO->log_debug( "droplet_image: " . print_r( $droplet_image, true ) );
				$GFDO->log_debug( "droplet_backups: " . print_r( $anyBackups, true ) );
				$GFDO->log_debug( "droplet_private_network: " . print_r( $anyPN, true ) );
				$GFDO->log_debug( "droplet_ssh_keys: " . print_r( $theSSHKeys, true ) );
				
				//Get its status
				$dStatus = $newDroplet->status;
	
				//Get its ID
				$dId = $newDroplet->id;
	
				//Are domains enabled?
				if($form_settings['domainEnabled'] == 1 &&
					$form_settings['chooseDomain'] != "" && $dStatus == "new"){
	
					//2 mins from now
					$nowPlusTwo = time() + 120;
	
					//Schedule it for 2 mins from now as an event
					wp_schedule_single_event($nowPlusTwo,'do_domain_for_droplet', array($DOToken,$dId,$entry[$form_settings['chooseDomain']]));
				}
	
				//Record the Droplet's ID
				$form_settings["droplet_id"] = trim($dId);
				$entry["droplet_id"] = trim($dId);
	
				//Record SSH Keys
				if(count($theSSHKeys > 0)){
					$entry["ssh_keys"] = ssh_ids_to_names($addon_settings, $theSSHKeys);
				}
	
				//and the 'verdict'
				$form_settings["droplet_creation_success"] = trim($dStatus);
	
				//Save it all
				GFAPI::update_entry($entry);
				$this->save_form_settings($form, $form_settings);
				do_action('gfdo_after_droplet_creation', $newDroplet, $entry, $form);
				
			} catch (Exception $e) {
				$GFDO = new GFDigitalOcean;
				if($e !== null){
					$GFDO->log_debug( "DROPLET NOT CREATED: ".$e );
				}
				else{
					$GFDO->log_debug( "DROPLET NOT CREATED: ");
				}
				$GFDO->log_debug( "droplet_name: " . print_r( $droplet_name, true ) );
				$GFDO->log_debug( "droplet_region: " . print_r( $droplet_region, true ) );
				$GFDO->log_debug( "droplet_size: " . print_r( $droplet_size, true ) );
				$GFDO->log_debug( "droplet_image: " . print_r( $droplet_image, true ) );
				$GFDO->log_debug( "droplet_backups: " . print_r( $anyBackups, true ) );
				$GFDO->log_debug( "droplet_private_network: " . print_r( $anyPN, true ) );
				$GFDO->log_debug( "droplet_ssh_keys: " . print_r( $theSSHKeys, true ) );
				
				//Save it all
				GFAPI::update_entry($entry);
				$this->save_form_settings($form, $form_settings);
			}
		}

		public function render_uninstall(){
			//Custom uninstall text
?>
                <form action="" method="post">
                    <?php wp_nonce_field("uninstall", "gf_addon_uninstall") ?>
                    <?php if ($this->current_user_can_any($this->_capabilities_uninstall)) { ?>
                        <div class="hr-divider"></div>

                        <h3><span><?php _e("Uninstall Gravity Forms + DigitalOcean", "gravityforms-digitalocean") ?></span></h3>
                        <div class="delete-alert"><?php _e("Warning - This will delete all settings.", "gravityforms-digitalocean") ?><br /><br />
                            <?php
				$uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall  Gravity Forms + DigitalOcean", "gravityforms-digitalocean") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL settings will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravityforms-digitalocean") . '\');"/>';
				echo $uninstall_button;
?>
                        </div>
                    <?php
			}
?>
                </form>
                <?php
		}

		//Form settings (Form > Settings)
		public function form_settings_fields($form) {

			$chooseDomainDep = array($this, 'checkDomainName');
			$chooseSSHDep = array($this, 'checkSSHKeys');

			//Get stored vals for these two
			$form_settings = $this->get_form_settings($form);
			if(array_key_exists('domainEnabled', (array)$form_settings)){
				if($form_settings['domainEnabled'] == 1){
					$chooseDomainDep = array(
						"field"  => "isEnabled",
						"values" => array(1)
					);
				}
			}
			if(array_key_exists('SSHKeysEnabled', (array)$form_settings)){
				if($form_settings['SSHKeysEnabled'] == 1){
					$chooseSSHDep = array(
						"field"  => "isEnabled",
						"values" => array(1)
					);
				}
			}


			if(array_key_exists('_gaddon_setting_domainEnabled', (array)$_POST)){
				if($_POST['_gaddon_setting_domainEnabled'] == 0){
					$chooseDomainDep = array($this, 'checkDomainName');
				}
			}
			if(array_key_exists('_gaddon_setting_SSHKeysEnabled', (array)$_POST)){
				if($_POST['_gaddon_setting_SSHKeysEnabled'] == 0){
					$chooseSSHDep = array($this, 'checkSSHKeys');
				}
			}
			//Get addon settings
			$addon_settings = $this->get_plugin_settings();

			//Get DigitalOcean token
			$DOToken = $addon_settings["digitalocean_token"];

			//If token is empty, show the error
			if(empty($DOToken)){
				return array(
					array(
						"title"  => __("Gravity Forms + DigitalOcean Settings", "gravityforms-digitalocean"),
						"description" => "<br /><br /><strong style=\"color: red;\">Token not entered</strong><br /><br />You need to enter your DigitalOcean access token <a href=\"".admin_url( 'admin.php?page=gf_settings&subview=gravityforms-digitalocean')."\">HERE</a>",
						"fields" => array()
					)
				);
			}

			//Else show settings
			else if(is_api_alive($this->get_plugin_settings()) == true){
					return array(
						array(
							"title"  => __("Gravity Forms + DigitalOcean Settings", "gravityforms-digitalocean"),
							"fields" => array(
								array(
									"name"     => "droplet_creation_success",
									"label"    => __("Droplet creation success", "gravityforms-digitalocean"),
									"type"     => "hidden",
									"value" => ""
								),
								array(
									"name"     => "droplet_id",
									"label"    => __("Droplet ID", "gravityforms-digitalocean"),
									"type"     => "hidden",
									"value" => ""
								),
								array(
									"name"     => "enableDigitalOcean",
									"tooltip"  => __("Activate Gravity Forms + DigitalOcean on this form. A new droplet will be created on successful submission of the form."),
									"label"    => __("Activate Gravity Forms + DigitalOcean", "gravityforms-digitalocean"),
									"onchange" => "jQuery(this).parents('form').submit();",
									"onclick"  => "jQuery(this).closest('form').submit();",
									"type"     => "checkbox",
									"choices"  => array(
										array(
											"label" => __("Enable Gravity Forms + DigitalOcean for this form", "gravityforms-digitalocean"),
											"name"  => "isEnabled"
										)
									)
								),
								array(
									"name"     => "selectDropletName",
									"required" => "yes",
									"dependency" => array(
										"field"  => "isEnabled",
										"values" => array(1)
									),
									"tooltip"  => __("Select the form field to name the droplet."),
									"label"    => __("Droplet name", "gravityforms-digitalocean"),
									"type"     => "select",
									"choices"  => return_fields_title_and_values_for_settings_fields($form)
								),
								array(
									"name"     => "selectDropletSize",
									"required" => "yes",
									"dependency" => array(
										"field"  => "isEnabled",
										"values" => array(1)
									),
									"tooltip"  => __("Select the droplet size."),
									"label"    => __("Droplet size", "gravityforms-digitalocean"),
									"type"     => "select",
									"choices"  => return_droplet_sizes_for_settings_fields($this->get_plugin_settings())
								),
								array(
									"name"     => "selectDropletRegion",
									"required" => "yes",
									"dependency" => array(
										"field"  => "isEnabled",
										"values" => array(1)
									),
									"tooltip"  => __("Select the droplet region."),
									"label"    => __("Droplet region", "gravityforms-digitalocean"),
									"type"     => "select",
									"choices"  => return_droplet_regions_for_settings_fields($this->get_plugin_settings())
								),
								array(
									"name"     => "selectDropletImage",
									"required" => "yes",
									"dependency" => array(
										"field"  => "isEnabled",
										"values" => array(1)
									),
									"tooltip"  => __("Select the droplet image (optional) from your DigitalOcean account."),
									"label"    => __("Droplet image", "gravityforms-digitalocean"),
									"type"     => "select",
									"choices"  => return_droplet_images_for_settings_fields($this->get_plugin_settings())
								),
								array(
									"name"     => "enableDigitalOceanBackups",
									"dependency" => array(
										"field"  => "isEnabled",
										"values" => array(1)
									),
									"tooltip"  => __("Enable DigitalOcean backups for this droplet."),
									"label"    => __("Backups (extra charge)", "gravitycontacts"),
									"type"     => "checkbox",
									"choices"  => array(
										array(
											"label" => __("Enable DigitalOcean backups for this droplet", "gravityforms-digitalocean"),
											"name"  => "backupsEnabled"
										)
									)
								),
								array(
									"name"     => "enablePrivateNetwork",
									"dependency" => array(
										"field"  => "isEnabled",
										"values" => array(1)
									),
									"tooltip"  => __("Enable Private Network for this droplet."),
									"label"    => __("Private Network", "gravitycontacts"),
									"type"     => "checkbox",
									"choices"  => array(
										array(
											"label" => __("Enable Private Network for this droplet", "gravityforms-digitalocean"),
											"name"  => "privateNetworkEnabled"
										)
									)
								),
								array(
									"name"     => "enableDomainName",
									"dependency" => array(
										"field"  => "isEnabled",
										"values" => array(1)
									),
									"tooltip"  => __("Add domain name to droplet."),
									"label"    => __("Add a domain name to the droplet from a form field", "gravitycontacts"),
									"onchange" => "jQuery(this).parents('form').submit();",
									"onclick"  => "jQuery(this).closest('form').submit();",
									"type"     => "checkbox",
									"choices"  => array(
										array(
											"label" => __("Enable the user to associate a domain name with the new droplet", "gravityforms-digitalocean"),
											"name"  => "domainEnabled"
										)
									)
								),
								array(
									"name"     => "chooseDomain",
									"required" => "yes",
									"dependency" => $chooseDomainDep,
									"tooltip"  => __("Select the form field where the domain name will be entered."),
									"label"    => __("Domain name", "gravityforms-digitalocean"),
									"type"     => "select",
									"choices"  => return_fields_title_and_values_for_settings_fields($form, "website")
								),
								array(
									"name"     => "enableSSHKeys",
									"dependency" => array(
										"field"  => "isEnabled",
										"values" => array(1)
									),
									"tooltip"  => __("Add SSH keys to droplet."),
									"label"    => __("Add SSH keys to the droplet", "gravitycontacts"),
									"onchange" => "jQuery(this).parents('form').submit();",
									"onclick"  => "jQuery(this).closest('form').submit();",
									"type"     => "checkbox",
									"choices"  => array(
										array(
											"label" => __("Enable SSH keys to be associated with the newly created droplet", "gravityforms-digitalocean"),
											"name"  => "SSHKeysEnabled"
										)
									)
								),
								array(
									"name"     => "chooseSSHKeys",
									"required" => "yes",
									"dependency" => $chooseSSHDep,
									"tooltip"  => __("Select the SSH keys you'd like to use for this droplet (can select multiple keys). This will cause there to be no password emailed upon droplet creation."),
									"label"    => __("SSH keys", "gravityforms-digitalocean"),
									"type"     => "checkbox",
									"choices"  => return_key_names_and_ids_for_settings_fields($this->get_plugin_settings())
								),
								array(
									"type"     => "save",
									"value"    => __("Update Gravity Forms + DigitalOcean settings", "gravityforms-digitalocean"),
									"messages" => array(
										"success" => __("Gravity Forms + DigitalOcean settings updated", "gravityforms-digitalocean"),
										"error"   => __("There was an error while saving the Gravity Forms + Digital Ocean Settings", "gravityforms-digitalocean")
									)
								))
						)
					);
				}
			else{
				return array(
					array(
						"title"  => __("Gravity Forms + DigitalOcean Settings", "gravityforms-digitalocean"),
						"description" => "<br /><br /><strong style=\"color: red;\">API error</strong><br /><br />Please <a target='_blank' href='https://www.digitalocean.com/company/contact/'>contact</a> DigitalOcean.",
						"fields" => array()
					)
				);
			}
		}

		//Callbacks for Domain name + SSH Keys
		function checkDomainName(){
			if(array_key_exists('_gaddon_setting_domainEnabled', (array)$_POST) &&
				array_key_exists('_gaddon_setting_isEnabled', $_POST)){
				if($_POST['_gaddon_setting_domainEnabled'] == 1 && $_POST['_gaddon_setting_isEnabled'] == 1){
					return true;
				}
				else{
					return false;
				}
			}
			else{
				return false;
			}
		}

		function checkSSHKeys(){
			if(array_key_exists('_gaddon_setting_SSHKeysEnabled', (array)$_POST) &&
				array_key_exists('_gaddon_setting_isEnabled', $_POST)){
				if($_POST['_gaddon_setting_SSHKeysEnabled'] == 1 && $_POST['_gaddon_setting_isEnabled'] == 1){
					return true;
				}
				else{
					return false;
				}
			}
			else{
				return false;
			}
		}

		//Gravity Forms + DigitalOcean logo
		public function plugin_settings_icon(){
			return "<img src='https://gravitydo.com/wp-content/themes/gravitydo/library/images/gravitydo.png' width='auto' /><br />";
		}

		public function form_settings_icon(){
			return "<img src='https://gravitydo.com/wp-content/themes/gravitydo/library/images/gravitydo.png' width='auto' /><br />";
		}

		//After save is clicked on the plugin settings page
		public function maybe_save_plugin_settings(){

			//Make sure we're really saving..
			if( $this->is_save_postback() ) {

				//Get settings that were posted
				$settings = $this->get_posted_settings();

				//See if the key is there
				if(array_key_exists('gfdo_key', (array)$settings)){

					//Put new key in var
					$newKey = trim($settings['gfdo_key']);

					//Reset old key var
					$oldKey = "";

					//Get existing settings
					$gfdo_settings = get_option('gfdosettings');

					//See if settings is an array
					if(is_array($gfdo_settings)){

						//If old settings has a key...
						if(array_key_exists('gfdo_key', (array)$gfdo_settings)){

							//...Assign it to a var
							$oldKey = $gfdo_settings['gfdo_key'];

							//If the new key is longer than 0 characters..
							if(strlen($newKey) > 0){

								//Put the new key in the array
								$gfdo_settings['gfdo_key'] = $newKey;

								//And update options with it
								update_option('gfdosettings', $gfdo_settings);
							}
						}

						//Else we can put the new one straight in
						else{
							$gfdo_settings['gfdo_key'] = $newKey;
							update_option('gfdosettings', $gfdo_settings);
						}
					}

					//Put new one in
					else{
						$gfdo_settings['gfdo_key'] = $newKey;
						update_option('gfdosettings', $gfdo_settings);
					}
				}

				//ORIGINAL CODE BELOW FOR SAVING

				// store a copy of the previous settings for cases where action whould only happen if value has changed
				$this->set_previous_settings( $this->get_plugin_settings() );


				$sections = $this->plugin_settings_fields();
				$is_valid = $this->validate_settings( $sections, $settings );

				if( $is_valid ){
					$this->update_plugin_settings( $settings );
					GFCommon::add_message( $this->get_save_success_message( $sections ) );
				}
				else{
					GFCommon::add_error_message( $this->get_save_error_message( $sections ) );
				}

			}
		}

		//Plugin settings fields
		public function plugin_settings_fields() {

			return array(
				array(
					"title"  => __("Gravity Forms + DigitalOcean License Key", "gravityforms-digitalocean"),
					"description" => "The license key from your post-purchase email. The 'from' address will be 'orders@gravitydo.com'.",
					"fields" => array(
						array(
							"name"    => "gfdo_key",
							"tooltip" => __("Enter the license key.", "gravityforms-digitalocean"),
							"label"   => __("License Key", "gravityforms-digitalocean"),
							"type"    => "text",
							"class"   => "medium",
							"style"   => "width: 229px"
						)
					)
				),
				array(
					"title"  => __("DigitalOcean API Details", "gravityforms-digitalocean"),
					"description" => "Go to <a target=\"_blank\" href=\"https://cloud.digitalocean.com/settings/applications\">https://cloud.digitalocean.com/settings/applications</a> and click \"Generate new token\". Enter 'GF+DO' for the Token Name, and under 'Select Scopes' select both <strong>Read</strong> and <strong>Write</strong>.<br /><br />Remove the brackets either side of the token: <span style=\"color: red\">(</span>XXXXXXXXX<span style=\"color: red;\">)</span><br /><br />And paste it in below.",
					"fields" => array(
						array(
							"name"    => "digitalocean_token",
							"tooltip" => __("Enter the token.", "gravityforms-digitalocean"),
							"label"   => __("Access token", "gravityforms-digitalocean"),
							"type"    => "text",
							"class"   => "large",
							"style"   => "width: 435px"
						)
					)
				)
			);
		}

		//Custom title for plugin settings fields
		public function plugin_settings_title() {
			return "Gravity Forms + DigitalOcean Add-On Settings";
		}

		//Enqueue CSS
		public function styles(){

			parent::styles();
			return array(
				array(  "handle" => "gravityforms-digitalocean_css",
					"src" => $this->get_base_url()."/css/gfdo.css",
					"version" => GFCommon::$version,
					"enqueue" => array(
						array("admin_page" => array("form_settings", "plugin_settings", "plugin_page") )
					)
				)
			);
		}

		//Scripts
		public function scripts(){

			parent::scripts();
			return array(
				array("handle"   => "gfdo-scripts",
					"src"      => $this->get_base_url()."/js/gfdo.js",
					"version"  => GFCommon::$version,
					"enqueue"  => array(
						array(
							"admin_page"  => array( "form_settings" )
						)
					)
				)
			);
		}

		//Get entry meta
		public function get_entry_meta($entry_meta, $form_id) {
			$entry_meta['droplet_size'] = array(
				'label'                      => 'Droplet Size',
				'is_numeric'                 => false,
				'update_entry_meta_callback' => array($this, 'update_entry_meta'),
				'is_default_column'          => true, // default column on the entry list
				'filter'                     => array(
					'operators' => array("is", "isnot", "contains")
				)
			);

			$entry_meta['droplet_name'] = array(
				'label'                      => 'Droplet Name',
				'is_numeric'                 => false,
				'update_entry_meta_callback' => array($this, 'update_entry_meta'),
				'is_default_column'          => true, // default column on the entry list
				'filter'                     => array(
					'operators' => array("is", "isnot", "contains")
				)
			);

			$entry_meta['droplet_region'] = array(
				'label'                      => 'Droplet Region',
				'is_numeric'                 => false,
				'update_entry_meta_callback' => array($this, 'update_entry_meta'),
				'is_default_column'          => true, // default column on the entry list
				'filter'                     => array(
					'operators' => array("is", "isnot", "contains")
				)
			);

			$entry_meta['droplet_image'] = array(
				'label'                      => 'Droplet Image',
				'is_numeric'                 => false,
				'update_entry_meta_callback' => array($this, 'update_entry_meta'),
				'is_default_column'          => true, // default column on the entry list
				'filter'                     => array(
					'operators' => array("is", "isnot", "contains")
				)
			);
			$entry_meta['ssh_keys'] = array(
				'label'                      => 'SSH Keys',
				'is_numeric'                 => false,
				'update_entry_meta_callback' => array($this, 'update_entry_meta'),
				'is_default_column'          => true, // default column on the entry list
				'filter'                     => array(
					'operators' => array("is", "isnot", "contains")
				)
			);
			$entry_meta['droplet_private_network'] = array(
				'label'                      => 'Droplet Private Network',
				'is_numeric'                 => false,
				'update_entry_meta_callback' => array($this, 'update_entry_meta'),
				'is_default_column'          => true, // default column on the entry list
				'filter'                     => array(
					'operators' => array("is", "isnot", "contains")
				)
			);
			$entry_meta['droplet_backups'] = array(
				'label'                      => 'DigitalOcean Backups enabled?',
				'is_numeric'                 => false,
				'update_entry_meta_callback' => array($this, 'update_entry_meta'),
				'is_default_column'          => true, // default column on the entry list
				'filter'                     => array(
					'operators' => array("is", "isnot", "contains")
				)
			);

			$entry_meta['droplet_creation_success'] = array(
				'label'                      => 'DigitalOcean Server Response',
				'is_numeric'                 => false,
				'update_entry_meta_callback' => array($this, 'update_entry_meta'),
				'is_default_column'          => true, // default column on the entry list
				'filter'                     => array(
					'operators' => array("is", "isnot", "contains")
				)
			);

			$entry_meta['droplet_id'] = array(
				'label'                      => 'Droplet ID',
				'is_numeric'                 => false,
				'update_entry_meta_callback' => array($this, 'update_entry_meta'),
				'is_default_column'          => true, // default column on the entry list
				'filter'                     => array(
					'operators' => array("is", "isnot", "contains")
				)
			);

			return $entry_meta;
		}

		//Update the meta
		public function update_entry_meta($key, $entry, $form) {
			$form_settings = $this->get_form_settings($form);
			$GFDO = new GFDigitalOcean;
			$GFDO->log_debug( "Entry meta update: " . print_r( $entry, true ) );
			$GFDO->log_debug( "Key meta update: " . print_r( $key, true ) );
			$GFDO->log_debug( "Form meta update: " . print_r( $form, true ) );
			if ($key === "droplet_size") {
				return empty($form_settings["selectDropletSize"]) ? "None chosen" : droplet_size_id_to_size_name($this->get_plugin_settings(),$form_settings["selectDropletSize"]);
			}
			else if ($key === "droplet_id") {
					return empty($form_settings["droplet_id"]) ? "NO RESPONSE" : $form_settings["droplet_id"];
				}
			else if ($key === "droplet_name") {
					return empty($entry[$form_settings["selectDropletName"]]) ? "None chosen" : $entry[$form_settings["selectDropletName"]];
				}
			else if ($key === "droplet_region") {
					return empty($form_settings["selectDropletRegion"]) ? "None chosen" :droplet_region_id_to_region_name($this->get_plugin_settings(),$form_settings["selectDropletRegion"]);
				}
			else if ($key === "droplet_image") {
					return empty($form_settings["selectDropletImage"]) ? "None chosen" : droplet_image_id_to_image_name($this->get_plugin_settings(),$form_settings["selectDropletImage"]);
				}
			else if ($key === "ssh_keys") {
					return empty($entry["ssh_keys"]) ? "None selected" : $entry["ssh_keys"];
				}
			else if ($key === "droplet_private_network") {
					if(empty($form_settings["privateNetworkEnabled"])){
						return "No";
					}
					else if($form_settings["privateNetworkEnabled"] == 1){
							return "Yes";
						}
					else {
						return "No";
					}
				}
			else if ($key === "droplet_backups") {
					if(empty($form_settings["backupsEnabled"])){
						return "No";
					}
					else if($form_settings["backupsEnabled"] == 1){
							return "Yes";
						}
					else {
						return "No";
					}
				}
			else if ($key === "droplet_creation_success") {
					return empty($form_settings["droplet_creation_success"]) ? "NO RESPONSE" : $form_settings["droplet_creation_success"];
				}
		}
	}

	//All done
	new GFDigitalOcean();
}