/**
 * Settings > PostViews.
 *
 * One delegated listener for the "Restore Default Template" buttons, replacing
 * the inline onclick handlers and the jQuery call they used up to 1.78.1. The
 * defaults arrive as data from wp_localize_script() rather than being written
 * into an attribute, so no escaping happens in a JavaScript string literal.
 */
document.addEventListener( 'click', function( event ) {
	const button = event.target.closest( '[data-postviews-reset]' );

	if ( ! button ) {
		return;
	}

	const key = button.dataset.postviewsReset;
	const field = document.getElementById( button.dataset.postviewsTarget );

	if (
		! field ||
		typeof postviewsAdminL10n === 'undefined' ||
		typeof postviewsAdminL10n.defaults[ key ] === 'undefined'
	) {
		return;
	}

	field.value = postviewsAdminL10n.defaults[ key ];
} );

/**
 * Confirmation for the random views data write operation.
 *
 * This is a destructive action that zeros all existing view counts before
 * writing random data, so we require explicit confirmation before allowing
 * the form to submit.
 */
document.addEventListener( 'DOMContentLoaded', function() {
	const form = document.getElementById( 'postviews-random-form' );

	if ( ! form ) {
		return;
	}

	form.addEventListener( 'submit', function( event ) {
		if ( typeof postviewsAdminL10n === 'undefined' || ! postviewsAdminL10n.confirmRandom ) {
			return;
		}

		if ( ! confirm( postviewsAdminL10n.confirmRandom ) ) {
			event.preventDefault();
		}
	} );
} );
