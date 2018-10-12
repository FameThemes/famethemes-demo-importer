<?php

add_action( 'tgmpa_register', 'demo_contents_register_required_plugins' );

function demo_contents_register_required_plugins() {

    $template_slug  = get_option( 'template' );
    switch ( $template_slug ) {
        case  'screenr':
            $plugins = array(
                array(
                    'name' => 'Contact Form 7',
                    'slug' => 'contact-form-7',
                    'required' => false,
                ),
            );
            break;
        case  'onepress':
            $plugins = array(
                array(
                    'name' => 'WPForms',
                    'slug' => 'wpforms-lite',
                    'required' => false,
                ),
            );
            break;
        default:
            return ;
    }

    /*
     * Array of configuration settings. Amend each line as needed.
     *
     * TGMPA will start providing localized text strings soon. If you already have translations of our standard
     * strings available, please help us make TGMPA even better by giving us access to these translations or by
     * sending in a pull-request with .po file(s) with the translations.
     *
     * Only uncomment the strings in the config array if you want to customize the strings.
     */
    $config = array(
        'id'           => 'demo-contents',                 // Unique ID for hashing notices for multiple instances of TGMPA.
        'default_path' => '',                      // Default absolute path to bundled plugins.
        'menu'         => 'tgmpa-install-plugins', // Menu slug.
        'parent_slug'  => 'plugins.php',            // Parent menu slug.
        'capability'   => 'manage_options',    // Capability needed to view plugin install page, should be a capability associated with the parent menu used.
        'has_notices'  => true,                    // Show admin notices or not.
        'dismissable'  => true,                    // If false, a user cannot dismiss the nag message.
        'dismiss_msg'  => '',                      // If 'dismissable' is false, this message will be output at top of nag.
        'is_automatic' => false,                   // Automatically activate plugins after installation or not.
        'message'      => '',                      // Message to output right before the plugins table.

    );

    tgmpa( $plugins, $config );
}
