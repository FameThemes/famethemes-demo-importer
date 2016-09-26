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