<?php
include(plugin_dir_path( __FILE__ )."../api/vendor/autoload.php");
use DigitalOceanV2\Adapter\BuzzAdapter;
use DigitalOceanV2\DigitalOceanV2;

//REQUIRED FUNCTIONS FOR THE PLUGIN
function do_domain($DOToken, $id, $siteName){
	if(empty($DOToken)){
		return false;
	}
	$theIp = "";
	$adapter = new BuzzAdapter($DOToken);
	$do = new DigitalOceanV2($adapter);
	$domain = $do->domain();
	$droplet = $do->droplet();
	
	$theSite = $droplet->getById($id);
	$networks = $theSite->networks;
	foreach($networks as $network){
		if($network->type == "public"){
			$theIp = $network->ipAddress;
		}
	}
	$url = preg_replace("(https?://)", "", $siteName );
	return $domain->create($url, $theIp);
}
function get_ssh_keys_as_array($form_settings){
	$only_sshs = array();
	foreach ($form_settings as $key => $value) {
	    if (strpos($key, 'gfdo_ssh_key_') === 0) {
	        $only_sshs[] = substr($key, 13);
	    }
	}
	if(count($only_sshs) > 0){
		return $only_sshs;
	}
	else{
		return false;
	}
}
function return_key_names_and_ids_for_settings_fields($addon_settings){
	$DOToken = $addon_settings["digitalocean_token"];
	if(empty($DOToken)){
		return false;
	}
	$adapter = new BuzzAdapter($DOToken);
	$do = new DigitalOceanV2($adapter);
	$theKey = $do->key();
	$allKeys = $theKey->getAll();
	$keyValues = array();
	foreach($allKeys as $key){
			$keyValues[] = array("label" => trim($key->name), "name" => "gfdo_ssh_key_".trim($key->id));
	}
	return $keyValues;
}
function is_api_alive($addon_settings){

	$DOToken = $addon_settings["digitalocean_token"];
	
	if(empty($DOToken)){
		return false;
	}
	try {
	    $adapter = new BuzzAdapter($DOToken);
	    $do = new DigitalOceanV2($adapter);
	    $image = $do->image();
	    $images = $image->getAll();
	    return true;
	} catch (Exception $e) {
		$GFDO = new GFDigitalOcean;
		$GFDO->log_debug( "Get all images error: " . print_r( $e, true ) );
	    return false;
	}
}
function return_fields_title_and_values_for_settings_fields($form, $fType = "text"){
	$fieldsArr = $form['fields'];
	$titleValues = array();
	foreach($fieldsArr as $field){
		if($field['type'] == $fType){
			$titleValues[] = array("label" => trim($field["label"]), "value" => trim($field["id"]));
		}
	}
	if(empty($titleValues)){
		$titleValues[] = array("label" => "Please create a ".$fType." field on the form.", "value" => "" );
	}
	return $titleValues;
}

function return_droplet_images_for_settings_fields($addon_settings){
	$DOToken = $addon_settings["digitalocean_token"];
	if(empty($DOToken)){
		return false;
	}
	$adapter = new BuzzAdapter($DOToken);
	$do = new DigitalOceanV2($adapter);
	$image = $do->image();
	$images = $image->getAll();

	$theImages = array();
	$theImages[] = array("label" => "Select an image", "value" => "");
	foreach($images as $image){
			($image->public != 1 ? $myImage = "MY IMAGES: " : $myImage = "");
			$theImages[] = array("label" => $myImage.trim($image->name), "value" => trim($image->id));
	}
	return $theImages;
}

function droplet_image_id_to_image_name($addon_settings, $dropletId){
	$DOToken = $addon_settings["digitalocean_token"];
	if(empty($DOToken)){
		return false;
	}
	//Global
	$adapter = new BuzzAdapter($DOToken);
	$do = new DigitalOceanV2($adapter);
	$image = $do->image();
	$images = $image->getAll();
	if(empty($images)){
		return false;
	}
	
	$theImages = array();
	foreach($images as $image){
			$theImages[$image->id] = $image->name;
	}
	if($theImages[$dropletId]){
		return $theImages[$dropletId];
	}
}

function return_droplet_regions_for_settings_fields($addon_settings){
	$DOToken = $addon_settings["digitalocean_token"];
	if(empty($DOToken)){
		return false;
	}
	$adapter = new BuzzAdapter($DOToken);
	$do = new DigitalOceanV2($adapter);
	$region = $do->region();
	$regions = $region->getAll();
	
	if(empty($regions)){
		return false;
	}
	$theRegions = array();
	$theRegions[] = array("label" => "Select the droplet region", "value" => "");
	foreach($regions as $region){
			$theRegions[] = array("label" => trim($region->name), "value" => trim($region->slug));
	}
	return $theRegions;
}

function droplet_region_id_to_region_name($addon_settings, $regionId){
	$DOToken = $addon_settings["digitalocean_token"];
	if(empty($DOToken)){
		return false;
	}
	$adapter = new BuzzAdapter($DOToken);
	$do = new DigitalOceanV2($adapter);
	$region = $do->region();
	$regions = $region->getAll();
	if(empty($regions)){
		return false;
	}
	$theRegions = array();
	foreach($regions as $region){
			$theRegions[$region->slug] = $region->name;
	}
	if($theRegions[$regionId]){
		return $theRegions[$regionId];
	}
}

function return_droplet_sizes_for_settings_fields($addon_settings){
	$DOToken = $addon_settings["digitalocean_token"];
	if(empty($DOToken)){
		return false;
	}
	$adapter = new BuzzAdapter($DOToken);
	$do = new DigitalOceanV2($adapter);
	$size = $do->size();
	$sizes = $size->getAll();
	if(empty($sizes)){
		return false;
	}
	$theSizes = array();
	$theSizes[] = array("label" => "Select a droplet size", "value" => "");
	foreach($sizes as $size){
			$theSizes[] = array("label" => trim($size->slug)." ($".trim($size->priceHourly)." hour, $".trim($size->priceMonthly)." month)", "value" => trim($size->slug));
	}
	return $theSizes;
}
function ssh_ids_to_names($addon_settings, $sshKeys){
	$DOToken = $addon_settings["digitalocean_token"];
	if(empty($DOToken)){
		return false;
	}
	$adapter = new BuzzAdapter($DOToken);
	$do = new DigitalOceanV2($adapter);
	$theKey = $do->key();
	$allKeys = $theKey->getAll();
	$matchKeys = array();
	foreach($allKeys as $key){
			foreach($sshKeys as $refKey){
				if($refKey == $key->id){
					$matchKeys[] = $key->name;
				}
			}
	}
	$matchKeys = implode(', ', $matchKeys);
	return (string)$matchKeys;
}
function droplet_size_id_to_size_name($addon_settings, $sizeId){
	$DOToken = $addon_settings["digitalocean_token"];
	if(empty($DOToken)){
		return false;
	}
	$adapter = new BuzzAdapter($DOToken);
	$do = new DigitalOceanV2($adapter);
	$size = $do->size();
	$sizes = $size->getAll();
	if(empty($sizes)){
		return false;
	}
	$theSizes = array();
	foreach($sizes as $size){
			$theSizes[$size->slug] = trim($size->slug)." ($".trim($size->priceHourly)." hour, $".trim($size->priceMonthly)." month)";
	}
	if($theSizes[$sizeId]){
		return $theSizes[$sizeId];
	}
}

function siteurl(){
    if(isset($_SERVER['HTTPS'])){
        $protocol = ($_SERVER['HTTPS'] && $_SERVER['HTTPS'] != "off") ? "https" : "http";
    }
    else{
        $protocol = 'http';
    }
    return $protocol . "://" . $_SERVER['HTTP_HOST'];
}

function get_all_droplet_names($addon_settings){
	$DOToken = $addon_settings["digitalocean_token"];
	if(empty($DOToken)){
		return false;
	}
	$adapter = new BuzzAdapter($DOToken);
	$do = new DigitalOceanV2($adapter);
	$droplet = $do->droplet();
	$allDroplets = $droplet->getAll();
	if(empty($allDroplets)){
		return false;
	}
	$theDroplets = array();
	foreach($allDroplets as $droplet){
			$theDroplets[] = $droplet->name;
	}
	return $theDroplets;
}
?>