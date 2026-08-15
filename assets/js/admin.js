( function () {
	'use strict';

	function findDescCell( el ) {
		return el.closest( '.essf-ofx-review-desc' );
	}

	// The "use this too" link (if any) is only ever hidden while a cell is
	// being edited, never destroyed just for opening the editor — cancel
	// leaves the row's value (and so the offer) exactly as it was. It's
	// only actually removed once a *committed* edit changes the value —
	// see commitDescEdit() — since that's the point it stops applying.
	function hideApplyLink( cell ) {
		var link = cell.querySelector( '.essf-ofx-desc-apply-link' );
		if ( link ) {
			link.hidden = true;
		}
	}

	function restoreApplyLink( cell ) {
		var link = cell.querySelector( '.essf-ofx-desc-apply-link' );
		if ( link ) {
			link.hidden = false;
		}
	}

	function removeApplyLink( cell ) {
		var link = cell.querySelector( '.essf-ofx-desc-apply-link' );
		if ( link ) {
			link.remove();
		}
	}

	function enterDescEdit( cell ) {
		if ( ! cell ) {
			return;
		}
		var display = cell.querySelector( '.essf-ofx-desc-display' );
		var edit    = cell.querySelector( '.essf-ofx-desc-edit' );
		var input   = cell.querySelector( '.essf-ofx-desc-input' );
		var hidden  = cell.querySelector( '.essf-ofx-desc-value' );

		hideApplyLink( cell );

		input.value    = hidden.value;
		display.hidden = true;
		edit.hidden    = false;
		input.focus();
		input.select();
	}

	// Cancels the edit UI only — does not touch the apply-link's visibility.
	// Callers decide whether to restore it (cancel) or remove it (committed
	// change) since only they know which happened.
	function exitDescEdit( cell ) {
		if ( ! cell ) {
			return;
		}
		cell.querySelector( '.essf-ofx-desc-edit' ).hidden    = true;
		cell.querySelector( '.essf-ofx-desc-display' ).hidden = false;
	}

	// The cancel path: exit edit mode and bring back whatever offer was
	// showing before, since the row's value didn't actually change.
	function cancelDescEdit( cell ) {
		exitDescEdit( cell );
		restoreApplyLink( cell );
	}

	// When one row's description changes, every OTHER row that still shows
	// that same (un-customized) text gets a small "use this too" link
	// instead of being silently updated — most rows sharing a merchant/
	// counterparty were probably going to need the same fix, but it's the
	// operator's call per row, not automatic. Rows already individually
	// edited (their indicator is gone — see commitDescEdit()) are skipped,
	// since that's the signal an operator deliberately wants that one row
	// to differ from the rest.
	function offerBulkApply( dialog, editedCell, oldValue, newValue ) {
		if ( ! dialog || oldValue === newValue ) {
			return;
		}
		var label = ( dialog.dataset.applyLabel || 'Use "%s"' ).replace( '%s', newValue );

		dialog.querySelectorAll( '.essf-ofx-review-desc' ).forEach( function ( cell ) {
			if ( cell === editedCell ) {
				return;
			}
			var indicator = cell.querySelector( '.essf-ofx-desc-indicator' );
			if ( ! indicator ) {
				return;
			}
			var hidden = cell.querySelector( '.essf-ofx-desc-value' );
			if ( ! hidden || hidden.value !== oldValue ) {
				return;
			}

			var existingLink = cell.querySelector( '.essf-ofx-desc-apply-link' );
			if ( existingLink ) {
				existingLink.remove();
			}

			var link = document.createElement( 'button' );
			link.type = 'button';
			link.className = 'essf-ofx-desc-apply-link';
			link.dataset.applyValue = newValue;
			link.textContent = label;
			cell.querySelector( '.essf-ofx-desc-display' ).insertAdjacentElement( 'afterend', link );
		} );
	}

	function applyBulkLink( cell, dialog ) {
		var link = cell.querySelector( '.essf-ofx-desc-apply-link' );
		if ( ! link ) {
			return;
		}
		var hidden    = cell.querySelector( '.essf-ofx-desc-value' );
		var text      = cell.querySelector( '.essf-ofx-desc-text' );
		var indicator = cell.querySelector( '.essf-ofx-desc-indicator' );
		var value     = link.dataset.applyValue;

		hidden.value = value;
		if ( text ) {
			text.textContent = value;
		}
		if ( indicator ) {
			indicator.remove();
		}
		link.remove();
		saveState( dialog );
	}

	function commitDescEdit( cell, dialog ) {
		if ( ! cell ) {
			return;
		}
		var input      = cell.querySelector( '.essf-ofx-desc-input' );
		var hidden     = cell.querySelector( '.essf-ofx-desc-value' );
		var text       = cell.querySelector( '.essf-ofx-desc-text' );
		var indicator  = cell.querySelector( '.essf-ofx-desc-indicator' );
		var value      = input.value.trim();

		if ( value ) {
			var oldValue = hidden.value;
			var changed  = value !== oldValue;

			// Once the operator has knowingly changed the description, the
			// suggestion/needs-input indicator no longer describes anything —
			// drop it. Re-confirming the same value leaves it as-is.
			if ( changed && indicator ) {
				indicator.remove();
			}
			hidden.value      = value;
			text.textContent  = value;

			if ( changed ) {
				// The row's value is no longer what this cell's own pending
				// offer (if any) was proposing to replace — it's stale now.
				removeApplyLink( cell );
				offerBulkApply( dialog, cell, oldValue, value );
			} else {
				restoreApplyLink( cell );
			}
		}

		exitDescEdit( cell );
	}

	// Only one description cell edits at a time. Opening a different one
	// resolves whatever was already open first: commit it if the operator
	// had actually changed the value, otherwise just cancel it — either way
	// without requiring an explicit click on that row's own confirm/cancel.
	function closeOtherDescEdits( dialog, exceptCell ) {
		dialog.querySelectorAll( '.essf-ofx-review-desc' ).forEach( function ( cell ) {
			if ( cell === exceptCell ) {
				return;
			}
			var edit = cell.querySelector( '.essf-ofx-desc-edit' );
			if ( ! edit || edit.hidden ) {
				return;
			}
			var input  = cell.querySelector( '.essf-ofx-desc-input' );
			var hidden = cell.querySelector( '.essf-ofx-desc-value' );
			if ( input && hidden && input.value.trim() && input.value.trim() !== hidden.value ) {
				commitDescEdit( cell, dialog );
			} else {
				cancelDescEdit( cell );
			}
		} );
	}

	// ── Resume after a page reload ───────────────────────────────────────
	// The staged rows themselves survive a reload (they're in a server-side
	// transient, re-rendered on GET), but include/exclude toggles and
	// description edits only ever lived in the DOM. Mirror them into
	// localStorage, keyed by the review token, so a refresh mid-review
	// doesn't throw that work away; cleared once the import is confirmed.

	function storageKey( dialog ) {
		var token = dialog.dataset.token;
		return token ? 'essf_ofx_review_' + token : null;
	}

	function saveState( dialog ) {
		var key = storageKey( dialog );
		if ( ! key ) {
			return;
		}

		var state = { rows: {} };
		var fromInput = dialog.querySelector( '#essf-ofx-filter-from' );
		var toInput   = dialog.querySelector( '#essf-ofx-filter-to' );
		if ( fromInput || toInput ) {
			state.filter = {
				from: fromInput ? fromInput.value : '',
				to: toInput ? toInput.value : ''
			};
		}

		dialog.querySelectorAll( '.essf-ofx-review-table tbody tr' ).forEach( function ( row ) {
			var idx = row.dataset.row;
			if ( undefined === idx ) {
				return;
			}
			var includeInput = row.querySelector( '.essf-ofx-include-input' );
			var descValue    = row.querySelector( '.essf-ofx-desc-value' );
			var catSelect    = row.querySelector( '.essf-ofx-category-select' );
			state.rows[ idx ] = {
				include: includeInput ? includeInput.value : '1',
				description: descValue ? descValue.value : '',
				category: catSelect ? catSelect.value : ''
			};
		} );

		try {
			localStorage.setItem( key, JSON.stringify( state ) );
		} catch ( e ) {
			// Storage full or unavailable (e.g. private browsing) — the
			// review still works, it just won't survive a reload.
		}
	}

	function clearState( dialog ) {
		var key = storageKey( dialog );
		if ( ! key ) {
			return;
		}
		try {
			localStorage.removeItem( key );
		} catch ( e ) {
			// Ignore — nothing to clean up if storage isn't available.
		}
	}

	function restoreState( dialog ) {
		var key = storageKey( dialog );
		if ( ! key ) {
			return;
		}

		var raw;
		try {
			raw = localStorage.getItem( key );
		} catch ( e ) {
			return;
		}
		if ( ! raw ) {
			return;
		}

		var state;
		try {
			state = JSON.parse( raw );
		} catch ( e ) {
			return;
		}

		if ( state.filter ) {
			var fromInput = dialog.querySelector( '#essf-ofx-filter-from' );
			var toInput   = dialog.querySelector( '#essf-ofx-filter-to' );
			if ( fromInput && state.filter.from ) {
				fromInput.value = state.filter.from;
			}
			if ( toInput && state.filter.to ) {
				toInput.value = state.filter.to;
			}
		}

		Object.keys( state.rows || {} ).forEach( function ( idx ) {
			var row = dialog.querySelector( '.essf-ofx-review-table tbody tr[data-row="' + idx + '"]' );
			if ( ! row ) {
				return;
			}
			var saved = state.rows[ idx ];

			var includeInput = row.querySelector( '.essf-ofx-include-input' );
			var toggle        = row.querySelector( '.essf-ofx-row-toggle' );
			if ( includeInput && saved.include !== includeInput.value ) {
				var excluded = '0' === saved.include;
				includeInput.value = saved.include;
				row.classList.toggle( 'is-excluded', excluded );
				if ( toggle ) {
					toggle.textContent = excluded ? '↺' : '✕';
					toggle.setAttribute(
						'aria-label',
						excluded ? toggle.dataset.includeLabel : toggle.dataset.excludeLabel
					);
				}
			}

			var descValue = row.querySelector( '.essf-ofx-desc-value' );
			var descText  = row.querySelector( '.essf-ofx-desc-text' );
			var indicator = row.querySelector( '.essf-ofx-desc-indicator' );
			if ( descValue && saved.description && saved.description !== descValue.value ) {
				descValue.value = saved.description;
				if ( descText ) {
					descText.textContent = saved.description;
				}
				if ( indicator ) {
					indicator.remove();
				}
			}

			var catSelect = row.querySelector( '.essf-ofx-category-select' );
			if ( catSelect && saved.category && saved.category !== catSelect.value ) {
				catSelect.value = saved.category;
			}
		} );
	}

	function applyDateFilter( dialog ) {
		var from  = dialog.querySelector( '#essf-ofx-filter-from' ).value;
		var to    = dialog.querySelector( '#essf-ofx-filter-to' ).value;
		var rows  = dialog.querySelectorAll( '.essf-ofx-review-table tbody tr' );
		var shown = 0;

		rows.forEach( function ( row ) {
			var date    = row.dataset.date || '';
			var visible = ( ! from || date >= from ) && ( ! to || date <= to );
			row.hidden  = ! visible;
			if ( visible ) {
				shown++;
			}
		} );

		var count = dialog.querySelector( '.essf-ofx-filter-count' );
		if ( count ) {
			count.textContent = shown + ' / ' + rows.length;
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var dialog = document.getElementById( 'essf-ofx-review-modal' );
		if ( ! dialog ) {
			return;
		}

		restoreState( dialog );

		// The dialog ships with the `open` attribute so it's visible even
		// without JS; upgrade it to a true modal (backdrop, focus trap) here.
		if ( typeof dialog.showModal === 'function' ) {
			if ( dialog.open ) {
				dialog.close();
			}
			dialog.showModal();
		}

		dialog.addEventListener( 'click', function ( event ) {
			var toggle = event.target.closest( '.essf-ofx-row-toggle' );
			if ( toggle ) {
				var row   = toggle.closest( 'tr' );
				var input = row.querySelector( '.essf-ofx-include-input' );
				var willExclude = '1' === input.value;

				input.value = willExclude ? '0' : '1';
				row.classList.toggle( 'is-excluded', willExclude );
				toggle.textContent = willExclude ? '↺' : '✕';
				toggle.setAttribute(
					'aria-label',
					willExclude ? toggle.dataset.includeLabel : toggle.dataset.excludeLabel
				);
				saveState( dialog );
				return;
			}

			var applyLink = event.target.closest( '.essf-ofx-desc-apply-link' );
			if ( applyLink ) {
				applyBulkLink( findDescCell( applyLink ), dialog );
				return;
			}

			var display = event.target.closest( '.essf-ofx-desc-display' );
			if ( display ) {
				var descCell = findDescCell( display );
				closeOtherDescEdits( dialog, descCell );
				enterDescEdit( descCell );
				saveState( dialog );
				return;
			}

			var confirmBtn = event.target.closest( '.essf-ofx-desc-confirm' );
			if ( confirmBtn ) {
				commitDescEdit( findDescCell( confirmBtn ), dialog );
				saveState( dialog );
				return;
			}

			var cancelBtn = event.target.closest( '.essf-ofx-desc-cancel' );
			if ( cancelBtn ) {
				cancelDescEdit( findDescCell( cancelBtn ) );
			}
		} );

		dialog.addEventListener( 'keydown', function ( event ) {
			if ( ! event.target.classList.contains( 'essf-ofx-desc-input' ) ) {
				return;
			}
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				commitDescEdit( findDescCell( event.target ), dialog );
				saveState( dialog );
			} else if ( 'Escape' === event.key ) {
				event.preventDefault();
				cancelDescEdit( findDescCell( event.target ) );
			}
		} );

		dialog.addEventListener( 'change', function ( event ) {
			if ( event.target.classList.contains( 'essf-ofx-category-select' ) ) {
				saveState( dialog );
			}
		} );

		var filterInputs = dialog.querySelectorAll( '.essf-ofx-filter-input' );
		if ( filterInputs.length ) {
			filterInputs.forEach( function ( input ) {
				input.addEventListener( 'input', function () {
					applyDateFilter( dialog );
					saveState( dialog );
				} );
			} );
			applyDateFilter( dialog );
		}

		var form = dialog.querySelector( 'form' );
		if ( form ) {
			form.addEventListener( 'submit', function () {
				clearState( dialog );
			} );
		}
	} );
} )();
