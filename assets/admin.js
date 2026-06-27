/* global jQuery, LinkGuardian */
( function ( $ ) {
	'use strict';

	var cfg       = window.LinkGuardian || {};
	var BATCH_SIZE = 20;

	function startScan() {
		var $btn      = $( '#lg-scan-start' );
		var total     = parseInt( $btn.data( 'total' ), 10 ) || 0;
		var $progress = $( '#lg-scan-progress' );
		var $bar      = $progress.find( '.lg-progress__bar span' );
		var $label    = $progress.find( '.lg-progress__label' );
		var $results  = $( '#lg-scan-results' );
		var $tbody    = $results.find( 'tbody' );
		var brokenCount = 0;

		$btn.prop( 'disabled', true );
		$progress.prop( 'hidden', false );
		$results.prop( 'hidden', true );
		$tbody.empty();
		$bar.css( 'width', '0%' );

		if ( 0 === total ) {
			$label.text( cfg.i18n.noBroken );
			$btn.prop( 'disabled', false );
			return;
		}

		function runBatch( offset ) {
			$.post( cfg.ajaxUrl, {
				action: 'lg_scan_batch',
				nonce:  cfg.nonce,
				offset: offset,
				limit:  BATCH_SIZE
			} ).done( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					$label.text( cfg.i18n.error );
					$btn.prop( 'disabled', false );
					return;
				}

				var d   = resp.data;
				var pct = d.total ? Math.round( ( d.processed / d.total ) * 100 ) : 100;
				$bar.css( 'width', pct + '%' );

				if ( d.broken && d.broken.length ) {
					d.broken.forEach( function ( item ) {
						appendRow( $tbody, item );
						brokenCount++;
					} );
					$results.prop( 'hidden', false );
				}

				if ( d.done ) {
					$bar.css( 'width', '100%' );
					$label.text(
						cfg.i18n.done + ' ' +
						( brokenCount ? brokenCount + ' ' + cfg.i18n.foundOne : cfg.i18n.noBroken )
					);
					$btn.prop( 'disabled', false );
				} else {
					$label.text( cfg.i18n.scanning + ' ' + d.processed + ' / ' + d.total );
					runBatch( d.processed );
				}
			} ).fail( function () {
				$label.text( cfg.i18n.error );
				$btn.prop( 'disabled', false );
			} );
		}

		runBatch( 0 );
	}

	function appendRow( $tbody, item ) {
		var $tr = $( '<tr/>' );

		var $found = $( '<td/>' );
		if ( item.edit_link ) {
			$found.append( $( '<a/>', { href: item.edit_link, text: item.post_title || '(untitled)' } ) );
		} else {
			$found.text( item.post_title || '(untitled)' );
		}

		var $url = $( '<td/>' ).append( $( '<code/>', { text: item.url } ) );

		var fixHref = cfg.redirectsUrl +
			'&lg_prefill_source=' + encodeURIComponent( item.path ) + '#lg-source';
		var $action = $( '<td/>' ).append(
			$( '<a/>', { href: fixHref, 'class': 'button button-small', text: cfg.i18n.fixLabel } )
		);

		$tr.append( $found, $url, $action );
		$tbody.append( $tr );
	}

	/* ------------------------------------------------------------------
	 * Redirect audit (REST API)
	 * --------------------------------------------------------------- */

	function esc( str ) {
		return $( '<div/>' ).text( null === str || undefined === str ? '' : String( str ) ).html();
	}

	function pill( val, label, tone ) {
		return '<span class="lg-pill lg-pill--' + ( tone || 'n' ) + '">' +
			'<strong>' + ( val || 0 ) + '</strong> ' + esc( label ) + '</span>';
	}

	function section( title, itemsHtml ) {
		return '<div class="lg-card"><h2>' + esc( title ) + '</h2>' +
			'<ul class="lg-list">' + itemsHtml + '</ul></div>';
	}

	function arrow( parts ) {
		return ( parts || [] ).map( function ( p ) {
			return '<code>' + esc( p ) + '</code>';
		} ).join( ' <span class="lg-arrow">&rarr;</span> ' );
	}

	function ucfirst( str ) {
		str = String( str || '' );
		return str.charAt( 0 ).toUpperCase() + str.slice( 1 );
	}

	function renderAudit( data, $sum, $body ) {
		var s = data.summary || {};
		var patternIssue = ( s.pattern_loops || s.pattern_invalid ) ? 'warn' : 'n';

		$sum.prop( 'hidden', false ).html(
			pill( s.total, 'Total' ) +
			pill( s.exact, 'Exact' ) +
			pill( s.patterns, 'Patterns', patternIssue ) +
			pill( s.loops, 'Loops', s.loops ? 'bad' : 'good' ) +
			pill( s.chains, 'Chains', s.chains ? 'warn' : 'good' ) +
			pill( s.connected, 'Connected', s.connected ? 'warn' : 'n' )
		);

		var html = '';

		if ( data.loops && data.loops.length ) {
			html += section( cfg.i18n.loopsHd, data.loops.map( function ( hops ) {
				return '<li>' + arrow( hops ) + '</li>';
			} ).join( '' ) );
		}

		if ( data.chains && data.chains.length ) {
			html += section( cfg.i18n.chainsHd, data.chains.map( function ( c ) {
				return '<li>' + arrow( c.path ) +
					' <span class="lg-muted">(' + ( c.hops || 0 ) + ' hops)</span></li>';
			} ).join( '' ) );
		}

		if ( data.connected && data.connected.length ) {
			html += section( cfg.i18n.connHd, data.connected.map( function ( c ) {
				return '<li>' + arrow( [ c.source, c.target ] ) + '</li>';
			} ).join( '' ) );
		}

		if ( data.broken_dest && data.broken_dest.length ) {
			html += section( cfg.i18n.deadHd, data.broken_dest.map( function ( c ) {
				return '<li>' + arrow( [ c.source, c.terminal ] ) + '</li>';
			} ).join( '' ) );
		}

		if ( data.patterns && data.patterns.length ) {
			html += section( cfg.i18n.patternsHd, data.patterns.map( function ( p ) {
				var flags = '';
				if ( ! p.valid ) {
					flags += ' <span class="lg-flag lg-flag--bad">' + esc( cfg.i18n.invalid ) + '</span>';
				}
				if ( p.self_loop ) {
					flags += ' <span class="lg-flag lg-flag--warn">' + esc( cfg.i18n.mayLoop ) + '</span>';
				}
				var exc = p.exceptions ? ' <span class="lg-muted">(' + p.exceptions + ' exc.)</span>' : '';
				return '<li><span class="lg-badge lg-badge--pattern">' + esc( ucfirst( p.match_type ) ) + '</span> ' +
					arrow( [ p.source, p.target ] ) + exc + flags + '</li>';
			} ).join( '' ) );
		}

		if ( '' === html ) {
			html = '<div class="notice notice-success inline"><p>' + esc( cfg.i18n.allClear ) + '</p></div>';
		}

		$body.html( html );
	}

	function runAudit() {
		var $btn  = $( '#lg-audit-run' );
		var label = $btn.text();
		var $sum  = $( '#lg-audit-summary' );
		var $body = $( '#lg-audit-body' );

		$btn.prop( 'disabled', true ).text( cfg.i18n.auditing );
		$sum.prop( 'hidden', true ).empty();
		$body.empty();

		$.ajax( {
			url:    cfg.restUrl + 'audit',
			method: 'GET',
			beforeSend: function ( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', cfg.restNonce );
			}
		} ).done( function ( data ) {
			renderAudit( data, $sum, $body );
		} ).fail( function () {
			$body.html( '<div class="notice notice-error inline"><p>' + esc( cfg.i18n.auditErr ) + '</p></div>' );
		} ).always( function () {
			$btn.prop( 'disabled', false ).text( label );
		} );
	}

	$( document ).on( 'click', '#lg-scan-start', startScan );
	$( document ).on( 'click', '#lg-audit-run', runAudit );

	// Adapt the Add form to the selected match type (exact / wildcard / regex).
	var MATCH_HINTS = {
		exact: {
			src: '/old-url',
			tgt: '/new-url or https://example.com/page',
			help: 'Exact match: redirect one specific URL to another.'
		},
		wildcard: {
			src: '/old-section/*',
			tgt: '/new-section/*',
			help: 'Wildcard: * matches anything. Put * in the target to reuse what * captured (e.g. /blog/* → /news/*).'
		},
		regex: {
			src: '^/shop/(\\d+)$',
			tgt: '/products/$1',
			help: 'Regex: full pattern matching. Use $1, $2… in the target for capture groups. Patterns run with a bounded backtrack limit.'
		}
	};

	function updateMatchUi() {
		var sel = document.getElementById( 'lg-match-type' );
		if ( ! sel ) {
			return;
		}
		var hint   = MATCH_HINTS[ sel.value ] || MATCH_HINTS.exact;
		var source = document.getElementById( 'lg-source' );
		var target = document.getElementById( 'lg-target' );
		var help   = document.querySelector( '.lg-match-help' );
		var excRow = document.querySelector( '.lg-exceptions-row' );

		if ( source ) {
			source.placeholder = hint.src;
		}
		if ( target ) {
			target.placeholder = hint.tgt;
		}
		if ( help ) {
			help.textContent = hint.help;
		}
		if ( excRow ) {
			if ( 'exact' === sel.value ) {
				excRow.setAttribute( 'hidden', '' );
			} else {
				excRow.removeAttribute( 'hidden' );
			}
		}
	}

	$( document ).on( 'change', '#lg-match-type', updateMatchUi );
	updateMatchUi();

	// Toggle the (full-width) Add / Edit redirect panel.
	$( document ).on( 'click', '.lg-add-toggle', function () {
		var panel = document.getElementById( 'lg-add-panel' );
		if ( ! panel ) {
			return;
		}
		var willOpen = panel.hasAttribute( 'hidden' );
		if ( willOpen ) {
			panel.removeAttribute( 'hidden' );
			panel.querySelector( 'input:not([readonly]):not([type=hidden])' ).focus();
		} else {
			panel.setAttribute( 'hidden', '' );
		}
		$( this ).attr( 'aria-expanded', willOpen ? 'true' : 'false' );
	} );
} )( jQuery );
