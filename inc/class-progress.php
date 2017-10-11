<?php
/**
 * Created by PhpStorm.
 * User: truongsa
 * Date: 9/16/17
 * Time: 9:10 AM
 */


class  Demo_Contents_Progress {


    private $config_data= array();
    private $tgmpa;

    function __construct()
    {
        if ( Demo_Contents::php_support() ) {
            add_action('wp_ajax_demo_contents__import', array($this, 'ajax_import'));
            add_action('admin_enqueue_scripts', array($this, 'checking_plugins'), 900, 1);
        }
    }

    function checking_plugins( $hook ) {

        if( $hook != 'themes.php' ) {
            return;
        }
        if ( ! isset( $_REQUEST['__checking_plugins'] ) ) {
            return;
        }

        $plugins = Demo_Contents::get_instance()->dashboard->get_tgmpa_plugins();
        ob_clean();
        ob_flush();

        ob_start();
        wp_send_json_success( $plugins );
        die();
    }


    /**
     * @see https://github.com/devinsays/edd-theme-updater/blob/master/updater/theme-updater.php
     */
    function ajax_import(){

        if ( ! class_exists( 'Merlin_WXR_Parser' ) ) {
            require DEMO_CONTENT_PATH. 'inc/merlin-wp/includes/class-merlin-xml-parser.php' ;
        }

        if ( ! class_exists( 'Merlin_Importer' ) ) {
            require DEMO_CONTENT_PATH .'inc/merlin-wp/includes/class-merlin-importer.php';
        }

        if ( ! current_user_can( 'import' ) ) {
            wp_send_json_error( __( "You have not permissions to import.", 'demo-contents' ) );
        }

        $doing = isset( $_REQUEST['doing'] ) ? sanitize_text_field( $_REQUEST['doing'] ) : '';
        if ( ! $doing ) {
            wp_send_json_error( __( "No actions to do", 'demo-contents' ) );
        }

        // Current theme for import
        $current_theme = isset( $_REQUEST['current_theme'] ) ? $_REQUEST['current_theme'] : false;

        $current_theme = wp_parse_args( $current_theme, array(
            'name' => '',
            'slug' => '',
            'demo_version' => '',
            'demo_name' => '',
            'activate' => '',
            'xml_id' => '',
            'json_id' => ''
        ) );
        $current_theme_slug = false;
        $current_theme_demo_version = false;
        if ( ! $current_theme || ! is_array( $current_theme ) || ! isset( $current_theme['slug'] ) || ! $current_theme['slug'] ) {
            wp_send_json_error( __( 'Not theme selected', 'demo-contents' ) );
        }

        $current_theme_slug = sanitize_text_field( $current_theme['slug'] );
        if ( $current_theme['demo_version'] ) {
            $current_theme_demo_version = sanitize_text_field( $current_theme['demo_version'] );
        }

        $themes = Demo_Contents::get_instance()->dashboard->setup_themes();
        // if is_activate theme
        if ( $doing == 'activate_theme' ) {
            switch_theme( $current_theme_slug );
            wp_send_json_success( array( 'msg' => sprintf( __( '%s theme activated', 'demo-contents' ), $themes[ $current_theme_slug ]['name'] ) ) );
        }

        if ( $doing == 'checking_resources' ){
            $file_data = $this->maybe_remote_download_data_files( $current_theme );
            if ( ! $file_data || empty( $file_data ) ) {
                wp_send_json_error( sprintf( __( 'Demo data not found for <strong>%s</strong>. However you can import demo content by upload your demo files below.', 'demo-contents' ) , $themes[ $current_theme_slug ]['name'] ) );
            } else {
                wp_send_json_success( __( 'Demo data ready for import.', 'demo-contents' ) );
            }
        }

        /// Check theme activate
        if ( ! isset( $themes[ $current_theme_slug ] ) ) {
            wp_send_json_error( __( 'This theme have not installed.', 'demo-contents' ) );
        }


        //wp_send_json_success(); // just for test
       $file_data = $this->maybe_remote_download_data_files( $current_theme );
       if ( ! $file_data || empty( $file_data ) ) {
           wp_send_json_error( array( 'type' => 'no-files', 'msg' => __( 'Dummy data files not found', 'demo-contents' ), 'files' => $file_data  ) );
       }

       $transient_key = '_demo_content_'.$current_theme_slug;
       if ( $current_theme_demo_version ) {
           $transient_key .= '-demos-'.$current_theme_demo_version;
       }


        $importer = new Merlin_Importer();
        $content = get_transient( $transient_key );
        if ( ! $content ) {
            $parser = new Merlin_WXR_Parser();
            $content = $parser->parse( $file_data['xml'] );
           set_transient( $transient_key, $content, DAY_IN_SECONDS );
        }

        if ( is_wp_error( $content ) ) {
            wp_send_json_error( __( 'Dummy content empty', 'demo-contents' ) );
        }

        // Setup config
        $option_config = get_transient( $transient_key.'-json' );
        if ( false === $option_config ) {
            if ( file_exists( $file_data['xml']  ) ) {
                global $wp_filesystem;
                WP_Filesystem();
                $file_contents = $wp_filesystem->get_contents( $file_data['json'] );
                $option_config = json_decode( $file_contents, true );
                set_transient( $transient_key.'-json',  $option_config, DAY_IN_SECONDS ) ;
            }
        }

        switch ( $doing ) {
            case 'import_users':
                if ( ! empty( $content['users'] ) ) {
                    $importer->import_users( $content['users'] );
                }
                break;

            case 'import_categories':
                if ( ! empty( $content['categories'] ) ) {
                    $importer->importTerms( $content['categories'] );
                }
                break;
            case 'import_tags':
                if ( ! empty( $content['tags'] ) ) {
                    $importer->importTerms( $content['tags'] );
                }
                break;
            case 'import_taxs':
                if ( ! empty( $content['terms'] ) ) {
                    $importer->importTerms( $content['terms'] );
                }
                break;
            case 'import_posts':
                if ( ! empty( $content['posts'] ) ) {
                    $importer->importPosts( $content['posts'] );
                }
                $importer->remapImportedData();
                do_action( 'demo_contents_import_posts_completed', $this, $importer );

                break;

            case 'import_theme_options':
                if ( isset( $option_config['options'] ) ){
                    $this->importOptions( $option_config['options'] );
                }

                // Setup Pages
                $processed_posts = get_transient('_wxr_imported_posts') ? get_transient('_wxr_imported_posts') : array();
                if ( isset( $option_config['pages'] ) ){
                    foreach ( $option_config['pages']  as $key => $id ) {
                        $val = isset( $processed_posts[ $id ] )  ? $processed_posts[ $id ] : null ;
                        update_option( $key, $val );
                    }
                }

                do_action( 'demo_contents_import_theme_options_completed', $this, $importer );

                break;

            case 'import_widgets':
                $this->config_data = $option_config;
                if ( isset( $option_config['widgets'] ) ){
                   // print_r( $option_config['widgets'] );
                    if ( ! isset( $this->config_data['widgets_config'] ) ) {
                        $this->config_data['widgets_config'] = array();
                    }
                    $importer->importWidgets( $option_config['widgets'], $this->config_data['widgets_config'] );
                    do_action( 'demo_contents_import_widgets_completed', $this, $importer );
                }
                break;

            case 'import_customize':
                if ( isset( $option_config['theme_mods'] ) ){
                    $importer->importThemeOptions( $option_config['theme_mods'] );
                    if ( isset( $option_config['customizer_keys'] ) ) {
                        foreach ( ( array ) $option_config['customizer_keys'] as $k => $list_key ) {
                            $this->resetup_repeater_page_ids( $k, $list_key );
                        }
                    }
                }

                do_action( 'demo_contents_import_customize_completed', $this, $importer );

                // Remove transient data if is live mod
                if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
                    $importer->importEnd();
                    // Delete file
                    $file_key = '_demo_contents_file_'.$current_theme_slug;
                    if ( $current_theme_demo_version ) {
                        $file_key .= '-demos-'.$current_theme_demo_version;
                    }
                    $files = get_transient( $file_key );
                    if ( is_array( $files ) ) {
                        foreach ( $files as $file_id ) {
                            wp_delete_attachment( $file_id );
                        }
                    }
                    delete_transient( $transient_key );
                    delete_transient( $transient_key.'-json' );
                }

                break;

        } // end switch action

        wp_send_json_success( );

    }


    function importOptions( $options ){
        if ( empty( $options ) ) {
            return ;
        }
        foreach ( $options as $option_name => $ops ) {
            update_option( $option_name, $ops );
        }
    }


    function resetup_repeater_page_ids( $theme_mod_name = null, $list_keys, $url ='', $option_type = 'theme_mod' ){

        $processed_posts = get_transient('_wxr_imported_posts') ? get_transient('_wxr_imported_posts') : array();
        if ( ! is_array( $processed_posts ) ) {
            $processed_posts = array();
        }

        // Setup service
        $data = get_theme_mod( $theme_mod_name );
        if (  is_string( $list_keys ) ) {
            switch( $list_keys ) {
                case 'media':
                    $new_data = $processed_posts[ $data ];
                    if ( $option_type == 'option' ) {
                        update_option( $theme_mod_name , $new_data );
                    } else {
                        set_theme_mod( $theme_mod_name , $new_data );
                    }
                    break;
            }
            return;
        }

        if ( is_string( $data ) ) {
            $data = json_decode( $data, true );
        }
        if ( ! is_array( $data ) ) {
            return false;
        }
        if ( ! is_array( $processed_posts ) ) {
            return false;
        }

        if ( $url ) {
            $url = trailingslashit( $this->config_data['home_url'] );
        }

        $home = home_url('/');


        foreach ($list_keys as $key_info) {
            if ($key_info['type'] == 'post' || $key_info['type'] == 'page') {
                foreach ($data as $k => $item) {
                    if (isset($item[$key_info['key']]) && isset ($processed_posts[$item[$key_info['key']]])) {
                        $data[$k][$key_info['key']] = $processed_posts[ $item[$key_info['key']] ];
                    }
                }
            } elseif ($key_info['type'] == 'media') {

                $main_key = $key_info['key'];
                $sub_key_id = 'id';
                $sub_key_url = 'url';
                if ($main_key) {

                    foreach ($data as $k => $item) {
                        if ( isset ($item[$main_key]) && is_array($item[$main_key])) {
                            if (isset ($item[$main_key][$sub_key_id]) ) {
                                $data[$k][$main_key][$sub_key_id] = $processed_posts[ $item[$main_key] [$sub_key_id] ];
                            }
                            if (isset ($item[$main_key][$sub_key_url])) {
                                $data[$k][$main_key][$sub_key_url] = str_replace($url, $home, $item[$main_key][$sub_key_url]);
                            }
                        }
                    }

                }
            }
        }

        if ( $option_type == 'option' ) {
            update_option( $theme_mod_name , $data );
        } else {
            set_theme_mod( $theme_mod_name , $data );
        }

    }


    function maybe_remote_download_data_files( $args = array() ) {
        $args = wp_parse_args( $args, array(
            'slug' => '',
            'demo_version' => '',
            'xml_id' => '',
            'json_id' => ''
        ) );

        $theme_name = $args['slug'];
        $demo_version = $args['demo_version'];
        if ( $args['xml_id'] ) {
            return array( 'xml' => get_attached_file( $args['xml_id'] ), 'json' => get_attached_file( $args['json_id'] ) );
        }

        if ( ! $theme_name ) {
            return false;
        }

        $sub_path = $theme_name;
        if ( $demo_version ) {
            $sub_path .= '/demos/'.$demo_version;
        }
        $prefix_name = str_replace( '/', '-', $sub_path );

        $xml_file_name =  $prefix_name .'-dummy-data.xml' ;
        $config_file_name = $prefix_name .'-config.json';

        $xml_file = false;
        $config_file = false;

        $files_data = get_transient( '_demo_contents_file_'.$prefix_name );

        // If have cache
        if ( ! empty( $files_data ) ) {
            $files_data = wp_parse_args( $files_data, array( 'xml' => '', 'json' => '' ) );
            $xml_file = get_attached_file( $files_data['xml'] );
            $config_file = get_attached_file( $files_data['json']  );
            if ( ! empty( $xml_file ) ) {
                return  array( 'xml' => $xml_file, 'json' => $config_file );
            }
        }

        $remote_folder = apply_filters( 'demo_contents_remote_demo_data_folder_url', false );

        if ( ! $remote_folder ) {
            $repo = Demo_Contents::get_github_repo();
            $remote_folder = 'https://raw.githubusercontent.com/'.$repo.'/master/';
        }
        $remote_folder = trailingslashit( $remote_folder );


        $xml_id = Demo_Contents::download_file( $remote_folder.$sub_path.'/dummy-data.xml',  $xml_file_name );
        if ( $xml_id ) {
            $xml_file = get_attached_file( $xml_id );
        }

        $config_id = Demo_Contents::download_file( $remote_folder.$sub_path.'/config.json',  $config_file_name );
        if ( $config_id ) {
            $config_file = get_attached_file( $config_id );
        }

        if ( ! empty( $xml_file ) ) {
            set_transient( '_demo_contents_file_'.$prefix_name, array( 'xml' => $xml_id, 'json' => $config_id ) );
            return  array( 'xml' => $xml_file, 'json' => $config_file );
        }

        return false;

    }

}
