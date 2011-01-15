<?php
/*
Plugin Name: Photo Dropper
Plugin URI: http://www.photodropper.com/wordpress-plugin/
Description: Enhanced version of Photodropper for Wordpress Media Library integration. Lets you add Creative commons licensed Photos to Your Posts from Flickr. By activating this plugin you agree to be fully responsbile for adhering to Creative Commons licenses for all photos you post to your blog.
Version: 2.0.0
Author: Photodropper
Author URI: http://www.photodropper.com/wordpress-plugin/

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

*/

if (!class_exists("PhotoDropperCL")) {
    class PhotoDropperCL
    {
        var $flickr_post_apikey = "c9509ecd5e4e58943e1865b2c9d237e3";
        var $flickr_post_secret = "4ad13992d0aed4d1";
        var $adminOptionsName = "PhotoDropperCLAdminOptions";
        var $locale_id = "PhotoDropperCL";
        /* Default constructor */
        function PhotoDropperCL()
        {
            $this->applyHooks();
        }

        function applyHooks() {
            // Actions
            // Hook for adding admin menus
            add_action('admin_menu', array($this, 'flickr_post_add_pages'));
            
            add_filter('media_upload_tabs', array($this, 'media_upload_tabs'));
            
            // Media button
            add_action('media_upload_flickr', array($this, 'media_upload_flickr'));
            
            // Filters
        }
        
        function getAdminOptions() {
            $photodropperAdminOptions = array('fper_page' => '5', 'sortbyinteresting' => 'true',
                'commercial_only' => 'true',  'align' => 'right', 'size' => 'small' );
            $pdOptions = get_option($this->adminOptionsName);
            if (!empty($pdOptions)) {
                foreach ( $pdOptions as $key => $option ) {
                    $photodropperAdminOptions[$key] = $option;
                }
            }
            update_option($this->adminOptionsName, $pdOptions);
            return $photodropperAdminOptions;
        }
        
        /**
         * {@internal Missing Short Description}}
         *
         * This handles the file upload POST itself, creating the attachment post.
         *
         * @since unknown
         *
         * @param unknown_type $file_id
         * @param unknown_type $post_id
         * @param unknown_type $post_data
         * @return unknown
         */
        function media_handle_flickr_download($url, $name, $post_id, $post_data = array()) {
            $overrides = array('test_form'=>false);
            $file = download_url( $_POST['photo_url'] );

            $time = current_time('mysql');
            if ( $post = get_post($post_id) ) {
                if ( substr( $post->post_date, 0, 4 ) > 0 )
                    $time = $post->post_date;
            }

            // A writable uploads dir will pass this test. Again, there's no point overriding this one.
            if ( ! ( ( $uploads = wp_upload_dir($time) ) && false === $uploads['error'] ) ) {
                return $upload_error_handler( $file, $uploads['error'] );
            }

            $filename = wp_unique_filename( $uploads['path'], $file );

            // Move the file to the uploads dir
            $new_file = $uploads['path'] . "/$filename";
            $new_file = str_replace(".tmp", ".jpg", $new_file );
            if ( !rename( $file, $new_file ) ) {
                return sprintf( __('The uploaded file could not be moved to %s.' ), $uploads['path'] );
            }

            // Set correct file permissions
            $stat = stat( dirname( $new_file ));
            $perms = $stat['mode'] & 0000666;
            @ chmod( $new_file, $perms );

            // Compute the URL
            $url = $uploads['url'] . "/$filename";

            $name_parts = pathinfo($name);
            $name = trim( substr( $name, 0, -(1 + strlen($name_parts['extension'])) ) );

            $type = 'image/jpeg';
            $title = $name;
            $content = '';

            // use image exif/iptc data for title and caption defaults if possible
            if ( $image_meta = @wp_read_image_metadata($new_file) ) {
                if ( trim($image_meta['title']) )
                    $title = $image_meta['title'];
                if ( trim($image_meta['caption']) )
                    $content = $image_meta['caption'];
            }

            // Construct the attachment array
            $attachment = array_merge( array(
                'post_mime_type' => $type,
                'guid' => $url,
                'post_parent' => $post_id,
                'post_title' => $title,
                'post_content' => $content,
            ), $post_data );

            // Save the data
            $id = wp_insert_attachment($attachment, $new_file, $post_id);
            if ( !is_wp_error($id) ) {
                wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
            }

            return $id;

        }
        function media_upload_flickr_form_handler() {
            $pdOptions = $this->getAdminOptions();
            check_admin_referer('media-form');

            $errors = null;
            
            $post_id = $_POST['post_id'];
            $id = $this->media_handle_flickr_download($file, $_POST['photo_title'], $post_id, $post_data = array()); 

            if ( isset($_POST['insert-gallery']) || isset($_POST['update-gallery']) ) { ?>
                <script type="text/javascript">
                /* <![CDATA[ */
                var win = window.dialogArguments || opener || parent || top;
                win.tb_remove();
                /* ]]> */
                </script>
                <?php
                exit;
            }

            if ( isset($id) ) {
                $photo_page_url = $_POST['photo_page_url'];
                $attachment = array(
                    'url' => $photo_page_url,
                    'align' => $pdOptions['align'],
                    'image-size' => 'small',
                    'image_alt' => $_POST['photo_title'],
                );
                $html = "<a href='{$photo_page_url}'$rel>$html</a>";

                $html = apply_filters('media_send_to_editor', $html, $id, $attachment);
                return media_send_to_editor($html);
            }

            return $errors;
        }
        
        function media_upload_flickr() {
            $errors = array();
            if ( !empty($_POST) ) {
                $return = $this->media_upload_flickr_form_handler();
                echo $return;
                return;
                
                if ( is_string($return) )
                    return $return;
                if ( is_array($return) )
                    $errors = $return;
            }

            return wp_iframe( 'media_upload_flickr_form', $errors );
        }
        
        // Add Photo Dropper to media upload tabs (next to 'From URL', 'From Computer', etc)
        function media_upload_tabs($tabs) {
            $tabs = array_merge($tabs, array('flickr' => __( 'From Flickr' ) ) );
            return $tabs;
        }
        
        function flickr_post_add_pages() {
            wp_enqueue_script('pd-js',get_option('siteurl'). '/wp-content/plugins/photo-dropper/flickr-js.php', array('jquery'));
            add_options_page('Photo Dropper', 'Photo Dropper', 5, __FILE__, array($this,'flickr_post_options_page'));
        }

        function flickr_post_options_page() {
            $pdOptions = $this->getAdminOptions();
            $submit_id = 'update_photodropperAdminSettings';
            ?>
            <div class="wrap">	
            <?php
            if (isset($_POST[$submit_id])) {
                // Save Settings
                if (isset($_POST['fper_page']))
                {
                    if ( is_numeric($_POST['fper_page']) && intval($_POST['fper_page']) > 0) {
                        $pdOptions['fper_page'] = $_POST['fper_page'];
                    }
                }
                
                // Commercial use only
                if ( isset( $_POST['commercial_only']) ) {
                    $pdOptions['commercial_only'] = 'true';
                } else {
                    $pdOptions['commercial_only'] = 'false';
                }
                
                // Sort by interesting posts
                if ( isset( $_POST['sortbyinteresting']) ) {
                    $pdOptions['sortbyinteresting'] = 'true';
                } else {
                    $pdOptions['sortbyinteresting'] = 'false';
                }
                
                if ( isset( $_POST['default_size'] ) ) {
                    switch ( $_POST['default_size'] ) {
                        case 'small':
                        case 'medium':
                        case 'large':
                            $pdOptions['size'] = $_POST['default_size'];
                            break;
                    }
                }
                
                if ( isset( $_POST['default_align']) ) {
                    switch ( $_POST['default_align'] ) {
                        case 'left':
                            $pdOptions['align'] = 'left';
                            break;

                        case 'right':
                            $pdOptions['align'] = 'right';
                            break;

                        case 'center':
                            $pdOptions['align'] = 'center';
                            break;

                        case 'none':
                            $pdOptions['align'] = '';
                            break;
                    }
                }
                
                update_option($this->adminOptionsName, $pdOptions);
                ?>
                    <div id="message" class="updated fade">
                        <p><strong><?php _e('Options Saved!');?></strong></p>
                    </div>
                <?php
            }
            ?>
                <div align="left">
                    <h3><?php _e('Photo Dropper Settings');?></h3>
                    
                    <form method="post" action="<?php echo str_replace( '%7E', '~', $_SERVER['REQUEST_URI']); ?>">
                    <!-- action=3 - Update Options -->
                    <input type="hidden" name="action" value="save" />
                    
                    <br /><br /><strong><?php _e('Pages'); ?></strong>
                    <table class="form-table">
                        <tbody>
                            <tr valign="top">
                                <th scope="row">
                                    <label for="fper_page"><?php _e('Images per page:' ); ?></label>
                                </th>
                                <td>
                                    <input type="text" name="fper_page" id="fper_page" value="<?php echo $pdOptions['fper_page']; ?>" style="padding: 3px; width: 50px;" />
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">
                                    <label for="commercial_only"><?php _e('Show only photos that can be used commercially:'); ?></label>
                                </th>
                                <td>
                                    <input name="commercial_only" type="checkbox" id="commercial_only" value="1" <?php if($pdOptions['commercial_only'] == 'true') echo 'checked="checked" '; ?>/><label for="commercial_only"><?php _e('(Check this box if your blog is a commercial blog.)');?></label>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">
                                    <label for="sortbyinteresting"><?php _e('Sort photos by "most interesting"');?></label>
                                </th>
                                <td>
                                    <input name="sortbyinteresting" type="checkbox" id="sortbyinteresting" value="1" <?php if($pdOptions['sortbyinteresting'] == 'true') echo 'checked="checked" '; ?>/>
                                </td>
                            </tr>
                            <tr valign="top">
                                <th scope="row">
                                    <label for="default_align"><?php _e('Default image alignment:');?></label>
                                </th>
                                <td>
                                    <select name="default_align">
                                        <option value="none"<?php if ($pdOptions['align'] == '') echo " selected='selected'"; ?>>None</option>
                                        <option value="left"<?php if ($pdOptions['align'] == 'left') echo " selected='selected'"; ?>>Left</option>
                                        <option value="center"<?php if ($pdOptions['align'] == 'center') echo " selected='selected'"; ?>>Center</option>
                                        <option value="right"<?php if ($pdOptions['align'] == 'right') echo " selected='selected'"; ?>>Right</option>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" id="<?php echo $submit_id; ?>" name="<?php echo $submit_id; ?>" value="<?php _e('Save Settings') ?> &raquo;" style="font-size: 1.5em;" />
                    </p>
                    </form>
                </div>
                
            </div>	
        <?php	
        }

        function photos_per_page( ) {
            $pdOptions = $this->getAdminOptions();
            //$this->flickr_post_secret;
            $per_page = (int) $pdOptions['fper_page'];
            if ( $per_page < 1 ) $per_page = 5;
            
            return $per_page;
        }

        function getFlickrResults( $query, $page=1 ) {
            $pdOptions = $this->getAdminOptions();
            $photos = array();

            //$this->flickr_post_secret;
            $per_page = (int) $pdOptions['fper_page'];
            if ( $per_page < 1 ) $per_page = 5;
            
            // build the API URL to call
            $params = array(
                'api_key'	=> $this->flickr_post_apikey,
                'method'	=> 'flickr.photos.search',
                'format'	=> 'php_serial',
                'text'      => $query,
                'per_page'  => $per_page,
                'page'      => $page,
            );
            
            if ( $pdOptions['commercial_only'] == 'true' ) {
                $params = array_merge($params, array('license' => '4,5,6'));
            }

            $encoded_params = array();
            foreach ($params as $k => $v){
                $encoded_params[] = urlencode($k).'='.urlencode($v);
            }

            // call the API and decode the response
            $url = "http://api.flickr.com/services/rest/?".implode('&', $encoded_params);
            $rsp = file_get_contents($url);
            $rsp_obj = unserialize($rsp);

            // display the photo title (or an error if it failed)
            if ($rsp_obj['stat'] == 'ok'){
                $photos = $rsp_obj['photos'];
            }else{
                //echo "Call failed!";
            }
            
            return $photos;
        }
    }
    
    $cl_photodropper = new PhotoDropperCL();
} // End class PhotoDropperCL

// Media upload
function media_upload_flickr_form($errors) {
    global $cl_photodropper;
    if ( !isset($cl_photodropper) )
        return;
        
    global $wpdb, $wp_query, $wp_locale, $type, $tab, $post_mime_types;

    media_upload_header();
    $post_id = intval($_REQUEST['post_id']);

    $form_action_url = admin_url("media-upload.php?type=$type&tab=flickr&post_id=$post_id");
    $form_action_url = apply_filters('media_upload_form_url', $form_action_url, $type);

    $_GET['paged'] = isset( $_GET['paged'] ) ? intval($_GET['paged']) : 0;
    if ( $_GET['paged'] < 1 )
        $_GET['paged'] = 1;
    $start = ( $_GET['paged'] - 1 ) * 10;
    if ( $start < 1 )
        $start = 0;
    add_filter( 'post_limits', $limit_filter = create_function( '$a', "return 'LIMIT $start, 10';" ) );

    list($post_mime_types, $avail_post_mime_types) = wp_edit_attachments_query();
    ?>
    
    <link type="text/css" rel="stylesheet" href="<?php echo WP_PLUGIN_URL.'/'.str_replace(basename( __FILE__),"",plugin_basename(__FILE__)); ?>/css/styles.css" />
                <form id="filter" action="" method="get">
    <input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>" />
    <input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
    <input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>" />
    <input type="hidden" name="post_mime_type" value="<?php echo isset( $_GET['post_mime_type'] ) ? esc_attr( $_GET['post_mime_type'] ) : ''; ?>" />

    <p id="media-search" class="search-box">
	<label class="screen-reader-text" for="media-search-input"><?php _e('Search Flickr');?>:</label>
	<input type="text" id="media-search-input" name="s" value="<?php the_search_query(); ?>" />
	<input type="submit" value="<?php esc_attr_e( 'Search Flickr' ); ?>" class="button" />
    </p>
    
    <?php
    $qu = get_search_query();
    if ( !empty($qu) ) {
        $photos = $cl_photodropper->getFlickrResults($qu, $_GET['paged']);
        if ( count( $photos ) > 0 && count( $photos['photo'] ) > 0 && (int) $photos['total'] > 0 ) {
            $totalPages = (int) $photos['pages'];
        ?>
            <div class="tablenav">

            <?php
            $page_links = paginate_links( array(
                'base' => add_query_arg( 'paged', '%#%' ),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => $totalPages,
                'current' => $_GET['paged']
            ));

            if ( $page_links )
                echo "<div class='tablenav-pages'>$page_links</div>";
            ?>
            <br class="clear" />
            </div>
            </form>
            
            <ul id="flickr-photos">
        <?php
            foreach ( $photos['photo'] as $photo ) {
                $photo_page_url = 'http://www.flickr.com/photos/'.$photo['owner'].'/'.$photo['id'].'/';
                if (isset($photos['originalformat']) && isset($photos['originalsecret'])
                    && !empty($photos['originalformat']) && !empty($photos['originalsecret']) ) {
                    $photo_url = 'http://farm'.$photo['farm'].'.static.flickr.com/'.$photo['server'].'/'.$photo['id'].'_'.$photo['originalsecret'].'_o.'.$photo['originalformat'];
                } else {
                    $photo_url = 'http://farm'.$photo['farm'].'.static.flickr.com/'.$photo['server'].'/'.$photo['id'].'_'.$photo['secret'].'.jpg';
                }
                ?>
                <li>
                    <form enctype="multipart/form-data" method="post" action="<?php echo esc_attr($form_action_url); ?>" class="media-upload-form validate" id="flickr-form">
                    <?php wp_nonce_field('media-form'); ?>
                    <input type="hidden" name="post_id" id="post_id" value="<?php echo (int) $post_id; ?>" />
                    <input type="hidden" name="photo_url" id="photo_url" value="<?php echo $photo_url; ?>" />
                    <input type="hidden" name="photo_title" id="photo_title" value="<?php echo $photo['title']; ?>" />
                    <input type="hidden" name="photo_page_url" id="photo_page_url" value="<?php echo $photo_page_url; ?>" />
                    <img src="http://farm<?php echo $photo['farm']; ?>.static.flickr.com/<?php echo $photo['server']; ?>/<?php echo $photo['id']; ?>_<?php echo $photo['secret']; ?>_t.jpg" />
                    <p class="ml-submit">
                    <a href='<?php echo $photo_page_url; ?>' target='_blank'><img src="<?php echo WP_PLUGIN_URL.'/'.str_replace(basename( __FILE__),"",plugin_basename(__FILE__)); ?>/images/newwin.gif" alt="go to Flickr page" class="alignleft" /></a>
                    <input type="submit" class="button savebutton" name="save" value="<?php esc_attr_e( 'Use Photo' ); ?>" />
                    </p>
                    </form>
                </li>
                <?php
            }
        ?>
            </ul>
            <br class="clear" />

            <div class="tablenav">
        <?php
            if ( $page_links )
                echo "<div class='tablenav-pages'>$page_links</div>";
        ?>
            </div>
        <?php
        }
    }
}

// Updated activation hook.
register_activation_hook(__FILE__, 'flickr_post_install');

function flickr_post_install() {
}
?>