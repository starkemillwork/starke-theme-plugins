<?php

namespace MatthiasWeb\RealMediaLibrary\lite;

use MatthiasWeb\RealMediaLibrary\Vendor\DevOwl\Freemium\CorePro;
use MatthiasWeb\RealMediaLibrary\lite\folder\Collection;
use MatthiasWeb\RealMediaLibrary\lite\folder\Creatable;
use MatthiasWeb\RealMediaLibrary\lite\folder\Gallery;
use MatthiasWeb\RealMediaLibrary\lite\order\Sortable;
// @codeCoverageIgnoreStart
\defined('ABSPATH') or die('No script kiddies please!');
// Avoid direct file request
// @codeCoverageIgnoreEnd
/** @internal */
trait Core
{
    use CorePro;
    /**
     * Updater instance.
     *
     * @see https://github.com/Capevace/wordpress-plugin-updater
     */
    private $updater;
    // Documented in IOverrideCore
    public function overrideConstruct()
    {
        \wp_rml_register_creatable(Collection::class, RML_TYPE_COLLECTION);
        \wp_rml_register_creatable(Gallery::class, RML_TYPE_GALLERY);
    }
    // Documented in IOverrideCore
    public function overrideInit()
    {
        \add_filter('mla_media_modal_query_final_terms', [Sortable::class, 'mla_media_modal_query_final_terms'], 10, 2);
        \add_filter('posts_clauses', [Sortable::class, 'posts_clauses'], 10, 2);
        \add_action('RML/Item/MoveFinished', [Sortable::class, 'item_move_finished'], 1, 4);
        \add_action('deleted_realmedialibrary_meta', [Creatable::class, 'deleted_realmedialibrary_meta'], 10, 3);
    }
}
