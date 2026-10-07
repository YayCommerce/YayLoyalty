<?php
namespace YayLoyalty\Tests\Integration\Settings;

use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** The settings limits: null is no limit, and a 0 stored when 0 meant "no limit" reads and saves as null. */
final class SettingsLimitTest extends IntegrationTestCase {

    protected function setUp(): void {
        parent::setUp();
        delete_option( SettingsHelper::OPTION_SETTINGS );
    }

    protected function tearDown(): void {
        delete_option( SettingsHelper::OPTION_SETTINGS );
        parent::tearDown();
    }

    public function test_a_stored_zero_reads_as_no_limit(): void {
        update_option(
            SettingsHelper::OPTION_SETTINGS,
            [
                'advanced' => [
                    'adjustment_ceiling' => 0,
                    'max_points_per_day' => 0,
                ],
                'emails'   => [ 'throttle_per_week' => 0 ],
            ]
        );

        $settings = SettingsHelper::get_settings();

        $this->assertNull( $settings['advanced']['adjustment_ceiling'] );
        $this->assertNull( $settings['advanced']['max_points_per_day'] );
        $this->assertNull( $settings['emails']['throttle_per_week'] );
    }

    public function test_zero_or_empty_saves_null_and_a_number_saves_it(): void {
        SettingsHelper::update_settings(
            [
                'advanced' => [
                    'max_points_per_day' => 0,
                    'adjustment_ceiling' => '',
                ],
                'emails'   => [ 'throttle_per_week' => null ],
            ]
        );

        $stored = get_option( SettingsHelper::OPTION_SETTINGS );

        $this->assertNull( $stored['advanced']['max_points_per_day'] );
        $this->assertNull( $stored['advanced']['adjustment_ceiling'] );
        $this->assertNull( $stored['emails']['throttle_per_week'] );

        SettingsHelper::update_settings( [ 'advanced' => [ 'max_points_per_day' => 5 ] ] );

        $this->assertSame( 5, SettingsHelper::get_settings()['advanced']['max_points_per_day'] );
    }

    public function test_an_unsaved_throttle_keeps_its_default(): void {
        $this->assertSame( 3, SettingsHelper::get_settings()['emails']['throttle_per_week'] );
        $this->assertNull( SettingsHelper::get_settings()['advanced']['max_points_per_day'] );
    }
}
