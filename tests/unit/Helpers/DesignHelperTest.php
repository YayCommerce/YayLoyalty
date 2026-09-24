<?php
namespace YayLoyalty\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\DesignSchema;

/**
 * DesignHelper merges over defaults, coerces incoming fields, handles Pro groups in Lite
 * downgrades, and manages storefront-text literal '' vs. default substitution.
 * These tests prove the coercion rules that fail silently when wrong — especially
 * list replacement (slug_list/badge_list wholesale-assigned, not index-merged).
 */
class DesignHelperTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yayloyalty_test_options']         = [];
        $GLOBALS['yayloyalty_test_current_user'] = 1;
    }

    /**
     * Unknown fields are dropped from the schema whitelist; the input includes a
     * non-schema key 'fake_field' which does not appear in the stored result.
     */
    public function test_unknown_key_dropped(): void {
        $input = [
            'launcher' => [
                'position'   => 'bottom_left',
                'fake_field' => 'should be dropped',
            ],
        ];

        $result = DesignHelper::update_design( $input );

        $this->assertArrayNotHasKey( 'fake_field', $result['launcher'] ?? [] );
        $this->assertEquals( 'bottom_left', $result['launcher']['position'] );
    }

    /**
     * Invalid enum value (launcher.position: 'middle' is not in the allowed list)
     * reverts to the stored value or default on merge.
     */
    public function test_invalid_enum_reverts_to_default(): void {
        $input = [
            'launcher' => [
                'position' => 'middle', // Invalid, not in schema
            ],
        ];

        $result = DesignHelper::update_design( $input );

        // Invalid enum is coerced to null, skipped, so the default survives the merge
        $this->assertEquals( 'bottom_right', $result['launcher']['position'] );
    }

    /**
     * corner_radius is clamped to [0, 24]; input 30 becomes 24, input -5 becomes 0.
     */
    public function test_corner_radius_clamped(): void {
        $input  = [
            'launcher' => [
                'corner_radius' => 30,
            ],
        ];
        $result = DesignHelper::update_design( $input );
        $this->assertEquals( 24, $result['launcher']['corner_radius'] );

        $input['launcher']['corner_radius'] = -5;
        $result                             = DesignHelper::update_design( $input );
        $this->assertEquals( 0, $result['launcher']['corner_radius'] );
    }

    /**
     * Badge list coercion: per-field sanitisation, 20-badge cap, entries without
     * tier_id dropped, dedup on tier_id keeping first occurrence.
     */
    public function test_badge_list_coercion(): void {
        $input = [
            'tier' => [
                'badges' => [
                    [
                        'tier_id' => 'tier1',
                        'color'   => '#FF0000', // Valid
                        'icon'    => 'ring',
                        'label'   => 'Tier One',
                    ],
                    [
                        'tier_id' => '', // No tier_id, entry dropped
                        'color'   => '#00FF00',
                        'icon'    => 'diamond',
                        'label'   => 'No ID',
                    ],
                    [
                        'tier_id' => 'tier1', // Duplicate, second occurrence dropped
                        'color'   => '#0000FF',
                        'icon'    => 'square',
                        'label'   => 'Tier One Again',
                    ],
                ],
            ],
        ];

        $result = DesignHelper::update_design( $input );

        $this->assertCount( 1, $result['tier']['badges'] );
        $this->assertEquals( 'tier1', $result['tier']['badges'][0]['tier_id'] );
        $this->assertEquals( '#FF0000', $result['tier']['badges'][0]['color'] ); // sanitize_hex_color() preserves case
    }

    /**
     * Badge list 20-item cap: input has 21 badges, result has 20.
     */
    public function test_badge_list_20_cap(): void {
        $badges = [];
        for ( $i = 0; $i < 25; $i++ ) {
            $badges[] = [
                'tier_id' => "tier_{$i}",
                'color'   => '#c8c8c8',
                'icon'    => 'ring',
                'label'   => "Tier {$i}",
            ];
        }

        $input = [
            'tier' => [
                'badges' => $badges,
            ],
        ];

        $result = DesignHelper::update_design( $input );

        $this->assertCount( 20, $result['tier']['badges'] );
    }

    /**
     * List field wholesale replacement, NOT index merge: save visibility.include_page_ids = []
     * over a stored [ 12, 34 ] and assert the result is [], not the old list.
     */
    public function test_list_field_wholesale_replacement(): void {
        // Set a non-empty list first
        update_option(
            DesignHelper::OPTION_DESIGN,
            [
                'visibility' => [
                    'include_page_ids' => [ 12, 34 ],
                ],
            ]
        );

        // Save an empty list to replace it
        $input = [
            'visibility' => [
                'include_page_ids' => [],
            ],
        ];

        $result = DesignHelper::update_design( $input );

        // If index-merged, this would be [ 12, 34 ]; wholesale-replaced, it's []
        $this->assertEquals( [], $result['visibility']['include_page_ids'] );
    }

    /**
     * Save two badges over the three defaults, then assert count === 2 (not 3).
     */
    public function test_list_replacement_badges_count(): void {
        $input = [
            'tier' => [
                'badges' => [
                    [
                        'tier_id' => 'explorer',
                        'color'   => '#c8c8c8',
                        'icon'    => 'ring',
                        'label'   => 'Explorer',
                    ],
                    [
                        'tier_id' => 'summit',
                        'color'   => '#8a8a8a',
                        'icon'    => 'diamond',
                        'label'   => 'Summit',
                    ],
                ],
            ],
        ];

        $result = DesignHelper::update_design( $input );

        $this->assertCount( 2, $result['tier']['badges'] );
    }

    /**
     * schema_version is stamped on every update and read.
     */
    public function test_schema_version_stamped(): void {
        $input = [
            'launcher' => [
                'position' => 'bottom_left',
            ],
        ];

        $result = DesignHelper::update_design( $input );

        $this->assertEquals( DesignHelper::SCHEMA_VERSION, $result['schema_version'] );

        $result2 = DesignHelper::get_design();

        $this->assertEquals( DesignHelper::SCHEMA_VERSION, $result2['schema_version'] );
    }

    /**
     * Corrupt stored option (non-array, e.g., a string or null) is treated as [],
     * so get_design() returns defaults, and update_design() can proceed.
     */
    public function test_corrupt_stored_option_reverts_to_defaults(): void {
        // Simulate corruption: stored as a string instead of array
        update_option( DesignHelper::OPTION_DESIGN, 'corrupted string' );

        $result = DesignHelper::get_design();

        // Corrupt value is treated as [], so defaults apply
        $this->assertEquals( 'bottom_right', $result['launcher']['position'] );
        $this->assertArrayHasKey( 'tier', $result ); // Pro group present
    }

    /**
     * Pro groups survive a Lite-shaped save: build defaults, change one Free field,
     * save the whole blob, assert tier/panel/typography byte-identical to before.
     */
    public function test_pro_groups_survive_lite_shaped_save(): void {
        // Give every Pro group (tier/panel/typography) a non-default value so "unchanged
        // after the save" actually proves survival, not just that defaults were re-applied.
        $pro_design                                    = DesignSchema::get_default_design();
        $pro_design['tier']['badges'][0]['color']      = '#ff0000';
        $pro_design['panel']['layout']                 = 'full';
        $pro_design['panel']['show_reward_catalog']    = true;
        $pro_design['typography']['mode']              = 'custom';
        $pro_design['typography']['font']              = 'Georgia';
        $pro_design['typography']['custom_css']        = '.yayloyalty { color: red; }';

        update_option( DesignHelper::OPTION_DESIGN, $pro_design );

        // Simulate a Lite save: send only Free fields, omitting Pro entirely.
        $lite_input = [
            'launcher'        => [
                'position'     => 'top_left', // Changed Free field
                'button_style' => 'icon',
            ],
            'storefront_text' => [
                'launcher_label' => 'Points',
            ],
        ];

        $result = DesignHelper::update_design( $lite_input );

        // All three Pro groups must be byte-identical to what was stored before the save.
        $this->assertEquals( $pro_design['tier'], $result['tier'] );
        $this->assertEquals( $pro_design['panel'], $result['panel'] );
        $this->assertEquals( $pro_design['typography'], $result['typography'] );
        $this->assertEquals( 'top_left', $result['launcher']['position'] ); // Free field changed
    }

    /**
     * Storefront text defaults are non-empty for all seven fields.
     */
    public function test_storefront_text_defaults_non_empty(): void {
        $defaults = DesignSchema::get_default_design();

        foreach ( $defaults['storefront_text'] as $field => $value ) {
            $this->assertNotEmpty( $value, "storefront_text.{$field} is empty" );
        }
    }

    /**
     * Saved empty string '' stays literal '' on read for non-structural fields;
     * it is not substituted with the default unless reset() is called.
     * (Structural labels reject empty strings, so we test a non-structural field.)
     */
    public function test_saved_empty_string_stays_empty(): void {
        $input = [
            'storefront_text' => [
                'earn_rate_line'    => '', // Non-structural, empty is allowed
                'signed_out_prompt' => 'Custom Prompt',
            ],
        ];

        $result = DesignHelper::update_design( $input );

        $this->assertEquals( '', $result['storefront_text']['earn_rate_line'] );
        $this->assertEquals( 'Custom Prompt', $result['storefront_text']['signed_out_prompt'] );
    }

    /**
     * reset( 'storefront_text' ) restores the seven storefront_text fields to defaults
     * and touches nothing else.
     */
    public function test_reset_one_group(): void {
        // Set custom values for both storefront_text and launcher
        $input = [
            'launcher'        => [
                'position' => 'top_left',
            ],
            'storefront_text' => [
                'launcher_label' => 'Custom',
            ],
        ];
        DesignHelper::update_design( $input );

        // Reset only storefront_text
        $result = DesignHelper::reset( 'storefront_text' );

        // storefront_text is restored to defaults
        $defaults = DesignSchema::get_default_design();
        $this->assertEquals( $defaults['storefront_text'], $result['storefront_text'] );

        // launcher is untouched
        $this->assertEquals( 'top_left', $result['launcher']['position'] );
    }

    /**
     * reset( 'nonsense' ) (invalid group name) is a no-op and returns current design.
     */
    public function test_reset_invalid_group_is_noop(): void {
        $input = [
            'storefront_text' => [
                'launcher_label' => 'Custom',
            ],
        ];
        DesignHelper::update_design( $input );

        $before = DesignHelper::get_design();
        $result = DesignHelper::reset( 'nonsense' );
        $after  = DesignHelper::get_design();

        // Design is unchanged
        $this->assertEquals( 'Custom', $result['storefront_text']['launcher_label'] );
        $this->assertEquals( $before, $after );
    }

    /**
     * reset( null ) restores the entire design to defaults.
     */
    public function test_reset_all(): void {
        $input = [
            'launcher'        => [
                'position' => 'top_left',
            ],
            'storefront_text' => [
                'launcher_label' => 'Custom',
            ],
        ];
        DesignHelper::update_design( $input );

        $result = DesignHelper::reset( null );

        $defaults = DesignSchema::get_default_design();
        // launcher is restored (icon_upload_url is server-derived, not in defaults)
        $this->assertEquals( $defaults['launcher']['position'], $result['launcher']['position'] );
        // storefront_text is restored entirely
        $this->assertEquals( $defaults['storefront_text'], $result['storefront_text'] );
    }

    /** A blank required storefront label is refused and keeps its prior value; the optional ones store the blank. */
    public function test_blank_required_label_keeps_prior_value(): void {
        $result = DesignHelper::update_design(
            [
                'storefront_text' => [
                    'launcher_label'        => '   ',
                    'balance_heading'       => '',
                    'redeem_button'         => '<b></b>',
                    'signed_out_prompt'     => '',
                    'referral_card_heading' => '',
                    'tier_progress_line'    => '',
                ],
            ]
        );

        $this->assertSame( '', $result['storefront_text']['signed_out_prompt'] );
        $this->assertSame( '', $result['storefront_text']['referral_card_heading'] );
        $this->assertSame( '', $result['storefront_text']['tier_progress_line'] );

        $defaults = DesignSchema::get_default_design()['storefront_text'];
        $this->assertEquals( $defaults['launcher_label'], $result['storefront_text']['launcher_label'] );
        $this->assertEquals( $defaults['balance_heading'], $result['storefront_text']['balance_heading'] );
        $this->assertEquals( $defaults['redeem_button'], $result['storefront_text']['redeem_button'] );
    }

    /** Text is capped at 200 characters, the font name at 120, custom CSS at 20 000. */
    public function test_text_length_caps(): void {
        $result = DesignHelper::update_design(
            [
                'storefront_text' => [ 'signed_out_prompt' => str_repeat( 'a', 250 ) ],
                'typography'      => [
                    'font'       => str_repeat( 'f', 150 ),
                    'custom_css' => str_repeat( 'c', 20050 ),
                ],
            ]
        );

        $this->assertSame( 200, mb_strlen( $result['storefront_text']['signed_out_prompt'] ) );
        $this->assertSame( 120, mb_strlen( $result['typography']['font'] ) );
        $this->assertSame( 20000, mb_strlen( $result['typography']['custom_css'] ) );
    }

    /** An invalid colour is refused and the default survives. */
    public function test_invalid_color_keeps_default(): void {
        $result = DesignHelper::update_design( [ 'launcher' => [ 'brand_color' => 'not-a-colour' ] ] );

        $this->assertEquals( DesignSchema::get_default_design()['launcher']['brand_color'], $result['launcher']['brand_color'] );
    }

    /** Page ids become positive integers, deduped, capped at 100. */
    public function test_page_ids_cleaned_and_capped(): void {
        $result = DesignHelper::update_design( [ 'visibility' => [ 'include_page_ids' => [ 5, '5', 0, -3, 'x', 9 ] ] ] );
        $this->assertSame( [ 5, 3, 9 ], $result['visibility']['include_page_ids'] );

        $result = DesignHelper::update_design( [ 'visibility' => [ 'include_page_ids' => range( 1, 150 ) ] ] );
        $this->assertCount( 100, $result['visibility']['include_page_ids'] );
    }

    /** Role slugs are sanitised and empty ones dropped. */
    public function test_role_slugs_sanitised(): void {
        $result = DesignHelper::update_design( [ 'visibility' => [ 'hide_for_roles' => [ 'Shop Manager!', '' ] ] ] );

        $this->assertSame( [ 'shopmanager' ], $result['visibility']['hide_for_roles'] );
    }

    /** An attachment id is cast to a non-negative integer. */
    public function test_attachment_id_cast(): void {
        $result = DesignHelper::update_design( [ 'launcher' => [ 'icon_upload_id' => '12abc' ] ] );

        $this->assertSame( 12, $result['launcher']['icon_upload_id'] );
    }

    /** Choices are matched after sanitize_key, so case differences still match. */
    public function test_choice_matched_case_insensitively(): void {
        $result = DesignHelper::update_design( [ 'launcher' => [ 'position' => 'BOTTOM_LEFT' ] ] );

        $this->assertSame( 'bottom_left', $result['launcher']['position'] );
    }

    /** Bad values already in the stored option are cleaned on read, not only on write. */
    public function test_stored_values_cleaned_on_read(): void {
        $GLOBALS['yayloyalty_test_options'][ DesignHelper::OPTION_DESIGN ] = [
            'launcher' => [
                'corner_radius' => 99,
                'position'      => 'middle',
            ],
        ];

        $result = DesignHelper::get_design();

        $this->assertSame( 24, $result['launcher']['corner_radius'] );
        $this->assertSame( 'bottom_right', $result['launcher']['position'] );
    }

    /** A boolean sent as a string is read the way WordPress reads it, so "false" is false. */
    public function test_bool_accepts_string_forms(): void {
        $cases = [
            'false' => false,
            'FALSE' => false,
            '0'     => false,
            ''      => false,
            'true'  => true,
            '1'     => true,
        ];

        foreach ( $cases as $input => $expected ) {
            $result = DesignHelper::update_design( [ 'visibility' => [ 'hide_on_mobile' => (string) $input ] ] );
            $this->assertSame( $expected, $result['visibility']['hide_on_mobile'], "input '{$input}'" );
        }

        $result = DesignHelper::update_design( [ 'visibility' => [ 'hide_on_mobile' => false ] ] );
        $this->assertFalse( $result['visibility']['hide_on_mobile'] );
    }
}
