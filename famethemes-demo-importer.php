<?php
/*
Plugin Name: FameTheme Demo Importer
Plugin URI: https://github.com/FameThemes/famethemes-demo-importer
Description: Demo data import tool for FameThemes's themes.
Author: famethemes
Author URI:  http://www.famethemes.com/
Version: 1.0.5
Text Domain: ftdi
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

include dirname( __FILE__ ).'/inc/class-dashboard.php';

define( 'FT_DEMO_CONTENT_URL', trailingslashit ( plugins_url('', __FILE__) ) );
define( 'FT_DEMO_CONTENT_PATH', trailingslashit( plugin_dir_path( __FILE__) ) );


class FT_Demo_Content_Importer {
    public $dir;
    public $url;
    public $git_repo = 'https://raw.githubusercontent.com/FameThemes/famethemes-xml-demos/master/';
    function __construct( ){

        $this->url = trailingslashit( plugins_url('', __FILE__) );
        $this->dir = trailingslashit( plugin_dir_path( __FILE__) );

        require_once $this->dir.'inc/class-demo-content.php';

        // Example config plugins
        require_once $this->dir.'inc/class-tgm-plugin-activation.php';
        require_once $this->dir.'/sample/example.php';


        require_once $this->dir.'inc/class-progress.php';

        add_action( 'wp_ajax_ft_demo_import_content', array( $this, 'ajax_import' ) );
        add_action( 'wp_ajax_ft_demo_import_download', array( $this, 'ajax_download' ) );
        add_action( 'wp_ajax_ft_demo_export', array( $this, 'ajax_export' ) );

        $template_slug = get_option( 'template' );
        $theme_slug = get_option( 'stylesheet' );

        // child theme active
        if ( $template_slug != $theme_slug ) {
            add_action( $template_slug.'_demo_import_content_tab', array( $this, 'display_import' ) );
        } else {
            add_action( $theme_slug.'_demo_import_content_tab', array( $this, 'display_import' ) );
        }

        add_action( 'customize_controls_print_footer_scripts', array( $this, 'update_customizer_keys' ) );

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

    function download_dummy_files( $item_name ){
        $files = array(
            'dummy-data'    => 'xml', // file ext
            'config'        => 'json'
        );
        $downloaded_file = array();
        foreach ( $files as $k => $ext ) {
            $file = $item_name.'-'.$k.'.'.$ext;
            $file_path = self::download_file( $this->git_repo.$item_name.'/'.$k.'.'.$ext, $file, false );
            $downloaded_file[ $k ] = $file_path;
        }

        return $downloaded_file;
    }



    function data_dir( $param ){

        $siteurl = get_option( 'siteurl' );
        $upload_path = trim( get_option( 'upload_path' ) );

        if ( empty( $upload_path ) || 'wp-content/uploads' == $upload_path ) {
            $dir = WP_CONTENT_DIR . '/uploads';
        } elseif ( 0 !== strpos( $upload_path, ABSPATH ) ) {
            // $dir is absolute, $upload_path is (maybe) relative to ABSPATH
            $dir = path_join( ABSPATH, $upload_path );
        } else {
            $dir = $upload_path;
        }

        if ( !$url = get_option( 'upload_url_path' ) ) {
            if ( empty($upload_path) || ( 'wp-content/uploads' == $upload_path ) || ( $upload_path == $dir ) )
                $url = WP_CONTENT_URL . '/uploads';
            else
                $url = trailingslashit( $siteurl ) . $upload_path;
        }


        $param['path']  = $dir . '/ft-dummy-data';
        $param['url']   = $url . '/ft-dummy-data';

        return $param;
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
                echo FT_Demo_Content::generate_config();
                break;
        }
        die();
    }

}

if ( is_admin() ) {
    function ft_demo_importer(){
        new FT_Demo_Content_Importer();
    }
    add_action( 'plugins_loaded', 'ft_demo_importer' );
}

/**
 * Redirect to import page
 *
 * @param $plugin
 * @param bool|false $network_wide
 */
function ft_demo_importer_plugin_activate( $plugin, $network_wide = false ) {
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
add_action( 'activated_plugin', 'ft_demo_importer_plugin_activate', 90, 2 );






