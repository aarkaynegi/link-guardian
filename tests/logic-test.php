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
function wp_cache_get( $key, $group = '' ) {
	return false;
}
function wp_cache_set( $key, $value, $group = '' ) {
	return true;
}
function wp_cache_delete( $key, $group = '' ) {
	return true;
}

require __DIR__ . '/../includes/class-link-guardian-redirects.php';
require __DIR__ . '/../includes/class-link-guardian-slug-watcher.php';

/**
 * Redirect store backed by an in-memory rule map (no DB).
 */
class Test_Redirects extends Link_Guardian_Redirects {
	public $rules = array();

	public function add( $source, $target, $active = 1, $auto = 0, $type = 301, $match_type = 'exact', $exceptions = '' ) {
		$key                 = ( 'exact' === $match_type )
			? self::normalize_path( $source )
			: self::normalize_pattern_source( $source, $match_type );
		$this->rules[ $key ] = (object) array(
			'id'            => count( $this->rules ) + 1,
			'source_path'   => $key,
			'target_url'    => $target,
			'match_type'    => $match_type,
			'exceptions'    => $exceptions,
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

	public function get_pattern_rules() {
		$out = array();
		foreach ( $this->rules as $r ) {
			if ( 1 === (int) $r->is_active && 'exact' !== $r->match_type ) {
				$out[] = $r;
			}
		}
		return $out;
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

echo "\n# pattern matching (wildcard / regex / exceptions)\n";
$R->rules = array();
$R->add( '/blog/*', '/news/*', 1, 0, 301, 'wildcard' );
$w = $R->match_pattern( '/blog/hello-world' );
check( 'wildcard /blog/* -> /news/*', $w && false !== strpos( $w['target'], '/news/hello-world' ), true );
check( 'wildcard non-match returns null', $R->match_pattern( '/shop/x' ), null );

$R->rules = array();
$R->add( '^/shop/(\\d+)$', '/products/$1', 1, 0, 302, 'regex' );
$rx = $R->match_pattern( '/shop/42' );
check( 'regex capture -> /products/42', $rx && false !== strpos( $rx['target'], '/products/42' ), true );
check( 'regex preserves redirect type 302', $rx && 302 === $rx['type'], true );
check( 'regex no-match returns null', $R->match_pattern( '/shop/abc' ), null );

$R->rules = array();
$R->add( '/docs/*', '/help/*', 1, 0, 301, 'wildcard', "/docs/keep\n/docs/legacy-*" );
check( 'exception (exact) skips the rule', $R->match_pattern( '/docs/keep' ), null );
check( 'exception (wildcard) skips the rule', $R->match_pattern( '/docs/legacy-v1' ), null );
$ok = $R->match_pattern( '/docs/intro' );
check( 'non-excepted path still redirects', $ok && false !== strpos( $ok['target'], '/help/intro' ), true );

echo "\n# pattern validation / safety\n";
check( 'invalid regex does not compile', Link_Guardian_Redirects::compile_pattern( '(unclosed', 'regex' ), null );
check( 'valid wildcard compiles', is_string( Link_Guardian_Redirects::compile_pattern( '/a/*', 'wildcard' ) ), true );
check( 'pattern target rejects javascript:', Link_Guardian_Redirects::sanitize_pattern_target( 'javascript:alert(1)' ), '' );
check( 'pattern target keeps capture ref', Link_Guardian_Redirects::sanitize_pattern_target( '/x/$1' ), '/x/$1' );
check( 'match_type sanitises unknown -> exact', Link_Guardian_Redirects::sanitize_match_type( 'bogus' ), 'exact' );

echo "\n# open-redirect guard (host must not come from a visitor capture)\n";
$R->rules = array();
$R->add( '/go/*', '*', 1, 0, 301, 'wildcard' );
check( 'bare-capture wildcard cannot redirect off-site', $R->match_pattern( '/go/http://evil.com' ), null );
$R->rules = array();
$R->add( '^/r/(.*)$', '$1', 1, 0, 301, 'regex' );
check( 'bare-capture regex cannot redirect off-site', $R->match_pattern( '/r/https://evil.com' ), null );
$R->rules = array();
$R->add( '/ext/*', 'https://trusted.example/*', 1, 0, 301, 'wildcard' );
$ext = $R->match_pattern( '/ext/page' );
check( 'literal external host (admin intent) is allowed', $ext && 'https://trusted.example/page' === $ext['target'], true );

echo "\n# pattern loop protection (serve-time)\n";
$R->rules = array();
$R->add( '/blog/*', '/blog/archive/*', 1, 0, 301, 'wildcard' );
check( 'self-growing pattern loop is aborted (no infinite redirect)', $R->resolve_pattern_chain( '/blog/x' ), null );
$R->rules = array();
$R->add( '/a/*', '/b/*', 1, 0, 301, 'wildcard' );
$R->add( '/b/*', '/a/*', 1, 0, 301, 'wildcard' );
check( 'two-rule pattern loop aborted', $R->resolve_pattern_chain( '/a/x' ), null );
$R->rules = array();
$R->add( '/old/*', '/new/*', 1, 0, 301, 'wildcard' );
$chain = $R->resolve_pattern_chain( '/old/page' );
check( 'normal pattern resolves to terminal', $chain && false !== strpos( $chain['target'], '/new/page' ), true );

echo "\n----------------------------------------\n";
echo "RESULT: {$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
