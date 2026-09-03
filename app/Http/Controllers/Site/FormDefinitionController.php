<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Support\FormFieldDefinition;
use Illuminate\Http\JsonResponse;

class FormDefinitionController extends Controller
{
    public function __invoke(int $site, int $page, int $section): JsonResponse
    {
        $siteModel = Site::findOrFail($site);
        $this->authorize('view', $siteModel);

        $pageModel = GeneratedPage::where('site_id', $site)
            ->whereNull('archived_at')
            ->findOrFail($page);

        $content = $this->currentEditableContent($pageModel);
        $sectionData = $content['sections'][$section] ?? null;

        if (! is_array($sectionData)) {
            abort(422, 'Section index out of range.');
        }

        $sectionType = (string) ($sectionData['type'] ?? '');

        if (! in_array($sectionType, ['contact_form', 'lead_form'], true)) {
            abort(422, 'Section is not a form.');
        }

        $fieldsKey = $sectionType === 'lead_form' ? 'extra_fields' : 'fields';
        $storedFields = $sectionData[$fieldsKey] ?? [];
        $fields = is_array($storedFields) ? array_values($storedFields) : [];

        if ($sectionType === 'contact_form'
            && $fields === []
            && ! ($sectionData['fields_migrated'] ?? false)) {
            $fields = $this->implicitContactFields();
        }

        if ($sectionType === 'lead_form'
            && ! ($sectionData['message_field_migrated'] ?? false)
            && ! collect($fields)->contains('name', 'message')) {
            $fields[] = $this->implicitLeadMessageField();
        }

        return response()->json([
            'section_index' => $section,
            'section_type' => $sectionType,
            'title' => (string) ($sectionData['title'] ?? ''),
            'submit_label' => (string) ($sectionData['submit_label'] ?? ''),
            'fields' => $fields,
            'reserved' => ['name', 'email'],
            'max_fields' => FormFieldDefinition::capFor($sectionType),
            'max_options' => FormFieldDefinition::MAX_OPTIONS,
            'revision_id' => $pageModel->draft_revision_id ?? $pageModel->published_revision_id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function currentEditableContent(GeneratedPage $page): array
    {
        $rid = $page->draft_revision_id ?? $page->published_revision_id;

        if ($rid) {
            return PageRevision::find($rid)?->content_data ?? $page->content_data ?? [];
        }

        return $page->content_data ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function implicitContactFields(): array
    {
        return [
            [
                'name' => 'phone',
                'label' => 'Phone',
                'type' => 'tel',
                'required' => false,
                'placeholder' => 'Your phone number',
            ],
            [
                'name' => 'message',
                'label' => 'Message',
                'type' => 'textarea',
                'required' => false,
                'placeholder' => 'How can we help?',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function implicitLeadMessageField(): array
    {
        return [
            'name' => 'message',
            'label' => 'Message',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'How can we help?',
        ];
    }
}
