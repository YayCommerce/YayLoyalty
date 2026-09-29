<?php
namespace YayLoyalty\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Helpers\SettingsHelper;

/** SettingsHelper coerces each field to its declared type on the way in. */
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

    public function test_update_settings_writes_only_the_fields_sent(): void {
        SettingsHelper::update_settings( [ 'general' => [ 'points_label_singular' => 'Star' ] ] );
        SettingsHelper::update_settings( [ 'advanced' => [ 'adjustment_ceiling' => 50 ] ] );

        $settings = SettingsHelper::get_settings();
        $this->assertSame( 'Star', $settings['general']['points_label_singular'] );
        $this->assertSame( 50, $settings['advanced']['adjustment_ceiling'] );
    }

    /** Defaults stay out of the stored option, so translated defaults like the point labels keep following the site language. */
    public function test_update_settings_stores_no_default_it_was_not_sent(): void {
        SettingsHelper::update_settings( [ 'emails' => [ 'throttle_per_week' => 5 ] ] );

        $stored = get_option( SettingsHelper::OPTION_SETTINGS );
        $this->assertArrayNotHasKey( 'general', $stored );
        $this->assertSame( 5, $stored['emails']['throttle_per_week'] );
    }

    public function test_update_settings_can_empty_a_list(): void {
        SettingsHelper::update_settings( [ 'general' => [ 'excluded_roles' => [ 'editor' ] ] ] );
        SettingsHelper::update_settings( [ 'general' => [ 'excluded_roles' => [] ] ] );

        $this->assertSame( [], SettingsHelper::get_settings()['general']['excluded_roles'] );
    }

    /** A stored list replaces the default list whole; a recursive merge would keep the default's tail. */
    public function test_a_stored_list_replaces_the_default_list(): void {
        SettingsHelper::update_settings( [ 'general' => [ 'award_statuses' => [ 'on-hold' ] ] ] );

        $this->assertSame( [ 'on-hold' ], SettingsHelper::award_statuses() );
    }

    public function test_award_statuses_fall_back_to_the_default_when_emptied(): void {
        SettingsHelper::update_settings( [ 'general' => [ 'award_statuses' => [] ] ] );

        $this->assertSame( [ 'processing', 'completed' ], SettingsHelper::award_statuses() );
    }

    public function test_emails_throttle_per_week_defaults_to_three(): void {
        $this->assertSame( 3, SettingsHelper::get_settings()['emails']['throttle_per_week'] );
    }

    /** The throttle is a count: a negative value is stored as 0, whoever writes it. */
    public function test_emails_throttle_per_week_is_never_negative(): void {
        $result = SettingsHelper::update_settings( [ 'emails' => [ 'throttle_per_week' => -2 ] ] );

        $this->assertSame( 0, $result['emails']['throttle_per_week'] );
    }
}
