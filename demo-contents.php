<?php
/*
Plugin Name: FameTheme Demo Importer
Plugin URI: https://github.com/FameThemes/famethemes-demo-importer
Description: Demo data import tool for FameThemes's themes.
Author: famethemes
Author URI:  http://www.famethemes.com/
Version: 1.0.5
Text Domain: demo-contents
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/



define( 'DEMO_CONTENT_URL', trailingslashit ( plugins_url('', __FILE__) ) );
define( 'DEMO_CONTENT_PATH', trailingslashit( plugin_dir_path( __FILE__) ) );


class Demo_Contents {
    public $dir;
    public $url;
    static $git_repo = 'FameThemes/famethemes-xml-demos';
    public $dashboard;
    public $progress;
    static $_instance;

    static function get_instance(){
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }

        return self::$_instance ;
    }

    function __construct(){

        require_once DEMO_CONTENT_PATH.'inc/class-tgm-plugin-activation.php';
        require_once DEMO_CONTENT_PATH.'inc/theme-supports.php';
        require_once DEMO_CONTENT_PATH.'inc/class-dashboard.php';
        require_once DEMO_CONTENT_PATH.'inc/class-progress.php';
        $this->dashboard = new Demo_Content_Dashboard();
        $this->progress = new Demo_Contents_Progress();


    }


    /**
     * Handles a side-loaded file in the same way as an uploaded file is handled by media_handle_upload().
     *
     * @since 2.6.0
     *
     * @param array  $file_array Array similar to a `$_FILES` upload array.
     * @param int    $post_id    The post ID the media is associated with.
     * @param string $desc       Optional. Description of the side-loaded file. Default null.
     * @param array  $post_data  Optional. Post data to override. Default empty array.
     * @return int|object The ID of the attachment or a WP_Error on failure.
     */
    static function media_handle_sideload( $file_array, $post_id, $desc = null, $post_data = array(), $save_attachment = true ) {
        $overrides = array(
            'test_form'=>false,
            'test_type'=>false
        );

        $time = current_time( 'mysql' );
        if ( $post = get_post( $post_id ) ) {
            if ( substr( $post->post_date, 0, 4 ) > 0 )
                $time = $post->post_date;
        }

        $file = wp_handle_sideload( $file_array, $overrides, $time );
        if ( isset($file['error']) )
            return new WP_Error( 'upload_error', $file['error'] );

        $url = $file['url'];
        $type = $file['type'];
        $file = $file['file'];
        $title = preg_replace('/\.[^.]+$/', '', basename($file));
        $content = '';

        if ( $save_attachment ) {
            if (isset($desc)) {
                $title = $desc;
            }

            // Construct the attachment array.
            $attachment = array_merge(array(
                'post_mime_type' => $type,
                'guid' => $url,
                'post_parent' => $post_id,
                'post_title' => $title,
                'post_content' => $content,
            ), $post_data);

            // This should never be set as it would then overwrite an existing attachment.
            unset($attachment['ID']);

            // Save the attachment metadata
            $id = wp_insert_attachment($attachment, $file, $post_id);

            return $id;
        } else {
            return $file;
        }
    }

    /**
     * Download image form url
     *
     * @return bool
     */
    static function download_file( $url, $name = '', $save_attachment = true ){
        if ( ! $url || empty ( $url ) ) {
            return false;
        }
        // These files need to be included as dependencies when on the front end.
        require_once (ABSPATH . 'wp-admin/includes/image.php');
        require_once (ABSPATH . 'wp-admin/includes/file.php');
        require_once (ABSPATH . 'wp-admin/includes/media.php');
        $file_array = array();
        // Download file to temp location.
        $file_array['tmp_name'] = download_url( $url );

        // If error storing temporarily, return the error.
        if ( empty( $file_array['tmp_name'] ) || is_wp_error( $file_array['tmp_name'] ) ) {
            return false;
        }

        if ( $name ) {
            $file_array['name'] = $name;
        } else {
            $file_array['name'] = basename( $url );
        }
        // Do the validation and storage stuff.
        $file_path_or_id = self::media_handle_sideload( $file_array, 0, null, array(), $save_attachment );

        // If error storing permanently, unlink.
        if ( is_wp_error( $file_path_or_id ) ) {
            @unlink( $file_array['tmp_name'] );
            return false;
        }
        return $file_path_or_id;
    }

    function ajax_export(){
        $type = $_REQUEST['type'];
        ob_start();
        ob_end_clean();

        /**
         * Filters the export filename.
         *
         * @since 4.4.0
         *
         * @param string $wp_filename The name of the file for download.
         * @param string $sitename    The site name.
         * @param string $date        Today's date, formatted.
         */
        $filename = 'config.json';

        header( 'Content-Description: File Transfer' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Content-Type: application/xml; charset=' . get_option( 'blog_charset' ), true );

        switch ( $type ) {
            case 'options':
            case 'option':
            case 'config':
            case 'widget':
            case 'widgets':
            case 'theme_mod':
            case 'theme_mods':
                echo Demo_Contents::generate_config();
                break;
        }
        die();
    }

}

/*
echo '<pre>';
print_r( get_theme_mod( 'clients_items' ) );
echo '</pre>';
*/

if ( is_admin() ) {
    function demo_contents__init(){
        new Demo_Contents();

    }
    add_action( 'plugins_loaded', 'demo_contents__init' );
}

/**
 * Redirect to import page
 *
 * @param $plugin
 * @param bool|false $network_wide
 */
function demo_contents_importer_plugin_activate( $plugin, $network_wide = false ) {
    if ( ! $network_wide &&  $plugin == plugin_basename( __FILE__ ) ) {
        $template_slug = get_option('template');
        $url = add_query_arg(
            array(
                'page' => 'ft_' . $template_slug,
                'tab' => 'demo-data-importer',
            ),
            admin_url('themes.php')
        );
        wp_redirect($url);
        die();
    }
}
add_action( 'activated_plugin', 'demo_contents_importer_plugin_activate', 90, 2 );


// Support Upload XML file
add_filter('upload_mimes', 'demo_contents_custom_upload_xml');
function demo_contents_custom_upload_xml($mimes)
{
    if ( current_user_can( 'upload_files' ) ) {
    $mimes = array_merge($mimes, array(
        'xml' => 'application/xml',
        'json' => 'application/json'
    ));
    }
    return $mimes;
}







