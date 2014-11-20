=== Plugin Name ===
Contributors: Iulia Cazan 
Author URI: https://profiles.wordpress.org/iulia-cazan
Tags: media, image, image sizes, image crop, image regenerate, image sizes details, missing images, image placeholder, image debug
Requires at least: not tested
Tested up to: wp 4.0
Stable tag:  1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Donate Link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=JJA37EHZXWUTJ

== Description ==
The plugin appends two custom buttons that allows you to regenereate and crop the images, provides details about the image sizes registered in the application and the status of each image sizes for images. The plugin also appends a sub-menu to "Settings" that allows you to configure the plugin for global or particular post type attached images and to enable the developer mode for debug if necessary.

The "Details/Options" button will open a lightbox where you can see all the image sizes registered in the application and details about the status of each image sizes for that image. If one of the image sizes has not been found, you will see more details about this and, if possible, the option to manually genereate this (for example the image size width and height are compatible with the original image). For the image sizes that are of "crop" type, you will be able to re-crop in one click the image using a specific portion of the original image: left/top, center/top, right/top, left/center, center/center, right/center, left/bottom, center/bottom, right/bottom. The preview of the result is shown right away, so you can re-crop if necessary.

The "Regenerate" button allows you to regenerate in one click all the image sizes for that specific image. This is really useful when durring development you registered various image sizes and the already uploaded images are "left behind".

The plugin does not require any additional code, it hooks in the admin_post_thumbnail_html and edit_form_top filter and appends two custom buttons that will be shown in "Edit Media" page and in the "Edit Post" and "Edit Page" where there is a featured image. This works also for custom post types. Also, it can be used in different resolutions and responsive layout.

== Installation ==
* Upload `Image Regenerate & Select Crop` to the `/wp-content/plugins/` directory of your application
* Login as Admin
* Activate the plugin through the 'Plugins' menu in WordPress

== Hooks ==
admin_enqueue_scripts, init, add_meta_boxes, wp_ajax_, plugins_loaded, admin_menu, intermediate_image_sizes_advanced, added_post_meta, image_downsize, admin_post_thumbnail_html, edit_form_top, image_regenerate_select_crop_button

== Screenshots ==
1. The custom buttons are added in the featured image box or to edit media page, and by clicking the Details/Options button you can access the details of that image and options to regenerate / crop this for a particular image size. As you can see, the image was not found for the image size name called in the example "4columns", hence, on the front side the full size image is provided.
2. After regenerationg the "4columns" image, you will be able to crop this and preview the result on the fly. Based on the crop type you chose, the front image will be updated.
3. However, you can regenerate all images for a selected image size and the result will be that all the front side tiles from the example will have the same size and fit as required.
4. The developer mode for placeholders allows you to select the global mode or the "only missing images" mode. This allows you to identify on the front side the image sizes names used in different layouts and also you can identify what are the images that did not get to be generated due to various reasons or development steps.

== Frequently Asked Questions ==
None

== Changelog ==
None

== Upgrade Notice ==
None

== License ==
This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. 

== Version history ==
1.0 - Development version.

== Custom Actions ==
If you want to display the custom buttons in your plugins, you can use the custom action with $attachmentId parameter as the image post->ID you want the button for. Usage example : do_action( 'image_regenerate_select_crop_button', $attachmentId );

== Images Placeholders Developer Mode == 
This option allows you to display placeholders for front-side images called programmatically (that are not embedded in content with their src, but retrieved with the wp_get_attachment_image_src, and the other related WP native functions). If there is no placeholder set, then the default behavior would be to display the full size image instead of a missing image size.
If you activate the "force global" option, all the images on the front side that are related to posts will be replaced with the placeholders that mention the image size required. This is useful for debug, to quick identify the image sizes used for each layout. 
If you activate the "only missing images" option, all the images on the front side that are related to posts and do not have the requested image size generate, will be replaced with the placeholders that mention the image size required. This is useful for showing smaller images instead of full size images. 

== Global Ignore ==
This option allows you to exclude globally from the application some of the image sizes that are registered through various plugins and themes options, but you don't need these in your application at all (these are just stored in your folders and database but not used). By excluding these, the unnecessary image sizes will not be generated at all. 

== Hide Preview ==
This option allows you to exclude from the "Image Regenerate & Select Crop Settings" lightbox the details and options for the selected image sizes. This is useful when you want to restrict from other users the functionality of crop or resize for particular image sizes.

== Force Original ==
This option means that the original image will be scaled to a max width or a max height specified by the image size you select. This might be useful if you do not use the original image in any of the layout at the full size, and this might save some storage space. 
Leave "nothing selected" to keep the original image as what you upload. 

== Clean Up All ==
This option allows you to clean up all the image sizes you already have in the application but you don't use these at all. Please be careful, once you click to remove the selected image size, the action is irreversible, the images generated will be deleted from your folders and database records. 

== Regenerate All ==
This option allows you to regenerate all the image for the selected image size Please be careful, once you click to regenerate the selected image size, the action is irreversible, the images already generated will be overwritten.