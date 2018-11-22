    save_checkout = function ( transaction, callback, type ) {

        var params = [
            'transaction_details=' + JSON.stringify( transaction ),
            //'_wpnonce=' + wc_bolt_checkout_config.nonce.checkout,
            type + '=1'
        ];
        //if(bolt_checkout_form && jQuery( bolt_checkout_form ).length>0){
        //    params.unshift(jQuery( bolt_checkout_form ).serialize());
        //}
        var cart_data = params.join("&");
        
        jQuery.ajax( {
            type: 'POST',
            url: '<?= admin_url('admin-ajax.php'); ?>?action=bolt_create_order',
            data: cart_data,
            success: function ( data ) {
                if ( data.result != 'success' ) {
                    //jQuery('#bolt-modal-background').remove();
                    //jQuery('html').removeClass('bolt_modal_active');
                    //jQuery('body').css('overflow', 'auto');
                    //display_notices(data);
                } else {
                    redirect_url = data.redirect_url;
                    callback();
                }
            }
        } );
     };

    var cart = {
        "orderToken": "<?= $orderToken;?>",
        "authcapture": <?= $authcapture;?>
    };
    var hints = <?= $hints;?>;
    var callbacks = {
        check: function () {
            // This function is called just before the checkout form loads.
            // This is a hook to determine whether Bolt can actually proceed
            // with checkout at this point. This function MUST return a boolean.
            return true;
        },

        onCheckoutStart: function () {
            // This function is called after the checkout form is presented to the user.
        },

        onShippingDetailsComplete: function () {
            // This function is called when the user proceeds to the shipping options page.
            // This is applicable only to multi-step checkout.
        },

        onShippingOptionsComplete: function () {
            // This function is called when the user proceeds to the payment details page.
            // This is applicable only to multi-step checkout.
        },

        onPaymentSubmit: function () {
            // This function is called after the user clicks the pay button.
        },

        success: function (transaction, callback) {
            save_checkout(transaction, callback, 'product_page');
        },

        close: function () {
            // This function is called when the Bolt checkout modal is closed.
            location.href = redirect_url;
        }
    };
    BoltCheckout.configure(cart, hints, callbacks);
    //cart update event.
    jQuery(document).on('updated_cart_totals',function(){
        // Re-navigate to the same page with a fresh session to avoid repeating the last action 
        window.location = window.location.href;
    });