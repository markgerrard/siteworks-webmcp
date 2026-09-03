<?php

namespace App\Services\Site\Editor;

/**
 * Flyer-leg commerce operations (spec v7 § 7).
 */
final class CommerceOperations
{
    /**
     * @var list<string>
     */
    public const NAMES = [
        'list_products',
        'get_product',
        'draft_product',
        'update_draft_product',
        'set_product_image',
        'manage_category',
        'draft_category_content',
    ];

    /**
     * Commerce sandbox set: the five flyer-leg ops plus the single upload path.
     *
     * @var list<string>
     */
    public const SANDBOX = [
        'list_products',
        'get_product',
        'draft_product',
        'update_draft_product',
        'set_product_image',
        'manage_category',
        'draft_category_content',
        'upload_image',
        'export_products',
        'get_site_context',
        'get_brand_system',
        'get_logo_assets',
        'describe_import_products',
        'import_products',
        'skill_import_catalogue_from_source',
        'skill_add_product_with_imagery',
        'skill_export_catalogue',
    ];

    /**
     * Global site-scoped base set: read/handoff tools safe to advertise on
     * every portal and agents page that has an unambiguous active site.
     * A subset of SANDBOX. upload_image is the one write kept (media-library
     * scoped); import_products is deliberately excluded — a client-executable
     * catalogue write must not be advertised on pages that render
     * public-submitted text (enquiries, reviews, orders). Specialist editor
     * mutations stay off this list entirely.
     *
     * @var list<string>
     */
    public const PORTAL_BASE = [
        'get_site_context',
        'get_brand_system',
        'get_logo_assets',
        'export_products',
        'list_products',
        'get_product',
        'upload_image',
        'skill_export_catalogue',
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    public static function isCommerce(string $name): bool
    {
        return in_array($name, self::NAMES, true);
    }
}
