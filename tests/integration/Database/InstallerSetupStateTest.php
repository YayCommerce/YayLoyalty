<?php
namespace YayLoyalty\Tests\Integration\Database;

use YayLoyalty\Database\Installer;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Helpers\SetupHelper;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** Which installs start the setup wizard: only a site that never had the plugin, never an upgrade of a live store. */
final class InstallerSetupStateTest extends IntegrationTestCase {

    /** Site options the tests overwrite. wpdb's options table is the site's own, not a scratch one. */
    private const OPTIONS = [
        Installer::OPTION_DB_VERSION,
        SetupHelper::OPTION_SETUP,
        SettingsHelper::OPTION_SETTINGS,
    ];

    /** @var array<string, mixed> Option => stored value, null when it was absent. */
    private $saved = [];

    protected function setUp(): void {
        parent::setUp();

        foreach ( self::OPTIONS as $option ) {
            $this->saved[ $option ] = get_option( $option, null );
        }
    }

    protected function tearDown(): void {
        foreach ( $this->saved as $option => $value ) {
            if ( null === $value ) {
                delete_option( $option );
            } else {
                update_option( $option, $value );
            }
        }

        parent::tearDown();
    }

    public function test_an_existing_install_is_marked_completed_and_stays_on(): void {
        update_option( Installer::OPTION_DB_VERSION, '0.9.0' );
        delete_option( SetupHelper::OPTION_SETUP );
        SettingsHelper::update_settings( [ 'enabled' => true ] );

        Installer::install();

        $this->assertSame( SetupHelper::STATUS_COMPLETED, SetupHelper::get_state()['status'] );
        $this->assertTrue( SettingsHelper::get_settings()['enabled'] );
    }

    public function test_a_fresh_install_starts_pending_with_the_program_off(): void {
        delete_option( Installer::OPTION_DB_VERSION );
        delete_option( SetupHelper::OPTION_SETUP );
        SettingsHelper::update_settings( [ 'enabled' => true ] );

        Installer::install();

        $this->assertSame(
            [
                'status'              => 'pending',
                'step'                => 1,
                'draft'               => null,
                'first_run_dismissed' => false,
            ],
            get_option( SetupHelper::OPTION_SETUP )
        );
        $this->assertFalse( SettingsHelper::get_settings()['enabled'] );
    }

    public function test_an_upgrade_keeps_a_pending_setup_where_it_was(): void {
        update_option( Installer::OPTION_DB_VERSION, '0.9.0' );
        update_option(
            SetupHelper::OPTION_SETUP,
            [
                'status'              => 'pending',
                'step'                => 3,
                'draft'               => null,
                'first_run_dismissed' => false,
            ]
        );

        Installer::install();

        $this->assertSame( SetupHelper::STATUS_PENDING, SetupHelper::get_state()['status'] );
        $this->assertSame( 3, SetupHelper::get_state()['step'] );
    }
}
