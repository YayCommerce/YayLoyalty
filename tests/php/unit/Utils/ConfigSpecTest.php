<?php
namespace YayTests\Unit\Utils;

use YayLoyalty\Utils\ConfigSpec;
use YayTests\Unit\TestCase;

/**
 * @group P-7
 */
final class ConfigSpecTest extends TestCase {

    public function test_url_accepts_http_and_https_only() {
        $spec = [ 'type' => 'url' ];

        $this->assertTrue( ConfigSpec::matches( 'https://instagram.com/shop', $spec ) );
        $this->assertTrue( ConfigSpec::matches( 'http://example.com/page?a=1', $spec ) );

        $this->assertFalse( ConfigSpec::matches( 'javascript:alert(1)', $spec ) );
        $this->assertFalse( ConfigSpec::matches( '/relative/path', $spec ) );
        $this->assertFalse( ConfigSpec::matches( 'ftp://example.com', $spec ) );
        $this->assertFalse( ConfigSpec::matches( 'https://', $spec ) );
        $this->assertFalse( ConfigSpec::matches( 42, $spec ) );
    }
}
