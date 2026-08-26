<?php

	/**
	 * @link              https://wsform.com/knowledgebase/amazon-s3/
	 * @since             1.0.0
	 * @package           WS_Form_Amazon_S3_V3
	 *
	 * @wordpress-plugin
	 * Plugin Name:       WS Form PRO - Amazon S3
	 * Plugin URI:        https://wsform.com/knowledgebase/amazon-s3/
	 * Description:       Amazon S3 add-on for WS Form PRO
	 * Version:           1.0.3
	 * Requires at least: 5.4
	 * Requires PHP:      5.6
	 * License:           GPLv3 or later
	 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
	 * Author:            WS Form
	 * Author URI:        https://wsform.com
	 * Text Domain:       ws-form-amazon-s3-v3
	 */

	Class WS_Form_Add_On_Amazon_S3_V3 {

		const WS_FORM_PRO_ID 			= 'ws-form-pro/ws-form.php';
		const WS_FORM_PRO_VERSION_MIN 	= '1.8.138';

		function __construct() {

			// Load plugin.php
			if(!function_exists('is_plugin_active')) {

				include_once(ABSPATH . 'wp-admin/includes/plugin.php');
			}

			// Admin init
			add_action('plugins_loaded', array($this, 'plugins_loaded'), 20);
		}

		function plugins_loaded() {

			if(self::is_dependency_ok()) {

				new WS_Form_File_Handler_Amazon_S3_V3();

			} else {

				self::dependency_error();

				if(isset($_GET['activate'])) { unset($_GET['activate']); }
			}
		}

		function activate() {

			if (!self::is_dependency_ok()) {

				self::dependency_error();
			}
		}

		// Check dependencies
		function is_dependency_ok() {

			if(!defined('WS_FORM_VERSION')) { return false; }

			return(

				is_plugin_active(self::WS_FORM_PRO_ID) &&
				(version_compare(WS_FORM_VERSION, self::WS_FORM_PRO_VERSION_MIN) >= 0)
			);
		}

		// Add error notice action - Pro
		function dependency_error() {

			// Show error notification
			add_action('after_plugin_row_' . plugin_basename(__FILE__), array($this, 'dependency_error_notification'), 10, 2);
		}

		// Dependency error - Notification
		function dependency_error_notification($file, $plugin) {

			// Checks
			if(!current_user_can('update_plugins')) { return; }
			if($file != plugin_basename(__FILE__)) { return; }

			// Build notice
			$dependency_notice = sprintf('<tr class="plugin-update-tr"><td colspan="3" class="plugin-update colspanchange"><div class="update-message notice inline notice-error notice-alt"><p>%s</p></div></td></tr>', sprintf(__('This add-on requires %s (version %s or later) to be installed and activated.', 'ws-form-amazon-s3-v3'), '<a href="https://wsform.com?utm_source=ws_form_pro&utm_medium=plugins" target="_blank">WS Form PRO</a>', self::WS_FORM_PRO_VERSION_MIN));

			// Show notice
			echo $dependency_notice;
		}
	}

	$wsf_file_handler_amazon_s3_v3 = new WS_Form_Add_On_Amazon_S3_V3();	

	register_activation_hook(__FILE__, array($wsf_file_handler_amazon_s3_v3, 'activate'));

	// This gets fired by WS Form when it is ready to register add-ons
	add_action('wsf_plugins_loaded', function() {

		class WS_Form_File_Handler_Amazon_S3_V3 extends WS_Form_File_Handler {

			public $id = 'amazons3v3';
			public $pro_required = false;
			public $label;
			public $public = false;

			// Licensing
			private $licensing;

			// Config
			private $configured = false;
			private $api_access_key = false;
			private $api_secret_key = false;
			private $client = false;

			// Constants
			const WS_FORM_LICENSE_ITEM_ID = 78832;
			const WS_FORM_LICENSE_NAME = 'Amazon S3 add-on for WS Form PRO';
			const WS_FORM_LICENSE_VERSION = '1.0.3';
			const WS_FORM_LICENSE_AUTHOR = 'WS Form';

			const DEFAULT_REGION = 'us-west-2';
			const DEFAULT_UPLOAD_PATH = 'WS Form/#form_id/#submit_hash/#field_id/';
			const SELF_SIGNED_REQUEST_DURATION = '+30 minutes';

			public function __construct() {

				// Register config filters
				add_filter('wsf_config_options', array($this, 'config_options'), 10, 1);
				add_filter('wsf_config_meta_keys', array($this, 'config_meta_keys'), 10, 2);
				add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'), 10, 1);
				add_action('rest_api_init', array($this, 'rest_api_init'));
				add_filter('wsf_settings_static', array($this, 'settings_static'), 10, 2);
				add_filter('wsf_settings_button', array($this, 'settings_button'), 10, 3);
				add_filter('wsf_settings_update_fields', array($this, 'settings_update_fields'), 10, 2);

				// Add nag action
				add_action('wsf_nag', array($this, 'nag'));

				// Licensing
				$this->licensing = new WS_Form_Licensing(

					self::WS_FORM_LICENSE_ITEM_ID,
					$this->id,
					self::WS_FORM_LICENSE_NAME,
					self::WS_FORM_LICENSE_VERSION,
					self::WS_FORM_LICENSE_AUTHOR,
					__FILE__
				);
				$this->licensing->transient_check();
				add_action('admin_init', array($this->licensing, 'updater'));

				// Load plugin level configuration
				self::load_config_plugin();

				// Create intial file handler
				add_filter('wsf_file_handler_' . $this->id, array($this, 'handler'), 10, 5);

				// Register init action
				add_action('init', array($this, 'init'));
			}

			public function init() {

				// Set label
				$this->label = __('Amazon S3', 'ws-form');

				// Register file handler
				parent::register($this);
			}

			// Amazon S3 SDK loader
			public function sdk_load($region = self::DEFAULT_REGION) {

				if($this->client !== false) { return true; }

				require __DIR__ . '/composer/vendor/autoload.php';

				// Create credentials
				$credentials = new Aws\Credentials\Credentials($this->api_access_key, $this->api_secret_key);

				// Create an S3Client
				$this->client = new Aws\S3\S3Client([

					'version' => 'latest',
					'region' => $region,
					'credentials' => $credentials
				]);
			}

			// Handler
			public function handler($file_objects, $submit, $field, $section_repeatable_index) {

				// Check form ID
				WS_Form_Common::check_form_id($submit->form_id);

				// Field ID
				$field_id = intval($field->id);
				if($field_id == 0) { parent::db_throw_error(__('Invalid field ID', 'ws-form')); }

				foreach($file_objects as $file_object_index => $file_object) {

					// Get file name
					if(!isset($file_object['name'])) { parent::db_throw_error(__('File name not found in file object', 'ws-form')); }
					$file_name = $file_object['name'];

					// Get file type
					if(!isset($file_object['type'])) { parent::db_throw_error(__('File type not found in file object', 'ws-form')); }
					$file_type = $file_object['type'];

					// Get file path
					if(!isset($file_object['path'])) { parent::db_throw_error(__('File source path not found in file object', 'ws-form')); }
					$file_path = $file_object['path'];

					// Get file size
					if(!isset($file_object['size'])) { parent::db_throw_error(__('File size not found in file object', 'ws-form')); }
					$file_size = $file_object['size'];

					// Get form
					$form = isset($submit->form_object) ? $submit->form_object : false;

					// Get region
					$region = WS_Form_Common::get_object_meta_value($field, 'file_handler_' . $this->id . '_region', self::DEFAULT_REGION);

					// Get bucket
					$bucket = WS_Form_Common::get_object_meta_value($field, 'file_handler_' . $this->id . '_bucket', '');

					// Get path
					$upload_path = WS_Form_Common::get_object_meta_value($field, 'file_handler_' . $this->id . '_upload_path', self::DEFAULT_UPLOAD_PATH);

					// Get file link type setting
					$file_link_type = WS_Form_Common::get_object_meta_value($field, 'file_handler_' . $this->id . '_file_link_type', 'protected');

					// Get presigned expiry duration setting (in minutes)
					$presigned_expiry_minutes = absint(WS_Form_Common::get_object_meta_value($field, 'file_handler_' . $this->id . '_presigned_expiry', 30));
					
					// Sanitize: bound between 1 and 10080 (7 days), default to 30 if 0
					if($presigned_expiry_minutes <= 0 || $presigned_expiry_minutes > 10080) {
						$presigned_expiry_minutes = 30;
					}
					
					// Convert minutes to strtotime format
					$presigned_expiry = sprintf(

						'+%u minutes',
						$presigned_expiry_minutes
					);

					// Get apply public ACL setting (only needed for public links)
					$apply_public_acl = ($file_link_type === 'public') && (WS_Form_Common::get_object_meta_value($field, 'file_handler_' . $this->id . '_apply_public_acl', '') == 'on');

					// Parse with additional parameters
					$variables = array(

						'form_id' => $submit->form_id,
						'submit_hash' => $submit->hash,
						'field_id' => $field->id
					);
					$upload_path = WS_Form_Common::mask_parse($upload_path, $variables);

					// Regular parse
					if(strpos($bucket, '#') !== false) {

						$bucket = WS_Form_Common::parse_variables_process($bucket, $form, $submit, 'text/plain');
					}
					if(strpos($upload_path, '#') !== false) {

						$upload_path = WS_Form_Common::parse_variables_process($upload_path, $form, $submit, 'text/plain');
					}

					// Check Amazon S3 upload path
					if(substr($upload_path, -1) !== '/') { $upload_path .= '/'; }

					// Build key
					$key = sprintf('%s%s', $upload_path, $file_name);

					// Initialize Amazon S3 SDK
					self::sdk_load($region);

					// Get file data
					$source = fopen($file_path, 'rb');

					// Build object uploader with optional public ACL
					$uploader = new Aws\S3\ObjectUploader(

						$this->client,
						$bucket,
						$key,
						$source,
						$apply_public_acl ? 'public-read' : 'private'
					);

					do {

						try {

							$result = $uploader->upload();

						} catch (Aws\S3\Exception\S3Exception $e) {

							// Get error message
							$error_message = empty($e->getAwsErrorMessage()) ? $e->getMessage() : $e->getAwsErrorMessage();

							// Handle S3 exception
							throw new Exception($error_message);

						} catch (Aws\S3\Exception\MultipartUploadException $e) {

							// Handle multipart upload exception
							rewind($source);

							$uploader = new MultipartUploader($this->client, $source, [

								'state' => $e->getState(),
							]);
						}

					} while (!isset($result));

					// Close source
					fclose($source);

					// Check result
					if ($result["@metadata"]["statusCode"] == '200') {

						$file_objects[$file_object_index]['amazon_s3_region'] = $region;
						$file_objects[$file_object_index]['amazon_s3_bucket'] = $bucket;
						$file_objects[$file_object_index]['amazon_s3_key'] = $key;
						$file_objects[$file_object_index]['amazon_s3_file_link_type'] = $file_link_type;
						$file_objects[$file_object_index]['amazon_s3_presigned_expiry'] = $presigned_expiry;

						if(isset($result['ObjectURL'])) {

							$file_objects[$file_object_index]['amazon_s3_object_url'] = $result['ObjectURL'];
						}

						if(isset($result['ETag'])) {

							$file_objects[$file_object_index]['amazon_s3_e_tag'] = $result['ETag'];
						}
					}
				}

				self::touch($file_objects);

				return $file_objects;
			}

			// Generate presigned URL
			private function generate_presigned_url($region, $bucket, $key, $expiry = false) {

				if($expiry === false) {
					$expiry = self::SELF_SIGNED_REQUEST_DURATION;
				}

				self::sdk_load($region);

				try {

					$params = array(
						'Bucket' => $bucket,
						'Key' => $key,
					);

					$result = $this->client->getCommand('GetObject', $params);
					$request = $this->client->createPresignedRequest($result, $expiry);
					$url = (string) $request->getUri();

					return $url;

				} catch (Aws\S3\Exception\S3Exception $e) {

					return false;
				}
			}

			// Get URL
			public function get_url($file_object, $field_id = 0, $file_object_index = 0, $submit_hash = '') {

				// Ensure this file object belongs to this file handler
				if(!isset($file_object['handler']) || ($file_object['handler'] != $this->id)) { return false; }

				// Check amazon_s3_region
				if(!isset($file_object['amazon_s3_region']) || ($file_object['amazon_s3_region'] == '')) { return false; }
				$region = $file_object['amazon_s3_region'];
				if($region == '') { return false; }

				// Check amazon_s3_bucket
				if(!isset($file_object['amazon_s3_bucket']) || ($file_object['amazon_s3_bucket'] == '')) { return false; }
				$bucket = $file_object['amazon_s3_bucket'];
				if($bucket == '') { return false; }

				// Check amazon_s3_key
				if(!isset($file_object['amazon_s3_key']) || ($file_object['amazon_s3_key'] == '')) { return false; }
				$key = $file_object['amazon_s3_key'];
				if($key == '') { return false; }

				// Get file type
				if(!isset($file_object['type'])) { parent::db_throw_error(__('File type not found in file object', 'ws-form')); }
				$type = $file_object['type'];

				// Get file link type
				$file_link_type = isset($file_object['amazon_s3_file_link_type']) ? $file_object['amazon_s3_file_link_type'] : 'protected';

				// Get presigned expiry
				$presigned_expiry = isset($file_object['amazon_s3_presigned_expiry']) ? $file_object['amazon_s3_presigned_expiry'] : self::SELF_SIGNED_REQUEST_DURATION;

				// Route based on file link type
				switch($file_link_type) {

					case 'public':
						// Public - Return direct S3 URL
						if(isset($file_object['amazon_s3_object_url'])) {
							return sanitize_url($file_object['amazon_s3_object_url']);
						}
						// Fall through to presigned if object URL not available
						
					case 'presigned':
						// Presigned S3 - Generate S3 presigned URL directly
						$url = self::generate_presigned_url($region, $bucket, $key, $presigned_expiry);
						return $url ? sanitize_url($url) : false;

					case 'protected':
					default:
						// Protected - Return REST presigned URL with protection flag
						$params = array(
							'region' => $region,
							'bucket' => $bucket,
							'key' => $key,
							'type' => $type,
							'protected' => '1',
							'_wpnonce' => wp_create_nonce('wp_rest')
						);

						// Add presigned expiry if available
						if($presigned_expiry !== self::SELF_SIGNED_REQUEST_DURATION) {
							$params['expiry'] = $presigned_expiry;
						}

						// Return presigned URL (protected)
						$url = WS_Form_Common::get_api_path('action/' . $this->id . '/get-presigned-request/', http_build_query($params));
						return $url;
				}

				return false;
			}

			// Get presigned request
			public function api_get_presigned_request($parameters) {

				// Region
				$region = WS_Form_Common::get_query_var_nonce('region', self::DEFAULT_REGION, $parameters);
				if($region == '') { return self::api_get_presigned_request_error(__('Region not specified', 'ws-form-amazon-s3-v3')); }

				// Check region is valid
				$regions = self::get_regions();
				if(!isset($regions[$region])) { return self::api_get_presigned_request_error(__('Invalid region', 'ws-form-amazon-s3-v3')); }

				// Bucket
				$bucket = WS_Form_Common::get_query_var_nonce('bucket', '', $parameters);
				if($bucket == '') { return self::api_get_presigned_request_error(__('Bucket not specified', 'ws-form-amazon-s3-v3')); }

				// Key
				$key = WS_Form_Common::get_query_var_nonce('key', '', $parameters);
				if($key == '') { return self::api_get_presigned_request_error(__('Key not specified', 'ws-form-amazon-s3-v3')); }

				// Type
				$type = WS_Form_Common::get_query_var_nonce('type', '', $parameters);
				if($type == '') { return self::api_get_presigned_request_error(__('Type not specified', 'ws-form-amazon-s3-v3')); }

				// Download
				$download = (WS_Form_Common::get_query_var_nonce('download', '', $parameters) != '');

				// Expiry duration (for presigned S3 URLs)
				$expiry = WS_Form_Common::get_query_var_nonce('expiry', self::SELF_SIGNED_REQUEST_DURATION, $parameters);

				// Generate presigned URL
				$url = self::generate_presigned_url($region, $bucket, $key, $expiry);

				if($url === false) {
					return self::api_get_presigned_request_error(__('Unable to retrieve presigned request URL', 'ws-form-amazon-s3-v3'));
				}

				// Redirect to URL
				header(sprintf('Location: %s', $url));
				exit;
			}

			// Get presigned request - Error
			public function api_get_presigned_request_error($error_message) {

				return array('error' => true, 'error_message' => $error_message);
			}

			// Get value for parse variables
			public function get_value_parse_variable($file_object, $field_id = 0, $file_object_index = 0, $submit_hash = '', $file_links = false, $file_embed = false, $content_type = 'text/html') {

				// Read file data
				$file_name = $file_object['name'];
				$file_size = WS_Form_Common::get_file_size($file_object['size']);
				$file_type = $file_object['type'];
				$file_path = $file_object['path'];

				$file_data = false;

				// File links?
				if($file_links) {

					$file_url = self::get_url($file_object, $field_id, $file_object_index, $submit_hash);

					return sprintf('<a href="%s" target="_blank">%s</a> (%s)', $file_url, $file_name, $file_size);

				} else {

					return sprintf('%s (%s)', $file_name, $file_size);
				}
			}

			// Copy to file
			public function copy_to_temp_file($file_object, $temp_path = false) {

				// Ensure this file object belongs to this file handler
				if(!isset($file_object['handler']) || ($file_object['handler'] != $this->id)) { return false; }

				// Check path
				if(!isset($file_object['path']) || ($file_object['path'] == '')) { return false; }
				$path = $file_object['path'];

				// Check name
				if(!isset($file_object['name']) || ($file_object['name'] == '')) { return false; }
				$name = $file_object['name'];
				if(in_array($name, array( '.', '/', '\\'))) { return false; }

				// Check amazon_s3_region
				if(!isset($file_object['amazon_s3_region']) || ($file_object['amazon_s3_region'] == '')) { return false; }
				$region = $file_object['amazon_s3_region'];
				if($region == '') { return false; }

				// Check amazon_s3_bucket
				if(!isset($file_object['amazon_s3_bucket']) || ($file_object['amazon_s3_bucket'] == '')) { return false; }
				$bucket = $file_object['amazon_s3_bucket'];
				if($bucket == '') { return false; }

				// Check amazon_s3_key
				if(!isset($file_object['amazon_s3_key']) || ($file_object['amazon_s3_key'] == '')) { return false; }
				$key = $file_object['amazon_s3_key'];
				if($key == '') { return false; }

				// Get file path to copy to
				require_once(ABSPATH . 'wp-admin/includes/file.php');

				if($temp_path === false) {

					$file_path_copy_to = wp_tempnam();

				} else {

					if(!file_exists($temp_path)) {

						mkdir($temp_path);
					}

					if(!isset($file_object['name']) || ($file_object['name'] == '')) { return false; }

					$file_path_copy_to = $temp_path . '/' . $file_object['name'];
				}

				// Initialize Amazon S3 SDK
				self::sdk_load($region);

				// Create temporary file
				try {

					// Save object to a file
					$result = $this->client->getObject(array(

						'Bucket' => $bucket,
						'Key' => $key,
						'SaveAs' => $file_path_copy_to
					));

					return $file_path_copy_to;

				} catch (Aws\S3\Exception\S3Exception $e) {

					return false;
				}
			}

			// Get temp file
			public function get_temp_file($file_object, $temp_path) {

				$copy_to_temp_file_return = self::copy_to_temp_file($file_object, $temp_path);
				if($copy_to_temp_file_return === false) { return false; }

				return array(

					'path' 				=> $copy_to_temp_file_return,
					'unlink_after_use' 	=> true
				);
			}			

			// Delete file
			public function delete($file_object) {

				// Ensure this file object belongs to this file handler
				if(!isset($file_object['handler']) || ($file_object['handler'] != $this->id)) { return false; }

				// Check amazon_s3_region
				if(!isset($file_object['amazon_s3_region']) || ($file_object['amazon_s3_region'] == '')) { return false; }
				$region = $file_object['amazon_s3_region'];
				if($region == '') { return false; }

				// Check amazon_s3_bucket
				if(!isset($file_object['amazon_s3_bucket']) || ($file_object['amazon_s3_bucket'] == '')) { return false; }
				$bucket = $file_object['amazon_s3_bucket'];
				if($bucket == '') { return false; }

				// Check amazon_s3_key
				if(!isset($file_object['amazon_s3_key']) || ($file_object['amazon_s3_key'] == '')) { return false; }
				$key = $file_object['amazon_s3_key'];
				if($key == '') { return false; }

				// Initialize Amazon S3 SDK
				self::sdk_load($region);

				// Create temporary file
				try {

					// Save object to a file
					$result = $this->client->deleteObject(array(

						'Bucket' => $bucket,
						'Key' => $key
					));

					return true;

				} catch (Aws\S3\Exception\S3Exception $e) {

					return false;
				}

				return true;
			}

			// Get license item ID
			public function get_license_item_id() {

				return self::WS_FORM_LICENSE_ITEM_ID;
			}

			// Plugin action link
			public function plugin_action_links($links) {

				// Settings
				array_unshift($links, sprintf('<a href="%s">%s</a>', WS_Form_Common::get_admin_url('ws-form-settings', false, 'tab=action_' . $this->id), __('Settings', 'ws-form-amazon-s3-v3')));

				return $links;
			}

			// Settings - Static
			public function settings_static($value, $field) {

				switch ($field) {

					case 'action_' . $this->id . '_license_version' :

						$value = self::WS_FORM_LICENSE_VERSION;
						break;

					case 'action_' . $this->id . '_license_status' :

						$value = $this->licensing->license_status();
						break;

					case 'action_' . $this->id . '_api_access_key' :

						$value = str_repeat('*', strlen(WSF_AMAZON_S3_ACCESS_KEY));
						break;

					case 'action_' . $this->id . '_api_secret_key' :

						$value = str_repeat('*', strlen(WSF_AMAZON_S3_SECRET_KEY));
						break;
				}

				return $value;
			}

			// Settings - Button
			public function settings_button($value, $field, $button) {

				switch($button) {

					case 'license_action_' . $this->id :

						$license_activated = WS_Form_Common::option_get('action_' . $this->id . '_license_activated', false);
						if($license_activated) {

							$value = '<input class="wsf-button" type="button" data-action="wsf-mode-submit" data-mode="deactivate" value="' . __('Deactivate', 'ws-form-amazon-s3-v3') . '" />';

						} else {

							$value = '<input class="wsf-button" type="button" data-action="wsf-mode-submit" data-mode="activate" value="' . __('Activate', 'ws-form-amazon-s3-v3') . '" />';
						}

						break;
				}
				
				return $value;
			}

			// Settings - Update fields
			public function settings_update_fields($field, $value) {

				switch ($field) {

					case 'action_' . $this->id . '_license_key' :

						$mode = WS_Form_Common::get_query_var('action_mode');

						switch($mode) {

							case 'activate' :

								$this->licensing->activate($value);
								break;

							case 'deactivate' :

								$this->licensing->deactivate($value);
								break;
						}

						break;
				}
			}

			// Nag
			public function nag() {

				// Load plugin level configuration
				self::load_config_plugin();

				if(!$this->configured) {

					WS_Form_Common::admin_message_push(sprintf(__('To complete the %s setup for %s, please connect your Amazon S3 account <a href="%s">here</a>.', 'ws-form-amazon-s3-v3'), $this->label, WS_FORM_NAME_PRESENTABLE, WS_Form_Common::get_admin_url('ws-form-settings', -1, 'tab=action_' . $this->id)), 'notice-warning', false);
				}
			}

			// Get API error detail
			public function get_api_error_detail($response) {

				$api_response_decoded = json_decode($response);
				if(is_null($api_response_decoded)) { 

					if(is_string($response)) {

						return $response;

					} else {

						return __('Invalid API response data', 'ws-form-amazon-s3-v3');
					}
				}

				return $api_response_decoded->error_summary;
			}

			// Get settings
			public function get_file_handler_settings() {

				$settings = array(

					'meta_keys'		=> array(

						'file_handler_' . $this->id . '_region',
						'file_handler_' . $this->id . '_bucket',
						'file_handler_' . $this->id . '_upload_path',
						'file_handler_' . $this->id . '_file_link_type',
						'file_handler_' . $this->id . '_presigned_expiry',
						'file_handler_' . $this->id . '_apply_public_acl'
					)
				);

				return $settings;
			}

			// Meta keys for this action
			public function config_meta_keys($meta_keys = array(), $form_id = 0) {

				// Build config_meta_keys
				$config_meta_keys = array(

					// Region
					'file_handler_' . $this->id . '_region'	=> array(

						'label'			=>	__('Region', 'ws-form-amazon-s3-v3'),
						'type'			=>	'select',
						'help'			=>	__('Enter the Amazon S3 region your bucket resides in.', 'ws-form-amazon-s3-v3'),
						'default'		=>	self::DEFAULT_REGION,
						'options'		=>	array(),
						'condition'		=>	array(

							array(

								'logic'			=>	'==',
								'meta_key'		=>	'file_handler',
								'meta_value'	=>	$this->id
							)
						)
					),

					// Bucket
					'file_handler_' . $this->id . '_bucket'	=> array(

						'label'			=>	__('Bucket', 'ws-form-amazon-s3-v3'),
						'type'			=>	'text',
						'help'			=>	__('Enter the Amazon S3 bucket to upload the file(s) to.', 'ws-form-amazon-s3-v3'),
						'condition'		=>	array(

							array(

								'logic'			=>	'==',
								'meta_key'		=>	'file_handler',
								'meta_value'	=>	$this->id
							)
						),
						'select_list'	=>	true
					),

					// Upload path
					'file_handler_' . $this->id . '_upload_path'	=> array(

						'label'			=>	__('Upload Path', 'ws-form-amazon-s3-v3'),
						'type'			=>	'text',
						'help'			=>	__('Enter the Amazon S3 path to upload the file(s) to.', 'ws-form-amazon-s3-v3'),
						'default'		=>	self::DEFAULT_UPLOAD_PATH,
						'placeholder'	=>	'/',
						'condition'		=>	array(

							array(

								'logic'			=>	'==',
								'meta_key'		=>	'file_handler',
								'meta_value'	=>	$this->id
							)
						),
						'select_list'	=>	true
					),

					// File link type
					'file_handler_' . $this->id . '_file_link_type'	=> array(

						'label'			=>	__('File Link Type', 'ws-form-amazon-s3-v3'),
						'type'			=>	'select',
						'help'			=>	__('Public: Direct S3 URLs, anyone can access.<br> Presigned: S3-signed URLs that expire.<br>Presigned + Protected: WS Form login also required.', 'ws-form-amazon-s3-v3'),
						'default'		=>	'protected',
						'options'		=>	array(
							array('value' => 'public', 'text' => __('Public', 'ws-form-amazon-s3-v3')),
							array('value' => 'presigned', 'text' => __('Presigned', 'ws-form-amazon-s3-v3')),
							array('value' => 'protected', 'text' => __('Presigned + Protected', 'ws-form-amazon-s3-v3'))
						),
						'condition'		=>	array(

							array(

								'logic'			=>	'==',
								'meta_key'		=>	'file_handler',
								'meta_value'	=>	$this->id
							)
						)
					),

					// Presigned S3 expiry duration
					'file_handler_' . $this->id . '_presigned_expiry'	=> array(

						'label'			=>	__('Presigned Expiry (minutes)', 'ws-form-amazon-s3-v3'),
						'type'			=>	'number',
						'help'			=>	__('Duration in minutes for Presigned S3 URLs to remain valid. Minimum: 1, Maximum: 10080 (7 days). Leave blank for default (30 minutes).', 'ws-form-amazon-s3-v3'),
						'default'		=>	'',
						'placeholder'	=>	'30',
						'min'			=>	0,
						'max'			=>	10080,
						'condition'		=>	array(

							array(

								'logic'			=>	'==',
								'meta_key'		=>	'file_handler',
								'meta_value'	=>	$this->id
							),
							array(

								'logic'			=>	'!=',
								'meta_key'		=>	'file_handler_' . $this->id . '_file_link_type',
								'meta_value'	=>	'public'
							)
						)
					),

					// Upload files as public
					'file_handler_' . $this->id . '_apply_public_acl'	=> array(

						'label'			=>	__('Upload Files as Public', 'ws-form-amazon-s3-v3'),
						'type'			=>	'checkbox',
						'help'			=>	__('If enabled, uploaded files will have public-read ACL applied.', 'ws-form-amazon-s3-v3'),
						'default'		=>	false,
						'condition'		=>	array(

							array(

								'logic'			=>	'==',
								'meta_key'		=>	'file_handler',
								'meta_value'	=>	$this->id
							)
						)
					)
				);

				// Assign regions
				$regions = self::get_regions();
				foreach($regions as $region_id => $region_name) {

					$config_meta_keys['file_handler_' . $this->id . '_region']['options'][] = array(

						'value' => $region_id,
						'text' => sprintf('%s - %s', $region_id, $region_name)
					);
				}

				// Merge
				$meta_keys = array_merge($meta_keys, $config_meta_keys);

				return $meta_keys;
			}

			// Plug-in options for this action
			public function config_options($options) {

				$options['action_' . $this->id] = array(

					'label'		=>	$this->label,
					'fields'	=>	array(

						'action_' . $this->id . '_license_version'	=>	array(

							'label'		=>	__('Add-on Version', 'ws-form-amazon-s3-v3'),
							'type'		=>	'static'
						),

						'action_' . $this->id . '_license_key'	=>	array(

							'label'		=>	__('Add-on License Key', 'ws-form-amazon-s3-v3'),
							'type'		=>	'text',
							'help'		=>	__('Enter your Amazon S3 add-on for WS Form PRO license key here.', 'ws-form-amazon-s3-v3'),
							'button'	=>	'license_action_' . $this->id,
							'action'	=>	$this->id
						),

						'action_' . $this->id . '_license_status'	=>	array(

							'label'		=>	__('Add-on License Status', 'ws-form-amazon-s3-v3'),
							'type'		=>	'static'
						),

						'action_' . $this->id . '_api_access_key'	=>	array(

							'label'		=>	__('Amazon S3 Access Key', 'ws-form-amazon-s3-v3')
						),

						'action_' . $this->id . '_api_secret_key'	=>	array(

							'label'		=>	__('Amazon S3 Secret Key', 'ws-form-amazon-s3-v3')
						)
					)
				);

				// Access key
				if(defined('WSF_AMAZON_S3_ACCESS_KEY')) {

					$options['action_' . $this->id]['fields']['action_' . $this->id . '_api_access_key']['type'] = 'static';

				} else {

					$options['action_' . $this->id]['fields']['action_' . $this->id . '_api_access_key']['type'] = 'password';
				}

				// Secret key
				if(defined('WSF_AMAZON_S3_SECRET_KEY')) {

					$options['action_' . $this->id]['fields']['action_' . $this->id . '_api_secret_key']['type'] = 'static';

				} else {

					$options['action_' . $this->id]['fields']['action_' . $this->id . '_api_secret_key']['type'] = 'password';
				}

				return $options;
			}

			// Load config at plugin level
			public function load_config_plugin() {

				$this->configured = true;

				if(defined('WSF_AMAZON_S3_ACCESS_KEY')) {

					$this->api_access_key = WSF_AMAZON_S3_ACCESS_KEY;

				} else {

					$this->api_access_key = WS_Form_Common::option_get('action_' . $this->id . '_api_access_key', '');
				}

				if(defined('WSF_AMAZON_S3_SECRET_KEY')) {

					$this->api_secret_key = WSF_AMAZON_S3_SECRET_KEY;

				} else {

					$this->api_secret_key = WS_Form_Common::option_get('action_' . $this->id . '_api_secret_key', '');
				}

				// Build endpoint using API key
				if(empty($this->api_access_key) || empty($this->api_secret_key)) {

					$this->configured = false;
				}

				return $this->configured;
			}

			// Build REST API endpoints
			public function rest_api_init() {

				// API routes - get_* (Use cache)
				register_rest_route(WS_FORM_RESTFUL_NAMESPACE, '/action/' . $this->id . '/get-presigned-request/', array('methods' => 'GET', 'callback' => array($this, 'api_get_presigned_request'), 'permission_callback' => function () { return WS_Form_Common::can_user('read_submission'); }));
			}

			// Get regions
			public function get_regions() {

				return array(

					'us-east-1'      => 'US East (N. Virginia)',
					'us-east-2'      => 'US East (Ohio)',
					'us-west-1'      => 'US West (N. California)',
					'us-west-2'      => 'US West (Oregon)',
					'ca-central-1'   => 'Canada (Central)',
					'af-south-1'     => 'Africa (Cape Town)',
					'ap-east-1'      => 'Asia Pacific (Hong Kong)',
					'ap-south-1'     => 'Asia Pacific (Mumbai)',
					'ap-northeast-2' => 'Asia Pacific (Seoul)',
					'ap-northeast-3' => 'Asia Pacific (Osaka-Local)',
					'ap-southeast-1' => 'Asia Pacific (Singapore)',
					'ap-southeast-2' => 'Asia Pacific (Sydney)',
					'ap-northeast-1' => 'Asia Pacific (Tokyo)',
					'cn-north-1'     => 'China (Beijing)',
					'cn-northwest-1' => 'China (Ningxia)',
					'eu-central-1'   => 'EU (Frankfurt)',
					'eu-west-1'      => 'EU (Ireland)',
					'eu-west-2'      => 'EU (London)',
					'eu-south-1'     => 'EU (Milan)',
					'eu-west-3'      => 'EU (Paris)',
					'eu-north-1'     => 'EU (Stockholm)',
					'me-south-1'     => 'Middle East (Bahrain)',
					'sa-east-1'      => 'South America (Sao Paulo)',
				);
			}
		}
	});