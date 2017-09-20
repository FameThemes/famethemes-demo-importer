var ft_import_running = false;
window.onbeforeunload = function() {
    if ( ft_import_running ) {
        return FT_IMPORT_DEMO.confirm_leave;
    }
};



jQuery( document ).ready( function( $ ){

    $( '.ft-demo-import-now').on( 'click', function( e ){
        e.preventDefault();
        var btn = $( this);
        if ( btn.hasClass( 'disabled' ) ) {
            return ;
        }
        // Make sure user want to import
        var c = confirm( FT_IMPORT_DEMO.confirm_import );
        if ( ! c ) {
            return;
        }

        btn.addClass( 'disabled' );
        btn.addClass( 'updating-message' );

        var url =  btn.attr( 'href' );
        var frame = $( '<iframe style="display: none;"></iframe>' );
        frame.appendTo('body');
        // Thanks http://jsfiddle.net/KSXkS/1/
        try { // simply checking may throw in ie8 under ssl or mismatched protocol
            doc = frame[0].contentDocument ? frame[0].contentDocument : frame[0].document;
        } catch(err) {
            doc = frame[0].document;
        }
        doc.open();

        // Download url first
        url = btn.attr( 'data-download' );
        btn.html( FT_IMPORT_DEMO.downloading );
        var notice =  $( '.ft-ajax-notice' );
        notice.html( );

        ft_import_running = true;
        $.ajax( {
            url: url,
            error: function(){
                var msg = $( '<div class="ft-import-box ft-import-theme"></div>' );
                btn.removeClass( 'updating-message' );
                btn.html( FT_IMPORT_DEMO.import_error );
                msg.addClass( 'ft-import-error').html( '<p>'+FT_IMPORT_DEMO.no_data_found+'</p>' );
                notice.html( msg );
                ft_import_running = false;
                console.log( 'download_error' );
            },
            success: function( res ){
                // btn.removeClass( 'disabled' );
                //btn.removeClass( 'updating-message' );
                //doc.close();

                // Import
                setTimeout( function(){
                    btn.html( FT_IMPORT_DEMO.importing );
                    url = btn.attr( 'data-import' );
                    $.ajax( {
                        url: url,
                        dataType: 'html',
                        error: function( jqXHR, status, errorThrown ){

                            btn.removeClass( 'updating-message' );
                            doc.close();
                            btn.html( FT_IMPORT_DEMO.import_error );
                            btn.removeClass( 'button-primary' );
                            btn.addClass( 'button-secondary' );
                            var msg = $( '<div class="ft-import-box ft-import-theme"></div>' );
                            var err = jqXHR.statusText + ' ('+jqXHR.status+')';
                            msg.addClass( 'ft-import-error').html( '<p>'+err+'</p>' );
                            msg.append( '<p>'+FT_IMPORT_DEMO.import_error_msg+'</p>' );
                            notice.html( msg );

                            ft_import_running = false;

                        },
                        success: function( res ){
                            //btn.removeClass( 'disabled' );
                            btn.removeClass( 'updating-message' );
                            doc.close();
                            btn.html( FT_IMPORT_DEMO.imported );
                            btn.removeClass( 'button-primary' );
                            btn.addClass( 'button-secondary' );

                            var msg = $( '<div class="ft-import-box ft-import-theme"></div>' );
                            if ( res.indexOf( 'demo_imported' ) > -1  ) {
                                res = res.replace(/demo_imported/i, '');
                                msg.addClass( 'ft-import-success').html( '<p>'+FT_IMPORT_DEMO.demo_imported+'</p>' );
                                msg.append( '<div class="import_log">'+res+'</div>' );
                            } else if ( res.indexOf( 'no_data_found' ) > -1 ){
                                res = res.replace(/no_data_found/i, '');
                                msg.addClass( 'ft-import-error').html( '<p>'+FT_IMPORT_DEMO.no_data_found+'</p>' );
                                msg.append( '<div class="import_log">'+res+'</div>' );
                            } else if ( res.indexOf( 'demo_import_failed' ) > -1 ){
                                msg.addClass( 'ft-import-error').html( '<p>'+FT_IMPORT_DEMO.demo_import_failed+'</p>' );
                            } else {
                                res = res.replace(/demo_imported/i, '');
                                msg.addClass( 'ft-import-success').html( '<p>'+FT_IMPORT_DEMO.demo_imported+'</p>' );
                                msg.append( '<div class="import_log">'+res+'</div>' );
                            }

                            notice.html( msg );

                            ft_import_running = false;

                        }
                    } );
                }, 1000 );

            }
        } );



    } );
} );

// -------------------------------------------------------------------------------

(function ( $ ) {

    var ft_demo_content_params = ft_demo_content_params || window.ft_demo_content_params;

    ft_demo_content_params.plugin_install_count = parseInt( ft_demo_content_params.plugin_install_count );
    ft_demo_content_params.plugin_active_count = parseInt( ft_demo_content_params.plugin_active_count );
    ft_demo_content_params.plugin_update_count = parseInt( ft_demo_content_params.plugin_update_count );

    if( typeof ft_demo_content_params.plugins.active !== "object" ) {
        ft_demo_content_params.plugins.active = {};
    }
    var $document = $( document );
    var is_importing = false;

    /**
     * Function that loads the Mustache template
     */
    var repeaterTemplate = _.memoize(function () {
        var compiled,
            /*
             * Underscore's default ERB-style templates are incompatible with PHP
             * when asp_tags is enabled, so WordPress uses Mustache-inspired templating syntax.
             *
             * @see track ticket #22344.
             */
            options = {
                evaluate: /<#([\s\S]+?)#>/g,
                interpolate: /\{\{\{([\s\S]+?)\}\}\}/g,
                escape: /\{\{([^\}]+?)\}\}(?!\})/g,
                variable: 'data'
            };

        return function (data, tplId ) {
            if ( typeof tplId === "undefined" ) {
                tplId = '#tmpl-ft-theme-demo-preview';
            }
            compiled = _.template(jQuery( tplId ).html(), null, options);
            return compiled(data);
        };
    });

    var template = repeaterTemplate();

    var ftDemoContents  = {
        preparing_plugins: function() {
            var $list_install_plugins = $('.ft-demo-install-plugins');
            var n = _.size(ft_demo_content_params.plugins.install);
            if (n > 0) {
                var $child_steps = $list_install_plugins.find('.ft-child-steps');
                $.each(ft_demo_content_params.plugins.install, function ($slug, plugin) {
                    var $item = $('<div class="ft-child-item ft-plugin-' + $slug + '">Installing ' + plugin.name + '</div>');
                    $child_steps.append($item);
                    $item.attr('data-plugin', $slug);
                });

            }

            var $list_active_plugins = $( '.ft-demo-active-plugins' );
            var $activate_child_steps = $list_active_plugins.find(  '.ft-child-steps' );
            $.each(ft_demo_content_params.plugins.all, function ($slug, plugin) {
                var $item = $(  '<div class="ft-child-item ft-plugin-'+$slug+'">Activating '+plugin.name+'</div>' );
                $activate_child_steps.append( $item );
                $item.attr( 'data-plugin', $slug );
            });

        },
        installPlugins: function() {
            // Install Plugins
            var $list_install_plugins = $( '.ft-demo-install-plugins' );
            var $child_steps = $list_install_plugins.find(  '.ft-child-steps' );
            var n = _.size( ft_demo_content_params.plugins.install );
            if ( n > 0 ) {

                var current = $child_steps.find( '.ft-child-item' ).eq( 0 );

                var callback = function( current ){
                    if ( current.length ) {
                        var slug = current.attr( 'data-plugin' );
                        var plugin =  ft_demo_content_params.plugins.install[ slug ];
                        $.post( plugin.page_url, plugin.args, function (res) {
                            //console.log(plugin.name + ' Install Completed');
                            plugin.action = ft_demo_content_params.action_active_plugin;
                            ft_demo_content_params.plugins.activate[ slug ] = plugin;
                            current.html( plugin.name + 'Installed'  );
                            var next = current.next();
                            callback( next );
                        });
                    } else {
                        console.log( 'Plugins install completed' );
                        $list_install_plugins.addClass( 'ft-step-completed' );
                        $document.trigger( 'ft_demo_content_plugins_install_completed' );
                    }
                };
                callback( current );
            } else {
                $list_install_plugins.addClass( 'ft-step-completed' );
                $document.trigger( 'ft_demo_content_plugins_install_completed' );
            }

        },
        activePlugins: function(){
            var $list_active_plugins = $( '.ft-demo-active-plugins' );
            var $child_steps = $list_active_plugins.find(  '.ft-child-steps' );
            $.each(ft_demo_content_params.plugins.activate, function ($slug, plugin) {
                var $item = $(  '<div class="ft-child-item ft-plugin-'+$slug+'">Activating '+plugin.name+'</div>' );
                $child_steps.append( $item );
                $item.attr( 'data-plugin', $slug );
            });

            var callback = function( current ){
                if ( current.length ) {
                    var slug = current.attr( 'data-plugin' );
                    var plugin =  ft_demo_content_params.plugins.activate[ slug ];
                    $.post( plugin.page_url, plugin.args, function (res) {
                        current.html( plugin.name + ' Activated' );
                        var next = current.next();
                        callback( next );
                    });
                } else {
                    console.log( ' Activate all plugins' );
                    $list_active_plugins.addClass( 'ft-step-completed' );
                    $document.trigger( 'ft_demo_content_plugins_active_completed' );
                }
            };

            var current = $child_steps.find( '.ft-child-item' ).eq( 0 );
            callback( current );
        },
        ajax: function( doing, complete_cb ){
            console.log( 'Doing....', doing );
            $.ajax( {
                url: ft_demo_content_params.ajaxurl,
                data: {
                    action: 'ft_demo_import',
                    doing: doing,
                    theme: '', // Import demo for theme ?
                    version: '' // Current demo version ?
                },
                type: 'GET',
                dataType: 'json',
                success: function( res ){
                    if ( typeof complete_cb === 'function' ) {
                        complete_cb();
                    }
                    console.log( 'Done Step: ', doing );
                    $document.trigger( 'ft_demo_content_'+doing+'_completed' );
                },
                fail: function(){
                    $document.trigger( 'ft_demo_content_'+doing+'_fail' );
                }

            } )
        },
        import_users: function(){
            var step =  $( '.ft-demo-import-users' );
            step.addClass( 'ft-step-running' );
            this.ajax( 'import_users', function(){
                step.removeClass( 'ft-step-running' ).addClass( 'ft-step-completed' );
            } );
        },
        import_categories: function(){
            var step =  $( '.ft-demo-import-categories' );
            step.addClass( 'ft-step-running' );
            this.ajax(  'import_categories', function(){
                step.removeClass( 'ft-step-running' ).addClass( 'ft-step-completed' );
            } );
        },
        import_tags: function(){
            var step =  $( '.ft-demo-import-tags' );
            step.addClass( 'ft-step-running' );
            this.ajax(  'import_tags', function(){
                step.removeClass( 'ft-step-running' ).addClass( 'ft-step-completed' );
            } );
        },
        import_taxs: function(){
            var step =  $( '.ft-demo-import-taxs' );
            step.addClass( 'ft-step-running' );
            this.ajax(  'import_taxs', function(){
                step.removeClass( 'ft-step-running' ).addClass( 'ft-step-completed' );
            } );
        },
        import_posts: function(){
            var step =  $( '.ft-demo-import-posts' );
            step.addClass( 'ft-step-running' );
            this.ajax( 'import_posts', function(){
                step.removeClass( 'ft-step-running' ).addClass( 'ft-step-completed' );
            } );
        },

        toggle_collapse: function(){
            $document .on( 'click', '.ft-collapse-sidebar', function( e ){
                $( '#ft-theme-demo-preview' ).toggleClass('ft-preview-collapse');
            } );
        },

        preview: function(){
            var that = this;
            $document .on( 'click', '.ft-preview-theme-btn', function( e ){
                e.preventDefault();
                var btn = $( this );
                var demoURL         = btn.attr( 'data-demo-url' ) || '';
                var slug            = btn.attr( 'data-theme-slug' ) || '';
                var name            = btn.attr( 'data-name' ) || '';
                var demo_version    = btn.attr( 'data-demo-version' ) || '';
                var demo_name       = btn.attr( 'data-demo-version-name' ) || '';
                if ( demoURL.indexOf( 'http' ) !== 0 ) {
                    demoURL = 'https://demos.famethemes.com/'+slug+'/';
                }
                $( '#ft-theme-demo-preview' ).remove();
                var previewHtml = template( {
                    name: name,
                    slug: slug,
                    demo_version: demo_version,
                    demo_name: demo_name,
                    demoURL: demoURL
                } );
                $( 'body' ).append( previewHtml );
                $( 'body' ).addClass( 'ft-demo-body-viewing' );

                that.preparing_plugins();

            } );

            $document.on( 'click', '.ft-demo-close', function( e ) {
                e.preventDefault();
                $( this ).closest('#ft-theme-demo-preview').remove();
                $( 'body' ).removeClass( 'ft-demo-body-viewing' );
            } );


        },

        init: function(){
            var that = this;

            that.preview();
            that.toggle_collapse();


            $document.on( 'ft_demo_content_ready', function(){
                that.installPlugins();
            } );

            $document.on( 'ft_demo_content_plugins_install_completed', function(){
                that.activePlugins();
            } );

            $document.on( 'ft_demo_content_plugins_active_completed', function(){
                that.import_users();
            } );

            $document.on( 'ft_demo_content_import_users_completed', function(){
                that.import_categories();
            } );

            $document.on( 'ft_demo_content_import_categories_completed', function(){
                that.import_tags();
            } );

            $document.on( 'ft_demo_content_import_tags_completed', function(){
                that.import_taxs();
            } );

            $document.on( 'ft_demo_content_import_taxs_completed', function(){
                that.import_posts();
            } );

            if ( ft_demo_content_params.run == 'run' ) {
                $document.trigger( 'ft_demo_content_ready' );
            }
            // test

            $( '.ft-preview-theme-btn' ).eq( 0 ).click();


        }
    };

    $.fn.ftDemoContent = function() {
        ftDemoContents.init();
    };




}( jQuery ));

jQuery( document ).ready( function( $ ){

    $( document ).ftDemoContent();
    // Active Plugins








});



