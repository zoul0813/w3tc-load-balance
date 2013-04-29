<?php
/**
Plugin Name: W3 Total Cache - Load Balance Extension
Plugin URI: http://www.dphcoders.com/
Description: Adds the ability to load balance a w3tc installation with CDN
Version: 1.0
Author: David Higgins <higginsd@zoulcreations.com>
Author URI: http://www.dphcoders.com
License: GPL2
 */

if(!class_exists('WP_Http'))
	include_once(ABSPATH . WPINC . '/class-http.php');

new W3TC_LoadBalance;

class W3TC_LoadBalance {
	var $w3tc_options;
	var $tempdir = '/tmp/'; // TODO: needs override
	function __construct() {
		// since we are calling is_plugin_active() from a non admin area
		add_action('admin_init', array($this, 'admin_init'));
	}

	function admin_init() {
		// is w3tc activated?
		@include_once(ABSPATH.'wp-admin/includes/plugin.php');
		if(function_exists('is_plugin_active')) {
			if(is_plugin_active('w3-total-cache/w3-total-cache.php')) {
				// load the w3tc config
				if(is_null($this->w3tc_options)) $this->w3tc_options = @include W3TC_CONFIG_DIR . '/master.php';
				// is the cdn enabled?
				if(!empty($this->w3tc_options) && array_key_exists('cdn.enabled',$this->w3tc_options) && $this->w3tc_options['cdn.enabled'])
				{
					// rewrite the URL for attachments in the admin
					if(!defined('DOING_AJAX') || !DOING_AJAX) 
						add_filter('upload_dir', array($this, 'upload_dir'));
					
					// This filter downloads the image to our local temporary directory, prior to editing the image.
					add_filter('load_image_to_edit_path', array($this, 'load_image_to_edit_path'));
					
					// TODO: intercept image save, and overwrite old image file so all references point to edit???
					// when the image is rotated, flipped, cropped, etc a new file is created Image-e32423423432.png
					// this means all existing references to Image.png are unaffected, only new references to it will
					// use the changes you applied -- wtf much???
				}
			}
		}	
	}
	
	private function is_external($filepath) {
		$urlParse = parse_url($filepath);
		if(!is_null($urlParse) && array_key_exists('host', $urlParse)) {
			$urlHost = strtolower($urlParse['host']);
			$this->debug("is_external($filepath): host = $urlHost");
			if($urlHost != '') {
				$this->debug("is_external($filepath) == true");
				return true;
			} 
		} else {
			$this->debug("is_external($filepath) == false");
			return false;
		}
	}
	
	function debug($msg) {
		$fh = fopen('/tmp/w3tclb-debug.log', 'a');
		fwrite($fh, date("Y-m-d H:i:s") . "\t" . trim($msg) . "\n");
		fclose($fh);
	}

	function url_normalizer($url) {
		if (strpos($url, '%') !== false) return $url;
		$url = explode('/', $url);
		foreach ($url as $key => $val) $url[$key] = urlencode($val);
		return str_replace('%3A', ':', join('/', $url));
	}
	
	function upload_dir($data) {
		$this->debug("upload_dir()");

		$w3_cdn = new W3_Plugin_Cdn();
		
		$data['baseurl'] = 'http://' . $this->w3tc_options['cdn.s3.bucket'] . '.s3.amazonaws.com/wp-content/uploads';
		
		foreach($data as $k=>$v) {
			$this->debug("-> \$data[$k] = $v");
		}
		
		return $data;
	}
	
	function load_image_to_edit_path($filepath) {
		$this->debug("load_image_to_edit_path($filepath)");
		
		if($this->is_external($filepath)) {
			
			$url = parse_url($filepath); // FIX: redundant... *sigh*
			
			// DEBUG
			foreach($url as $k=>$v) {
				$this->debug("-> \$url[$k] = $v");
			}
			$downloadPath = '';
			if(array_key_exists('path', $url)) {
				$filename = basename($url['path']);
				$path = dirname(ABSPATH . substr($url['path'], 1));
				
				$this->debug('-> basename: ' . $path);
				$this->debug('-> filename: ' . $filename);
				
				if(!file_exists($path)) 
					mkdir($path, 0775, true);
				$downloadPath = $path . '/' . $filename;
			}

			$this->debug('-> Loading file from: ' . $filepath);
			$this->debug('-> Storing file at: ' . $downloadPath);

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $filepath);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_AUTOREFERER, true);

			$fh = fopen($downloadPath, 'w');
			fwrite($fh, curl_exec($ch));
			fclose($fh);

			return $downloadPath;

		} 
		
		return $filepath;
	}
}