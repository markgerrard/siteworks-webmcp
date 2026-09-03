<?php

namespace Tests\Support;

/**
 * Independent statement of OperationRegistry::effectiveRequiresApproval()
 * for every registered operation. OperationRiskTest and
 * AgentApprovalRoutesTest both read this so they cannot disagree about
 * the gated set.
 */
final class EditorOperationRisk
{
    /**
     * @return array<string, bool>
     */
    public static function expectedRequiresApproval(): array
    {
        return [
            'add_section' => false,
            'apply_theme_token_preset' => false,
            'assign_media' => false,
            'draft_category_content' => false,
            'edit_field' => false,
            'generate_image' => true,
            'generate_logo_concepts' => true,
            'get_brand_context' => false,
            'get_brand_system' => false,
            'get_draft_diff' => false,
            'get_effective_hero_state' => false,
            'get_job_status' => false,
            'get_logo_assets' => false,
            'get_page_structure' => false,
            'get_site_context' => false,
            'get_video_state' => false,
            'inspect_draft' => false,
            'list_image_versions' => false,
            'list_media' => false,
            'list_theme_token_presets' => false,
            'manage_category' => false,
            'manage_video' => true,
            'move_section' => false,
            'publish_summary' => false,
            'regenerate_hero' => true,
            'remove_section' => false,
            'restore_image_version' => true,
            'restore_media_version' => false,
            'save_theme_token_preset' => false,
            'seed_product_reviews' => false,
            'select_logo' => true,
            'set_fulfilment' => false,
            'set_hero_copy_style' => false,
            'set_logo_media' => true,
            'set_nav_container' => false,
            'set_nav_label' => false,
            'set_section_style' => false,
            'set_shop_index_blocks' => false,
            'set_theme_tokens' => false,
            'set_title_emphasis' => false,
            'set_variant' => false,
            'draft_product' => false,
            'get_product' => false,
            'list_products' => false,
            'set_product_image' => false,
            'update_draft_product' => false,
            'undo_revision' => true,
            'update_asset_metadata' => false,
            'update_brand_theme' => false,
            'update_form' => false,
            'update_page_settings' => false,
            'upload_image' => true,
            'validate_draft' => false,
        ];
    }
}
