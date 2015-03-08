<?php
/*
Plugin Name: Image Regenerate & Select Crop
Description: Regenerate and crop images, details about all image sizes registered, status details for all the images, clean up and placeholders.
Author: Iulia Cazan
Version: 2.0.0
Author URI: https://profiles.wordpress.org/iulia-cazan
Donate Link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=JJA37EHZXWUTJ
License: GPL2

Copyright (C) 2014 Iulia Cazan

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

$upload_dir = wp_upload_dir();
$dest_url = $upload_dir['baseurl'] . '/placeholders';
$dest_path = $upload_dir['basedir'] . '/placeholders';
if ( ! file_exists( $dest_path ) ) {
	wp_mkdir_p( $dest_path );
}
define( 'PLUGIN_FOLDER', dirname( __FILE__ ) );
define( 'PLACEHOLDER_FOLDER', realpath( $dest_path ) );
define( 'PLACEHOLDER_URL', esc_url( $dest_url ) );

class SIRSC_Image_Regenerate_Select_Crop
{
	private static $instance;
	var $is_configured = false;
	public static $settings;
	var $exclude_post_type = array();
	var $limit9999 = 300;
	var $crop_positions = array();

	/**
	 * Get active object instance
	 *
	 * @access public
	 * @static
	 * @return object
	 */
	public static function get_instance() {

		if ( ! self::$instance ) {
			self::$instance = new SIRSC_Image_Regenerate_Select_Crop();
		}
		return self::$instance;
	}

	/**
	 * Class constructor.  Includes constants, includes and init method.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Run action and filter hooks.
	 *
	 * @access private
	 * @return void
	 */
	private function init() {
		self::$settings = get_option( 'sirsc_settings' );
		$this->is_configured = ( ! empty( self::$settings ) ) ? true : false;
		$this->exclude_post_type = array( 'nav_menu_item', 'revision', 'attachment' );

		if ( is_admin() ) {
			add_action( 'image_regenerate_select_crop_button', array( $this, 'image_regenerate_select_crop_button' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'load_assets' ) );
			add_action( 'init', array( $this, 'register_image_button' ) );
			add_action( 'add_meta_boxes', array( $this, 'register_image_meta' ), 10, 3 );
			add_action( 'wp_ajax_sirsc_show_actions_result', array( $this, 'show_actions_result' ) );
			add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			register_deactivation_hook( __FILE__, array( $this, 'deactivate_plugin' ) );

			$this->crop_positions = array(
				'lt' => __( 'Left/Top', 'sirsc' ),
				'ct' => __( 'Center/Top', 'sirsc' ),
				'rt' => __( 'Right/Top', 'sirsc' ),
				'lc' => __( 'Left/Center', 'sirsc' ),
				'cc' => __( 'Center/Center', 'sirsc' ),
				'rc' => __( 'Right/Center', 'sirsc' ),
				'lb' => __( 'Left/Bottom', 'sirsc' ),
				'cb' => __( 'Center/Bottom', 'sirsc' ),
				'rb' => __( 'Right/Bottom', 'sirsc' ),
			);
		}

		/** This is global, as the image sizes can be also registerd in the themes or other plugins */
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_ignore_global_image_sizes' ) );
		add_action( 'added_post_meta', array( $this, 'process_filtered_attachments' ), 10, 4 );

		if ( ! is_admin() && ! empty( self::$settings['placeholders'] ) ) {
			/** For the front side, let's use placeolders if the case.*/
			if ( ! empty( self::$settings['placeholders']['force_global'] ) ) {
				add_filter( 'image_downsize', array( $this, 'image_downsize_placeholder_force_global' ), 10, 3 );
			} elseif ( ! empty( self::$settings['placeholders']['only_missing'] ) ) {
				add_filter( 'image_downsize', array( $this, 'image_downsize_placeholder_only_missing' ), 10, 3 );
			}
		}
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::deactivate_plugin() The actions to be executed when the plugin is deactivated
	 */
	function deactivate_plugin() {
		global $wpdb;
		$tmpQuery = $wpdb->prepare( "SELECT option_name FROM " . $wpdb->options . " WHERE option_name like %s OR option_name like %s ",
			'sirsc_settings%',
			'sirsc_types%'
		);
		$rows = $wpdb->get_results( $tmpQuery, ARRAY_A );
		if ( ! empty( $rows ) && is_array( $rows ) ) {
			foreach ( $rows as $v ) {
				delete_option( $v['option_name'] );
			}
		}
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::load_textdomain() Load text domain for internalization
	 */
	function load_textdomain() {
		load_plugin_textdomain( 'sirsc', false, dirname( plugin_basename( __FILE__ ) ) . '/langs/' );
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::load_assets() Enqueue the css and javascript files
	 */
	function load_assets( $hook_suffix = '' ) {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'jquery-ui' );
		wp_enqueue_style( 'sirsc-style', plugins_url( '/assets/css/style.css', __FILE__ ), array(), '1.0', false );
		wp_register_script( 'sirsc-custom-js', plugins_url( '/assets/js/custom.js', __FILE__ ), array(), '20141118' );
		wp_localize_script( 'sirsc-custom-js', 'SIRSC_settings', array(
			'confirm_cleanup'        => __( 'Clean Up All ?', 'sirsc' ),
			'confirm_regenerate'     => __( 'Regenerate All ?', 'sirsc' ),
			'time_warning'           => __( 'This operation might take a while, depending on how many images you have.', 'sirsc' ),
			'irreversible_operation' => __( 'The operation is irreversible!', 'sirsc' ),
		) );
		wp_enqueue_script( 'sirsc-custom-js' );
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::admin_menu() Add the new menu in tools section that allows to configure the image sizes restrictions
	 * */
	function admin_menu() {
		add_submenu_page(
			'options-general.php',
			'<div class="dashicons dashicons-format-gallery"></div> ' . __( 'Image Regenerate & Select Crop Settings', 'sirsc' ),
			'<div class="dashicons dashicons-format-gallery"></div> ' . __( 'Image Regenerate & Select Crop Settings', 'sirsc' ),
			'manage_options',
			'image-regenerate-select-crop-settings',
			array( $this, 'image_regenerate_select_crop_settings' )
		);
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::get_post_type_settings() Load the post type settings if available
	 */
	function get_post_type_settings( $post_type ) {
		$pt = ( ! empty( $post_type ) ) ? '_' . $post_type : '';
		$tmp_set = get_option( 'sirsc_settings' . $pt );
		if ( ! empty( $tmp_set ) ) {
			self::$settings = $tmp_set;
		}
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::load_settings_for_post_id() Load the settings for a post ID (by parent post type)
	 */
	function load_settings_for_post_id( $post_id = 0 ) {
		$post = get_post( $post_id );
		if ( ! empty( $post->post_parent ) ) {
			$pt = get_post_type( $post->post_parent );
			if ( ! empty( $pt ) ) {
				self::get_post_type_settings( $pt );
			}
		} elseif ( ! empty( $post->post_type ) ) {
			self::get_post_type_settings( $post->post_type );
		}
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::filter_ignore_global_image_sizes() Exclude globally the image sizes selected in the settings from being generated on upload
	 */
	function filter_ignore_global_image_sizes( $sizes ) {
		if ( ! empty( self::$settings['complete_global_ignore'] ) ) {
			foreach ( self::$settings['complete_global_ignore'] as $s ) {
				unset( $sizes[$s] );
			}
		}
		return $sizes;
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::process_filtered_attachments() Check if the attached image is required to be replaced with the "Force Original" from the settings
	 *
	 * @param int $meta_id
	 * @param int $post_id
	 * @param string $meta_key
	 * @param array $meta_value
	 */
	function process_filtered_attachments( $meta_id = '', $post_id = '', $meta_key = '', $meta_value = '' ) {
		if ( ! empty( $post_id ) && '_wp_attachment_metadata' == $meta_key && ! empty( $meta_value ) ) {
			$this->load_settings_for_post_id( $post_id );
			if ( ! empty( self::$settings['default_crop'] ) ) {
				foreach ( self::$settings['default_crop'] as $ck => $cv ) {
					if ( 'cc' != $cv ) {
						$this->make_images_if_not_exists( $post_id, $ck, $cv );
					}
				}
			}
			if ( ! empty( self::$settings['force_original_to'] ) ) {
				$size = $this->get_all_image_sizes( self::$settings['force_original_to'] );
				$m = $this->process_image_resize_brute( $post_id, $size['width'], $size['height'] );
				return ( $m );
			}
		}
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::process_image_resize_brute() Generates an image size version of a specified image
	 *
	 * @param int $id
	 * @param int $max_width
	 * @param int $max_height
	 *
	 * @return array
	 */
	function process_image_resize_brute( $id, $max_width, $max_height ) {
		$this->load_settings_for_post_id( $id );

		/** Make sure we get in the DB and folders all image sizes with the custom restrictions */
		$this->make_images_if_not_exists( $id, 'all' );

		$att = get_attached_file( $id, false );
		$m = get_post_meta( $id, '_wp_attachment_metadata', true );
		$img = wp_get_image_editor( $att );
		if ( ! is_wp_error( $img ) && ! empty( $m['file'] ) ) {
			$size = $img->get_size();
			$to_replace = false;
			if ( ! empty( $max_width ) && ! empty( $max_height ) ) {
				if ( $size['width'] >= $size['height'] ) {
					/** This is a landscape image, let's resize it by max width */
					if ( $size['width'] >= $max_width ) {
						$img->resize( $max_width, null );
						$to_replace = true;
					}
				} else {
					/** This is a portrait image, let's resize it by max height */
					if ( $size['height'] >= $max_height ) {
						$img->resize( null, $max_height );
						$to_replace = true;
					}
				}
			} elseif ( ! empty( $max_width ) ) {
				/** This is not restricted by height, but only by max width */
				if ( $size['width'] >= $max_width ) {
					$img->resize( $max_width, null );
					$to_replace = true;
				}
			} else {
				/** This is not restricted by width, but only by max height */
				if ( $size['height'] >= $max_height ) {
					$img->resize( null, $max_height );
					$to_replace = true;
				}
			}

			if ( $to_replace ) {
				$img->set_quality( 100 );
				$saved = $img->save();
				$upload_dir = wp_upload_dir();
				if ( @copy( $saved['path'], $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $m['file'] ) ) {
					@unlink( $saved['path'] );
					$m['width'] = $saved['width'];
					$m['height'] = $saved['height'];
					wp_update_attachment_metadata( $id, $m );
				}
				$this->make_images_if_not_exists( $id, 'all' );
				return $m;
			}
		}
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::image_regenerate_select_crop_settings() Functionality to manage the image regenerate & select crop settings
	 */
	function image_regenerate_select_crop_settings() {
		$initiate_cleanup = '';

		/** Verify user capabilities in order to deny the access if the user does not have the capabilities */
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Action not allowed.', 'sirsc' ) );
		} else {

			/** User can save the options */
			if ( ! empty( $_POST ) ) {
				if ( ! isset( $_POST['_sirsc_settings_nonce'] ) || ! wp_verify_nonce( $_POST['_sirsc_settings_nonce'], '_sirsc_settings_save' ) ) {
					wp_die( __( 'Action not allowed.', 'sirsc' ), __( 'Security Breach', 'sirsc' ) );
				}

				$settings = array(
					'exclude'                => array(),
					'force_original_to'      => '',
					'complete_global_ignore' => array(),
					'placeholders'           => array(),
					'default_crop'           => array(),
				);
				$exclude_size = array();
				if ( ! empty( $_POST['_sirsrc_exclude_size'] ) ) {
					foreach ( $_POST['_sirsrc_exclude_size'] as $k => $v ) {
						array_push( $settings['exclude'], sanitize_text_field( $k ) );
					}
				}
				if ( ! empty( $_POST['_sirsrc_complete_global_ignore'] ) ) {
					foreach ( $_POST['_sirsrc_complete_global_ignore'] as $k => $v ) {
						array_push( $settings['complete_global_ignore'], sanitize_text_field( $k ) );
					}
				}
				if ( ! empty( $_POST['_sirsrc_force_original_to'] ) ) {
					$settings['force_original_to'] = sanitize_text_field( $_POST['_sirsrc_force_original_to'] );
				}
				if ( ! empty( $_POST['_sirsrc_placeholders'] ) ) {
					if ( $_POST['_sirsrc_placeholders'] == 'force_global' ) {
						$settings['placeholders']['force_global'] = 1;
					} elseif ( $_POST['_sirsrc_placeholders'] == 'only_missing' ) {
						$settings['placeholders']['only_missing'] = 1;
					}
				}
				if ( ! empty( $_POST['_sirsrc_default_crop'] ) ) {
					foreach ( $_POST['_sirsrc_default_crop'] as $k => $v ) {
						$settings['default_crop'][$k] = sanitize_text_field( $v );
					}
				}

				$_sirsc_post_types = ( ! empty( $_POST['_sirsc_post_types'] ) ) ? '_' . sanitize_text_field( $_POST['_sirsc_post_types'] ) : '';
				update_option( 'sirsc_settings' . $_sirsc_post_types, $settings );
			}
		}

		/** Display the form and the next digests contents */
		?>
		<br/>
		<h1><?php _e( 'Image Regenerate & Select Crop Settings', 'sirsc' ); ?></h1>
		<br/>
		<?php
		if ( ! $this->is_configured ) {
			echo '<div class="update-nag">' . __( 'Image Regenerate & Select Crop Settings are not configured yet', 'sirsc' ) . '</div><hr/>';
		}
		?>

		<?php
		$post_types = $this->get_all_post_types_plugin();
		$_sirsc_post_types = ( ! empty( $_GET['_sirsc_post_types'] ) ) ? $_GET['_sirsc_post_types'] : '';
		$settings = maybe_unserialize( get_option( 'sirsc_settings' ) );
		if ( ! empty( $_sirsc_post_types ) ) {
			$settings = maybe_unserialize( get_option( 'sirsc_settings_' . $_sirsc_post_types ) );
		}
		?>

		<table cellspacing="0">
			<tr>
				<td class="vtopAlign">
					<div class="updated">
						<span class="dashicons dashicons-info"></span>
						<?php _e( 'To assure the optimal behavior for the features of this plugin, please make sure you visit and update your settings here, whenever you activate a new theme or plugins, so that the new image size registerd, adjusted or removed to be reflected also here.', 'sirsc' ); ?>
					</div>
				</td>
				<td class="vtopAlign" nowrap="nowrap">
					<div class="floatright textright">
						<b><?php _e( 'Don\'t forget to donate !', 'sirsc' ); ?></b>
						<br/><?php _e( 'It means you apreciate the time I spent to develop the plugin for your benefit.', 'sirsc' ); ?>
						<br/><b><?php _e( 'Thank you !', 'sirsc' ); ?></b>
					</div>
				</td>
				<td class="vtopAlign">
					<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_top"><input type="hidden" name="cmd"
																											value="_s-xclick"><input type="hidden"
																																	 name="hosted_button_id"
																																	 value="JJA37EHZXWUTJ"><input
							type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif" border="0" name="submit"
							alt="PayPal - The safer, easier way to pay online!"><img alt="" border="0"
																					 src="https://www.paypalobjects.com/en_US/i/scr/pixel.gif"
																					 width="1" height="1"></form>
				</td>
			</tr>
		</table>
		<hr/>

		<br/>

		<form action="" method="post">
		<?php wp_nonce_field( '_sirsc_settings_save', '_sirsc_settings_nonce' ); ?>

		<table cellspacing="0">
			<tr>
				<td class="vtopAlign">
					<h3><a class="dashicons dashicons-info" title="<?php _e( 'Details', 'sirsc' ); ?>"
						   onclick="sirsc_toggle_info('#info_developer_mode')"></a> <?php _e( 'Images Placeholders Developer Mode', 'sirsc' ); ?>
						&nbsp;</h3>

					<div class="sirsc_info_box_wrap">
						<div id="info_developer_mode" class="sirsc_info_box" onclick="sirsc_toggle_info('#info_developer_mode')">
							<?php _e( 'This option allows you to display placeholders for front-side images called programmatically (that are not embedded in content with their src, but retrieved with the wp_get_attachment_image_src, and the other related WP native functions). If there is no placeholder set, then the default behavior would be to display the full size image instead of a missing image size.', 'sirsc' ); ?>
							<hr/><?php _e( 'If you activate the "force global" option, all the images on the front size that are related to posts will be replaced with the placeholders that mention the image size required. This is useful for debug, to quick identify the image sizes used for each layout.', 'sirsc' ); ?>
							<hr/><?php _e( 'If you activate the "only missing images" option, all the images on the front size that are related to posts and do not have the requested image size generate, will be replaced with the placeholders that mention the image size required. This is useful for showing smaller images instead of full size images.', 'sirsc' ); ?>
						</div>
					</div>
				</td>
				<td class="vtopAlign">
					<?php $is_checked = ( empty( $settings['placeholders'] ) ) ? 1 : 0; ?>
					<h3><label>
							<input type="radio" name="_sirsrc_placeholders" id="_sirsrc_placeholders_none"
								   value="" <?php checked( 1, $is_checked, true ); ?> />
							<?php _e( 'no placeholder', 'sirsc' ); ?>
						</label></h3>
				</td>
				<td class="vtopAlign">
					<?php $is_checked = ( ! empty( $settings['placeholders']['force_global'] ) ) ? 1 : 0; ?>
					<h3><label>
							<input type="radio" name="_sirsrc_placeholders" id="_sirsrc_placeholders_force_global"
								   value="force_global" <?php checked( 1, $is_checked, true ); ?> />
							<?php _e( 'force global', 'sirsc' ); ?>
						</label></h3>
				</td>
				<td class="vtopAlign">
					<?php $is_checked = ( ! empty( $settings['placeholders']['only_missing'] ) ) ? 1 : 0; ?>
					<h3><label>
							<input type="radio" name="_sirsrc_placeholders" id="_sirsrc_placeholders_only_missing"
								   value="only_missing" <?php checked( 1, $is_checked, true ); ?> />
							<?php _e( 'only missing images', 'sirsc' ); ?>
						</label></h3>
				</td>
			</tr>
		</table>

		<hr/>
		<h2><?php _e( 'Exclude Image Sizes', 'sirsc' ); ?></h2>

		<p><?php _e( 'The selected image sizes will be excluded from the generation of the new images. By default, all image sizes defined in the system will be allowed. You can setup a global configuration, or individual configuration for all images attached to a particular post type. If no particular settings are made for a post type, then the default general settings will be used.', 'sirsc' ); ?></p>

		<?php
		if ( ! empty( $post_types ) ) {
			$ptypes = array();
			echo '<select name="_sirsc_post_types" id="_sirsc_post_type" onchange="sirsc_load_post_type(this, \'' . admin_url( 'options-general.php?page=image-regenerate-select-crop-settings' ) . '\')">
					<option value="">' . __( 'General settings (used as default for all images)', 'sirsc' ) . '</option>';
			foreach ( $post_types as $pt => $obj ) {
				array_push( $ptypes, $pt );
				$is_selected = ( $_sirsc_post_types == $pt ) ? 1 : 0;
				$extra = ( ! empty( $obj->_builtin ) ) ? '' : ' (custom post type)';
				echo '<option value="' . esc_attr( $pt ) . '" ' . selected( 1, $is_selected, true ) . '>' . __( 'Settings for images attached to a ', 'sirsc' ) . ' ' . esc_html( $pt . $extra ) . '</option>';
			}
			echo '</select><hr />';
			update_option( 'sirsc_types_options', $ptypes );
		}
		?>

		<table id="main_settings_block" class="form-table fixed" cellspacing="0">
		<tr>
			<td class="vtopAlign">
				<h3><a class="dashicons dashicons-info" title="<?php _e( 'Details', 'sirsc' ); ?>"
					   onclick="sirsc_toggle_info('#info_global_ignore')"></a> <?php _e( 'Global Ignore', 'sirsc' ); ?></h3>

				<div class="sirsc_info_box_wrap">
					<div id="info_global_ignore" class="sirsc_info_box" onclick="sirsc_toggle_info('#info_global_ignore')">
						<?php _e( 'This option allows you to exclude globally from the application some of the image sizes that are registered through various plugins and themes options, but you don\'t need these in your application at all (these are just stored in your folders and database but not used).', 'sirsc' ); ?>
						<hr/><?php _e( 'By excluding these, the unnecessary image sizes will not be generated at all.', 'sirsc' ); ?>
					</div>
				</div>
			</td>
			<td class="vtopAlign">
				<h3><?php _e( 'Image Size Name', 'sirsc' ); ?></h3>
			</td>
			<td class="vtopAlign">
				<h3><?php _e( 'Image Size Description', 'sirsc' ); ?></h3>
			</td>
			<td class="vtopAlign">
				<h3><a class="dashicons dashicons-info" title="<?php _e( 'Details', 'sirsc' ); ?>"
					   onclick="sirsc_toggle_info('#info_exclude')"></a> <?php _e( 'Hide Preview', 'sirsc' ); ?></h3>

				<div class="sirsc_info_box_wrap">
					<div id="info_exclude" class="sirsc_info_box" onclick="sirsc_toggle_info('#info_exclude')">
						<?php _e( 'This option allows you to exclude from the "Image Regenerate & Select Crop Settings" lightbox the details and options for the selected image sizes.', 'sirsc' ); ?>
						<hr/><?php _e( 'This is usefull when you want to restrict from other users the functionality of crop or resize for particular image sizes', 'sirsc' ); ?>
					</div>
				</div>
			</td>
			<td class="vtopAlign">
				<h3><a class="dashicons dashicons-info" title="<?php _e( 'Details', 'sirsc' ); ?>"
					   onclick="sirsc_toggle_info('#info_force_original')"></a> <?php _e( 'Force Original', 'sirsc' ); ?></h3>
				<label>
					<input type="radio" name="_sirsrc_force_original_to" id="_sirsrc_force_original_to_0"
						   value="0" <?php checked( 1, 1, true ); ?> />
					<?php _e( 'nothing selected', 'sirsc' ); ?>
				</label>

				<div class="sirsc_info_box_wrap">
					<div id="info_force_original" class="sirsc_info_box" onclick="sirsc_toggle_info('#info_force_original')">
						<?php _e( 'This option means that the original image will be scaled to a max width or a max height specified by the image size you select.', 'sirsc' ); ?>
						<hr/><?php _e( 'This might be useful if you do not use the original image in any of the layout at the full size, and this might save some storage space.', 'sirsc' ); ?>
						<hr/><?php _e( 'Leave "nothing selected" to keep the original image as what you upload.', 'sirsc' ); ?>
					</div>
				</div>
			</td>
			<td class="vtopAlign">
				<h3><a class="dashicons dashicons-info" title="<?php _e( 'Default Crop', 'sirsc' ); ?>"
					   onclick="sirsc_toggle_info('#info_default_crop')"></a> <?php _e( 'Default Crop', 'sirsc' ); ?></h3>

				<div class="sirsc_info_box_wrap">
					<div id="info_default_crop" class="sirsc_info_box" onclick="sirsc_toggle_info('#info_default_crop')">
						<?php _e( 'This option allows you to set a default crop position for the images generated for particular image size. This default option will be used when you chose to regenerate an individual image or all of these and also when a new image is uploaded.', 'sirsc' ); ?>
					</div>
				</div>
			</td>
			<td class="vtopAlign">
				<h3><a class="dashicons dashicons-info" title="<?php _e( 'Details', 'sirsc' ); ?>"
					   onclick="sirsc_toggle_info('#info_clean_up')"></a> <?php _e( 'Clean Up All', 'sirsc' ); ?></h3>

				<div class="sirsc_info_box_wrap">
					<div id="info_clean_up" class="sirsc_info_box" onclick="sirsc_toggle_info('#info_clean_up')">
						<?php _e( 'This option allows you to clean up all the image sizes you already have in the application but you don\'t use these at all.', 'sirsc' ); ?>
						<hr/><?php _e( 'Please be carefull, once you click to remove the selected image size, the action is irreversible,
							the images generated will be deleted from your folders and database records.', 'sirsc' ); ?>
					</div>
				</div>
			</td>
			<td class="vtopAlign">
				<h3><a class="dashicons dashicons-info" title="<?php _e( 'Details', 'sirsc' ); ?>"
					   onclick="sirsc_toggle_info('#info_regenerate')"></a> <?php _e( 'Regenerate All', 'sirsc' ); ?></h3>

				<div class="sirsc_info_box_wrap">
					<div id="info_regenerate" class="sirsc_info_box" onclick="sirsc_toggle_info('#info_regenerate')">
						<?php _e( 'This option allows you to regenerate all the image for the selected image size', 'sirsc' ); ?>
						<hr/><?php _e( 'Please be carefull, once you click to regenerate the selected image size, the action is irreversible,
							the images already generated will be overwritten.', 'sirsc' ); ?>
					</div>
				</div>
			</td>
		</tr>

		<?php
		$all_sizes = $this->get_all_image_sizes();
		if ( ! empty( $all_sizes ) ) {
			foreach ( $all_sizes as $k => $v ) {
				$is_checked = ( ! empty( $settings['exclude'] ) && in_array( $k, $settings['exclude'] ) ) ? 1 : 0;
				$is_checked_ignore = ( ! empty( $settings['complete_global_ignore'] ) && in_array( $k, $settings['complete_global_ignore'] ) ) ? 1 : 0;
				$is_checked_force = ( ! empty( $settings['force_original_to'] ) && $k == $settings['force_original_to'] ) ? 1 : 0;
				$cl = ( ! empty( $is_checked_ignore ) ) ? ' _sirsc_ignored' : '';
				$cl .= ( ! empty( $is_checked_force ) ) ? ' _sirsc_force_original' : '';
				$cl .= ( empty( $is_checked ) ) ? ' _sirsc_included' : '';

				$has_crop = ( ! empty( $settings['default_crop'][$k] ) ) ? $settings['default_crop'][$k] : 'cc';
				?>
				<tr class="<?php echo esc_attr( $cl ); ?>">
					<td class="vtopAlign">
						<label>
							<input type="checkbox" name="_sirsrc_complete_global_ignore[<?php echo esc_attr( $k ); ?>]"
								   id="_sirsrc_complete_global_ignore_<?php echo esc_attr( $k ); ?>"
								   value="<?php echo esc_attr( $k ); ?>" <?php checked( 1, $is_checked_ignore, true ); ?> />
							<?php _e( 'global ignore', 'sirsc' ); ?>
						</label>
					</td>
					<td class="vtopAlign">
						<b><?php esc_html_e( $k ); ?></b>
						<?php $this->image_placeholder_for_image_size( $k, true ); ?>
					</td>
					<td class="vtopAlign"><?php echo $this->size_to_text( $v ); ?></td>
					<td class="vtopAlign">
						<label>
							<input type="checkbox" name="_sirsrc_exclude_size[<?php echo esc_attr( $k ); ?>]"
								   id="_sirsrc_exclude_size_<?php echo esc_attr( $k ); ?>"
								   value="<?php echo esc_attr( $k ); ?>" <?php checked( 1, $is_checked, true ); ?> />
							<?php _e( 'hide', 'sirsc' ); ?>
						</label>
					</td>
					<td class="vtopAlign">
						<label>
							<input type="radio" name="_sirsrc_force_original_to" id="_sirsrc_force_original_to_<?php echo esc_attr( $k ); ?>"
								   value="<?php echo esc_attr( $k ); ?>" <?php checked( 1, $is_checked_force, true ); ?> />
							<?php _e( 'force original', 'sirsc' ); ?>
						</label>
					</td>
					<td class="vtopAlign">
						<?php
						if ( ! empty( $v['crop'] ) ) {
							echo str_replace( 'thumb0' . $k, '', str_replace( 'crop_small_type', '_sirsrc_default_crop[' . $k . ']', $this->make_generate_images_crop( 0, $k, false, $has_crop ) ) );
						}
						?>
					</td>
					<td class="vtopAlign">
						<?php
						$total_cleanup = $this->calculate_total_to_cleanup( $_sirsc_post_types, $k );
						if ( ! empty( $total_cleanup ) ) {
							?>
							<div class="dashicons dashicons-update" title="<?php _e( 'Clean Up All', 'sirsc' ); ?>"></div>
							<span class="button-secondary button-large" title="<?php echo intval( $total_cleanup ); ?>"
								  onclick="sirsc_initiate_cleanup('<?php echo esc_attr( $k ); ?>');">
										<?php _e( 'Clean Up', 'sirsc' ); ?>
									</span>

							<div class="sirsc_button-regenerate-wrap">
								<div id="_sirsc_cleanup_initiated_for_<?php echo esc_attr( $k ); ?>">
									<input type="hidden" name="_sisrsc_image_size_name" id="_sisrsc_image_size_name<?php echo esc_attr( $k ); ?>"
										   value="<?php echo esc_attr( $k ); ?>"/>
									<input type="hidden" name="_sisrsc_post_type" id="_sisrsc_post_type<?php echo esc_attr( $k ); ?>"
										   value="<?php echo esc_attr( $_sirsc_post_types ); ?>"/>
									<input type="hidden" name="_sisrsc_image_size_name_page"
										   id="_sisrsc_image_size_name_page<?php echo esc_attr( $k ); ?>" value="0"/>

									<div class="sirsc_button-regenerate">
										<div>
											<div id="_sirsc_cleanup_initiated_for_<?php echo esc_attr( $k ); ?>_result" class="result"><span
													class="spinner off"></span></div>
										</div>
									</div>
									<div class="sirsc_clearAll"></div>
								</div>
							</div>
						<?php
						}
						?>
					</td>
					<td class="vtopAlign">
						<?php
						if ( ! $is_checked_ignore ) {
							?>
							<div class="dashicons dashicons-update" title="<?php _e( 'Regenerate All', 'sirsc' ); ?>"></div>
							<span class="button-primary button-large"
								  onclick="sirsc_initiate_regenerate('<?php echo esc_attr( $k ); ?>');">
									<?php _e( 'Regenerate', 'sirsc' ); ?>
								</span>
							<div class="sirsc_button-regenerate-wrap">
								<div id="_sirsc_regenerate_initiated_for_<?php echo esc_attr( $k ); ?>">
									<input type="hidden" name="_sisrsc_regenerate_image_size_name"
										   id="_sisrsc_regenerate_image_size_name<?php echo esc_attr( $k ); ?>"
										   value="<?php echo esc_attr( $k ); ?>"/>
									<input type="hidden" name="_sisrsc_post_type"
										   id="_sisrsc_regenerate_post_type<?php echo esc_attr( $k ); ?>"
										   value="<?php echo esc_attr( $_sirsc_post_types ); ?>"/>
									<input type="hidden" name="_sisrsc_regenerate_image_size_name_page"
										   id="_sisrsc_regenerate_image_size_name_page<?php echo esc_attr( $k ); ?>" value="0"/>

									<div class="sirsc_button-regenerate">
										<div>
											<div id="_sirsc_regenerate_initiated_for_<?php echo esc_attr( $k ); ?>_result" class="result">
												<span class="spinner off"></span></div>
										</div>
									</div>
									<div class="sirsc_clearAll"></div>
								</div>
							</div>
						<?php
						}
						?>
					</td>
				</tr>
			<?php
			}
		}
		?>
		<tr>
			<td colspan="7">
				<hr/><?php submit_button(); ?></td>
		</tr>
		</table>
		</form>
	<?php
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::register_image_button() For the featured image of the show we should be able to generate the missing image sizes
	 */
	function register_image_button() {
		add_filter( 'admin_post_thumbnail_html', array( $this, 'append_image_generate_button' ), 10, 2 );
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::register_image_meta() Append the image sizes generator button to the edit media page
	 */
	function register_image_meta() {
		global $post;
		if ( ! empty( $post->post_type ) && $post->post_type == 'attachment' ) {
			add_action( 'edit_form_top', array( $this, 'append_image_generate_button' ), 10, 2 );
		}
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::image_regenerate_select_crop_button() This can be used in do_action
	 */
	function image_regenerate_select_crop_button( $image_id = 0 ) {
		$this->append_image_generate_button( '', '', $image_id );
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::append_image_generate_button() Append or display the button for generating the missing image sizes and request individual crop of images
	 */
	function append_image_generate_button( $content, $post_id = 0, $thumbnail_id = 0 ) {
		$display = false;
		if ( is_object( $content ) ) {
			$thumbnail_id = $content->ID;
			$content = '';
			$display = true;
		}
		if ( ! empty( $post_id ) || ! empty( $thumbnail_id ) ) {
			if ( ! empty( $thumbnail_id ) ) {
				$thumb_ID = $thumbnail_id;
				$display = true;
			} else {
				$thumb_ID = get_post_thumbnail_id( $post_id );
				$display = false;
			}
			$this->load_settings_for_post_id( $thumb_ID );
			if ( ! empty( $thumb_ID ) ) {
				$content = '
				<div id="sirsc_recordsArray_' . intval( $thumb_ID ) . '">
					<input type="hidden" name="post_id" id="post_id' . 'thumb' . intval( $thumb_ID ) . '" value="' . esc_attr( intval( $thumb_ID ) ) . '" />' . $this->make_generate_images_button( $thumb_ID ) . ' &nbsp;
				</div>
				' . $content;
			}
		}
		if ( $display ) {
			echo '<div class="sirsc_button-regenerate-wrap">' . $content . '</div>';
		} else {
			return $content;
		}
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::make_generate_images_button() Return the html code for a button that triggers the image sizes generator.
	 *
	 * @param int $attachmentId
	 * @return string
	 */
	function make_generate_images_button( $attachmentId = 0 ) {
		$button_regenerate = '
		<div class="sirsc_button-regenerate">
			<div id="sirsc_inline_regenerate_sizes' . intval( $attachmentId ) . '">
				<span class="button-primary button-large" onclick="sirsc_open_details(\'' . intval( $attachmentId ) . '\')">
					<div class="dashicons dashicons-format-gallery" title="' . esc_attr__( 'Details/Options',
				'sirsc' ) . '"></div> ' . __( 'Details/Options', 'sirsc' ) . '
				</span>
				<span class="button-primary button-large" onclick="sirsc_start_regenerate(\'' . intval( $attachmentId ) . '\')">
					<div class="dashicons dashicons-update" title="' . esc_attr__( 'Regenerate',
				'sirsc' ) . '"></div> ' . __( 'Regenerate', 'sirsc' ) . '
				</span>
				<div id="sirsc_recordsArray_' . intval( $attachmentId ) . '_result" class="result">
					<span class="spinner off"></span>
				</div>
			</div>
		</div>
		<div class="sirsc_clearAll"></div>';
		return $button_regenerate;
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::make_ajax_call() Return the sirsc_show_ajax_action
	 *
	 * @param string $callback
	 * @param string $element
	 * @param string $target
	 * @return string
	 */
	function make_ajax_call( $callback, $element, $target ) {
		$make_ajax_call = "sirsc_show_ajax_action('" . $callback . "', '" . $element . "', '" . $target . "');";
		return $make_ajax_call;
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::parse_ajax_data() Return the array of keys=>values from the ajax post
	 *
	 * @param array $data
	 * @return array
	 */
	function parse_ajax_data( $sirsc_data ) {
		$result = false;
		if ( ! empty( $sirsc_data ) ) {
			$result = array();
			foreach ( $sirsc_data as $v ) {
				$result[$v['name']] = $v['value'];
			}
		}
		return $result;
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::show_actions_result() Execute and return the response of the callback, if the specified method exists
	 *
	 * @return string
	 */
	function show_actions_result() {
		if ( ! empty( $_REQUEST['sirsc_data'] ) ) {
			$postData = $this->parse_ajax_data( $_REQUEST['sirsc_data'] );
			if ( ! empty( $postData['callback'] ) ) {
				if ( method_exists( $this, $postData['callback'] ) ) {
					call_user_func( array( $this, $postData['callback'] ), $postData );
				}
			}
		}
		die();
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::make_generate_images_crop() Return the html code for a button that trriggers the image sizes generator.
	 *
	 * @param int $attachmentId
	 * @return string
	 */
	function make_generate_images_crop( $attachmentId = 0, $size = 'thumbnail', $click = true, $selected = 'cc' ) {
		$id = intval( $attachmentId ) . $size;
		$action = ( $click ) ? ' onclick="sirsc_crop_position(\'' . $id . '\');"' : '';

		$button_regenerate = '
		<table cellspacing="0" cellpadding="0" title="' . esc_attr__( 'Click to generate a crop of the image from this position', 'sirsc' ) . '">';

		$c = 0;
		foreach ( $this->crop_positions as $k => $v ) {
			$sel = ( ! empty( $selected ) && $k == $selected ) ? ' checked="checked"' : '';
			$button_regenerate .= ( $c % 3 == 0 ) ? '<tr>' : '';
			$button_regenerate .= '<td title="' . esc_attr( $v ) . '"><label><input type="radio" name="crop_small_type" id="crop_small_type' . 'thumb' . $id . '" value="' . $k . '"' . $action . $sel . ' /></label></td>';
			$button_regenerate .= ( $c % 3 == 2 ) ? '</tr>' : '';
			++$c;
		}

		$button_regenerate .= '
		</table>';
		return $button_regenerate;
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::allow_resize_from_original() Return the details about an image size for an image.
	 */
	function allow_resize_from_original( $filename, $image, $size, $selected_size ) {
		$result = array(
			'found'                => 0,
			'is_crop'              => 0,
			'is_identical_size'    => 0,
			'is_resize'            => 0,
			'is_proportional_size' => 0,
			'width'                => 0,
			'height'               => 0,
			'path'                 => '',
			'url'                  => '',
			'can_be_cropped'       => 0,
			'can_be_generated'     => 0,
		);
		$original_w = $image['width'];
		$original_h = $image['height'];

		$w = ( ! empty( $size[$selected_size]['width'] ) ) ? intval( $size[$selected_size]['width'] ) : 0;
		$h = ( ! empty( $size[$selected_size]['height'] ) ) ? intval( $size[$selected_size]['height'] ) : 0;
		$c = ( ! empty( $size[$selected_size]['crop'] ) ) ? $size[$selected_size]['crop'] : false;

		if ( empty( $image['sizes'][$selected_size]['file'] ) ) {
			// not generated probably
			if ( ! empty( $c ) ) {
				if ( $original_w >= $w && $original_h >= $h ) {
					$result['can_be_generated'] = 1;
				}
			} else {
				if (
					( $w == 0 && $original_h >= $h )
					|| ( $h == 0 && $original_w >= $w )
					|| ( $w != 0 && $h != 0 && ( $original_w >= $w || $original_h >= $h ) )
				) {
					$result['can_be_generated'] = 1;
				}
			}
		} else {
			$file = str_replace( basename( $filename ), $image['sizes'][$selected_size]['file'], $filename );
			if ( file_exists( $file ) ) {
				$c_image_size = getimagesize( $file );
				$ciw = intval( $c_image_size[0] );
				$cih = intval( $c_image_size[1] );
				$result['found'] = 1;
				$result['width'] = $ciw;
				$result['height'] = $cih;
				$result['path'] = $file;
				if ( $ciw == $w && $cih == $h ) {
					$result['is_identical_size'] = 1;
					$result['can_be_cropped'] = 1;
					$result['can_be_generated'] = 1;
				}
				if ( ! empty( $c ) ) {
					$result['is_crop'] = 1;
					if ( $original_w >= $w && $original_h >= $h ) {
						$result['can_be_cropped'] = 1;
						$result['can_be_generated'] = 1;
					}
				} else {
					$result['is_resize'] = 1;
					if ( ( $w == 0 && $cih == $h ) || ( $ciw == $w && $h == 0 ) ) {
						$result['is_proportional_size'] = 1;
						$result['can_be_generated'] = 1;
					} elseif ( $w != 0 && $h != 0 && ( $ciw == $w || $cih == $h ) ) {
						$result['is_proportional_size'] = 1;
						$result['can_be_generated'] = 1;
					}
					if ( $original_w >= $w && $original_h >= $h ) {
						$result['can_be_generated'] = 1;
					}
				}
			} else {
				//to do the not exists but size exists
				if ( ! empty( $c ) ) {
					if ( $original_w >= $w && $original_h >= $h ) {
						$result['can_be_generated'] = 1;
					}
				} else {
					if (
						( $w == 0 && $original_h >= $h )
						|| ( $h == 0 && $original_w >= $w )
						|| ( $w != 0 && $h != 0 && ( $original_w >= $w || $original_h >= $h ) )
					) {
						$result['can_be_generated'] = 1;
					}
				}
			}
		}
		return $result;
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::ajax_show_available_sizes() Return the html code that contains the description of the images sizes defined in the application and provides details about the image sizes of an uploaded image.
	 */
	function ajax_show_available_sizes() {
		if ( ! empty( $_REQUEST['sirsc_data'] ) ) {
			$postData = $this->parse_ajax_data( $_REQUEST['sirsc_data'] );
			if ( ! empty( $postData['post_id'] ) ) {
				$post = get_post( $postData['post_id'] );
				if ( ! empty( $post ) ) {
					$this->load_settings_for_post_id( intval( $postData['post_id'] ) );
					$all_size = $this->get_all_image_sizes_plugin();
					$image = wp_get_attachment_metadata( $postData['post_id'] );
					$filename = get_attached_file( $postData['post_id'] );
					if ( ! empty( $filename ) ) {
						$original_w = $image['width'];
						$original_h = $image['height'];
						echo '
						<div class="sirsc_under-image-options"></div>
						<div class="sirsc_image-size-selection-box">
							<div class="sirsc_options-title">
								<div class="sirsc_options-close-button-wrap"><a class="sirsc_options-close-button" onclick="sirsc_clear_result(\'' . intval( $postData['post_id'] ) . '\');"><span class="dashicons dashicons-dismiss"></span></a></div>
								<h2>' . __( 'Image Details & Options', 'sirsc' ) . '</h2>
								<a onclick="sirsc_open_details(\'' . intval( $postData['post_id'] ) . '\');"><span class="dashicons dashicons-update"></span></a>
							</div>
							<div class="inside">';

						if ( ! empty( $all_size ) ) {
							echo '
							<table class="wp-list-table widefat fixed media">
								<thead>
									<tr>
										<th class="manage-column">' . __( 'Image size name and description', 'sirsc' ) . '</th>
										<th class="manage-column textcenter">' . __( 'Image generated', 'sirsc' ) . '<br />(' . __( 'original has ', 'sirsc' ) . $original_w . 'x' . $original_h . 'px)</th>
										<th class="manage-column textright">' . __( 'Actions', 'sirsc' ) . '</th>
									</tr>
								</thead>
								<tbody id="the-list">
							';
							$count = 0;
							foreach ( $all_size as $k => $v ) {
								$count ++;
								$rez_img = $this->allow_resize_from_original( $filename, $image, $all_size, $k );
								if ( ! empty( $rez_img['found'] ) ) {
									$ima = wp_get_attachment_image_src( $postData['post_id'], $k );
									$im = '<span id="idsrc' . intval( $postData['post_id'] ) . $k . '"><img src="' . $ima[0] . '" border="0" /><br /> ' . $rez_img['width'] . 'x' . $rez_img['height'] . 'px</span>';
								} else {
									$im = '<span id="idsrc' . intval( $postData['post_id'] ) . $k . '">' . __( 'NOT FOUND', 'sirsc' ) . '</span>';
								}

								$action = '';
								if ( ! empty( $rez_img['is_crop'] ) ) {
									if ( ! empty( $rez_img['can_be_cropped'] ) ) {
										$action .= '<div class="dashicons dashicons-image-crop"></div> ' . __( 'Crop image',
												'sirsc' ) . '<div class="sirsc_clearAll"></div>' . $this->make_generate_images_crop( $postData['post_id'], $k ) . '';
									}
								}

								if ( ! empty( $rez_img['can_be_generated'] ) ) {
									$iddd = intval( $postData['post_id'] ) . $k;
									$action .= '<div class="sirsc_clearAll"></div><a onclick="sirsc_start_regenerate(\'' . $iddd . '\');"><div class="dashicons dashicons-update"></div> ' . __( 'Regenerate', 'sirsc' ) . '</a>';
								} else {
									$action .= __( 'Cannot be generated, the original image is smaller that the requested size.', 'sirsc' );
								}

								$cl = ( $count % 2 == 1 ) ? 'alternate' : '';
								$size_text = $this->size_to_text( $v );

								echo '
								<tr class="' . $cl . '" id="sirsc_recordsArray_' . intval( $postData['post_id'] ) . $k . '">
									<input type="hidden" name="post_id" id="post_id' . intval( $postData['post_id'] ) . $k . '" value="' . intval( $postData['post_id'] ) . '" />
									<input type="hidden" name="selected_size" id="selected_size' . intval( $postData['post_id'] ) . $k . '" value="' . $k . '" />
									<td class="image-size-column"><b>' . $k . '</b><br />(' . $size_text . ')</td>
									<td class="image-src-column"><div class="result_inline"><div id="sirsc_recordsArray_' . intval( $postData['post_id'] ) . $k . '_result" class="result inline"><span class="spinner off"></span></div></div>' . $im . '</td>
									<td class="sirsc_image-action-column">' . $action . '</td>
								</tr>
								';
							}
							echo '
								</tbody>
							</table>';
						}

						echo '</div></div>';
						echo '<script>
						jQuery(document).ready(function () { 
							sirsc_arrange_center_element(\'.sirsc_image-size-selection-box\');
						 });</script>';
					} else {
						echo '<span class="sirsc_successfullysaved">' . __( 'The file is missing !', 'sirsc' ) . '</span>';
					}
				}
			} else {
				echo '<span class="sirsc_successfullysaved">' . __( 'Something went wrong !', 'sirsc' ) . '</span>';
			}
		}
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::ajax_process_image_sizes_on_request() Regenerate the image sizes for a specified image
	 */
	function ajax_process_image_sizes_on_request() {
		if ( ! empty( $_REQUEST['sirsc_data'] ) ) {
			$postData = $this->parse_ajax_data( $_REQUEST['sirsc_data'] );
			if ( ! empty( $postData['post_id'] ) ) {
				$post = get_post( $postData['post_id'] );
				if ( ! empty( $post ) ) {
					$this->load_settings_for_post_id( intval( $postData['post_id'] ) );
					$sizes = ( ! empty( $postData['selected_size'] ) ) ? $postData['selected_size'] : 'all';
					$crop_small_type = ( ! empty( $postData['crop_small_type'] ) ) ? $postData['crop_small_type'] : '';
					$this->make_images_if_not_exists( $postData['post_id'], $sizes, $crop_small_type );
					if ( $sizes != 'all' ) {
						$image = wp_get_attachment_metadata( $postData['post_id'] );
						$th = wp_get_attachment_image_src( $postData['post_id'], $sizes );
						$th_src = $th[0];

						$crop_table = '';
						$tmp_details = $this->get_all_image_sizes( $sizes );
						if ( ! empty( $tmp_details['crop'] ) ) {
							$crop_table = '<div class="dashicons dashicons-image-crop"></div>' . __( 'Crop image', 'sirsc' ) . '<div class="sirsc_clearAll"></div>' . preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', $this->make_generate_images_crop( intval( $postData['post_id'] ), $sizes ) ) . '<div class="sirsc_clearAll"></div><a onclick="sirsc_start_regenerate(\'' . intval( $postData['post_id'] ) . $sizes . '\');"><div class="dashicons dashicons-update"></div> Regenerate</a>';
						}

						echo '<script>
						jQuery(document).ready(function () {
							sirsc_thumbnail_details(\'' . intval( $postData['post_id'] ) . '\', \'' . $sizes . '\', \'' . $th_src . '?cache=' . time() . '\', \'' . $image['sizes'][$sizes]['width'] . '\', \'' . $image['sizes'][$sizes]['height'] . '\', \'' . addslashes( $crop_table ) . '\');
						});
						</script>';
					}
					echo '<span class="sirsc_successfullysaved">' . __( 'Done !', 'sirsc' ) . '</span>';
				}
			} else {
				echo '<span class="sirsc_successfullysaved">' . __( 'Something went wrong !', 'sirsc' ) . '</span>';
			}
		}
	}

	/** SIRSC_Image_Regenerate_Select_Crop::make_images_if_not_exists() Create the image for a specified attachment and image size if that does not exist and update the image metadata. This is usefull for example in the cases when the server configuration does not permit to generate many images from a single uploaded image (timeouts or image sizes defined after images have been uploaded already). This should be called before the actual call of wp_get_attachment_image_src with a specified image size
	 *
	 * @param int $id : id of the attachment
	 * @param array $selected_size : the set of defined image sizes used by the site
	 * @param array $small_crop : the position of a potential crop (lt = left/top, lc = left/center, etc.)
	 */
	public function make_images_if_not_exists( $id, $selected_size = 'all', $small_crop = '' ) {
		try {
			$execute_crop = false;
			self::load_settings_for_post_id( $id );
			$alls = self::get_all_image_sizes_plugin();
			if ( 'all' == $selected_size ) {
				$sizes = $alls;
			} else {
				if ( ! empty( $selected_size ) && ! empty( $alls[$selected_size] ) ) {
					$sizes = array(
						$selected_size => $alls[$selected_size],
					);
					$execute_crop = true;

					if ( empty( $small_crop ) ) {
						if ( ! empty( self::$settings['default_crop'][$selected_size] ) )
							$small_crop = self::$settings['default_crop'][$selected_size];
					}
				}
			}
			if ( ! empty( $sizes ) ) {
				$image = wp_get_attachment_metadata( $id );
				$filename = get_attached_file( $id );
				if ( ! empty( $filename ) ) {
					foreach ( $sizes as $sname => $sval ) {
						$execute = false;
						if ( empty( $image['sizes'][$sname] ) ) {
							$execute = true;
						} else {
							/** Check if the file does exist, else generate it */
							if ( empty( $image['sizes'][$sname]['file'] ) ) {
								$execute = true;
							} else {
								$file = str_replace( basename( $filename ), $image['sizes'][$sname]['file'], $filename );
								if ( ! file_exists( $file ) ) {
									$execute = true;
								} else {
									/** Check if the file does exist and has the required width and height */
									$w = ( ! empty( $sval['width'] ) ) ? intval( $sval['width'] ) : 0;
									$h = ( ! empty( $sval['height'] ) ) ? intval( $sval['height'] ) : 0;
									$c = ( ! empty( $sval['crop'] ) ) ? $sval['crop'] : false;
									$c_image_size = getimagesize( $file );
									$ciw = intval( $c_image_size[0] );
									$cih = intval( $c_image_size[1] );
									if ( ! empty( $c ) ) {
										if ( $w != $ciw || $h != $cih ) {
											$execute = true;
										}
									} else {
										if ( ( $w == 0 && $cih <= $h ) || ( $h == 0 && $ciw <= $w ) || ( $w != 0 && $h != 0 && $ciw <= $w && $cih <= $h ) ) {
											$execute = true;
										}
									}
								}
							}
						}

						if ( $execute ) {
							$w = ( ! empty( $sval['width'] ) ) ? intval( $sval['width'] ) : 0;
							$h = ( ! empty( $sval['height'] ) ) ? intval( $sval['height'] ) : 0;
							$c = ( ! empty( $sval['crop'] ) ) ? $sval['crop'] : false;
							$new_meta = image_make_intermediate_size( $filename, $w, $h, $c );
							if ( $new_meta ) {
								$img_meta = wp_get_attachment_metadata( $id );
								$img_meta['sizes'][$sname] = $new_meta;
								wp_update_attachment_metadata( $id, $img_meta );
							} else {
								/** Let's check if there is already an image with the same size but under different size name (in order to update the attachment metadata in a proper way) as in this case the image_make_intermediate_size will not return anything as the image with the specified parameters already exists. */
								$found_one = false;
								$all_know_sizes = $image['sizes'];
								$img_meta = wp_get_attachment_metadata( $id );

								/** We can use the original size if this is a match for the missing generated image size */
								$all_know_sizes['**full**'] = array(
									'file'   => basename( $img_meta['file'] ),
									'width'  => ( ! empty( $img_meta['width'] ) ) ? intval( $img_meta['width'] ) : 0,
									'height' => ( ! empty( $img_meta['height'] ) ) ? intval( $img_meta['height'] ) : 0,
								);

								/** This is a strange case when the image size is only a DPI resolution variation */
								if ( $w == 0 && $h == 0 ) {
									$w = $all_know_sizes['**full**']['width'];
									$h = $all_know_sizes['**full**']['height'];
								}

								if ( true === $c ) {
									/** We are looking for a perfect match image */
									foreach ( $all_know_sizes as $aisv ) {
										if ( $aisv['width'] == $w && $aisv['height'] == $h ) {
											$tmpfile = str_replace( basename( $filename ), $aisv['file'], $filename );
											if ( file_exists( $tmpfile ) ) {
												$found_one = $aisv;
												break;
											}
										}
									}
								} else {
									if ( $w == 0 ) {
										/** For scale to maximum height */
										foreach ( $all_know_sizes as $aisv ) {
											if ( $aisv['height'] == $h && $aisv['width'] != 0 ) {
												$tmpfile = str_replace( basename( $filename ), $aisv['file'], $filename );
												if ( file_exists( $tmpfile ) ) {
													$found_one = $aisv;
													break;
												}
											}
										}
									} elseif ( $h == 0 ) {
										/** For scale to maximum width */
										foreach ( $all_know_sizes as $aisv ) {
											if ( $aisv['width'] == $w && $aisv['height'] != 0 ) {
												$tmpfile = str_replace( basename( $filename ), $aisv['file'], $filename );
												if ( file_exists( $tmpfile ) ) {
													$found_one = $aisv;
													break;
												}
											}
										}
									} else {
										/** For scale to maximum width or maximum height */
										foreach ( $all_know_sizes as $aisv ) {
											if ( ( $aisv['height'] == $h && $aisv['width'] != 0 ) || ( $aisv['width'] == $w && $aisv['height'] != 0 ) ) {
												$tmpfile = str_replace( basename( $filename ), $aisv['file'], $filename );
												if ( file_exists( $tmpfile ) ) {
													$found_one = $aisv;
													break;
												}
											}
										}
									}
								}
								if ( $found_one ) {
									$img_meta = wp_get_attachment_metadata( $id );
									$img_meta['sizes'][$sname] = $found_one;
									wp_update_attachment_metadata( $id, $img_meta );
								}
							}
						}

						/** Re-cut the specified image size to the specified position */
						if ( $selected_size && ! empty( $small_crop ) ) {
							if ( $selected_size == $sname ) {
								$w = ( ! empty( $sval['width'] ) ) ? intval( $sval['width'] ) : 0;
								$h = ( ! empty( $sval['height'] ) ) ? intval( $sval['height'] ) : 0;
								$cV = $small_crop{0};
								if ( $cV == 'l' ) {
									$cV = 'left';
								}
								if ( $cV == 'c' ) {
									$cV = 'center';
								}
								if ( $cV == 'r' ) {
									$cV = 'right';
								}
								$cH = $small_crop{1};
								if ( $cH == 't' ) {
									$cH = 'top';
								}
								if ( $cH == 'c' ) {
									$cH = 'center';
								}
								if ( $cH == 'b' ) {
									$cH = 'bottom';
								}
								$c = array( $cV, $cH );
								$img = wp_get_image_editor( $filename );
								if ( ! is_wp_error( $img ) ) {
									$img->resize( $w, $h, $c );
									$img->set_quality( 100 );
									$saved = $img->save();
									if ( ! empty( $saved ) ) {
										$img_meta = wp_get_attachment_metadata( $id );
										$img_meta['sizes'][$sname] = $saved;
										wp_update_attachment_metadata( $id, $img_meta );
									}
								}
							}
						}
					}
				}
			}
		} catch ( ErrorException $e ) {
		}
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::size_to_text() Returns a text description of an image size details
	 */
	function size_to_text( $v ) {
		if ( $v['height'] == 0 ) {
			$size_text = '<b>' . __( 'scale', 'sirsc' ) . '</b> ' . __( 'to max width of', 'sirsc' ) . ' <b>' . $v['width'] . '</b>px';
		} elseif ( $v['width'] == 0 ) {
			$size_text = '<b>' . __( 'scale', 'sirsc' ) . '</b> ' . __( 'to max height of', 'sirsc' ) . ' <b>' . $v['height'] . '</b>px';
		} else {
			if ( ! empty( $v['crop'] ) ) {
				$size_text = '<b>' . __( 'crop', 'sirsc' ) . '</b> ' . __( 'of', 'sirsc' ) . ' <b>' . $v['width'] . '</b>x<b>' . $v['height'] . '</b>px';
			} else {
				$size_text = '<b>' . __( 'scale', 'sirsc' ) . '</b> ' . __( 'to max width of', 'sirsc' ) . ' <b>' . $v['width'] . '</b>px ' . __( 'or to max height of', 'sirsc' ) . ' <b>' . $v['height'] . '</b>px';
			}
		}
		return $size_text;
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::get_all_image_sizes() Returns an array of all the image sizes registered in the application
	 */
	public static function get_all_image_sizes( $size = '' ) {
		global $_wp_additional_image_sizes;
		$sizes = array();
		$get_intermediate_image_sizes = get_intermediate_image_sizes();

		/** Create the full array with sizes and crop info */
		foreach ( $get_intermediate_image_sizes as $_size ) {
			if ( in_array( $_size, array( 'thumbnail', 'medium', 'large' ) ) ) {
				$sizes[$_size]['width'] = get_option( $_size . '_size_w' );
				$sizes[$_size]['height'] = get_option( $_size . '_size_h' );
				$sizes[$_size]['crop'] = (bool) get_option( $_size . '_crop' );
			} elseif ( isset( $_wp_additional_image_sizes[$_size] ) ) {
				$sizes[$_size] = array(
					'width'  => $_wp_additional_image_sizes[$_size]['width'],
					'height' => $_wp_additional_image_sizes[$_size]['height'],
					'crop'   => $_wp_additional_image_sizes[$_size]['crop']
				);
			}
		}

		/** Get only 1 size if found */
		if ( $size ) {
			if ( isset( $sizes[$size] ) ) {
				return $sizes[$size];
			} else {
				return false;
			}
		}
		return $sizes;
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::get_all_image_sizes_plugin() Returns an array of all the image sizes registered in the application filtered by the plugin settings and for a specified image size name
	 */
	function get_all_image_sizes_plugin( $size = '' ) {
		$sizes = self::get_all_image_sizes( $size );
		if ( ! empty( self::$settings['exclude'] ) ) {
			$newSizes = array();
			foreach ( $sizes as $k => $si ) {
				if ( ! in_array( $k, self::$settings['exclude'] ) ) {
					$newSizes[$k] = $si;
				}
			}
			$sizes = $newSizes;
		}

		/** Get only 1 size if found */
		if ( $size ) {
			if ( isset( $sizes[$size] ) ) {
				return $sizes[$size];
			} else {
				return false;
			}
		}
		return $sizes;
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::get_all_post_types_plugin() Returns an array of all the post types allowed in the plugin filters
	 */
	public static function get_all_post_types_plugin() {
		$post_types = get_post_types( array(), 'objects' );
		if ( ! empty( $post_types ) && ! empty( self::$exclude_post_type ) ) {
			foreach ( self::$exclude_post_type as $k ) {
				unset( $post_types[$k] );
			}
		}
		return $post_types;
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::calculate_total_to_cleanup() Returns the number if images of "image size name" that can be clean up for a specified post type if is set, or the global number of images that can be clean up for the "image size name"
	 */
	function calculate_total_to_cleanup( $post_type = '', $image_size_name = '', $next_post_id = 0 ) {
		global $wpdb;
		$total_to_delete = 0;
		if ( ! empty( $image_size_name ) ) {
			$cond_join = '';
			$cond_where = '';
			if ( ! empty( $post_type ) ) {
				$cond_join = " LEFT JOIN " . $wpdb->posts . " as parent ON( parent.ID = p.post_parent )";
				$cond_where = $wpdb->prepare( " AND parent.post_type = %s ", $post_type );
			}
			$tmpQuery = $wpdb->prepare( "
				SELECT count( p.ID ) as total_to_delete
				FROM " . $wpdb->posts . " as p
				LEFT JOIN " . $wpdb->postmeta . " as pm ON(pm.post_id = p.ID)
				" . $cond_join . "
				WHERE pm.meta_key like %s
				AND pm.meta_value like %s
				AND p.ID > %d
				" . $cond_where,
				'_wp_attachment_metadata',
				'%' . $image_size_name . '%',
				intval( $next_post_id )
			);

			$rows = $wpdb->get_results( $tmpQuery, ARRAY_A );
			if ( ! empty( $rows ) && is_array( $rows ) ) {
				$total_to_delete = $rows[0]['total_to_delete'];
			}
		}
		return $total_to_delete;
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::ajax_cleanup_image_sizes_on_request() Remove the images from the folders and database records for the specified image size name
	 */
	function ajax_cleanup_image_sizes_on_request() {
		if ( ! empty( $_REQUEST['sirsc_data'] ) ) {
			$postData = $this->parse_ajax_data( $_REQUEST['sirsc_data'] );
			if ( ! empty( $postData['_sisrsc_image_size_name'] ) ) {
				global $wpdb;
				$_sisrsc_image_size_name = ( ! empty( $postData['_sisrsc_image_size_name'] ) ) ? $postData['_sisrsc_image_size_name'] : '';
				$_sisrsc_post_type = ( ! empty( $postData['_sisrsc_post_type'] ) ) ? $postData['_sisrsc_post_type'] : '';
				$next_post_id = ( ! empty( $postData['_sisrsc_image_size_name_page'] ) ) ? $postData['_sisrsc_image_size_name_page'] : 0;
				$max_in_one_go = 10;
				$total_to_delete = $this->calculate_total_to_cleanup( $_sisrsc_post_type, $postData['_sisrsc_image_size_name'], $next_post_id );
				$remaining_to_delete = $total_to_delete;
				if ( $total_to_delete > 0 ) {
					$cond_join = '';
					$cond_where = '';
					if ( ! empty( $_sisrsc_post_type ) ) {
						$cond_join = " LEFT JOIN " . $wpdb->posts . " as parent ON( parent.ID = p.post_parent )";
						$cond_where = $wpdb->prepare( " AND parent.post_type = %s ", $_sisrsc_post_type );
					}
					echo '
					<div class="sirsc_under-image-options"></div>
					<div class="sirsc_image-size-selection-box">
						<div class="sirsc_options-title">
							<div class="sirsc_options-close-button-wrap"><a class="sirsc_options-close-button" onclick="jQuery(\'#_sirsc_cleanup_initiated_for_' . esc_attr( $postData['_sisrsc_image_size_name'] ) . '_result\').html(\'\');"><span class="dashicons dashicons-dismiss"></span></a></div>
							<h2>' . __( 'REMAINING TO CLEAN UP : ', 'sirsc' ) . $total_to_delete . '</h2>
						</div>
						<div class="inside">';

					$tmpQuery = $wpdb->prepare( "
						SELECT p.ID
						FROM " . $wpdb->posts . " as p
						LEFT JOIN " . $wpdb->postmeta . " as pm ON(pm.post_id = p.ID)
						" . $cond_join . "
						WHERE pm.meta_key like %s 
						AND pm.meta_value like %s
						AND p.ID > %d
						" . $cond_where . "
						ORDER BY pm.meta_id ASC
						LIMIT 0, %d
						",
						'_wp_attachment_metadata',
						'%' . $postData['_sisrsc_image_size_name'] . '%',
						intval( $next_post_id ),
						intval( $max_in_one_go )
					);
					$rows = $wpdb->get_results( $tmpQuery, ARRAY_A );
					if ( ! empty( $rows ) && is_array( $rows ) ) {
						echo '<ul>';
						foreach ( $rows as $v ) {
							echo '<li><hr />';
							$image_meta = wp_get_attachment_metadata( $v['ID'] );
							$filename = realpath( get_attached_file( $v['ID'] ) );
							$unset = false;
							$deleted = false;
							if ( ! empty( $filename ) ) {

								$string = ( ! empty( $image_meta['sizes'][$_sisrsc_image_size_name]['file'] ) ) ? $image_meta['sizes'][$_sisrsc_image_size_name]['file'] : '';

								$file = str_replace( basename( $filename ), $string, $filename );
								$file = realpath( $file );
								$th = wp_get_attachment_image_src( $v['ID'], $_sisrsc_image_size_name );
								$th_src = $th[0];
								if ( file_exists( $file ) && $file != $filename ) {
									/** Make sure not to delete the original file */
									echo __( 'The image ', 'sirsc' ) . ' <b>' . $th_src . '</b> ' . __( 'has been deleted.', 'sirsc' );
									@unlink( $file );
									$unset = true;
									$deleted = true;
								} else {
									echo __( 'The image', 'sirsc' ) . ' <b>' . $th_src . '</b> ' . __( 'could not be deleted (it is the original file).', 'sirsc' );
								}
							}

							if ( $unset ) {
								unset( $image_meta['sizes'][$_sisrsc_image_size_name] );
								wp_update_attachment_metadata( $v['ID'], $image_meta );
							} else {
								unset( $image_meta['sizes'][$_sisrsc_image_size_name] );
								wp_update_attachment_metadata( $v['ID'], $image_meta );
								if ( ! $deleted ) {
									echo __( 'The image ', 'sirsc' ) . $_sisrsc_image_size_name . __( ' could not be found. ', 'sirsc' );
								}
							}
							$remaining_to_delete --;
							$next_post_id = $v['ID'];
							echo '</li>';
						}
						echo '</ul>';
					}
					echo '
						</div>
					</div>';
				}
				if ( $remaining_to_delete > 0 ) {
					echo '<script>
					jQuery(document).ready(function () {
						sirsc_continue_cleanup(\'' . esc_attr( $_sisrsc_image_size_name ) . '\', \'' . intval( $next_post_id ) . '\');
					 }).delay(2000);
					</script>';
				} else {
					echo '
					<script>
					jQuery(document).ready(function () { 
						sirsc_finish_cleanup(\'' . esc_attr( $_sisrsc_image_size_name ) . '\');
					}).delay(4000);
					</script>';
					echo '<span class="sirsc_successfullysaved">' . __( 'Done !', 'sirsc' ) . '</span>';
				}
			} else {
				echo '<span class="sirsc_successfullysaved">' . __( 'Something went wrong !', 'sirsc' ) . '</span>';
			}
		}
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::ajax_regenerate_image_sizes_on_request() Regenerate all the images for the specified image size name
	 */
	function ajax_regenerate_image_sizes_on_request() {
		if ( ! empty( $_REQUEST['sirsc_data'] ) ) {
			$postData = $this->parse_ajax_data( $_REQUEST['sirsc_data'] );
			if ( ! empty( $postData['_sisrsc_regenerate_image_size_name'] ) ) {
				global $wpdb;
				$_sisrsc_post_type = ( ! empty( $postData['_sisrsc_post_type'] ) ) ? $postData['_sisrsc_post_type'] : '';
				$cond_join = '';
				$cond_where = '';
				if ( ! empty( $_sisrsc_post_type ) ) {
					$cond_join = " LEFT JOIN " . $wpdb->posts . " as parent ON( parent.ID = p.post_parent )";
					$cond_where = $wpdb->prepare( " AND parent.post_type = %s ", $_sisrsc_post_type );
				}
				$next_post_id = ( ! empty( $postData['_sisrsc_regenerate_image_size_name_page'] ) ) ? $postData['_sisrsc_regenerate_image_size_name_page'] : 0;
				$total_to_update = 0;
				$tmpQuery = $wpdb->prepare( "
					SELECT count( p.ID ) as total_to_update
					FROM " . $wpdb->posts . " as p
					" . $cond_join . "
					WHERE p.ID > %d
					AND p.post_mime_type like %s
					" . $cond_where . "
					ORDER BY p.ID ASC 
					",
					intval( $next_post_id ),
					'image/%'
				);
				$rows = $wpdb->get_results( $tmpQuery, ARRAY_A );
				if ( ! empty( $rows ) && is_array( $rows ) ) {
					$total_to_update = $rows[0]['total_to_update'];
				}
				if ( $total_to_update > 0 ) {
					echo '
					<div class="sirsc_under-image-options"></div>
					<div class="sirsc_image-size-selection-box">
						<div class="sirsc_options-title">
							<div class="sirsc_options-close-button-wrap"><a class="sirsc_options-close-button" onclick="jQuery(\'#_sirsc_regenerate_initiated_for_' . esc_attr( $postData['_sisrsc_regenerate_image_size_name'] ) . '_result\').html(\'\')"><span class="dashicons dashicons-dismiss"></span></a></div>
							<h2>' . __( 'REMAINING TO REGENERATE : ', 'sirsc' ) . $total_to_update . '</h2>
						</div>
						<div class="inside">';
					$tmpQuery = $wpdb->prepare( "
						SELECT p.ID
						FROM " . $wpdb->posts . " as p
						" . $cond_join . "
						WHERE p.ID > %d
						AND p.post_mime_type like %s
						" . $cond_where . "
						ORDER BY p.ID ASC 
						LIMIT 0, 1
						",
						intval( $next_post_id ),
						'image/%'
					);
					$rows = $wpdb->get_results( $tmpQuery, ARRAY_A );
					if ( ! empty( $rows ) && is_array( $rows ) ) {
						foreach ( $rows as $v ) {
							echo '<center><hr />';
							$filename = get_attached_file( $v['ID'] );
							if ( ! empty( $filename ) && file_exists( $filename ) ) {
								$this->make_images_if_not_exists( $v['ID'], $postData['_sisrsc_regenerate_image_size_name'] );
								$image = wp_get_attachment_metadata( $v['ID'] );
								$th = wp_get_attachment_image_src( $v['ID'], $postData['_sisrsc_regenerate_image_size_name'] );
								if ( ! empty( $th[0] ) ) {
									$th_src = $th[0];
									echo '<img src="' . $th_src . '?cache=' . time() . '" /><hr />' . $th_src;
								} else {
									echo __( 'Could not generate, the original is too small.', 'sirsc' ) . '<hr />';
								}
							} else {
								echo __( 'Could not generate, the original file is missing.', 'sirsc' ) . '<hr />' . $filename;
							}
							echo '</center>';
							$next_post_id = $v['ID'];
						}
					}
					echo '
						</div>
					</div>';
				}
				$remaining_to_update = $total_to_update - 1;
				if ( $remaining_to_update >= 0 ) {
					echo '<script>
					jQuery(document).ready(function () {
						sirsc_continue_regenerate(\'' . esc_attr( $postData['_sisrsc_regenerate_image_size_name'] ) . '\', \'' . intval( $next_post_id ) . '\');
					 }).delay(500);
					</script>';
				} else {
					echo '
					<script>
					jQuery(document).ready(function () { 
						sirsc_finish_regenerate(\'' . esc_attr( $postData['_sisrsc_regenerate_image_size_name'] ) . '\');
					}).delay(500);
					</script>';
					echo '<span class="sirsc_successfullysaved">' . __( 'Done !', 'sirsc' ) . '</span>';
				}
			} else {
				echo '<span class="sirsc_successfullysaved">' . __( 'Something went wrong !', 'sirsc' ) . '</span>';
			}
		}
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::image_downsize_placeholder_force_global() Replace all the front side images retrieved programmatically with wp function with the placeholders instead of the full size image.
	 */
	function image_downsize_placeholder_force_global( $f, $id, $s ) {
		$img_url = $this->image_placeholder_for_image_size( $s );
		$size = $this->get_all_image_sizes( $s );
		$alternative = array( $img_url, $size['width'], $size['height'], true );
		return $alternative;
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::image_downsize_placeholder_only_missing() Replace the missing images sizes with the placeholders instead of the full size image. As the "image size name" is specified, we know what width and height the resulting image should have. Hence, first, the potential image width and height are matched against the entire set of image sizes defined in order to identify if there is the exact required image either an alternative file with the specific required width and height already generated for that width and height but with another "image size name" in the database or not.
	 *
	 * Basically, the first step is to identify if there is an image with the required width and height. If that is identified, it will be presented, regardless of the fact that the "image size name" is the requested one or it is not even yet defined for this specific post (due to a later definition of the image in the project development).
	 *
	 * If the image to be presented is not identified at any level, then the code is trying to identify the appropriate theme placeholder for the requested "image size name". For that we are using the placeholder function with the requested "image size name".
	 *
	 * If the placeholder exists, then this is going to be presented, else we are logging the missing placeholder alternative that can be added in the image_placeholder_for_image_size function.
	 */
	function image_downsize_placeholder_only_missing( $f, $id, $s ) {
		$all_sizes = $this->get_all_image_sizes();
		if ( 'full' != $s && ! empty( $all_sizes[$s] ) ) {
			try {
				$execute = false;
				$image = wp_get_attachment_metadata( $id );
				$filename = get_attached_file( $id );
				$rez_img = $this->allow_resize_from_original( $filename, $image, $all_sizes, $s );
				$upload_dir = wp_upload_dir();
				if ( ! empty( $rez_img['found'] ) ) {
					$url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $rez_img['path'] );
					$crop = ( ! empty( $rez_img['is_crop'] ) ) ? true : false;
					$alternative = array( $url, $rez_img['width'], $rez_img['height'], $crop );
					return $alternative;
				}
				$requestW = $all_sizes[$s]['width'];
				$requestH = $all_sizes[$s]['height'];
				$alternative = array(
					'name'         => $s,
					'file'         => $f,
					'width'        => $requestW,
					'height'       => $requestH,
					'intermediate' => true,
				);
				$found_match = false;
				if ( $requestW == $image['width'] && $requestH == $image['height'] && ! empty( $image['file'] ) ) {
					$tmp_file = str_replace( basename( $filename ), basename( $image['file'] ), $filename );
					if ( file_exists( $tmp_file ) ) {
						$folder = str_replace( $upload_dir['basedir'], '', $filename );
						$old_file = basename( str_replace( $upload_dir['basedir'], '', $filename ) );
						$folder = str_replace( $old_file, '', $folder );
						$alternative = array(
							'name'         => 'full',
							'file'         => $upload_dir['baseurl'] . $folder . basename( $image['file'] ),
							'width'        => $image['width'],
							'height'       => $image['height'],
							'intermediate' => false,
						);
						$found_match = true;
					}
				}
				foreach ( $image['sizes'] as $name => $var ) {
					if ( $found_match ) {
						break;
					}
					if ( $requestW == $var['width'] && $requestH == $var['height'] && ! empty( $var['file'] ) ) {
						$tmp_file = str_replace( basename( $filename ), $var['file'], $filename );
						if ( file_exists( $tmp_file ) ) {

							$folder = str_replace( $upload_dir['basedir'], '', $filename );
							$old_file = basename( str_replace( $upload_dir['basedir'], '', $filename ) );
							$folder = str_replace( $old_file, '', $folder );
							$alternative = array(
								'name'         => $name,
								'file'         => $upload_dir['baseurl'] . $folder . $var['file'],
								'width'        => $var['width'],
								'height'       => $var['height'],
								'intermediate' => true,
							);
							$found_match = true;
							break;
						}
					}
				}

				if ( ! empty( $alternative ) && $found_match ) {
					$placeholder = array( $alternative['file'], $alternative['width'], $alternative['height'], $alternative['intermediate'] );
					return $placeholder;
				} else {
					$img_url = $this->image_placeholder_for_image_size( $s );
					if ( ! empty( $img_url ) ) {
						$width = $requestW;
						$height = $requestW;
						$is_intermediate = true;
						$placeholder = array( $img_url, $width, $height, $is_intermediate );
						return $placeholder;
					} else {
						return;
					}
				}
			} catch ( ErrorException $e ) {
				/** Nothing to do */
			}
		}
	}

	/**
	 * SIRSC_Image_Regenerate_Select_Crop::image_placeholder_for_image_size() Generate a placeholder image for a specified image size name
	 */
	function image_placeholder_for_image_size( $selected_size, $force_update = false ) {
		$dest = realpath( PLACEHOLDER_FOLDER ) . '/' . $selected_size . '.png';
		$dest_url = esc_url( PLACEHOLDER_URL . '/' . $selected_size . '.png' );
		if ( file_exists( $dest ) ) {
			if ( ! $force_update ) {
				return $dest_url;
			}
		}
		$alls = $this->get_all_image_sizes_plugin();
		$size = $alls[$selected_size];
		$iw = $size['width'];
		$ih = $size['height'];
		if ( ! empty( $size['width'] ) && empty( $size['height'] ) ) {
			$ih = $iw;
		} elseif ( empty( $size['width'] ) && ! empty( $size['height'] ) ) {
			$iw = $ih;
		}
		if ( $iw >= 9999 ) {
			$iw = $this->limit9999;
		}
		if ( $ih >= 9999 ) {
			$ih = $this->limit9999;
		}
		$im = @imagecreatetruecolor( $iw, $ih );
		$white = @imagecolorallocate( $im, 255, 255, 255 );
		$rand = @imagecolorallocate( $im, mt_rand( 0, 150 ), mt_rand( 0, 150 ), mt_rand( 0, 150 ) );
		@imagefill( $im, 0, 0, $rand );
		$font = @realpath( PLUGIN_FOLDER . '/assets/fonts' ) . '/arial.ttf';
		@imagettftext( $im, 6.5, 0, 2, 10, $white, $font, 'placeholder' );
		@imagettftext( $im, 6.5, 0, 2, 20, $white, $font, $selected_size );
		@imagettftext( $im, 6.5, 0, 2, 30, $white, $font, $size['width'] . 'x' . $size['height'] . 'px' );
		@imagepng( $im, $dest, 9 );
		@imagedestroy( $im );
		return $dest_url;
	}
}

SIRSC_Image_Regenerate_Select_Crop::get_instance();


if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Quick WP-CLI command to for SIRSC plugin that allows to regenerate and remove images
	 */
	class SIRSC_Image_Regenerate_Select_Crop_CLI_Command extends WP_CLI_Command
	{
		private static function prepare_args( $args ) {
			$rez = array(
				'site_id'   => 1,
				'post_type' => '',
				'size_name' => '',
				'parent_id' => '',
				'all_sizes' => array(),
			);
			if ( ! isset( $args[0] ) ) {
				WP_CLI::error( __( 'Please specify the site id (1 if not multisite).', 'sirsc' ) );
				return;
			} else {
				$rez['site_id'] = intval( $args[0] );
			}
			switch_to_blog( $rez['site_id'] );
			WP_CLI::line( '******* ****************************************** *******' );
			WP_CLI::line( '******* EXECUTE OPERATION ON SITE ' . $rez['site_id'] . ' *******' );
			if ( ! isset( $args[1] ) ) {
				$post_types = get_option( 'sirsc_types_options', array() );
				if ( ! empty( $post_types ) ) {
					$av = '';
					foreach ( $post_types as $k => $v ) {
						$av .= ( '' == $av ) ? '' : ', ';
						$av .= $v;
					}
				} else {
					$post_types = SIRSC_Image_Regenerate_Select_Crop::get_all_post_types_plugin();
					$av = '';
					foreach ( $post_types as $k => $v ) {
						$av .= ( '' == $av ) ? '' : ', ';
						$av .= $k;
					}
				}
				WP_CLI::error( 'Please specify the post type (one of: ' . $av . ', etc).' );
				return;
			} else {
				$rez['post_type'] = trim( $args[1] );
			}
			$all_sizes = SIRSC_Image_Regenerate_Select_Crop::get_all_image_sizes();
			$rez['all_sizes'] = $all_sizes;
			if ( ! isset( $args[2] ) ) {
				$ims = '';
				foreach ( $all_sizes as $k => $v ) {
					$ims .= ( '' == $ims ) ? '' : ', ';
					$ims .= $k;
				}
				WP_CLI::error( 'Please specify the image size name (one of: ' . $ims . ').' );
				return;
			} else {
				if ( 'all' == $args[2] || ! empty( $all_sizes[$args[2]] ) ) {
					$rez['size_name'] = trim( $args[2] );
				} else {
					WP_CLI::error( 'Please specify a valid image size name.' );
					return;
				}
			}
			if ( isset( $args[3] ) ) {
				$rez['parent_id'] = intval( $args[3] );
			}

			return $rez;
		}

		/**
		 * Arguments order and types : (int)site_id (string)post_type (string)size_name (int)parent_id
		 */
		function regenerate( $args, $assoc_args ) {
			$config = self::prepare_args( $args );
			if ( ! is_array( $config ) ) {
				return;
			}
			extract( $config );
			if ( ! empty( $post_type ) && ! empty( $size_name ) && ! empty( $all_sizes ) ) {
				global $wpdb;
				$execute_sizes = array();
				if ( 'all' == $size_name ) {
					$execute_sizes = $all_sizes;
				} else {
					if ( ! empty( $all_sizes[$size_name] ) ) {
						$execute_sizes[$size_name] = $size_name;
					}
				}
				$cond_join = $cond_where = '';
				if ( ! empty( $post_type ) && '*' != $post_type ) {
					$cond_join = " INNER JOIN " . $wpdb->posts . " as parent ON( parent.ID = p.post_parent )";
					$cond_where = $wpdb->prepare( " AND parent.post_type = %s ", $post_type );
					if ( ! empty( $parent_id ) ) {
						$cond_where .= $wpdb->prepare( " AND parent.ID = %d ", $parent_id );
						WP_CLI::line( '------- EXECUTE REGENERATE FOR IMAGES ASSOCIATED TO ' . $post_type . ' WITH ID = ' . $parent_id . ' -------' );
					} else {
						$cond_where .= " AND parent.ID IS NOT NULL ";
						WP_CLI::line( '------- EXECUTE REGENERATE FOR ALL IMAGES ASSOCIATED TO ' . $post_type . ' -------' );
					}
				}
				$tmpQuery = $wpdb->prepare( " SELECT p.ID FROM " . $wpdb->posts . " as p " . $cond_join . " WHERE p.post_mime_type like %s " . $cond_where . " ORDER BY p.ID ASC ", 'image/%' );
				$rows = $wpdb->get_results( $tmpQuery, ARRAY_A );
				if ( ! empty( $rows ) && is_array( $rows ) ) {
					if ( ! empty( $execute_sizes ) ) {
						foreach ( $execute_sizes as $sn => $sv ) {
							WP_CLI::line( '-------------- REGENERATE ' . $sn . ' --------------' );
							foreach ( $rows as $v ) {
								$filename = get_attached_file( $v['ID'] );
								if ( ! empty( $filename ) && file_exists( $filename ) ) {
									SIRSC_Image_Regenerate_Select_Crop::make_images_if_not_exists( $v['ID'], $sn );
									$image = wp_get_attachment_metadata( $v['ID'] );
									$th = wp_get_attachment_image_src( $v['ID'], $sn );
									if ( ! empty( $th[0] ) ) {
										WP_CLI::success( $th[0] );
									} else {
										WP_CLI::error( __( 'Could not generate, the original is too small.', 'sirsc' ) );
									}
								} else {
									WP_CLI::error( __( 'Could not generate, the original file is missing ', 'sirsc' ) ) . $filename . ' !';
								}
							}
						}
					}
				}
				WP_CLI::success( 'DONE ALL !!!' );
			} else {
				WP_CLI::error( 'Unexpected ERROR' );
			}
		}

		/**
		 * Arguments order and types : (int)site_id (string)post_type (string)size_name (int)parent_id
		 */
		function cleanup( $args, $assoc_args ) {
			$config = self::prepare_args( $args );
			if ( ! is_array( $config ) ) {
				return;
			}
			extract( $config );
			if ( ! empty( $post_type ) && ! empty( $size_name ) && ! empty( $all_sizes ) ) {
				global $wpdb;
				$execute_sizes = array();
				if ( 'all' == $size_name ) {
					$execute_sizes = $all_sizes;
				} else {
					if ( ! empty( $all_sizes[$size_name] ) ) {
						$execute_sizes[$size_name] = $size_name;
					}
				}
				$cond_join = $cond_where = '';
				if ( ! empty( $post_type ) && '*' != $post_type ) {
					$cond_join = " INNER JOIN " . $wpdb->posts . " as parent ON( parent.ID = p.post_parent )";
					$cond_where = $wpdb->prepare( " AND parent.post_type = %s ", $post_type );
					if ( ! empty( $parent_id ) ) {
						$cond_where .= $wpdb->prepare( " AND parent.ID = %d ", $parent_id );
						WP_CLI::line( '------- EXECUTE REMOVE IMAGES ASSOCIATED TO ' . $post_type . ' WITH ID = ' . $parent_id . ' -------' );
					} else {
						$cond_where .= " AND parent.ID IS NOT NULL ";
						WP_CLI::line( '------- EXECUTE REMOVE ALL IMAGES ASSOCIATED TO ' . $post_type . ' -------' );
					}
				}
				$tmpQuery = $wpdb->prepare( " SELECT p.ID FROM " . $wpdb->posts . " as p " . $cond_join . " WHERE p.post_mime_type like %s " . $cond_where . " ORDER BY p.ID ASC ", 'image/%' );
				$rows = $wpdb->get_results( $tmpQuery, ARRAY_A );
				if ( ! empty( $rows ) && is_array( $rows ) ) {
					if ( ! empty( $execute_sizes ) ) {
						foreach ( $execute_sizes as $sn => $sv ) {
							WP_CLI::line( '-------------- REMOVE ' . $sn . ' --------------' );
							foreach ( $rows as $v ) {
								$image_meta = wp_get_attachment_metadata( $v['ID'] );
								if ( ! empty( $image_meta['sizes'][$sn] ) ) {
									$filename = realpath( get_attached_file( $v['ID'] ) );
									if ( ! empty( $filename ) ) {
										$string = ( ! empty( $image_meta['sizes'][$sn]['file'] ) ) ? $image_meta['sizes'][$sn]['file'] : '';
										$file = str_replace( basename( $filename ), $string, $filename );
										$file = realpath( $file );
										if ( ! empty( $file ) ) {
											if ( file_exists( $file ) && $file != $filename ) {
												/** Make sure not to delete the original file */
												WP_CLI::success( $file . ' ' . __( 'was removed', 'sirsc' ) );
												@unlink( $file );
											} else {
												WP_CLI::line( __( 'Could not remove', 'sirsc' ) . ' ' . $file . __( '. The image is missing or
												it is the original file.', 'sirsc' ) );
											}
										}
									}
									unset( $image_meta['sizes'][$sn] );
									wp_update_attachment_metadata( $v['ID'], $image_meta );
								}
							}
						}
					}
				}
				WP_CLI::success( 'DONE ALL !!!' );
			} else {
				WP_CLI::error( 'Unexpected ERROR' );
			}
		}
	}

	WP_CLI::add_command( 'sirsc', 'SIRSC_Image_Regenerate_Select_Crop_CLI_Command' );
}
