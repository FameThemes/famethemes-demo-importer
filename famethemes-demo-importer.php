<?php
/*
Plugin Name: FameTheme Demo Importer
Plugin URI: https://github.com/FameThemes/famethemes-demo-importer
Description: Import demo data for famethemes's themes.
Author: famethemes
Author URI:  http://www.famethemes.com/
Version: 1.0.0
Text Domain: ftdi
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/


class FT_Demo_Importer {
    public $dir;
    public $url;
    function __construct( ){

        $this->url = trailingslashit( plugins_url('', __FILE__) );
        $this->dir = trailingslashit( plugin_dir_path( __FILE__) );

        require_once $this->dir.'inc/class-demo-content.php';
        add_action( 'wp_ajax_ft_demo_import_content', array( $this, 'ajax_import' ) );
        add_action( 'wp_ajax_ft_demo_import_download', array( $this, 'ajax_download' ) );
        add_action( 'wp_ajax_ft_demo_export', array( $this, 'ajax_export' ) );
        //add_action( 'ft_import_after_imported', array( $this, 'setup_demo' ), 66 );
        add_action( 'screenr_more_tabs_details', array( $this, 'display_import' ) );


        add_action( 'customize_controls_print_footer_scripts', array( $this, 'update_customizer_keys' ) );
    }

    function update_customizer_keys(){
        $key = 'ft_demo_customizer_keys';
        $theme_slug = get_option( 'stylesheet' );
        $data = get_option( $key );
        if ( ! is_array( $data ) ) {
            $data = array();
        }

        global $wp_customize;

        $pages           = get_pages();
        $option_pages    = array();
        $option_pages[0] = esc_html__( 'Select page', 'screenr' );
        foreach( $pages as $p ){
            $option_pages[ $p->ID ] = $p->post_title;
        }

        foreach (  $wp_customize->controls() as $k => $c )  {
            if ( $c->type == 'repeatable' ) {
                $keys = array();
                if ( property_exists( $c, 'fields' ) ){
                    foreach ( $c->fields as $field_id => $field ) {
                        if( isset( $field['options'] ) && is_array( $field['options'] ) ) {
                            $result = array_diff( $option_pages, $field['options'] );
                            if ( empty( $result ) ) { // if this option use page
                                $info = array(
                                    'key' => $field_id,
                                    'type' => 'page',
                                );
                                array_push( $keys, $info );
                            }
                        } elseif( isset( $field['data_type'] ) && in_array( $field['data_type'] , array( 'post', 'page' ) ) ) {
                            $info = array(
                                'key' => $field_id,
                                'type' => 'page',
                            );
                            array_push( $keys, $info );
                        } elseif( $field['type'] == 'media' ) {
                            $info = array(
                                'key' => $field_id,
                                'type' => 'media',
                            );
                            array_push( $keys, $info );
                        }
                    }
                }

                $data[ $theme_slug ][ $k ] = $keys;

            }

        }
        update_option( $key, $data );
    }

    function display_import(){
        $nonce = wp_create_nonce( 'ft_demo_import' );
        $url = admin_url('admin-ajax.php');
        $url = remove_query_arg( array( '_nonce', 'action' ) , $url );
        $current_item = apply_filters( 'ft_demo_import_current_item',  false );
        if ( ! $current_item ) {
            $current_item = get_option( 'stylesheet' );
            $current_item = untrailingslashit( $current_item );
        }
        $import_url = add_query_arg( array(
            '_nonce'    => $nonce,
            'action'    => 'ft_demo_import_content',
            'data'      => $current_item
        ), $url );

        $download_url = add_query_arg( array(
            '_nonce'    => $nonce,
            'action'    => 'ft_demo_import_download',
            'data'      => $current_item
        ), $url );

        $import_export_config_url = add_query_arg( array(
            '_nonce'    => $nonce,
            'action'    => 'ft_demo_export',
            'type'      => 'config',
            'data'      => $current_item
        ), $url );

        $this->js();

        ?>
        <h3>Import demo content</h3>
        <a class="button button-primary ft-demo-import-now" data-download="<?php echo esc_url( $download_url ); ?>" data-import="<?php echo esc_url( $import_url ); ?>" href="#"><?php esc_html_e( 'Import Now', 'ftdi' ); ?></a>
        <a class="button button-secondary" href="<?php echo esc_url( $import_export_config_url ); ?>"><?php esc_html_e( 'Export Config', 'ftdi' ); ?></a>
        <?php

    }

    function js(){
        wp_enqueue_script( 'ft-demo-importer', $this->url.'assets/js/importer.js', array( 'jquery' ) );
        wp_localize_script( 'ft-demo-importer', 'FT_IMPORT_DEMO', array(
            'downloading' => esc_html__( 'Downloading...', 'ftid' ),
            'importing' => esc_html__( 'Importing...', 'ftid' ),
            'import' => esc_html__( 'Import Now', 'ftid' ),
            'import_again' => esc_html__( 'Import Again.', 'ftid' ),
            'imported' => esc_html__( 'Demo Data Imported.', 'ftid' ),
        ) );
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
    static function media_handle_sideload( $file_array, $post_id, $desc = null, $post_data = array() ) {
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

        if ( isset( $desc ) )
            $title = $desc;

        // Construct the attachment array.
        $attachment = array_merge( array(
            'post_mime_type' => $type,
            'guid' => $url,
            'post_parent' => $post_id,
            'post_title' => $title,
            'post_content' => $content,
        ), $post_data );

        // This should never be set as it would then overwrite an existing attachment.
        unset( $attachment['ID'] );

        // Save the attachment metadata
        $id = wp_insert_attachment($attachment, $file, $post_id);

        return $id;
    }

    /**
     * Download image form url
     *
     * @return bool
     */
    static function download_file( $url, $name = '' ){
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
        print_r( $file_array );
        // Do the validation and storage stuff.
        $id = self::media_handle_sideload( $file_array, 0 );

        // If error storing permanently, unlink.
        if ( is_wp_error( $id ) ) {
            @unlink( $file_array['tmp_name'] );
            return false;
        }

        return $id;
    }

    function download_dummy_files( $item_name ){
        $files = array(
            'dummy-data'    => 'xml', // file ext
            'config'        => 'json'
        );
        $downloaded_file = array();
        foreach ( $files as $k => $ext ) {
            $file = $item_name.'-'.$k.'.'.$ext;
            $file_id = self::download_file( 'https://raw.githubusercontent.com/FameThemes/famethemes-xml-demos/master/'.$item_name.'/'.$k.'.'.$ext, $file );
            if ( $file_id ) {
                $downloaded_file[ $k ] = get_attached_file( $file_id );
            } else {
                $downloaded_file[ $k ] = false;
            }
        }

        return $downloaded_file;
    }

    function ajax_download(){
        $nonce = $_REQUEST['_nonce'];
        if ( ! wp_verify_nonce( $nonce, 'ft_demo_import' ) ) {
            die( 'Security check' );
        }

        $item = wp_strip_all_tags( $_REQUEST['data'] );
        delete_transient( 'ft_demo_import_downloaded_'.$item );
        $import_files = $this->download_dummy_files( $item );
        set_transient( 'ft_demo_import_downloaded_'.$item, $import_files, 3 * HOUR_IN_SECONDS );

        wp_die('downloaded');

    }


    function ajax_import(){

        $nonce = $_REQUEST['_nonce'];
        if ( ! wp_verify_nonce( $nonce, 'ft_demo_import' ) ) {
            die( 'Security check' );
        }

        $item = wp_strip_all_tags( $_REQUEST['data'] );
        $import_files = get_transient( 'ft_demo_import_downloaded_'.$item );

        if ( $import_files ) {
            $import = new FT_Demo_Content( $import_files );
            $import->import();
            die( 'done' );
        } else {
            die( 'no_data' );
        }
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
        new FT_Demo_Importer();
    }
    add_action( 'plugins_loaded', 'ft_demo_importer' );
}