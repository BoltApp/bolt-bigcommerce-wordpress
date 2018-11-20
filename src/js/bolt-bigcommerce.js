var isRequestInFlight = false;
singlePayProcess = function () {
    //if current tab or browser is already in ajax call, we should return immediately, it is just a duplicated enty in this situation
    if(isRequestInFlight){
        return;
    }
    //lock
    isRequestInFlight = true;
    jQuery( '.bc-product-form' ).block( {
        message: null,
        overlayCSS: {
            background: '#fff',
            opacity: 0.6
        }
    } );

    var cart_form = jQuery( '.bc-product-form' );
    var url_array = cart_form[0].action.split("/");
    var post_id = url_array[url_array.length - 1];
    var quantity = cart_form.find( '[name=quantity]' ).val()
    var variance_id = (cart_form.find( '[name=variant_id]' ).length) ? cart_form.find( '[name=variant_id]' ).val() : '';

    jQuery.ajax( {
        type: 'POST',
        async: true,
        url: boltajax.url+'?action=bolt_create_single_order',
        data: 'quantity=' + quantity + '&post_id=' + post_id + "&variance_id=" + variance_id,
        success: function ( res ) {
            eval(res);
        },
        complete: function (data) {
            jQuery( '.bc-product-form' ).unblock();
            isRequestInFlight = false;
        }
    } );

};
