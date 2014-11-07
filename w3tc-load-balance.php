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
		add_action('admin_init', array($this, 'admin_init'));
		if(defined('WP_CLI') && WP_CLI) {
			$this->admin_init();
		}
	}

	function admin_init() {
		// since we are calling is_plugin_active() from a non admin area
		@include_once(ABSPATH.'wp-admin/includes/plugin.php');
		if(function_exists('is_plugin_active')) {
			// is w3tc activated?
			if(is_plugin_active('w3-total-cache/w3-total-cache.php')) {
				// load the w3tc config
				if(is_null($this->w3tc_options)) $this->w3tc_options = @include W3TC_CONFIG_DIR . '/master.php';
				// is the cdn enabled?
				if(!empty($this->w3tc_options) && array_key_exists('cdn.enabled',$this->w3tc_options) && $this->w3tc_options['cdn.enabled'])
				{
					// rewrite the URL for attachments in the admin
					add_filter('upload_dir', array($this, 'upload_dir'));
					
					// This filter downloads the image to our local temporary directory, prior to editing the image.
					add_filter('load_image_to_edit_path', array($this, 'load_image_to_edit_path'));

					if (defined('DOING_AJAX') && DOING_AJAX && 'image-editor' == (empty($_POST['action']) ? '' : $_POST['action'])) {
						add_filter('get_attached_file', array($this, 'get_attached_file'), 2, 2);
					}

					//add_filter('wp_prepare_attachment_for_js', array($this, 'wp_prepare_attachment_for_js'), 3, 3);
					
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
		if(defined('W3TCLB_DEBUG') && W3TCLB_DEBUG) {
			$ofn = defined('W3TCLB_DEBUG_LOG') ? W3TCLB_DEBUG_LOG : sys_get_temp_dir() . 'w3tclb-debug.log';
			$fh = fopen($ofn, 'a');
			$msg = date("Y-m-d H:i:s") . "\t" . trim($msg);
			fwrite($fh, $msg . "\n");
			fclose($fh);
			
			trigger_error($msg, E_USER_NOTICE);
		}
	}

	function url_normalizer($url) {
		if (strpos($url, '%') !== false) return $url;
		$url = explode('/', $url);
		foreach ($url as $key => $val) $url[$key] = urlencode($val);
		return str_replace('%3A', ':', join('/', $url));
	}
	
	function get_attached_file($file, $attachment_id) {
		$this->debug("get_attached_file($file, $attachment_id)");
		$this->debug("-> \$file = $file");
		$this->debug("-> \$attachment_id = $attachment_id");
		
		if(!file_exists($file)) {
			$file = get_post_meta($attachment_id, '_wp_attached_file', true);
			$this->debug("-> ATTACHED: $file");
			$url = $this->get_base_url() . '/' .$file;
			$this->debug("-> URL: $url");
			$file = $this->load_image_to_edit_path($url);
			$this->debug("-> LOCAL: $file");
		}
		
		return $file;
	}
	
	function get_base_url() {

		$site_url = parse_url(get_bloginfo('url'));
		$this->debug("get_base_url -> " . print_r($site_url, true));
		if(!isset($site_url['path'])) $site_url['path'] = "";
		switch($this->w3tc_options['cdn.engine']) {
			case 's3': {
				return 'http://' . $this->w3tc_options['cdn.s3.bucket'] . '.s3.amazonaws.com' . $site_url['path'] . '/wp-content/uploads';				
			} break;
			case 'cf': {
				return 'http://' . $this->w3tc_options['cdn.cf.bucket'] . '.s3.amazonaws.com' . $site_url['path'] . '/wp-content/uploads';				
			}
		}
	}
	
	function upload_dir($data) {
		$this->debug("upload_dir()");
		$data['baseurl'] = $this->get_base_url();
		
		if(defined('W3TCLB_DEBUG') && W3TCLB_DEBUG) {
			foreach($data as $k=>$v) {
				$this->debug("-> \$data[$k] = $v");
			}
		}
		
		return $data;
	}
	
	function load_image_to_edit_path($filepath) {
		$this->debug("load_image_to_edit_path($filepath)");
		
		if($this->is_external($filepath)) {
			
			$url = parse_url($filepath); // FIX: redundant... *sigh*
			
			if(defined('W3TCLB_DEBUG') && W3TCLB_DEBUG) {
				foreach($url as $k=>$v) {
					$this->debug("-> \$url[$k] = $v");
				}
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