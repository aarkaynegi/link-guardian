<?php
/**
 * End-to-end functional test for Link Guardian.
 *
 * Unlike tests/logic-test.php (which stubs WordPress), this boots a REAL
 * WordPress install and drives the full flow: activation, slug-change ->
 * auto 301, chain resolution, the cron-scheduled link rewrite, the M4
 * prefix-corruption regression, save-time loop prevention, and the REST
 * audit endpoint.
 *
 * Prerequisites: a WordPress install (MySQL or the SQLite drop-in) with this
 * plugin active and pretty permalinks enabled.
 *
 * Run:
 *   WP=/path/to/wordpress php tests/functional-test.php
 *
 * It was developed against WordPress 6.5 on the sqlite-database-integration
 * drop-in, driven by WP-CLI, with PHP 8.x.
 *
 * @package LinkGuardian
 */

define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

$wp_path = getenv( 'WP' );
if ( ! $wp_path || ! file_exists( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "Set WP=/path/to/wordpress (the dir containing wp-load.php).\n" );
	exit( 2 );
}
require $wp_path . '/wp-load.php';

$pass = 0;
$fail = 0;
function ok( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  PASS  $label\n";
	} else {
		++$fail;
		echo "  FAIL  $label\n";
	}
}

wp_set_current_user( 1 ); // Administrator (edit_others_posts + manage_options).

$lg        = link_guardian();
$redirects = $lg->redirects();

echo "\n# DB round-trip (activation created the table; CRUD works)\n";
$mid = $redirects->upsert(
	array(
		'source_path' => '/manual-old',
		'target_url'  => '/manual-new',
		'is_auto'     => 0,
	)
);
ok( 'upsert returns a row id', is_int( $mid ) && $mid > 0 );
$mrow = $redirects->get_by_source( '/manual-old' );
ok( 'row reads back with correct target', $mrow && '/manual-new' === $mrow->target_url );

echo "\n# slug change -> auto 301 + chain resolution + cron scheduled\n";
$a        = wp_insert_post(
	array(
		'post_title'   => 'Alpha',
		'post_content' => 'Alpha body.',
		'post_status'  => 'publish',
	)
);
$old_url  = get_permalink( $a );
$old_path = Link_Guardian_Redirects::normalize_path( $old_url );
$b        = wp_insert_post(
	array(
		'post_title'   => 'Beta',
		'post_content' => 'Read <a href="' . $old_url . '">Alpha</a> and <a href="' . $old_path . '">again</a>.',
		'post_status'  => 'publish',
	)
);

wp_update_post(
	array(
		'ID'        => $a,
		'post_name' => 'alpha-renamed',
	)
);
$new_url         = get_permalink( $a );
$expected_target = Link_Guardian_Redirects::normalize_path( $new_url );

ok( 'permalink actually changed', $old_url !== $new_url );
$rr = $redirects->get_by_source( $old_path );
ok( 'auto redirect old->new created', $rr && 1 === (int) $rr->is_auto && $rr->target_url === $expected_target );
$resolved = $redirects->resolve_chain( $old_path );
ok( 'resolve_chain targets the new URL', $resolved && false !== strpos( $resolved['target'], $expected_target ) );
$sched = wp_next_scheduled( 'link_guardian_rewrite_batch', array( (int) $a, $old_url, $new_url, 0 ) );
ok( 'background rewrite was scheduled (cron)', false !== $sched );

echo "\n# run the rewrite worker -> Beta's links are fixed\n";
do_action( 'link_guardian_rewrite_batch', (int) $a, $old_url, $new_url, 0 );
clean_post_cache( $b );
$beta = get_post( $b );
ok( 'Beta no longer contains the old absolute URL', false === strpos( $beta->post_content, 'href="' . $old_url . '"' ) );
ok( 'Beta now contains the new URL', false !== strpos( $beta->post_content, $new_url ) );

echo "\n# M4 regression: a sibling sharing the old prefix is NOT corrupted\n";
$sib_url = home_url( '/alpha-sibling/' );
$c       = wp_insert_post(
	array(
		'post_title'   => 'Gamma',
		'post_content' => 'Link to <a href="' . $sib_url . '">sibling</a>.',
		'post_status'  => 'publish',
	)
);
do_action( 'link_guardian_rewrite_batch', (int) $a, $old_url, $new_url, 0 );
clean_post_cache( $c );
ok( 'sibling /alpha-sibling left intact', false !== strpos( get_post( $c )->post_content, $sib_url ) );

echo "\n# loop prevention (save-time) on the live DB\n";
$redirects->upsert(
	array(
		'source_path' => '/lx',
		'target_url'  => '/ly',
		'is_auto'     => 1,
	)
);
$loop = $redirects->upsert(
	array(
		'source_path' => '/ly',
		'target_url'  => '/lx',
		'is_auto'     => 1,
	)
);
ok( 'reverse rule refused (would create a loop)', false === $loop );

echo "\n# REST audit endpoint (auth + data)\n";
$res = rest_do_request( new WP_REST_Request( 'GET', '/link-guardian/v1/audit' ) );
ok( 'audit endpoint returns 200', 200 === $res->get_status() );
$data = $res->get_data();
ok( 'audit payload has a summary', isset( $data['summary']['total'] ) );

echo "\n----------------------------------------\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
