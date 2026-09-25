<?php
namespace YayLoyalty\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Enums\CampaignConditionType;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Utils\Json;

/**
 * The two non-throwing checks CampaignRestController runs before a row is
 * written. A config or condition set that fails here answers 4xx; one that
 * passes is what set_config()/set_conditions() then normalise and store.
 */
final class CampaignValidationTest extends TestCase {

    public function test_default_config_is_valid_for_every_lite_trigger(): void {
        foreach ( [ CampaignTrigger::PURCHASE, CampaignTrigger::SIGNUP, CampaignTrigger::REVIEW ] as $trigger ) {
            $this->assertTrue( Campaign::config_is_valid( $trigger, Campaign::default_config( $trigger ) ), $trigger );
        }
    }

    public function test_config_rejects_unknown_keys_and_wrong_types(): void {
        $this->assertFalse(
            Campaign::config_is_valid(
                CampaignTrigger::PURCHASE,
                [
                    'points_per_unit' => 1,
                    'typo'            => 1,
                ]
            )
        );
        $this->assertFalse( Campaign::config_is_valid( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 'lots' ] ) );
        $this->assertFalse( Campaign::config_is_valid( CampaignTrigger::PURCHASE, [ 'rounding' => 'sideways' ] ) );
        $this->assertFalse( Campaign::config_is_valid( CampaignTrigger::PURCHASE, [ 'excluded_product_ids' => [ 1, 'two' ] ] ) );
        $this->assertFalse( Campaign::config_is_valid( CampaignTrigger::SIGNUP, [ 'points' => 10.5 ] ) );
    }

    public function test_a_purchase_rate_or_multiplier_must_be_above_zero(): void {
        $this->assertFalse( Campaign::config_is_valid( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 0 ] ) );
        $this->assertFalse( Campaign::config_is_valid( CampaignTrigger::PURCHASE, [ 'points_per_unit' => -1 ] ) );
        $this->assertTrue( Campaign::config_is_valid( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 0.01 ] ) );
        $this->assertFalse( Campaign::config_is_valid( CampaignTrigger::PURCHASE, [ 'multiplier' => 0 ] ) );
        $this->assertTrue( Campaign::config_is_valid( CampaignTrigger::PURCHASE, [ 'multiplier' => 1.5 ] ) );
        $this->assertFalse( Campaign::config_is_valid( CampaignTrigger::PURCHASE, [ 'multiplier' => -2 ] ) );
        $this->assertFalse( Campaign::config_is_valid( CampaignTrigger::PURCHASE, [ 'points_per_unit' => '0' ] ) );
    }

    public function test_an_inclusive_minimum_still_accepts_zero(): void {
        $this->assertTrue( Campaign::config_is_valid( CampaignTrigger::PURCHASE, [ 'min_subtotal' => 0 ] ) );
    }

    public function test_config_accepts_the_schema_version_a_stored_blob_carries(): void {
        // GET /campaigns/{id} returns config with the version stamp Json adds
        // on write; a client editing and sending it back must not be rejected.
        $config = Campaign::default_config( CampaignTrigger::PURCHASE ) + [ Json::SCHEMA_VERSION_KEY => 1 ];

        $this->assertTrue( Campaign::config_is_valid( CampaignTrigger::PURCHASE, $config ) );
    }

    public function test_config_of_a_trigger_this_build_cannot_judge_is_accepted(): void {
        $this->assertTrue( Campaign::config_is_valid( 'some_other_builds_trigger', [ 'anything' => 'goes' ] ) );
    }

    public function test_conditions_accept_an_empty_and_a_well_formed_set(): void {
        $this->assertTrue( Campaign::conditions_are_valid( [] ) );
        $this->assertTrue(
            Campaign::conditions_are_valid(
                [
                    [
                        'type'     => CampaignConditionType::USER_ROLE,
                        'operator' => CampaignConditionType::OP_IN,
                        'value'    => [ 'customer' ],
                    ],
                ]
            )
        );
    }

    public function test_conditions_reject_unknown_type_bad_operator_and_duplicates(): void {
        $role = [
            'type'     => CampaignConditionType::USER_ROLE,
            'operator' => CampaignConditionType::OP_IN,
            'value'    => [ 'customer' ],
        ];

        $this->assertFalse(
            Campaign::conditions_are_valid(
                [
                    [
                        'type'     => 'no_such_type',
                        'operator' => 'in',
                        'value'    => [ 1 ],
                    ],
                ]
            )
        );
        $this->assertFalse(
            Campaign::conditions_are_valid(
                [
                    [
                        'type'     => CampaignConditionType::USER_ROLE,
                        'operator' => CampaignConditionType::OP_GTE,
                        'value'    => [ 'customer' ],
                    ],
                ]
            )
        );
        $this->assertFalse( Campaign::conditions_are_valid( [ $role, $role ] ) );
    }
}
