<?php

namespace site7\studio\services\import;

use craft\elements\Entry;
use site7\studio\Site7Studio;

/**
 * Computes a deterministic hash of a live Craft Entry's content - the Phase
 * 9.2 `sourceHash` used to detect when an imported Page package's source has
 * changed since import ("Update Available"). Mirrors
 * EntryTypeSourceHasher's approach (Phase 9.1), but covers two content
 * shapes instead of one:
 *
 * - A plain page's own native field values (whatever PageImportService::
 *   importNativeContent() would capture).
 * - When the entry has Site7 Matrix content, the ordered matrix block
 *   composition (each block's Entry Type handle + its own field values) -
 *   TemplateGeneratorService (frozen, not modified by this phase) already
 *   reads this shape for "Save as Template"; this class independently
 *   reads the same live data for hashing only, never writing anything.
 */
class EntrySourceHasher
{
    public function computeHash(Entry $entry): string
    {
        $matrixHandle = $this->getMatrixFieldHandle();
        $skipHandles = $matrixHandle ? [$matrixHandle] : [];

        $payload = [
            'entryType' => $entry->getType()->handle,
            'nativeFields' => $this->extractScalarFieldValues($entry, $skipHandles),
            'matrixBlocks' => [],
        ];

        if ($matrixHandle && $entry->getFieldLayout()?->getFieldByHandle($matrixHandle)) {
            $fieldValue = $entry->getFieldValue($matrixHandle);
            $blocks = $fieldValue ? $fieldValue->status(null)->drafts(null)->savedDraftsOnly(false)->all() : [];
            foreach ($blocks as $block) {
                $payload['matrixBlocks'][] = [
                    'entryType' => $block->getType()->handle,
                    'fields' => $this->extractScalarFieldValues($block),
                ];
            }
        }

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @internal Public so PageUpdateService can reuse the exact same
     * scalar-extraction rule when recapturing a page's content on Update
     * Package, rather than a second slightly-different implementation.
     * @param string[] $skipHandles
     * @return array<string, mixed>
     */
    public function extractScalarFieldValues(Entry $element, array $skipHandles = []): array
    {
        $values = [];
        $layout = $element->getFieldLayout();
        foreach ($layout?->getCustomFields() ?? [] as $field) {
            if (in_array($field->handle, $skipHandles, true)) {
                continue;
            }
            $value = $element->getFieldValue($field->handle);
            if (is_scalar($value) || $value === null) {
                $values[$field->handle] = $value;
            } elseif (is_object($value) && method_exists($value, '__toString')) {
                $values[$field->handle] = (string)$value;
            }
        }
        ksort($values);
        return $values;
    }

    private function getMatrixFieldHandle(): ?string
    {
        $settings = Site7Studio::getInstance()->getSettings();
        if (!$settings->matrixFieldId) {
            return null;
        }
        $field = \Craft::$app->getFields()->getFieldById($settings->matrixFieldId);
        return $field?->handle;
    }
}
