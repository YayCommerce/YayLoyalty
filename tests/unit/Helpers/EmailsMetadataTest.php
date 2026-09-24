<?php
namespace YayLoyalty\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Helpers\EmailsMetadata;

/**
 * EmailsMetadata exposes static metadata (labels/descriptions/recipient/defaults) via methods,
 * not constants, because __() cannot run inside a const initializer on PHP 7.4. pro_email_groups()
 * is the single source of truth — Free ids live inside the "points" group, not duplicated.
 */
class EmailsMetadataTest extends TestCase {

    public function test_free_email_ids_has_three_entries(): void {
        $this->assertCount( 3, EmailsMetadata::FREE_EMAIL_IDS );
        $this->assertContains( 'points_earned', EmailsMetadata::FREE_EMAIL_IDS );
        $this->assertContains( 'points_expiring_soon', EmailsMetadata::FREE_EMAIL_IDS );
        $this->assertContains( 'points_expired', EmailsMetadata::FREE_EMAIL_IDS );
    }

    public function test_pro_email_groups_has_five_groups(): void {
        $groups = EmailsMetadata::pro_email_groups();

        $this->assertCount( 5, $groups );
        $this->assertEqualsCanonicalizing(
            [ 'points', 'rewards', 'referral', 'tiers', 'birthday' ],
            array_keys( $groups )
        );
    }

    public function test_pro_email_groups_have_labels(): void {
        foreach ( EmailsMetadata::pro_email_groups() as $group ) {
            $this->assertArrayHasKey( 'label', $group );
            $this->assertNotEmpty( $group['label'] );
            $this->assertArrayHasKey( 'items', $group );
        }
    }

    public function test_points_group_has_four_items_including_all_free_ids(): void {
        $points = EmailsMetadata::pro_email_groups()['points'];

        $this->assertCount( 4, $points['items'] );

        foreach ( EmailsMetadata::FREE_EMAIL_IDS as $free_id ) {
            $this->assertArrayHasKey( $free_id, $points['items'] );
        }
    }

    public function test_item_shape_has_label_description_recipient_default_enabled(): void {
        foreach ( EmailsMetadata::pro_email_groups() as $group ) {
            foreach ( $group['items'] as $id => $item ) {
                $this->assertArrayHasKey( 'label', $item, "missing label for {$id}" );
                $this->assertNotEmpty( $item['label'], "empty label for {$id}" );
                $this->assertArrayHasKey( 'description', $item, "missing description for {$id}" );
                $this->assertNotEmpty( $item['description'], "empty description for {$id}" );
                $this->assertArrayHasKey( 'recipient', $item, "missing recipient for {$id}" );
                $this->assertSame( 'customer', $item['recipient'] );
                $this->assertArrayHasKey( 'default_enabled', $item, "missing default_enabled for {$id}" );
                $this->assertIsBool( $item['default_enabled'] );
            }
        }
    }

    public function test_free_ids_default_enabled_matches_the_documented_values(): void {
        $points = EmailsMetadata::pro_email_groups()['points']['items'];

        $this->assertTrue( $points['points_earned']['default_enabled'] );
        $this->assertTrue( $points['points_expiring_soon']['default_enabled'] );
        $this->assertFalse( $points['points_expired']['default_enabled'] );
    }

    public function test_all_email_ids_has_twelve_unique_entries(): void {
        $ids = EmailsMetadata::all_email_ids();

        $this->assertCount( 12, $ids );
        $this->assertCount( 12, array_unique( $ids ), 'ids must be unique across groups' );
    }

    public function test_all_email_ids_includes_every_free_id(): void {
        $ids = EmailsMetadata::all_email_ids();

        foreach ( EmailsMetadata::FREE_EMAIL_IDS as $free_id ) {
            $this->assertContains( $free_id, $ids );
        }
    }

    public function test_all_defaults_has_twelve_entries_matching_group_data(): void {
        $defaults = EmailsMetadata::all_defaults();

        $this->assertCount( 12, $defaults );

        foreach ( EmailsMetadata::pro_email_groups() as $group ) {
            foreach ( $group['items'] as $id => $item ) {
                $this->assertSame( $item['default_enabled'], $defaults[ $id ], "default mismatch for {$id}" );
            }
        }
    }

    public function test_all_defaults_keys_match_all_email_ids(): void {
        $this->assertEqualsCanonicalizing(
            EmailsMetadata::all_email_ids(),
            array_keys( EmailsMetadata::all_defaults() )
        );
    }
}
