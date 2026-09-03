import './bootstrap';
import Sortable from 'sortablejs';
import richBodyEditor from './agents/rich-body-editor';
import './agents/repeatable-entries';
import { shopFilters } from './shop/filters';
import { shopSortMenu } from './shop/sort-menu';

// Expose globally so Alpine x-init blocks can reach it without per-component imports.
window.Sortable = Sortable;

// Livewire bundles Alpine and its classic script usually runs BEFORE this
// deferred module — so alpine:init has often already fired by the time we
// get here. Register immediately when Alpine exists (late registration
// still covers elements initialised afterwards, e.g. Livewire-morphed
// flyouts); fall back to the event for the opposite load order.
const registerAlpineComponents = () => {
    window.Alpine.data('richBodyEditor', richBodyEditor);
    window.Alpine.data('shopFilters', shopFilters);
    window.Alpine.data('shopSortMenu', shopSortMenu);
};
if (window.Alpine) {
    registerAlpineComponents();
} else {
    document.addEventListener('alpine:init', registerAlpineComponents);
}
