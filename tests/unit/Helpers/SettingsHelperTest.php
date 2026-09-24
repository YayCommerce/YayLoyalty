<?php
namespace YayLoyalty\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Helpers\SettingsHelper;

/** SettingsHelper coerces booleans the way WordPress reads them. */
class SettingsHelperTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yayloyalty_test_options'] = [];
    }

    /** A boolean sent as a string is read the way WordPress reads it, so "false" is false. */
    public function test_bool_accepts_string_forms(): void {
        $cases = [
            'false' => false,
            '0'     => false,
            'true'  => true,
            '1'     => true,
        ];

        foreach ( $cases as $input => $expected ) {
            $result = SettingsHelper::update_settings( [ 'general' => [ 'enabled' => (string) $input ] ] );
            $this->assertSame( $expected, $result['general']['enabled'], "input '{$input}'" );
        }
    }
}
