jQuery(document).ready( function($) {
	$('a').click(function() {
		var wpxapi_click_url_requested =  $(this).attr('href');
		var wpxapi_click_referrer_location = document.location.href;
		var data = {	
			action: "xapiclicklog_action",
			wpxapi_click_log: "true",
			wpxapi_nonce: wpxapi_ajax_object.wpxapi_nonce,
			wpxapi_uid: wpxapi_ajax_object.wpxapi_uid,
			wpxapi_blogid: wpxapi_ajax_object.wpxapi_blogid,
			wpxapi_click_url_requested: wpxapi_click_url_requested,
			wpxapi_click_referrer_location: wpxapi_click_referrer_location
		};
		
		$.post( wpxapi_ajax_object.ajax_url, data, function( response ) {
			//alert( 'Got this from response he server: ' + respons);	
			
			die();	
		});
	});
});
