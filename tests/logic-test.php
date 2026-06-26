<?php
/**
 * Standalone logic tests for Link Guardian's pure algorithms.
 *
 * These run WITHOUT a WordPress install by stubbing the handful of WP functions
 * the algorithmic code touches, then exercising the real plugin classes. They
 * cover the highest-risk logic: path normalisation, target sanitisation, the
 * redirect chain resolver, multi-hop loop detection/prevention, and the
 * href-anchored replacement map.
 *
 * Run: php tests/logic-test.php
 *
 * @package LinkGuardian
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );
define( 'LINK_GUARDIAN_VERSION', 'test' );

$GLOBALS['__home'] = 'https://example.com';

/* ---- Minimal WordPress function stubs ---- */
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function home_url( $path = '' ) {
	$base = rtrim( $GLOBALS['__home'], '/' );
	return ( '' === $path ) ? $base : $base . '/' . ltrim( $path, '/' );
}
function esc_url_raw( $url, $protocols = null ) {
	return $url;
}
function untrailingslashit( $s ) {
	return rtrim( $s, '/' );
}
function trailingslashit( $s ) {
	return rtrim( $s, '/' ) . '/';
}
function apply_filters( $tag, $value ) {
	return $value;
}
function url_to_postid( $url ) {
	// No real posts in the harness; treat every URL as resolving to none.
	return 0;
}

require __DIR__ . '/../includes/class-link-guardian-redirects.php';
require __DIR__ . '/../includes/class-link-guardian-slug-watcher.php';

/**
 * Redirect store backed by an in-memory rule map (no DB).
 */
class Test_Redirects extends Link_Guardian_Redirects {
	public $rules = array();

	public function add( $source, $target, $active = 1, $auto = 0, $type = 301 ) {
		$p                 = self::normalize_path( $source );
		$this->rules[ $p ] = (object) array(
			'id'            => count( $this->rules ) + 1,
			'source_path'   => $p,
			'target_url'    => $target,
			'redirect_type' => $type,
			'is_active'     => $active,
			'is_auto'       => $auto,
		);
	}

	public function get_by_source( $source_path ) {
		$p = self::normalize_path( $source_path );
		return isset( $this->rules[ $p ] ) ? $this->rules[ $p ] : null;
	}

	public function get_all() {
		return array_values( $this->rules );
	}
}

/**
 * Expose the protected replacement builder.
 */
class Test_Watcher extends Link_Guardian_Slug_Watcher {
	public function replacements( $a, $b, $c, $d ) {
		return $this->build_replacements( $a, $b, $c, $d );
	}
}

/* ---- Tiny test runner ---- */
$pass = 0;
$fail = 0;
function check( $label, $got, $expected ) {
	global $pass, $fail;
	$ok = ( $got === $expected );
	if ( $ok ) {
		++$pass;
		echo "  PASS  $label\n";
	} else {
		++$fail;
		echo "  FAIL  $label\n";
		echo '          expected: ' . var_export( $expected, true ) . "\n";
		echo '          got:      ' . var_export( $got, true ) . "\n";
	}
}

$R = new Test_Redirects();

echo "\n# normalize_path\n";
check( 'trailing slash stripped', Link_Guardian_Redirects::normalize_path( '/foo/' ), '/foo' );
check( 'absolute -> path', Link_Guardian_Redirects::normalize_path( 'https://example.com/bar/' ), '/bar' );
check( 'query + fragment removed', Link_Guardian_Redirects::normalize_path( 'https://example.com/p/?x=1#h' ), '/p' );
check( 'root stays root', Link_Guardian_Redirects::normalize_path( '/' ), '/' );
check( 'missing leading slash', Link_Guardian_Redirects::normalize_path( 'baz' ), '/baz' );

echo "\n# sanitize_target (XSS / open-redirect guards)\n";
check( 'javascript: rejected', Link_Guardian_Redirects::sanitize_target( 'javascript:alert(1)' ), '' );
check( 'data: rejected', Link_Guardian_Redirects::sanitize_target( 'data:text/html,x' ), '' );
check( 'protocol-relative rejected', Link_Guardian_Redirects::sanitize_target( '//evil.com/x' ), '' );
check( 'relative path normalised', Link_Guardian_Redirects::sanitize_target( 'new-page' ), '/new-page' );
check( 'https kept', Link_Guardian_Redirects::sanitize_target( 'https://example.com/x' ), 'https://example.com/x' );

echo "\n# resolve_chain (collapse) & walk_chain (loops)\n";
$R->rules = array();
$R->add( '/a', '/b', 1, 1 );
$R->add( '/b', '/c', 1, 1 );
$r = $R->resolve_chain( '/a' );
check( 'A->B->C collapses to terminal /c', $r['target'], 'https://example.com/c' );
check( 'A->B->C not a loop', $R->walk_chain( '/a' )['loop'], false );

$R->rules = array();
$R->add( '/a', '/b', 1, 1 );
$R->add( '/b', '/a', 1, 1 );
check( '2-hop loop A->B->A detected', $R->walk_chain( '/a' )['loop'], true );
check( '2-hop loop -> resolve_chain returns null', $R->resolve_chain( '/a' ), null );

$R->rules = array();
$R->add( '/a', '/b', 1, 1 );
$R->add( '/b', '/c', 1, 1 );
$R->add( '/c', '/a', 1, 1 );
check( '3-hop loop A->B->C->A detected', $R->walk_chain( '/a' )['loop'], true );
check( '3-hop loop -> resolve_chain returns null', $R->resolve_chain( '/a' ), null );

echo "\n# would_create_cycle (save-time prevention)\n";
$R->rules = array();
$R->add( '/a', '/b', 1, 1 );
$R->add( '/b', '/c', 1, 1 );
check( 'adding /c -> /a would close a 3-hop loop', $R->would_create_cycle( '/c', '/a' ), true );
check( 'adding /c -> /d is safe', $R->would_create_cycle( '/c', '/d' ), false );
check( 'direct self-loop /x -> /x', $R->would_create_cycle( '/x', '/x' ), true );
$R->rules = array();
$R->add( '/a', '/b', 1, 1 );
check( 'rename-back /b -> /a would loop', $R->would_create_cycle( '/b', '/a' ), true );

echo "\n# audit (whole-graph)\n";
$R->rules = array();
$R->add( '/a', '/b', 1, 1 );  // chain
$R->add( '/b', '/c', 1, 1 );
$R->add( '/x', '/y', 1, 1 );  // loop
$R->add( '/y', '/x', 1, 1 );
$audit = $R->audit();
check( 'audit finds the loop', $audit['summary']['loops'], 1 );
check( 'audit finds >=1 chain', $audit['summary']['chains'] >= 1, true );
check( 'audit counts connected links', $audit['summary']['connected'] >= 1, true );

echo "\n# build_replacements (M4: no prefix corruption)\n";
$W   = new Test_Watcher( $R );
$rep = $W->replacements( 'https://example.com/a', 'https://example.com/a-new', '/a', '/a-new' );
$in  = '<a href="https://example.com/about">sibling</a> '
	. '<a href="https://example.com/a">abs</a> '
	. '<a href="/a">rel</a>';
$out = strtr( $in, $rep );
check( 'sibling /about is NOT corrupted', ( false !== strpos( $out, 'href="https://example.com/about"' ) ), true );
check( 'absolute /a IS rewritten', ( false !== strpos( $out, 'href="https://example.com/a-new"' ) ), true );
check( 'relative /a IS rewritten', ( false !== strpos( $out, 'href="/a-new"' ) ), true );
check( 'no leftover bare /a link', ( false === strpos( $out, 'href="/a"' ) ), true );

echo "\n----------------------------------------\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
