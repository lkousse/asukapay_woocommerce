/**
 * Bascule les champs numéro/OTP selon l'opérateur choisi : masqués pour une
 * carte bancaire, OTP affiché uniquement pour les opérateurs qui l'exigent
 * (attribut data-otp posé côté serveur depuis le catalogue AsukaPay).
 */
( function ( $ ) {
	'use strict';

	function toggleFields() {
		var $select   = $( '#asukapay_operator' );
		var $option   = $select.find( 'option:selected' );
		var $phoneRow = $( '#asukapay-phone-row' );
		var $otpRow   = $( '#asukapay-otp-row' );
		var $otpInput = $( '#asukapay_otp' );
		var $phoneInput = $( '#asukapay_phone' );

		var isCard = $option.data( 'card' ) === 1 || $option.data( 'card' ) === '1';
		var needsOtp = $option.data( 'otp' ) === 1 || $option.data( 'otp' ) === '1';

		$phoneRow.toggle( ! isCard );
		$phoneInput.prop( 'required', ! isCard );

		$otpRow.toggle( ! isCard && needsOtp );
		$otpInput.prop( 'required', ! isCard && needsOtp );

		if ( isCard || ! needsOtp ) {
			$otpInput.val( '' );
		}
	}

	$( document.body ).on( 'change', '#asukapay_operator', toggleFields );
	$( document.body ).on( 'updated_checkout payment_method_selected', toggleFields );
	$( function () {
		toggleFields();
	} );
} )( jQuery );
